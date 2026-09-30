<?php
/**
 * Retention cleanup for plugin-owned operational data and backup files.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Core;

use Shcd\TornadoDatabaseMaintenance\Database\Identifier;
use Shcd\TornadoDatabaseMaintenance\Database\Tables;
use Shcd\TornadoDatabaseMaintenance\Reindex\GenerationRepository;

final class RetentionService {
	public function __construct( private readonly \wpdb $wpdb, private readonly Tables $tables, private readonly GenerationRepository $generations ) {}

	/** @return array<string,int> */
	public function run(): array {
		$wpdb = $this->wpdb;
		$job_days     = (int) Settings::get( 'job_retention_days', 30 );
		$log_days     = (int) Settings::get( 'log_retention_days', 30 );
		$backup_days  = (int) Settings::get( 'backup_retention_days', 14 );
		$deleted      = array( 'logs' => 0, 'jobs' => 0, 'mappings' => 0, 'discoveries' => 0, 'backups' => 0 );
		$logs         = Identifier::quote( $this->tables->logs() );
		$tornado_sql_logs = Identifier::normalize( $logs );
		$jobs         = Identifier::quote( $this->tables->jobs() );
		$tornado_sql_jobs = Identifier::normalize( $jobs );
		$mappings     = Identifier::quote( $this->tables->mappings() );
		$tornado_sql_mappings = Identifier::normalize( $mappings );
		$discoveries  = Identifier::quote( $this->tables->discoveries() );
		$tornado_sql_discoveries = Identifier::normalize( $discoveries );
		$backups      = Identifier::quote( $this->tables->backups() );
		$tornado_sql_backups = Identifier::normalize( $backups );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$deleted['logs'] = max( 0, (int) $wpdb->query( $wpdb->prepare(
			"DELETE FROM %i WHERE created_at < %s LIMIT 5000",
			$tornado_sql_logs,
			gmdate( 'Y-m-d H:i:s', time() - ( $log_days * DAY_IN_SECONDS ) )
		) ) ); 

		$protected_jobs = array_filter(
			array_merge(
				array(
					(string) get_option( 'shcd_tornado_dbm_last_discovery_uuid', '' ),
					(string) get_option( 'shcd_tornado_dbm_last_mapping_uuid', '' ),
					(string) get_option( 'shcd_tornado_dbm_last_executed_mapping_uuid', '' ),
				),
				array_merge( $this->generations->protected_mapping_uuids(), $this->generations->protected_job_uuids() )
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$old_jobs = $wpdb->get_col( $wpdb->prepare(
			"SELECT uuid FROM %i WHERE updated_at < %s AND status IN ('completed','failed','cancelled') LIMIT 1000",
			$tornado_sql_jobs,
			gmdate( 'Y-m-d H:i:s', time() - ( $job_days * DAY_IN_SECONDS ) )
		) ); 
		foreach ( is_array( $old_jobs ) ? $old_jobs : array() as $uuid ) {
			if ( in_array( (string) $uuid, $protected_jobs, true ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$deleted['mappings']    += max( 0, (int) $wpdb->query( $wpdb->prepare(
				"DELETE FROM %i WHERE job_uuid = %s",
				$tornado_sql_mappings,
				$uuid
			) ) ); 
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$deleted['discoveries'] += max( 0, (int) $wpdb->query( $wpdb->prepare(
				"DELETE FROM %i WHERE job_uuid = %s",
				$tornado_sql_discoveries,
				$uuid
			) ) ); 
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$deleted['jobs']        += max( 0, (int) $wpdb->query( $wpdb->prepare(
				"DELETE FROM %i WHERE uuid = %s",
				$tornado_sql_jobs,
				$uuid
			) ) ); 
		}

		$last_backup = (string) get_option( 'shcd_tornado_dbm_last_backup_uuid', '' );
		$protected_backups = array_values( array_unique( array_filter( array_merge(
			array( $last_backup, (string) get_option( 'shcd_tornado_dbm_last_prepare_backup_uuid', '' ) ),
			$this->generations->protected_backup_uuids()
		) ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT uuid, file_path FROM %i WHERE created_at < %s LIMIT 100",
			$tornado_sql_backups,
			gmdate( 'Y-m-d H:i:s', time() - ( $backup_days * DAY_IN_SECONDS ) )
		), ARRAY_A ); 
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( in_array( (string) ( $row['uuid'] ?? '' ), $protected_backups, true ) ) {
				continue;
			}
			$path = wp_normalize_path( (string) ( $row['file_path'] ?? '' ) );
			if ( is_file( $path ) ) {
				if ( is_link( $path ) || ! $this->safe_backup_path( $path ) || ! wp_delete_file( $path ) ) {
					continue;
				}
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$deleted['backups'] += max( 0, (int) $wpdb->query( $wpdb->prepare(
				"DELETE FROM %i WHERE uuid = %s",
				$tornado_sql_backups,
				(string) $row['uuid']
			) ) ); 
		}

		return $deleted;
	}

	private function safe_backup_path( string $path ): bool {
		if ( '' === $path || 1 !== preg_match( '/\/(?:shcd-tornado-dbm|tornado)-[A-Za-z0-9-]+\.sql$/', $path ) ) {
			return false;
		}

		$roots = array();
		$token = strtolower( (string) get_option( 'shcd_tornado_dbm_backup_storage_token', '' ) );
		if ( 1 === preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			$upload = wp_upload_dir( null, false );
			if ( empty( $upload['error'] ) && ! empty( $upload['basedir'] ) ) {
				$roots[] = trailingslashit( wp_normalize_path( (string) $upload['basedir'] ) )
					. 'shcd-tornado-dbm-private-backups-'
					. $token;
			}
		}
		if ( defined( 'SHCD_TORNADO_DBM_BACKUP_DIR' ) && is_string( SHCD_TORNADO_DBM_BACKUP_DIR ) && '' !== trim( SHCD_TORNADO_DBM_BACKUP_DIR ) ) {
			$roots[] = SHCD_TORNADO_DBM_BACKUP_DIR;
		}

		foreach ( $roots as $root ) {
			$root = trailingslashit( wp_normalize_path( (string) $root ) );
			if ( str_starts_with( $path, $root ) ) {
				return true;
			}
		}
		return false;
	}
}
