<?php
/**
 * Heuristic unused-table detection with reversible quarantine.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Cleanup;

use Shcd\TornadoDatabaseMaintenance\Backup\SqlBackupService;
use Shcd\TornadoDatabaseMaintenance\Database\Identifier;
use Shcd\TornadoDatabaseMaintenance\Jobs\JobRepository;
use Shcd\TornadoDatabaseMaintenance\Logging\Logger;
use Shcd\TornadoDatabaseMaintenance\Security\DestructiveActionGuard;
use RuntimeException;

final class UnusedTableService {
	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly SqlBackupService $backups,
		private readonly JobRepository $jobs,
		private readonly Logger $logger,
		private readonly DestructiveActionGuard $guard
	) {}

	/**
	 * @return array{tables:list<array<string,mixed>>,summary:array<string,int>}
	 */
	public function scan(): array {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME, ENGINE, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, CREATE_TIME, UPDATE_TIME
				 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s ORDER BY TABLE_NAME',
				(string) DB_NAME
			),
			ARRAY_A
		);

		$core_tables = method_exists( $wpdb, 'tables' ) ? $wpdb->tables( 'all', true ) : array();
		$core_tables = array_fill_keys( array_values( array_filter( array_map( 'strval', is_array( $core_tables ) ? $core_tables : array() ) ) ), true );
		$plugins     = $this->installed_plugins();
		$active      = $this->active_plugins();
		$tables      = array();
		$summary     = array(
			'protected'      => 0,
			'core'           => 0,
			'internal'       => 0,
			'active_owner'   => 0,
			'inactive_owner' => 0,
			'unknown'        => 0,
			'quarantined'    => 0,
		);

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$table_name = (string) $row['TABLE_NAME'];
			$owner      = $this->detect_owner( $table_name, $plugins, $active, $core_tables );
			$status     = $owner['status'];
			++$summary[ $status ];

			$tables[] = array(
				'table_name'    => $table_name,
				'engine'        => (string) ( $row['ENGINE'] ?? '' ),
				'rows_estimate' => (int) ( $row['TABLE_ROWS'] ?? 0 ),
				'size_bytes'    => (int) ( $row['DATA_LENGTH'] ?? 0 ) + (int) ( $row['INDEX_LENGTH'] ?? 0 ),
				'created_at'    => $row['CREATE_TIME'] ?? null,
				'updated_at'    => $row['UPDATE_TIME'] ?? null,
				'status'        => $status,
				'owner'         => $owner['owner'],
				'confidence'    => $owner['confidence'],
				'action'        => in_array( $status, array( 'inactive_owner', 'unknown' ), true ) ? 'quarantine_only' : 'protected',
			);
		}

		return array( 'tables' => $tables, 'summary' => $summary );
	}


	/**
	 * @return array<string, mixed>
	 */
	public function preflight( string $table_name, string $backup_uuid ): array {
		$wpdb = $this->wpdb;
		$record = $this->find_scan_record( $table_name );
		$backup       = $this->backups->find_verified( $backup_uuid );
		$backup_valid = null !== $backup;
		$status = (string) ( $record['status'] ?? 'missing' );
		$quarantinable = in_array( $status, array( 'inactive_owner', 'unknown' ), true );
		$purgeable = str_starts_with( $table_name, $wpdb->prefix . 'shcd_tornado_dbm_quarantine_' );

		return array(
			'table'                          => $record,
			'backup_valid'                   => $backup_valid,
			'quarantine_allowed'              => $backup_valid && $quarantinable,
			'quarantine_confirmation_phrase' => $this->confirmation_phrase( $table_name, $backup_uuid ),
			'purge_allowed'                   => $backup_valid && $purgeable && ! $this->emergency_stop() && ! empty( $backup['is_consistent'] ) && ! empty( $backup['is_private_location'] ),
			'purge_confirmation_phrase'      => $this->confirmation_phrase( $table_name, $backup_uuid, 'DROP' ),
			'requires_reauthentication'       => false,
			'requires_one_time_token'        => true,
			'emergency_stop'                  => $this->emergency_stop(),
		);
	}

	public function confirmation_phrase( string $table_name, string $backup_uuid, string $operation = 'QUARANTINE' ): string {
		return strtoupper( $operation ) . '-' . strtoupper( substr( hash( 'sha256', $table_name . ':' . $backup_uuid ), 0, 12 ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function quarantine( string $table_name, string $backup_uuid, string $confirmation ): array {
		$wpdb = $this->wpdb;
		if ( $this->emergency_stop() ) {
			throw new RuntimeException( 'Destructive operations are disabled by the emergency stop.' );
		}
		$backup = $this->backups->find_verified( $backup_uuid );
		if ( null === $backup ) {
			throw new RuntimeException( 'A checksum-valid backup is required before table quarantine.' );
		}

		$record = $this->find_scan_record( $table_name );
		if ( null === $record || ! in_array( $record['status'], array( 'inactive_owner', 'unknown' ), true ) ) {
			throw new RuntimeException( 'Only unknown or inactive-owner tables may be quarantined.' );
		}

		$required = $this->confirmation_phrase( $table_name, $backup_uuid );
		if ( ! hash_equals( $required, trim( $confirmation ) ) ) {
			throw new RuntimeException( 'Invalid quarantine confirmation phrase.' );
		}

		$source             = Identifier::quote( $table_name );
		$tornado_sql_source = Identifier::normalize( $source );
		$target_name = $this->quarantine_name( $table_name );
		$target             = Identifier::quote( $target_name );
		$tornado_sql_target = Identifier::normalize( $target );
		$job_uuid = $this->jobs->create(
			'table_quarantine',
			array( 'table_name' => $table_name, 'backup_uuid' => $backup_uuid )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		if ( false === $wpdb->query(
			 $wpdb->prepare( "RENAME TABLE %i TO %i", $tornado_sql_source, $tornado_sql_target ) 
		) ) { 
			$this->jobs->fail( $job_uuid, $wpdb->last_error, 'quarantine_failed' );
			throw new RuntimeException( 'Unable to quarantine the table.' );
		}

		$result = array(
			'uuid'          => $job_uuid,
			'original_name' => $table_name,
			'quarantine_name' => $target_name,
			'backup_uuid'   => $backup_uuid,
			'reversible'    => true,
			'restore_confirmation_phrase' => 'RESTORE-TABLE-' . strtoupper( substr( str_replace( '-', '', $job_uuid ), 0, 12 ) ),
		);
		$this->jobs->complete( $job_uuid, $result );
		$this->logger->log( 'warning', 'Database table moved to reversible quarantine.', $result, $job_uuid );
		return $result;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function restore( string $quarantine_job_uuid, string $confirmation ): array {
		$wpdb = $this->wpdb;
		$job = $this->jobs->find( $quarantine_job_uuid );
		if ( null === $job || 'table_quarantine' !== $job['type'] || 'completed' !== $job['status'] ) {
			throw new RuntimeException( 'Valid quarantine job not found.' );
		}

		$original   = (string) ( $job['result']['original_name'] ?? '' );
		$quarantine = (string) ( $job['result']['quarantine_name'] ?? '' );
		$required   = 'RESTORE-TABLE-' . strtoupper( substr( str_replace( '-', '', $quarantine_job_uuid ), 0, 12 ) );
		if ( ! hash_equals( $required, trim( $confirmation ) ) ) {
			throw new RuntimeException( 'Invalid table restore confirmation phrase.' );
		}

		if ( ! str_starts_with( $quarantine, $wpdb->prefix . 'shcd_tornado_dbm_quarantine_' ) ) {
			throw new RuntimeException( 'The recorded quarantine table name is invalid.' );
		}
		if ( $this->table_exists( $original ) ) {
			throw new RuntimeException( 'The original table name already exists.' );
		}
		if ( ! $this->table_exists( $quarantine ) ) {
			throw new RuntimeException( 'The quarantine table no longer exists.' );
		}

		$source             = Identifier::quote( $quarantine );
		$tornado_sql_source = Identifier::normalize( $source );
		$target             = Identifier::quote( $original );
		$tornado_sql_target = Identifier::normalize( $target );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		if ( false === $wpdb->query(
			 $wpdb->prepare( "RENAME TABLE %i TO %i", $tornado_sql_source, $tornado_sql_target ) 
		) ) { 
			throw new RuntimeException( 'Unable to restore the quarantined table.' );
		}

		$result = array( 'restored' => true, 'table_name' => $original, 'source' => $quarantine );
		$this->logger->log( 'warning', 'Quarantined database table restored.', $result, $quarantine_job_uuid );
		return $result;
	}

	/**
	 * Permanently drops only a table previously moved into the plugin quarantine namespace.
	 *
	 * @return array<string, mixed>
	 */
	public function purge( string $quarantine_name, string $backup_uuid, string $confirmation, string $authorization_token ): array {
		$wpdb = $this->wpdb;
		if ( $this->emergency_stop() ) {
			throw new RuntimeException( 'Destructive operations are disabled by the emergency stop.' );
		}
		$this->guard->consume( 'table_drop', $authorization_token );
		if ( ! str_starts_with( $quarantine_name, $wpdb->prefix . 'shcd_tornado_dbm_quarantine_' ) ) {
			throw new RuntimeException( 'Only plugin-quarantined tables may be permanently dropped.' );
		}
		$backup = $this->backups->find_verified( $backup_uuid );
		if ( null === $backup || empty( $backup['is_consistent'] ) || empty( $backup['is_private_location'] ) ) {
			throw new RuntimeException( 'A private, transaction-consistent, checksum-valid backup is required before permanent deletion.' );
		}

		$required = $this->confirmation_phrase( $quarantine_name, $backup_uuid, 'DROP' );
		if ( ! hash_equals( $required, trim( $confirmation ) ) ) {
			throw new RuntimeException( 'Invalid permanent-drop confirmation phrase.' );
		}

		$table = Identifier::quote( $quarantine_name );
		$tornado_sql_table = Identifier::normalize( $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		if ( false === $wpdb->query(
			 // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema mutation is an explicit, capability-gated maintenance operation and is never run as a cached read.
			 $wpdb->prepare( "DROP TABLE %i", $tornado_sql_table ) 
		) ) { 
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema mutation is an explicit, capability-gated maintenance operation and is never run as a cached read.
			throw new RuntimeException( 'Unable to permanently drop the quarantined table.' );
		}

		$result = array( 'dropped' => true, 'table_name' => $quarantine_name );
		$this->logger->log( 'critical', 'Quarantined database table permanently dropped.', $result );
		return $result;
	}


	private function emergency_stop(): bool {
		return defined( 'SHCD_TORNADO_DBM_DISABLE_DESTRUCTIVE_OPERATIONS' ) && true === SHCD_TORNADO_DBM_DISABLE_DESTRUCTIVE_OPERATIONS;
	}

	/**
	 * @param array<string, string> $plugins Installed plugin path => label.
	 * @param array<string, bool>   $active Active plugin paths.
	 * @param array<string, bool>   $core Core table names.
	 * @return array{status:string,owner:string,confidence:int}
	 */
	private function detect_owner( string $table_name, array $plugins, array $active, array $core ): array {
		$wpdb = $this->wpdb;
		if ( isset( $core[ $table_name ] ) ) {
			return array( 'status' => 'core', 'owner' => 'WordPress Core', 'confidence' => 100 );
		}
		if ( ! str_starts_with( $table_name, $wpdb->prefix ) ) {
			return array( 'status' => 'protected', 'owner' => 'Foreign prefix or another site', 'confidence' => 100 );
		}
		$suffix = substr( $table_name, strlen( $wpdb->prefix ) );
		if ( str_starts_with( $suffix, 'shcd_tornado_dbm_' ) && ! str_starts_with( $suffix, 'shcd_tornado_dbm_quarantine_' ) ) {
			return array( 'status' => 'internal', 'owner' => 'Tornado', 'confidence' => 100 );
		}
		if ( str_starts_with( $suffix, 'shcd_tornado_dbm_quarantine_' ) ) {
			return array( 'status' => 'quarantined', 'owner' => 'Tornado quarantine', 'confidence' => 100 );
		}

		$known = array(
			'woocommerce_' => 'woocommerce/woocommerce.php',
			'wc_'          => 'woocommerce/woocommerce.php',
			'actionscheduler_' => 'woocommerce/woocommerce.php',
			'elementor_'   => 'elementor/elementor.php',
			'rank_math_'   => 'seo-by-rank-math/rank-math.php',
			'icl_'         => 'sitepress-multilingual-cms/sitepress.php',
			'dokan_'       => 'dokan-lite/dokan.php',
			'yoast_'       => 'wordpress-seo/wp-seo.php',
			'litespeed_'   => 'litespeed-cache/litespeed-cache.php',
			'redirection_' => 'redirection/redirection.php',
		);
		foreach ( $known as $prefix => $plugin_path ) {
			if ( str_starts_with( $suffix, $prefix ) ) {
				return array(
					'status'     => isset( $active[ $plugin_path ] ) ? 'active_owner' : ( isset( $plugins[ $plugin_path ] ) ? 'inactive_owner' : 'unknown' ),
					'owner'      => $plugins[ $plugin_path ] ?? dirname( $plugin_path ),
					'confidence' => 90,
				);
			}
		}

		foreach ( $plugins as $plugin_path => $label ) {
			$slug  = dirname( $plugin_path );
			$token = str_replace( '-', '_', sanitize_key( $slug ) );
			if ( '.' !== $slug && strlen( $token ) >= 4 && ( str_starts_with( $suffix, $token . '_' ) || str_contains( $suffix, '_' . $token . '_' ) ) ) {
				return array(
					'status'     => isset( $active[ $plugin_path ] ) ? 'active_owner' : 'inactive_owner',
					'owner'      => $label,
					'confidence' => 65,
				);
			}
		}

		return array( 'status' => 'unknown', 'owner' => 'Unknown', 'confidence' => 0 );
	}

	/**
	 * @return array<string, string>
	 */
	private function installed_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$raw = get_plugins();
		$result = array();
		foreach ( is_array( $raw ) ? $raw : array() as $path => $headers ) {
			$result[ (string) $path ] = (string) ( $headers['Name'] ?? $path );
		}
		return $result;
	}

	/**
	 * @return array<string, bool>
	 */
	private function active_plugins(): array {
		$active = array_fill_keys( array_map( 'strval', (array) get_option( 'active_plugins', array() ) ), true );
		if ( is_multisite() ) {
			foreach ( array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) as $plugin ) {
				$active[ (string) $plugin ] = true;
			}
		}
		return $active;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function find_scan_record( string $table_name ): ?array {
		foreach ( $this->scan()['tables'] as $record ) {
			if ( hash_equals( (string) $record['table_name'], $table_name ) ) {
				return $record;
			}
		}
		return null;
	}

	private function quarantine_name( string $original ): string {
		$wpdb = $this->wpdb;
		$prefix = $wpdb->prefix . 'shcd_tornado_dbm_quarantine_';
		$suffix = gmdate( 'YmdHis' ) . '_' . substr( hash( 'sha256', $original . wp_generate_uuid4() ), 0, 10 );
		$name   = $prefix . $suffix;
		return substr( $name, 0, 64 );
	}

	private function table_exists( string $table_name ): bool {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;
	}
}
