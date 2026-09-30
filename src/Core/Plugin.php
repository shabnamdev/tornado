<?php
/**
 * Plugin composition root.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Core;

use Shcd\TornadoDatabaseMaintenance\Adapter\AdapterRegistry;
use Shcd\TornadoDatabaseMaintenance\Adapter\AdaptiveSchemaAdapter;
use Shcd\TornadoDatabaseMaintenance\Adapter\AdaptiveStructuredReferenceEngine;
use Shcd\TornadoDatabaseMaintenance\Adapter\RankMathAdapter;
use Shcd\TornadoDatabaseMaintenance\Adapter\RevisionArchiveAdapter;
use Shcd\TornadoDatabaseMaintenance\Admin\AdminController;
use Shcd\TornadoDatabaseMaintenance\Backup\SqlBackupService;
use Shcd\TornadoDatabaseMaintenance\Cache\CachePurger;
use Shcd\TornadoDatabaseMaintenance\Cleanup\CleanupService;
use Shcd\TornadoDatabaseMaintenance\Cleanup\UnusedTableService;
use Shcd\TornadoDatabaseMaintenance\Cli\Commands;
use Shcd\TornadoDatabaseMaintenance\Database\AutoIncrementService;
use Shcd\TornadoDatabaseMaintenance\Database\FingerprintService;
use Shcd\TornadoDatabaseMaintenance\Database\LegacyPrefixMigrator;
use Shcd\TornadoDatabaseMaintenance\Database\Schema;
use Shcd\TornadoDatabaseMaintenance\Database\Tables;
use Shcd\TornadoDatabaseMaintenance\Discovery\CandidateDetector;
use Shcd\TornadoDatabaseMaintenance\Discovery\SchemaInspector;
use Shcd\TornadoDatabaseMaintenance\Integrity\IntegrityChecker;
use Shcd\TornadoDatabaseMaintenance\Jobs\JobRepository;
use Shcd\TornadoDatabaseMaintenance\Logging\LogManagementService;
use Shcd\TornadoDatabaseMaintenance\Logging\Logger;
use Shcd\TornadoDatabaseMaintenance\Mapping\DryRunService;
use Shcd\TornadoDatabaseMaintenance\Mapping\MappingBuilder;
use Shcd\TornadoDatabaseMaintenance\Reindex\GenerationRepository;
use Shcd\TornadoDatabaseMaintenance\Reindex\ReferenceRegistry;
use Shcd\TornadoDatabaseMaintenance\Reindex\ReindexService;
use Shcd\TornadoDatabaseMaintenance\Reindex\StructuredDataScanner;
use Shcd\TornadoDatabaseMaintenance\Reindex\StructuredReferenceTransformer;
use Shcd\TornadoDatabaseMaintenance\Report\ReportExporter;
use Shcd\TornadoDatabaseMaintenance\Repair\BuilderRegistryService;
use Shcd\TornadoDatabaseMaintenance\Repair\ElementorRepairService;
use Shcd\TornadoDatabaseMaintenance\Rest\Routes;
use Shcd\TornadoDatabaseMaintenance\Security\DestructiveActionGuard;
use Shcd\TornadoDatabaseMaintenance\Security\WriteFreeze;

final class Plugin {
	private static ?self $instance = null;
	private bool $booted = false;
	private Container $container;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
		$this->container = new Container();
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		$this->maybe_upgrade();
		Activator::sync_capabilities();
		$this->register_services();
		$this->container->get( AdapterRegistry::class )->register( $this->container->get( RevisionArchiveAdapter::class ) );
		$this->container->get( AdapterRegistry::class )->register( $this->container->get( RankMathAdapter::class ) );
		$this->container->get( AdapterRegistry::class )->register( $this->container->get( AdaptiveSchemaAdapter::class ) );
		$this->container->get( OperationalDataService::class )->maybe_compact();
		if ( function_exists( 'do_action' ) ) {
			do_action( 'shcd_tornado_dbm_register_reference_adapters', $this->container->get( AdapterRegistry::class ) );
		}

		if ( is_admin() ) {
			$this->container->get( AdminController::class )->register();
		}
		$this->container->get( ElementorRepairService::class )->register_runtime_compatibility();
		$this->container->get( RevisionPolicyService::class )->register();
		$this->container->get( RevisionArchiveService::class )->register();
		$this->container->get( Routes::class )->register();
		$this->container->get( WriteFreeze::class )->register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$this->container->get( Commands::class )->register();
		}

		add_action( 'shcd_tornado_dbm_scheduled_cleanup', array( $this, 'run_scheduled_cleanup' ) );
		add_action( 'shcd_tornado_dbm_retention_cleanup', array( $this, 'run_retention_cleanup' ) );
		$this->sync_schedule();
	}

	public function run_scheduled_cleanup(): void {
		$types = array( 'auto_drafts', 'trash', 'expired_transients' );
		if ( 'wordpress' === Settings::get( 'revision_storage_mode', 'wordpress' ) ) {
			$types[] = 'revisions';
		}
		$this->container->get( CleanupService::class )->execute( $types );
		$this->container->get( RevisionArchiveService::class )->maintenance();
	}

	public function run_retention_cleanup(): void {
		$this->container->get( RetentionService::class )->run();
		$this->container->get( OperationalDataService::class )->compact();
	}

	private function maybe_upgrade(): void {
		global $wpdb;

		LegacyPrefixMigrator::migrate();
		$tables = new Tables( $wpdb );
		$required_tables = array(
			$tables->jobs(),
			$tables->mappings(),
			$tables->discoveries(),
			$tables->backups(),
			$tables->logs(),
			$tables->revision_archive(),
		);
		$schema_missing = false;
		foreach ( $required_tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct database access is required for schema maintenance and must observe current uncached rows.
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				$schema_missing = true;
				break;
			}
		}

		if ( (string) get_option( 'shcd_tornado_dbm_version', '' ) === SHCD_TORNADO_DBM_VERSION && ! $schema_missing ) {
			return;
		}

		Schema::install();
		update_option( 'shcd_tornado_dbm_version', SHCD_TORNADO_DBM_VERSION, false );
	}

	private function sync_schedule(): void {
		$enabled = (bool) Settings::get( 'scheduled_cleanup', false );
		$next    = wp_next_scheduled( 'shcd_tornado_dbm_scheduled_cleanup' );
		if ( $enabled && false === $next ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'shcd_tornado_dbm_scheduled_cleanup' );
		} elseif ( ! $enabled && false !== $next ) {
			wp_clear_scheduled_hook( 'shcd_tornado_dbm_scheduled_cleanup' );
		}

		if ( false === wp_next_scheduled( 'shcd_tornado_dbm_retention_cleanup' ) ) {
			wp_schedule_event( time() + ( 2 * HOUR_IN_SECONDS ), 'daily', 'shcd_tornado_dbm_retention_cleanup' );
		}
	}

	private function register_services(): void {
		global $wpdb;
		$this->container->instance( \wpdb::class, $wpdb );
		$this->container->set( Tables::class, static fn( Container $c ): Tables => new Tables( $c->get( \wpdb::class ) ) );
		$this->container->set( Logger::class, static fn( Container $c ): Logger => new Logger( $c->get( \wpdb::class ), $c->get( Tables::class ) ) );
		$this->container->set( JobRepository::class, static fn( Container $c ): JobRepository => new JobRepository( $c->get( \wpdb::class ), $c->get( Tables::class ) ) );
		$this->container->set( AutoIncrementService::class, static fn( Container $c ): AutoIncrementService => new AutoIncrementService( $c->get( \wpdb::class ), $c->get( JobRepository::class ), $c->get( Logger::class ) ) );
		$this->container->set( LogManagementService::class, static fn( Container $c ): LogManagementService => new LogManagementService( $c->get( \wpdb::class ), $c->get( Tables::class ), $c->get( AutoIncrementService::class ), $c->get( JobRepository::class ) ) );
		$this->container->set( FingerprintService::class, static fn( Container $c ): FingerprintService => new FingerprintService( $c->get( \wpdb::class ) ) );
		$this->container->set( DestructiveActionGuard::class, static fn(): DestructiveActionGuard => new DestructiveActionGuard() );
		$this->container->set( AdapterRegistry::class, static fn(): AdapterRegistry => new AdapterRegistry() );
		$this->container->set( GenerationRepository::class, static fn(): GenerationRepository => new GenerationRepository() );
		$this->container->set( EnvironmentInspector::class, static fn( Container $c ): EnvironmentInspector => new EnvironmentInspector( $c->get( \wpdb::class ) ) );
		$this->container->set( RetentionService::class, static fn( Container $c ): RetentionService => new RetentionService( $c->get( \wpdb::class ), $c->get( Tables::class ), $c->get( GenerationRepository::class ) ) );
		$this->container->set( OperationalDataService::class, static fn( Container $c ): OperationalDataService => new OperationalDataService( $c->get( \wpdb::class ), $c->get( Tables::class ), $c->get( GenerationRepository::class ) ) );
		$this->container->set( CandidateDetector::class, static fn(): CandidateDetector => new CandidateDetector() );
		$this->container->set( SchemaInspector::class, static fn( Container $c ): SchemaInspector => new SchemaInspector( $c->get( \wpdb::class ), $c->get( Tables::class ), $c->get( CandidateDetector::class ), $c->get( FingerprintService::class ), $c->get( JobRepository::class ), $c->get( Logger::class ) ) );
		$this->container->set( MappingBuilder::class, static fn( Container $c ): MappingBuilder => new MappingBuilder( $c->get( \wpdb::class ), $c->get( Tables::class ), $c->get( FingerprintService::class ), $c->get( JobRepository::class ), $c->get( Logger::class ), $c->get( GenerationRepository::class ) ) );
		$this->container->set( DryRunService::class, static fn( Container $c ): DryRunService => new DryRunService( $c->get( \wpdb::class ), $c->get( Tables::class ), $c->get( MappingBuilder::class ), $c->get( JobRepository::class ) ) );
		$this->container->set( SqlBackupService::class, static fn( Container $c ): SqlBackupService => new SqlBackupService( $c->get( \wpdb::class ), $c->get( Tables::class ), $c->get( FingerprintService::class ), $c->get( JobRepository::class ), $c->get( Logger::class ), $c->get( AutoIncrementService::class ), $c->get( GenerationRepository::class ) ) );
		$this->container->set( CleanupService::class, static fn( Container $c ): CleanupService => new CleanupService( $c->get( \wpdb::class ), $c->get( JobRepository::class ), $c->get( AutoIncrementService::class ) ) );
		$this->container->set( RevisionPolicyService::class, static fn(): RevisionPolicyService => new RevisionPolicyService() );
		$this->container->set( RevisionArchiveService::class, static fn( Container $c ): RevisionArchiveService => new RevisionArchiveService( $c->get( \wpdb::class ), $c->get( Tables::class ), $c->get( Logger::class ) ) );
		$this->container->set( RevisionArchiveAdapter::class, static fn( Container $c ): RevisionArchiveAdapter => new RevisionArchiveAdapter( $c->get( \wpdb::class ), $c->get( Tables::class ), $c->get( RevisionArchiveService::class ) ) );
		$this->container->set( RankMathAdapter::class, static fn( Container $c ): RankMathAdapter => new RankMathAdapter( $c->get( \wpdb::class ), $c->get( Tables::class ) ) );
		$this->container->set( AdaptiveStructuredReferenceEngine::class, static fn( Container $c ): AdaptiveStructuredReferenceEngine => new AdaptiveStructuredReferenceEngine( $c->get( \wpdb::class ), $c->get( Tables::class ) ) );
		$this->container->set( AdaptiveSchemaAdapter::class, static fn( Container $c ): AdaptiveSchemaAdapter => new AdaptiveSchemaAdapter( $c->get( \wpdb::class ), $c->get( Tables::class ), $c->get( AdaptiveStructuredReferenceEngine::class ) ) );
		$this->container->set( BuilderRegistryService::class, static fn( Container $c ): BuilderRegistryService => new BuilderRegistryService( $c->get( \wpdb::class ), $c->get( Tables::class ) ) );
		$this->container->set( ElementorRepairService::class, static fn( Container $c ): ElementorRepairService => new ElementorRepairService( $c->get( \wpdb::class ), $c->get( Tables::class ), $c->get( JobRepository::class ), $c->get( Logger::class ), $c->get( BuilderRegistryService::class ) ) );
		$this->container->set( CachePurger::class, static fn( Container $c ): CachePurger => new CachePurger( $c->get( \wpdb::class ), $c->get( AutoIncrementService::class ), $c->get( ElementorRepairService::class ) ) );
		$this->container->set( ReferenceRegistry::class, static fn( Container $c ): ReferenceRegistry => new ReferenceRegistry( $c->get( \wpdb::class ), $c->get( AdapterRegistry::class ) ) );
		$this->container->set( StructuredDataScanner::class, static fn( Container $c ): StructuredDataScanner => new StructuredDataScanner( $c->get( \wpdb::class ), $c->get( Tables::class ) ) );
		$this->container->set( StructuredReferenceTransformer::class, static fn( Container $c ): StructuredReferenceTransformer => new StructuredReferenceTransformer( $c->get( \wpdb::class ), $c->get( Tables::class ), $c->get( ReferenceRegistry::class ), $c->get( AdapterRegistry::class ) ) );
		$this->container->set( IntegrityChecker::class, static fn( Container $c ): IntegrityChecker => new IntegrityChecker( $c->get( \wpdb::class ), $c->get( Tables::class ), $c->get( JobRepository::class ), $c->get( ReferenceRegistry::class ) ) );
		$this->container->set( UnusedTableService::class, static fn( Container $c ): UnusedTableService => new UnusedTableService( $c->get( \wpdb::class ), $c->get( SqlBackupService::class ), $c->get( JobRepository::class ), $c->get( Logger::class ), $c->get( DestructiveActionGuard::class ) ) );
		$this->container->set( ReindexService::class, static fn( Container $c ): ReindexService => new ReindexService( $c->get( \wpdb::class ), $c->get( Tables::class ), $c->get( FingerprintService::class ), $c->get( MappingBuilder::class ), $c->get( SqlBackupService::class ), $c->get( AdapterRegistry::class ), $c->get( ReferenceRegistry::class ), $c->get( StructuredDataScanner::class ), $c->get( StructuredReferenceTransformer::class ), $c->get( IntegrityChecker::class ), $c->get( CachePurger::class ), $c->get( AutoIncrementService::class ), $c->get( JobRepository::class ), $c->get( Logger::class ), $c->get( GenerationRepository::class ), $c->get( DestructiveActionGuard::class ) ) );
		$this->container->set( ReportExporter::class, static fn( Container $c ): ReportExporter => new ReportExporter( $c->get( JobRepository::class ) ) );
		$this->container->set( WriteFreeze::class, static fn(): WriteFreeze => new WriteFreeze() );
		$this->container->set( AdminController::class, static fn( Container $c ): AdminController => new AdminController( $c->get( ReportExporter::class ) ) );
		$this->container->set( Routes::class, static fn( Container $c ): Routes => new Routes( $c->get( SchemaInspector::class ), $c->get( MappingBuilder::class ), $c->get( DryRunService::class ), $c->get( SqlBackupService::class ), $c->get( IntegrityChecker::class ), $c->get( CleanupService::class ), $c->get( UnusedTableService::class ), $c->get( CachePurger::class ), $c->get( AutoIncrementService::class ), $c->get( ReindexService::class ), $c->get( JobRepository::class ), $c->get( ReportExporter::class ), $c->get( DestructiveActionGuard::class ), $c->get( EnvironmentInspector::class ), $c->get( Logger::class ), $c->get( LogManagementService::class ), $c->get( BuilderRegistryService::class ), $c->get( OperationalDataService::class ), $c->get( RevisionArchiveService::class ) ) );
		$this->container->set( Commands::class, static fn( Container $c ): Commands => new Commands( $c->get( SchemaInspector::class ), $c->get( MappingBuilder::class ), $c->get( DryRunService::class ), $c->get( SqlBackupService::class ), $c->get( IntegrityChecker::class ), $c->get( CleanupService::class ), $c->get( UnusedTableService::class ), $c->get( CachePurger::class ), $c->get( AutoIncrementService::class ), $c->get( ReindexService::class ), $c->get( DestructiveActionGuard::class ), $c->get( OperationalDataService::class ) ) );
	}
}
