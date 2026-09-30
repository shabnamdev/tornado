<?php
/**
 * Authenticated REST API routes.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Rest;

use Shcd\TornadoDatabaseMaintenance\Backup\SqlBackupService;
use Shcd\TornadoDatabaseMaintenance\Cache\CachePurger;
use Shcd\TornadoDatabaseMaintenance\Cleanup\CleanupService;
use Shcd\TornadoDatabaseMaintenance\Cleanup\UnusedTableService;
use Shcd\TornadoDatabaseMaintenance\Core\Capabilities;
use Shcd\TornadoDatabaseMaintenance\Core\EnvironmentInspector;
use Shcd\TornadoDatabaseMaintenance\Core\OperationalDataService;
use Shcd\TornadoDatabaseMaintenance\Core\RevisionArchiveService;
use Shcd\TornadoDatabaseMaintenance\Core\Settings;
use Shcd\TornadoDatabaseMaintenance\Database\AutoIncrementService;
use Shcd\TornadoDatabaseMaintenance\Discovery\SchemaInspector;
use Shcd\TornadoDatabaseMaintenance\Integrity\IntegrityChecker;
use Shcd\TornadoDatabaseMaintenance\Jobs\JobRepository;
use Shcd\TornadoDatabaseMaintenance\Logging\LogManagementService;
use Shcd\TornadoDatabaseMaintenance\Logging\Logger;
use Shcd\TornadoDatabaseMaintenance\Mapping\DryRunService;
use Shcd\TornadoDatabaseMaintenance\Mapping\MappingBuilder;
use Shcd\TornadoDatabaseMaintenance\Reindex\ReindexService;
use Shcd\TornadoDatabaseMaintenance\Report\ReportExporter;
use Shcd\TornadoDatabaseMaintenance\Repair\BuilderRegistryService;
use Shcd\TornadoDatabaseMaintenance\Security\DestructiveActionGuard;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class Routes {
	private const NAMESPACE = 'shcd-tornado-dbm/v1';

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
		private readonly JobRepository $jobs,
		private readonly ReportExporter $reports,
		private readonly DestructiveActionGuard $guard,
		private readonly EnvironmentInspector $environment,
		private readonly Logger $logger,
		private readonly LogManagementService $log_management,
		private readonly BuilderRegistryService $builder_registry,
		private readonly OperationalDataService $operational_data,
		private readonly RevisionArchiveService $revision_archive
	) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		$this->route( '/system', WP_REST_Server::READABLE, array( $this, 'system' ), Capabilities::VIEW_DASHBOARD );
		$this->route( '/storage/footprint', WP_REST_Server::READABLE, fn(): WP_REST_Response|WP_Error => $this->respond( fn(): array => $this->operational_data->footprint() ), Capabilities::VIEW_DASHBOARD );
		$this->route( '/storage/compact', WP_REST_Server::CREATABLE, fn(): WP_REST_Response|WP_Error => $this->respond( fn(): array => $this->operational_data->compact() ), Capabilities::RUN_CLEANUP );
		$this->route( '/revisions/archive/status', WP_REST_Server::READABLE, fn(): WP_REST_Response|WP_Error => $this->respond( fn(): array => $this->revision_archive->status() ), Capabilities::VIEW_DASHBOARD );
		$this->route( '/revisions/archive', WP_REST_Server::READABLE, fn( WP_REST_Request $request ): WP_REST_Response|WP_Error => $this->respond( fn(): array => $this->revision_archive->list_snapshots( absint( $request->get_param( 'post_id' ) ?: 0 ), absint( $request->get_param( 'limit' ) ?: 100 ), absint( $request->get_param( 'offset' ) ?: 0 ) ) ), Capabilities::VIEW_DASHBOARD );
		$this->route( '/revisions/archive/restore', WP_REST_Server::CREATABLE, fn( WP_REST_Request $request ): WP_REST_Response|WP_Error => $this->respond( fn(): array => $this->revision_archive->restore( absint( $request->get_param( 'snapshot_id' ) ) ) ), Capabilities::RUN_CLEANUP );
		$this->route( '/revisions/archive/delete', WP_REST_Server::CREATABLE, fn( WP_REST_Request $request ): WP_REST_Response|WP_Error => $this->respond( fn(): array => $this->revision_archive->delete( $this->sanitize_int_list( $request->get_param( 'ids' ) ) ) ), Capabilities::RUN_CLEANUP );
		$this->route( '/revisions/archive/migrate-core', WP_REST_Server::CREATABLE, fn( WP_REST_Request $request ): WP_REST_Response|WP_Error => $this->respond( fn(): array => $this->migrate_core_revisions_request( $request ) ), Capabilities::RUN_CLEANUP );
		$this->route( '/reindex/prepare', WP_REST_Server::CREATABLE, fn(): WP_REST_Response|WP_Error => $this->respond( fn(): array => $this->reindex->prepare_repeatable_reindex() ), Capabilities::EXECUTE_REINDEX );
		$this->route( '/reindex/generations', WP_REST_Server::READABLE, fn(): WP_REST_Response|WP_Error => $this->respond( fn(): array => $this->reindex->generation_history() ), Capabilities::VIEW_DASHBOARD );
		$this->route( '/discover', WP_REST_Server::CREATABLE, fn(): WP_REST_Response|WP_Error => $this->respond( fn(): array => $this->discovery->discover() ), Capabilities::RUN_DISCOVERY );
		$this->route( '/mapping', WP_REST_Server::CREATABLE, fn(): WP_REST_Response|WP_Error => $this->respond( fn(): array => $this->mapping->build() ), Capabilities::RUN_DISCOVERY );
		$this->route( '/backup', WP_REST_Server::CREATABLE, fn(): WP_REST_Response|WP_Error => $this->respond( fn(): array => $this->backup->create() ), Capabilities::MANAGE_BACKUPS );
		$this->route(
			'/backups',
			WP_REST_Server::READABLE,
			fn( WP_REST_Request $request ): WP_REST_Response|WP_Error => $this->respond( fn(): array => $this->backup->list_backups( absint( $request->get_param( 'limit' ) ?: 100 ), absint( $request->get_param( 'offset' ) ?: 0 ) ) ),
			Capabilities::MANAGE_BACKUPS
		);
		$this->route(
			'/backups/delete',
			WP_REST_Server::CREATABLE,
			fn( WP_REST_Request $request ): WP_REST_Response|WP_Error => $this->respond( fn(): array => $this->delete_backups_request( $request ) ),
			Capabilities::MANAGE_BACKUPS
		);
		$this->route( '/cache/purge', WP_REST_Server::CREATABLE, fn(): WP_REST_Response|WP_Error => $this->respond( fn(): array => $this->cache->purge() ), Capabilities::RUN_CLEANUP );
		$this->route( '/builders', WP_REST_Server::READABLE, fn(): WP_REST_Response|WP_Error => $this->respond( fn(): array => $this->builder_registry->list_registry() ), Capabilities::RUN_CLEANUP );
		$this->route( '/builders/scan', WP_REST_Server::CREATABLE, fn(): WP_REST_Response|WP_Error => $this->respond( fn(): array => $this->builder_registry->scan() ), Capabilities::RUN_CLEANUP );
		$this->route(
			'/builders/save',
			WP_REST_Server::CREATABLE,
			fn( WP_REST_Request $request ): WP_REST_Response|WP_Error => $this->respond( fn(): array => $this->builder_registry->save( $this->builder_entries( $request ) ) ),
			Capabilities::RUN_CLEANUP
		);
		$this->route(
			'/elementor/repair',
			WP_REST_Server::CREATABLE,
			fn( WP_REST_Request $request ): WP_REST_Response|WP_Error => $this->respond(
				fn(): array => $this->cache->repair_elementor(
					$this->nullable_uuid( $request->get_param( 'mapping_uuid' ) ),
					(bool) Settings::get( 'restore_missing_attachments_from_uploads', false ),
					'historical',
					false
				)
			),
			Capabilities::RUN_CLEANUP
		);

		$this->route(
			'/auto-increment/preview',
			WP_REST_Server::CREATABLE,
			fn( WP_REST_Request $request ): WP_REST_Response|WP_Error => $this->respond( fn(): array => $this->auto_increment->preview( sanitize_key( (string) $request->get_param( 'scope' ) ) ?: 'all_prefixed' ) ),
			Capabilities::RUN_CLEANUP
		);
		$this->route(
			'/auto-increment/execute',
			WP_REST_Server::CREATABLE,
			fn( WP_REST_Request $request ): WP_REST_Response|WP_Error => $this->respond( fn(): array => $this->auto_increment->execute( sanitize_key( (string) $request->get_param( 'scope' ) ) ?: 'all_prefixed', $this->plain( $request, 'preview_token' ) ) ),
			Capabilities::RUN_CLEANUP
		);

		$this->route(
			'/dry-run',
			WP_REST_Server::CREATABLE,
			fn( WP_REST_Request $request ): WP_REST_Response|WP_Error => $this->respond( fn(): array => $this->dry_run->run( $this->required_uuid( $request, 'mapping_uuid' ) ) ),
			Capabilities::RUN_DISCOVERY
		);
		$this->route(
			'/integrity',
			WP_REST_Server::CREATABLE,
			fn( WP_REST_Request $request ): WP_REST_Response|WP_Error => $this->respond( fn(): array => $this->integrity->check( $this->nullable_uuid( $request->get_param( 'mapping_uuid' ) ) ) ),
			Capabilities::RUN_DISCOVERY
		);
		$this->route(
			'/cleanup/preview',
			WP_REST_Server::CREATABLE,
			fn( WP_REST_Request $request ): WP_REST_Response|WP_Error => $this->respond( fn(): array => $this->cleanup->preview( $this->sanitize_string_list( $request->get_param( 'types' ) ) ) ),
			Capabilities::RUN_CLEANUP
		);
		$this->route(
			'/cleanup/execute',
			WP_REST_Server::CREATABLE,
			fn( WP_REST_Request $request ): WP_REST_Response|WP_Error => $this->respond( fn(): array => $this->cleanup->execute( $this->sanitize_string_list( $request->get_param( 'types' ) ), $this->plain( $request, 'preview_token' ) ) ),
			Capabilities::RUN_CLEANUP
		);

		$this->route( '/tables/unused', WP_REST_Server::READABLE, fn(): WP_REST_Response|WP_Error => $this->respond( fn(): array => $this->unused_tables->scan() ), Capabilities::RUN_DISCOVERY );
		$this->route(
			'/tables/preflight',
			WP_REST_Server::CREATABLE,
			fn( WP_REST_Request $request ): WP_REST_Response|WP_Error => $this->respond( fn(): array => $this->unused_tables->preflight( $this->plain( $request, 'table_name' ), $this->required_uuid( $request, 'backup_uuid' ) ) ),
			Capabilities::RUN_CLEANUP
		);
		$this->route(
			'/tables/quarantine',
			WP_REST_Server::CREATABLE,
			fn( WP_REST_Request $request ): WP_REST_Response|WP_Error => $this->respond( fn(): array => $this->unused_tables->quarantine( $this->plain( $request, 'table_name' ), $this->required_uuid( $request, 'backup_uuid' ), $this->plain( $request, 'confirmation' ) ) ),
			Capabilities::RUN_CLEANUP
		);
		$this->route(
			'/tables/restore',
			WP_REST_Server::CREATABLE,
			fn( WP_REST_Request $request ): WP_REST_Response|WP_Error => $this->respond( fn(): array => $this->unused_tables->restore( $this->required_uuid( $request, 'job_uuid' ), $this->plain( $request, 'confirmation' ) ) ),
			Capabilities::RUN_CLEANUP
		);
		$this->route(
			'/tables/purge',
			WP_REST_Server::CREATABLE,
			fn( WP_REST_Request $request ): WP_REST_Response|WP_Error => $this->respond( fn(): array => $this->unused_tables->purge( $this->plain( $request, 'table_name' ), $this->required_uuid( $request, 'backup_uuid' ), $this->plain( $request, 'confirmation' ), $this->plain( $request, 'authorization_token' ) ) ),
			Capabilities::RUN_CLEANUP
		);

		$this->route(
			'/reindex/preflight',
			WP_REST_Server::CREATABLE,
			fn( WP_REST_Request $request ): WP_REST_Response|WP_Error => $this->respond( fn(): array => $this->reindex->preflight( $this->required_uuid( $request, 'mapping_uuid' ), $this->required_uuid( $request, 'backup_uuid' ) ) ),
			Capabilities::EXECUTE_REINDEX
		);
		$this->route(
			'/reindex/reference-policies/preflight',
			WP_REST_Server::CREATABLE,
			fn( WP_REST_Request $request ): WP_REST_Response|WP_Error => $this->respond( fn(): array => $this->save_reference_policies_and_preflight( $request ) ),
			Capabilities::EXECUTE_REINDEX
		);
		$this->route(
			'/reindex/execute',
			WP_REST_Server::CREATABLE,
			fn( WP_REST_Request $request ): WP_REST_Response|WP_Error => $this->respond( fn(): array => $this->reindex->execute( $this->required_uuid( $request, 'mapping_uuid' ), $this->required_uuid( $request, 'backup_uuid' ), $this->plain( $request, 'confirmation' ), $this->plain( $request, 'authorization_token' ) ) ),
			Capabilities::EXECUTE_REINDEX
		);

		register_rest_route(
			self::NAMESPACE,
			'/security/arm',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'arm' ),
				'permission_callback' => static fn(): bool => is_user_logged_in(),
			)
		);

		$this->route(
			'/jobs',
			WP_REST_Server::READABLE,
			fn( WP_REST_Request $request ): WP_REST_Response => rest_ensure_response( $this->jobs->recent( absint( $request->get_param( 'limit' ) ?: 20 ) ) ),
			Capabilities::VIEW_DASHBOARD
		);
		$this->route(
			'/logs/manage',
			WP_REST_Server::READABLE,
			fn( WP_REST_Request $request ): WP_REST_Response|WP_Error => $this->respond(
				fn(): array => $this->log_management->list(
					absint( $request->get_param( 'limit' ) ?: 100 ),
					absint( $request->get_param( 'offset' ) ?: 0 ),
					sanitize_key( (string) $request->get_param( 'level' ) ),
					sanitize_text_field( (string) $request->get_param( 'job_uuid' ) )
				)
			),
			Capabilities::VIEW_SENSITIVE_LOGS
		);
		$this->route(
			'/logs/delete',
			WP_REST_Server::CREATABLE,
			fn( WP_REST_Request $request ): WP_REST_Response|WP_Error => $this->respond( fn(): array => $this->delete_logs_request( $request ) ),
			Capabilities::RUN_CLEANUP
		);
		$this->route(
			'/logs/purge',
			WP_REST_Server::CREATABLE,
			fn( WP_REST_Request $request ): WP_REST_Response|WP_Error => $this->respond( fn(): array => $this->purge_logs_request( $request ) ),
			Capabilities::RUN_CLEANUP
		);

		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => static fn(): WP_REST_Response => rest_ensure_response( Settings::all() ),
					'permission_callback' => $this->permission( Capabilities::VIEW_DASHBOARD ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => fn( WP_REST_Request $request ): WP_REST_Response|WP_Error => $this->respond(
						static function () use ( $request ): array {
							$body = $request->get_json_params();
							Settings::update( is_array( $body ) ? $body : array() );
							return Settings::all();
						}
					),
					'permission_callback' => $this->permission( Capabilities::MANAGE_SETTINGS ),
				),
			)
		);
	}

	private function save_reference_policies_and_preflight( WP_REST_Request $request ): array {
		$body     = $request->get_json_params();
		$body     = is_array( $body ) ? $body : array();
		$policies = isset( $body['policies'] ) && is_array( $body['policies'] ) ? $body['policies'] : array();
		$settings = Settings::all();
		$settings['reference_policies'] = $policies;
		Settings::update( $settings );

		$stored       = Settings::all();
		$mapping_uuid = $this->required_uuid( $request, 'mapping_uuid' );
		$backup_uuid  = $this->required_uuid( $request, 'backup_uuid' );
		$preflight    = $this->reindex->preflight( $mapping_uuid, $backup_uuid );
		$allowed          = ! empty( $preflight['allowed'] );
		$blocker_messages = isset( $preflight['blockers'] ) && is_array( $preflight['blockers'] ) ? array_values( array_filter( array_map( 'strval', $preflight['blockers'] ) ) ) : array();
		$blockers         = count( $blocker_messages );
		$first_blocker    = $blocker_messages[0] ?? '';

		return array(
			'message'        => $allowed
				? 'سیاست Referenceهای اختصاصی ذخیره شد و Preflight اجازه ادامه عملیات را صادر کرد.'
				: sprintf(
					'سیاست Referenceهای اختصاصی ذخیره شد، اما Preflight هنوز %1$d مورد بازدارنده دارد.%2$s',
					$blockers,
					'' !== $first_blocker ? ' علت اصلی: ' . $first_blocker : ''
				),
			'saved_policies' => (array) ( $stored['reference_policies'] ?? array() ),
			'settings'       => $stored,
			'preflight'      => $preflight,
		);
	}

	public function system(): WP_REST_Response {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct database access is required for schema maintenance and must observe current uncached rows.
		$post_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->posts ) ); 
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct database access is required for schema maintenance and must observe current uncached rows.
		$post_max_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(ID), 0) FROM %i', $wpdb->posts ) ); 
		$post_auto_increment = 0;
		try {
			$post_auto_increment_rows = $this->auto_increment->inspect( 'posts_only' );
			$post_auto_increment = isset( $post_auto_increment_rows[0] ) ? (int) $post_auto_increment_rows[0]['current_auto_increment'] : 0;
		} catch ( \Throwable ) {
			$post_auto_increment = 0;
		}

		$last_backup_uuid = (string) get_option( 'shcd_tornado_dbm_last_backup_uuid', '' );
		$last_backup = wp_is_uuid( $last_backup_uuid ) ? $this->backup->find_verified( $last_backup_uuid ) : null;

		return rest_ensure_response(
			array_merge(
				array(
					'plugin_version'       => SHCD_TORNADO_DBM_VERSION,
					'plugin_build'         => defined( 'SHCD_TORNADO_DBM_BUILD' ) ? SHCD_TORNADO_DBM_BUILD : SHCD_TORNADO_DBM_VERSION,
					'database_prefix'      => (string) $wpdb->prefix,
					'posts_table'          => (string) $wpdb->posts,
					'postmeta_table'       => (string) $wpdb->postmeta,
					'options_table'        => (string) $wpdb->options,
					'post_count'           => $post_count,
					'post_max_id'          => $post_max_id,
					'post_auto_increment'  => $post_auto_increment,
					'wordpress_version'    => get_bloginfo( 'version' ),
					'php_version'          => PHP_VERSION,
					'database_version'     => $wpdb->db_version(),
					'database_server_info' => $wpdb->db_server_info(),
					'multisite'            => is_multisite(),
					'active_reindex'       => (string) get_transient( 'shcd_tornado_dbm_active_reindex_lock' ),
					'last_discovery_uuid'  => (string) get_option( 'shcd_tornado_dbm_last_discovery_uuid', '' ),
					'last_mapping_uuid'    => (string) get_option( 'shcd_tornado_dbm_last_mapping_uuid', '' ),
					'last_backup_uuid'     => $last_backup_uuid,
					'destructive_reindex_enabled' => (bool) Settings::get( 'allow_destructive_reindex', false ),
					'last_backup_reindex_eligible' => is_array( $last_backup ) && ! empty( $last_backup['is_consistent'] ) && ! empty( $last_backup['is_private_location'] ),
					'wp_config_required'   => false,
				),
				$this->environment->inspect()
			)
		);
	}


	/** @return array<string,mixed> */
	private function migrate_core_revisions_request( WP_REST_Request $request ): array {
		if ( 'MIGRATE-REVISIONS' !== $this->plain( $request, 'confirmation' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
			throw new \InvalidArgumentException( 'عبارت تأیید انتقال Revisionها معتبر نیست.' );
		}
		return $this->revision_archive->migrate_core_revisions( absint( $request->get_param( 'limit' ) ?: 100 ) );
	}

	/** @return array<string, mixed> */
	private function delete_backups_request( WP_REST_Request $request ): array {
		if ( 'DELETE-BACKUPS' !== $this->plain( $request, 'confirmation' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
			throw new \InvalidArgumentException( 'عبارت تأیید حذف Backupها معتبر نیست.' );
		}
		$uuids = $request->get_param( 'uuids' );
		$uuids = is_array( $uuids ) ? array_map( 'sanitize_text_field', $uuids ) : array();
		return $this->backup->delete_backups( $uuids );
	}

	/** @return array<string, mixed> */
	private function delete_logs_request( WP_REST_Request $request ): array {
		if ( 'DELETE-LOGS' !== $this->plain( $request, 'confirmation' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
			throw new \InvalidArgumentException( 'عبارت تأیید حذف Logها معتبر نیست.' );
		}
		$ids = $request->get_param( 'ids' );
		return $this->log_management->delete( is_array( $ids ) ? $ids : array() );
	}

	/** @return array<string, mixed> */
	private function purge_logs_request( WP_REST_Request $request ): array {
		if ( 'PURGE-LOGS' !== $this->plain( $request, 'confirmation' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
			throw new \InvalidArgumentException( 'عبارت تأیید پاک‌سازی دوره‌ای Logها معتبر نیست.' );
		}
		return $this->log_management->purge_older_than( absint( $request->get_param( 'days' ) ?: 30 ) );
	}

	public function arm( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$scope = sanitize_key( (string) $request->get_param( 'scope' ) );
		$capability = match ( $scope ) {
			'reindex'        => Capabilities::EXECUTE_REINDEX,
			'table_drop'     => Capabilities::RUN_CLEANUP,
			'backup_restore' => Capabilities::RESTORE_BACKUP,
			default          => '',
		};
		if ( '' === $capability || ! current_user_can( $capability ) ) {
			return new WP_Error( 'shcd_tornado_dbm_forbidden', 'شما مجوز انجام این عملیات را ندارید.', array( 'status' => 403 ) );
		}

		try {
			$result   = $this->guard->issue( $scope );
			$response = rest_ensure_response( $result );
			$response->header( 'Cache-Control', 'no-store, private' );
			$response->header( 'Pragma', 'no-cache' );
			$response->header( 'Referrer-Policy', 'same-origin' );
			return $response;
		} catch ( \Throwable $throwable ) {
			return new WP_Error( 'shcd_tornado_dbm_authorization_issue_failed', 'صدور مجوز یک‌بارمصرف انجام نشد. صفحه را بازخوانی کنید و دوباره تلاش نمایید.', array( 'status' => 403 ) );
		}
	}

	private function route( string $path, string $methods, callable $callback, string $capability ): void {
		register_rest_route(
			self::NAMESPACE,
			$path,
			array(
				'methods'             => $methods,
				'callback'            => $callback,
				'permission_callback' => $this->permission( $capability ),
			)
		);
	}

	/** @return callable():bool */
	private function permission( string $capability ): callable {
		return static fn(): bool => current_user_can( $capability );
	}

	/** @param callable():array<string,mixed>|list<mixed> $callback */
	private function respond( callable $callback ): WP_REST_Response|WP_Error {
		try {
			return rest_ensure_response( $callback() );
		} catch ( \InvalidArgumentException $exception ) {
			return new WP_Error( 'shcd_tornado_dbm_invalid_request', $this->user_message( $exception->getMessage() ), array( 'status' => 400 ) );
		} catch ( \RuntimeException $exception ) {
			$data = array( 'status' => 409 );
			if ( current_user_can( Capabilities::VIEW_SENSITIVE_LOGS ) ) {
				$data['technical_message'] = sanitize_text_field( $exception->getMessage() );
			}
			return new WP_Error( 'shcd_tornado_dbm_operation_blocked', $this->user_message( $exception->getMessage() ), $data );
		} catch ( \Throwable $throwable ) {
			try {
				$this->logger->log(
					'error',
					'خطای پیش‌بینی‌نشده در REST API افزونه Tornado.',
					array(
						'exception' => get_class( $throwable ),
						'message'   => sanitize_text_field( $throwable->getMessage() ),
					)
				);
			} catch ( \Throwable ) {
				
			}

			$data = array( 'status' => 500 );
			if ( current_user_can( Capabilities::VIEW_SENSITIVE_LOGS ) ) {
				$data['technical_message'] = sanitize_text_field( $throwable->getMessage() );
			}
			return new WP_Error(
				'shcd_tornado_dbm_operation_failed',
				'خطایی پیش‌بینی‌نشده رخ داد و عملیات متوقف شد. جزئیات فنی برای مدیر در مرکز «عملیات، گزارش‌ها و خطاها» ثبت شده است.',
				$data
			);
		}
	}

	private function user_message( string $message ): string {
		global $wpdb;

		$posts_table = isset( $wpdb->posts ) ? (string) $wpdb->posts : 'posts';
		$message = trim( wp_strip_all_tags( $message ) );
		$translations = array(
			'Unable to read INFORMATION_SCHEMA.' => 'اطلاعات INFORMATION_SCHEMA خوانده نشد. سطح دسترسی کاربر دیتابیس را بررسی کنید.',
			'The database changed during Discovery. Retry while writes are paused.' => 'دیتابیس هنگام اجرای Discovery تغییر کرد. عملیات نوشتن را موقتاً متوقف و Discovery را دوباره اجرا کنید.',
			'Operation failed.' => 'اجرای عملیات با خطا متوقف شد.',
			'Unable to create operation job.' => 'Job عملیات در دیتابیس ثبت نشد. جدول Jobها و سطح دسترسی دیتابیس را بررسی کنید.',
			'Access denied.' => 'شما مجوز انجام این عملیات را ندارید.',
			'Reauthentication failed.' => 'صدور مجوز یک‌بارمصرف انجام نشد.',
			'An authenticated administrator is required.' => 'برای انجام این عملیات باید با حساب مدیر وارد شده باشید.',
									'A valid destructive-operation authorization token is required.' => 'مجوز یک‌بارمصرف عملیات مخرب معتبر نیست. دوباره «صدور مجوز» را اجرا کنید.',
			'The destructive-operation authorization has expired.' => 'اعتبار مجوز عملیات مخرب به پایان رسیده است. دوباره مجوز صادر کنید.',
			'The authorization token belongs to a different session.' => 'این مجوز متعلق به نشست دیگری است. صفحه را بازخوانی و دوباره مجوز صادر کنید.',
			'The destructive-operation authorization token is invalid.' => 'توکن مجوز عملیات مخرب معتبر نیست.',
			'Failed to persist the ID mapping.' => 'Mapping شناسه‌ها در دیتابیس ذخیره نشد.',
			'The database changed while Mapping was being built. Retry while writes are paused.' => 'دیتابیس هنگام ساخت Mapping تغییر کرد. ثبت محتوا را موقتاً متوقف و Wizard را دوباره اجرا کنید.',
			'Unable to create the backup file.' => 'فایل Backup ساخته نشد. فضای دیسک و سطح دسترسی مسیر Backup را بررسی کنید.',
			'Unable to start a consistent database snapshot.' => 'Snapshot سازگار از دیتابیس ایجاد نشد.',
			'Unable to commit the backup snapshot transaction.' => 'Transaction مربوط به Backup نهایی نشد.',
			'Backup verification failed.' => 'فایل Backup اعتبارسنجی نشد.',
			'Backup header verification failed.' => 'ساختار و Header فایل Backup معتبر نیست.',
			'Unable to register the verified backup.' => 'Backup تأییدشده در دیتابیس افزونه ثبت نشد.',
			'Unable to enable WordPress maintenance mode.' => 'Maintenance Mode وردپرس فعال نشد.',
			'Unable to request serializable transaction isolation.' => 'سطح ایزولیشن SERIALIZABLE برای Transaction تنظیم نشد.',
			'Unable to start the database transaction.' => 'Transaction دیتابیس آغاز نشد.',
			'Pre-commit integrity validation detected newly broken post relationships.' => 'کنترل Integrity پیش از COMMIT، رابطه‌های شکسته جدیدی پیدا کرد؛ تغییرات Rollback شدند.',
			'Database transaction commit failed.' => 'Transaction دیتابیس نهایی نشد؛ عملیات بدون COMMIT پایان یافت.',
			'wp_posts changed between Preflight and the locked transaction; rebuild Mapping and Backup.' => sprintf( 'جدول %s بین Preflight و شروع Transaction تغییر کرده است. Wizard را از ابتدا اجرا کنید.', $posts_table ),
			'The database schema changed between Preflight and the locked transaction; rebuild Discovery and Backup.' => 'ساختار دیتابیس بین Preflight و شروع Transaction تغییر کرده است. Wizard را از ابتدا اجرا کنید.',
			'Another cleanup operation is active.' => 'یک عملیات پاک‌سازی دیگر در حال اجرا است.',
			'A fresh cleanup Preview is required before deletion.' => 'پیش از حذف، باید Preview تازه‌ای از پاک‌سازی تهیه شود.',
			'The cleanup Preview authorization is invalid.' => 'مجوز Preview پاک‌سازی معتبر نیست. Preview را دوباره اجرا کنید.',
			'The selected cleanup types differ from the confirmed Preview.' => 'موارد انتخاب‌شده برای پاک‌سازی با Preview تأییدشده یکسان نیستند.',
			'Cleanup candidates changed after Preview; review and confirm the updated result.' => 'فهرست موارد پاک‌سازی پس از Preview تغییر کرده است. نتیجه جدید را بررسی و دوباره تأیید کنید.',
			'A fresh AUTO_INCREMENT preview is required.' => 'پیش از تنظیم AUTO_INCREMENT، باید Preview تازه‌ای اجرا شود.',
			'The AUTO_INCREMENT preview authorization is invalid.' => 'مجوز Preview مربوط به AUTO_INCREMENT معتبر نیست.',
			'The AUTO_INCREMENT scope differs from the confirmed preview.' => 'دامنه AUTO_INCREMENT با Preview تأییدشده یکسان نیست.',
			'AUTO_INCREMENT candidates changed after Preview. Review the updated state.' => 'وضعیت جدول‌های AUTO_INCREMENT پس از Preview تغییر کرده است. نتیجه تازه را بررسی کنید.',
		);
		if ( isset( $translations[ $message ] ) ) {
			return $translations[ $message ];
		}

		$message = preg_replace( '/^Reindex preflight is blocked:\\s*/i', 'بررسی پیش از اجرای Reindex متوقف شد: ', $message ) ?? $message;
		$message = str_replace(
			array(
				'Destructive reindexing is disabled. Enable it in the Identifiers and AUTO_INCREMENT settings, then save the form.',
				'The embedded-data scan was incomplete; use a registered adapter or reduce the dataset before reindexing.',
			),
			array(
				'عملیات مخرب Reindex آماده اجرا است نشده است. در بخش «شناسه‌ها و AUTO_INCREMENT» سوییچ مربوط را روشن و تنظیمات را ذخیره کنید.',
				'اسکن داده‌های Embedded کامل نشده است. Adapter مناسب را ثبت کنید یا Discovery را دوباره اجرا نمایید.',
			),
			$message
		);
		$message = preg_replace(
			'/([0-9]+) database columns contain mapped post IDs but remain semantically ambiguous\\./i',
			'$1 ستون دیتابیس شامل Post IDهای Mapping است، اما نوع رابطه آن‌ها هنوز با اطمینان کافی مشخص نشده است.',
			$message
		) ?? $message;

		$message = preg_replace(
			'/^Transaction integrity regressed for reference:\s*(.+)$/i',
			'بررسی Integrity داخل Transaction برای Reference «$1» نشان داد تعداد روابط شکسته نسبت به پیش از Reindex افزایش یافته است.',
			$message
		) ?? $message;

		if ( preg_match( '/[\x{0600}-\x{06FF}]/u', $message ) !== 1 ) {
			if ( stripos( $message, 'backup' ) !== false ) {
				return 'عملیات به‌دلیل اشکال در Backup متوقف شد. وضعیت فایل، Checksum و مسیر ذخیره‌سازی را بررسی کنید.';
			}
			if ( stripos( $message, 'mapping' ) !== false || stripos( $message, 'post id' ) !== false ) {
				return 'عملیات به‌دلیل ناسازگاری Mapping یا Post IDها متوقف شد. Discovery و Mapping را دوباره اجرا کنید.';
			}
			if ( stripos( $message, 'adapter' ) !== false || stripos( $message, 'reference' ) !== false ) {
				return 'یک Reference یا Adapter با وضعیت فعلی دیتابیس سازگار نیست. جزئیات را در گزارش Preflight بررسی کنید.';
			}
			if ( stripos( $message, 'database' ) !== false || stripos( $message, 'transaction' ) !== false ) {
				return 'عملیات به‌دلیل خطای دیتابیس یا Transaction متوقف شد. جزئیات فنی در لاگ امن افزونه ثبت شده است.';
			}
			return 'عملیات متوقف شد. جزئیات فنی در لاگ امن افزونه ثبت شده است.';
		}

		return sanitize_text_field( $message );
	}

	private function required_uuid( WP_REST_Request $request, string $key ): string {
		$value = sanitize_text_field( (string) $request->get_param( $key ) );
		if ( ! wp_is_uuid( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
			throw new \InvalidArgumentException( 'برای فیلد «' . $key . '» باید یک UUID معتبر وارد شود.' );
		}
		return $value;
	}

	private function plain( WP_REST_Request $request, string $key ): string {
		return sanitize_text_field( (string) $request->get_param( $key ) );
	}

	/** @return array<int,mixed> */
	private function builder_entries( WP_REST_Request $request ): array {
		$params  = $request->get_json_params();
		$entries = is_array( $params ) ? ( $params['entries'] ?? array() ) : array();
		return is_array( $entries ) ? array_values( $entries ) : array();
	}

	private function nullable_uuid( mixed $value ): ?string {
		$value = sanitize_text_field( (string) $value );
		return wp_is_uuid( $value ) ? $value : null;
	}

	/** @return list<int> */
	private function sanitize_int_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_unique( array_filter( array_map( 'absint', $value ) ) ) );
	}

	private function sanitize_string_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'sanitize_key', $value ) ) );
	}
}
