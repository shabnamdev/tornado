<?php
/**
 * WP-CLI commands.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Cli;

use Shcd\TornadoDatabaseMaintenance\Backup\SqlBackupService;
use Shcd\TornadoDatabaseMaintenance\Cache\CachePurger;
use Shcd\TornadoDatabaseMaintenance\Cleanup\CleanupService;
use Shcd\TornadoDatabaseMaintenance\Cleanup\UnusedTableService;
use Shcd\TornadoDatabaseMaintenance\Core\OperationalDataService;
use Shcd\TornadoDatabaseMaintenance\Database\AutoIncrementService;
use Shcd\TornadoDatabaseMaintenance\Discovery\SchemaInspector;
use Shcd\TornadoDatabaseMaintenance\Integrity\IntegrityChecker;
use Shcd\TornadoDatabaseMaintenance\Mapping\DryRunService;
use Shcd\TornadoDatabaseMaintenance\Mapping\MappingBuilder;
use Shcd\TornadoDatabaseMaintenance\Reindex\ReindexService;
use Shcd\TornadoDatabaseMaintenance\Security\DestructiveActionGuard;

final class Commands {
	public function __construct(
		private readonly SchemaInspector $discovery,
		private readonly MappingBuilder $mapping,
		private readonly DryRunService $dry_run,
		private readonly SqlBackupService $backup,
		private readonly IntegrityChecker $integrity,
		private readonly CleanupService $cleanup,
		private readonly UnusedTableService $unused_tables,
		private readonly CachePurger $cache,
		private readonly AutoIncrementService $auto_increment,
		private readonly ReindexService $reindex,
		private readonly DestructiveActionGuard $guard,
		private readonly OperationalDataService $operational_data
	) {}

	public function register(): void {
		\WP_CLI::add_command( 'shcd-tornado-dbm discover', fn(): mixed => $this->output( $this->discovery->discover() ) );
		\WP_CLI::add_command( 'shcd-tornado-dbm storage status', fn(): mixed => $this->output( $this->operational_data->footprint() ) );
		\WP_CLI::add_command( 'shcd-tornado-dbm storage compact', fn(): mixed => $this->output( $this->operational_data->compact() ) );
		\WP_CLI::add_command( 'shcd-tornado-dbm mapping build', fn(): mixed => $this->output( $this->mapping->build() ) );
		\WP_CLI::add_command( 'shcd-tornado-dbm dry-run', function ( array $args ): mixed {
			if ( empty( $args[0] ) ) {
				\WP_CLI::error( 'Mapping UUID is required.' );
			}
			return $this->output( $this->dry_run->run( (string) $args[0] ) );
		} );
		\WP_CLI::add_command( 'shcd-tornado-dbm backup create', fn(): mixed => $this->output( $this->backup->create() ) );
		\WP_CLI::add_command( 'shcd-tornado-dbm backup restore', function ( array $args, array $assoc ): void {
			$uuid = (string) ( $args[0] ?? '' );
			$backup = $this->backup->find_verified( $uuid );
			if ( null === $backup ) {
				\WP_CLI::error( 'Verified backup not found or checksum verification failed.' );
			}
			$required = 'RESTORE-' . strtoupper( substr( str_replace( '-', '', $uuid ), 0, 12 ) );
			if ( ! isset( $assoc['confirm'] ) || ! hash_equals( $required, (string) $assoc['confirm'] ) ) {
				\WP_CLI::error( 'Confirmation required: --confirm=' . $required );
			}
			\WP_CLI::warning( 'Activating maintenance mode and importing the full SQL backup.' );
			\WP_CLI::runcommand( 'maintenance-mode activate', array( 'return' => false, 'exit_error' => true ) );
			try {
				\WP_CLI::runcommand( 'db import ' . escapeshellarg( (string) $backup['file_path'] ), array( 'return' => false, 'exit_error' => true ) );
				\WP_CLI::success( 'Backup restored.' );
			} finally {
				\WP_CLI::runcommand( 'maintenance-mode deactivate', array( 'return' => false, 'exit_error' => false ) );
			}
		} );
		\WP_CLI::add_command( 'shcd-tornado-dbm integrity check', function ( array $args ): mixed {
			return $this->output( $this->integrity->check( isset( $args[0] ) ? (string) $args[0] : null ) );
		} );
		\WP_CLI::add_command( 'shcd-tornado-dbm cleanup preview', function ( array $args ): mixed {
			return $this->output( $this->cleanup->preview( $this->cleanup_types( $args ) ) );
		} );
		\WP_CLI::add_command( 'shcd-tornado-dbm cleanup execute', function ( array $args, array $assoc ): mixed {
			if ( empty( $assoc['yes'] ) ) {
				\WP_CLI::confirm( 'Delete the selected cleanup candidates?' );
			}
			return $this->output( $this->cleanup->execute( $this->cleanup_types( $args ) ) );
		} );
		\WP_CLI::add_command( 'shcd-tornado-dbm tables scan-unused', fn(): mixed => $this->output( $this->unused_tables->scan() ) );
		\WP_CLI::add_command( 'shcd-tornado-dbm tables preflight', function ( array $args ): mixed {
			if ( count( $args ) < 2 ) {
				\WP_CLI::error( 'Usage: wp shcd-tornado-dbm tables preflight <table-name> <backup-uuid>' );
			}
			return $this->output( $this->unused_tables->preflight( (string) $args[0], (string) $args[1] ) );
		} );
		\WP_CLI::add_command( 'shcd-tornado-dbm tables quarantine', function ( array $args, array $assoc ): mixed {
			if ( count( $args ) < 2 || empty( $assoc['confirm'] ) ) {
				\WP_CLI::error( 'Usage: wp shcd-tornado-dbm tables quarantine <table-name> <backup-uuid> --confirm=<phrase>' );
			}
			return $this->output( $this->unused_tables->quarantine( (string) $args[0], (string) $args[1], (string) $assoc['confirm'] ) );
		} );
		\WP_CLI::add_command( 'shcd-tornado-dbm tables restore', function ( array $args, array $assoc ): mixed {
			if ( empty( $args[0] ) || empty( $assoc['confirm'] ) ) {
				\WP_CLI::error( 'Usage: wp shcd-tornado-dbm tables restore <quarantine-job-uuid> --confirm=<phrase>' );
			}
			return $this->output( $this->unused_tables->restore( (string) $args[0], (string) $assoc['confirm'] ) );
		} );
		\WP_CLI::add_command( 'shcd-tornado-dbm tables purge', function ( array $args, array $assoc ): mixed {
			if ( count( $args ) < 2 || empty( $assoc['confirm'] ) ) {
				\WP_CLI::error( 'Usage: wp shcd-tornado-dbm tables purge <quarantine-table> <backup-uuid> --confirm=<phrase>' );
			}
			if ( empty( $assoc['yes'] ) ) {
				\WP_CLI::confirm( 'Permanently drop the quarantined table?' );
			}
			$token = $this->guard->issue_cli( 'table_drop' );
			return $this->output( $this->unused_tables->purge( (string) $args[0], (string) $args[1], (string) $assoc['confirm'], $token ) );
		} );
		\WP_CLI::add_command( 'shcd-tornado-dbm cache purge', fn(): mixed => $this->output( $this->cache->purge() ) );
		\WP_CLI::add_command( 'shcd-tornado-dbm elementor repair', function ( array $args ): mixed {
			$mapping_uuid = isset( $args[0] ) ? (string) $args[0] : null;
			return $this->output( $this->cache->repair_elementor( $mapping_uuid ) );
		} );
		\WP_CLI::add_command( 'shcd-tornado-dbm auto-increment preview', function ( array $args, array $assoc ): mixed {
			$scope = sanitize_key( (string) ( $assoc['scope'] ?? $args[0] ?? 'all_prefixed' ) );
			return $this->output( $this->auto_increment->preview( $scope ) );
		} );
		\WP_CLI::add_command( 'shcd-tornado-dbm auto-increment normalize', function ( array $args, array $assoc ): mixed {
			$scope = sanitize_key( (string) ( $assoc['scope'] ?? $args[0] ?? 'all_prefixed' ) );
			if ( empty( $assoc['yes'] ) ) {
				\WP_CLI::confirm( 'Normalize AUTO_INCREMENT for the selected scope?' );
			}
			$preview = $this->auto_increment->preview( $scope );
			return $this->output( $this->auto_increment->execute( $scope, (string) $preview['preview_token'] ) );
		} );
		\WP_CLI::add_command( 'shcd-tornado-dbm reindex prepare', fn(): mixed => $this->output( $this->reindex->prepare_repeatable_reindex() ) );
		\WP_CLI::add_command( 'shcd-tornado-dbm reindex generations', fn(): mixed => $this->output( $this->reindex->generation_history() ) );
		\WP_CLI::add_command( 'shcd-tornado-dbm reindex preflight', function ( array $args ): mixed {
			if ( count( $args ) < 2 ) {
				\WP_CLI::error( 'Usage: wp shcd-tornado-dbm reindex preflight <mapping-uuid> <backup-uuid>' );
			}
			return $this->output( $this->reindex->preflight( (string) $args[0], (string) $args[1] ) );
		} );
		\WP_CLI::add_command( 'shcd-tornado-dbm reindex execute', function ( array $args, array $assoc ): mixed {
			if ( count( $args ) < 2 || empty( $assoc['confirm'] ) ) {
				\WP_CLI::error( 'Usage: wp shcd-tornado-dbm reindex execute <mapping-uuid> <backup-uuid> --confirm=<phrase>' );
			}
			if ( empty( $assoc['yes'] ) ) {
				\WP_CLI::confirm( 'Execute the destructive post-ID reindex now?' );
			}
			$token = $this->guard->issue_cli( 'reindex' );
			return $this->output( $this->reindex->execute( (string) $args[0], (string) $args[1], (string) $assoc['confirm'], $token ) );
		} );
	}

	/**
	 * @param array<string, mixed>|list<mixed> $data Output data.
	 */
	private function output( array $data ): array {
		\WP_CLI::line( (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		return $data;
	}

	/**
	 * @param list<string> $args CLI arguments.
	 * @return list<string>
	 */
	private function cleanup_types( array $args ): array {
		if ( empty( $args ) ) {
			return array( 'revisions', 'auto_drafts', 'trash', 'autosaves', 'pending_revisions', 'orphan_postmeta', 'orphan_comments', 'orphan_commentmeta', 'orphan_usermeta', 'orphan_termmeta', 'orphan_term_relationships', 'orphan_wc_order_itemmeta', 'orphan_wc_product_lookup', 'orphan_wc_attribute_lookup', 'orphan_wc_download_permissions', 'expired_transients' );
		}
		return array_values( array_filter( array_map( 'sanitize_key', explode( ',', (string) $args[0] ) ) ) );
	}
}
