<?php
/**
 * Streaming logical SQL backup service with snapshot and checksum verification.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Backup;

use Shcd\TornadoDatabaseMaintenance\Database\AutoIncrementService;
use Shcd\TornadoDatabaseMaintenance\Database\FingerprintService;
use Shcd\TornadoDatabaseMaintenance\Database\Identifier;
use Shcd\TornadoDatabaseMaintenance\Database\Tables;
use Shcd\TornadoDatabaseMaintenance\Jobs\JobRepository;
use Shcd\TornadoDatabaseMaintenance\Logging\Logger;
use Shcd\TornadoDatabaseMaintenance\Core\Filesystem;
use Shcd\TornadoDatabaseMaintenance\Reindex\GenerationRepository;
use RuntimeException;

final class SqlBackupService {
	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly Tables $tables,
		private readonly FingerprintService $fingerprints,
		private readonly JobRepository $jobs,
		private readonly Logger $logger,
		private readonly AutoIncrementService $auto_increment,
		private readonly GenerationRepository $generations
	) {}

	/**
	 * @return array<string, mixed>
	 */
	public function create(): array {
		$wpdb = $this->wpdb;
		$job_uuid = $this->jobs->create( 'backup' );
		$uuid     = wp_generate_uuid4();
		$location = $this->backup_directory();
		$dir      = $location['path'];
		$file     = trailingslashit( $dir ) . 'shcd-tornado-dbm-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 20, false, false ) . '.sql';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming avoids buffering a potentially multi-gigabyte SQL backup in PHP memory.
		$handle   = fopen( $file, 'xb' );
		$in_transaction = false;

		if ( false === $handle ) {
			$this->jobs->fail( $job_uuid, 'Unable to create the backup file.', 'backup_failed' );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
			throw new RuntimeException( 'Unable to create the backup file.' );
		}
		Filesystem::chmod( $file, 0600 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$table_rows = $wpdb->get_results( 'SHOW FULL TABLES', ARRAY_N );
		$table_rows = is_array( $table_rows ) ? $table_rows : array();
		usort(
			$table_rows,
			static fn( array $left, array $right ): int => ( strtoupper( (string) ( $left[1] ?? '' ) ) === 'VIEW' ? 1 : 0 ) <=> ( strtoupper( (string) ( $right[1] ?? '' ) ) === 'VIEW' ? 1 : 0 )
		);

		$transactional = $this->all_base_tables_transactional( $table_rows );
		$deterministic = $this->all_base_tables_have_primary_key( $table_rows );
		$consistent    = $transactional;
		$backup_db    = Identifier::quote( (string) DB_NAME );
		$table_num    = 0;
		$trigger_num  = 0;
		$post_hash    = '';
		$schema_hash  = '';

		try {
			if ( $transactional ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
				$wpdb->query( 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
				if ( false === $wpdb->query( 'START TRANSACTION WITH CONSISTENT SNAPSHOT' ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
					throw new RuntimeException( 'Unable to start a consistent database snapshot.' );
				}
				$in_transaction = true;
			}

			$post_hash   = $this->fingerprints->post_ids();
			$schema_hash = $this->fingerprints->schema();

			$this->write( $handle, "-- Tornado verified logical backup\n" );
			$this->write( $handle, '-- Created at UTC: ' . gmdate( 'c' ) . "\n" );
			$this->write( $handle, '-- Transaction snapshot: ' . ( $transactional ? 'repeatable-read' : 'best-effort-mixed-engine' ) . "\n" );
			$this->write( $handle, '-- Deterministic pagination: ' . ( $deterministic ? 'primary-key-ordered' : 'not-guaranteed' ) . "\n" );
			$this->write( $handle, '-- Post ID fingerprint: ' . $post_hash . "\n" );
			$this->write( $handle, '-- Schema fingerprint: ' . $schema_hash . "\n" );
			$this->write( $handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nUSE {$backup_db};\n\n" );

			foreach ( $table_rows as $row ) {
				$table_name = (string) ( $row[0] ?? '' );
				$table_type = strtoupper( (string) ( $row[1] ?? 'BASE TABLE' ) );
				if ( '' === $table_name ) {
					continue;
				}

				$table_name = Identifier::normalize( $table_name );
				$table      = Identifier::quote( $table_name );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
				$show  = $wpdb->get_row(
					 // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema mutation is an explicit, capability-gated maintenance operation and is never run as a cached read.
					 $wpdb->prepare( 'SHOW CREATE TABLE %i', $table_name ) ,
					ARRAY_N
				);
				if ( ! is_array( $show ) || empty( $show[1] ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
					throw new RuntimeException( 'Unable to export schema for table: ' . $table_name );
				}

				$this->write( $handle, "-- Object: {$table_name}\n" );
				$this->write( $handle, ( 'VIEW' === $table_type ? "DROP VIEW IF EXISTS {$table};\n" : "DROP TABLE IF EXISTS {$table};\n" ) );
				$this->write( $handle, (string) $show[1] . ";\n\n" );

				if ( 'VIEW' !== $table_type ) {
					$this->export_rows( $handle, $table_name );
				}

				++$table_num;
				$this->jobs->progress(
					$job_uuid,
					'exporting_tables',
					count( $table_rows ) > 0 ? ( $table_num / count( $table_rows ) ) * 90 : 90,
					array( 'current_table' => $table_name, 'exported_tables' => $table_num )
				);
			}

			$trigger_num = $this->export_triggers( $handle );
			$this->write( $handle, "SET FOREIGN_KEY_CHECKS=1;\n" );

			if ( $in_transaction ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
				if ( false === $wpdb->query( 'COMMIT' ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
					throw new RuntimeException( 'Unable to commit the backup snapshot transaction.' );
				}
				$in_transaction = false;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes a streaming backup handle.
			fclose( $handle );
			$handle = null;

			$size   = filesize( $file );
			$sha256 = hash_file( 'sha256', $file );
			if ( false === $size || false === $sha256 || $size < 128 ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
				throw new RuntimeException( 'Backup verification failed.' );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming file access keeps large SQL backups out of PHP memory.
			$verify_handle = fopen( $file, 'rb' );
			$first_line    = is_resource( $verify_handle ) ? fgets( $verify_handle ) : false;
			if ( is_resource( $verify_handle ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes a streaming backup handle.
				fclose( $verify_handle );
			}
			if ( ! is_string( $first_line ) || ! str_contains( $first_line, 'Tornado' ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
				throw new RuntimeException( 'Backup header verification failed.' );
			}

			$backup_table = Identifier::normalize( $this->tables->backups() );
			$now          = current_time( 'mysql', true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$inserted = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO %i (uuid, status, file_path, file_size, sha256, table_count, created_by, created_at, verified_at, post_id_fingerprint, schema_fingerprint, is_consistent, is_private_location)
					 VALUES (%s, 'verified', %s, %d, %s, %d, %d, %s, %s, %s, %s, %d, %d)",
					$backup_table,
					$uuid,
					$file,
					$size,
					$sha256,
					$table_num,
					get_current_user_id(),
					$now,
					$now,
					$post_hash,
					$schema_hash,
					$consistent ? 1 : 0,
					$location['private'] ? 1 : 0
				)
			);
			if ( false === $inserted ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
				throw new RuntimeException( 'Unable to register the verified backup.' );
			}

			$result = array(
				'uuid'                => $uuid,
				'job_uuid'            => $job_uuid,
				'status'              => 'verified',
				'file_size'           => $size,
				'sha256'              => $sha256,
				'table_count'         => $table_num,
				'trigger_count'       => $trigger_num,
				'created_at'          => $now,
				'consistent_snapshot' => $consistent,
				'transactional_snapshot' => $transactional,
				'deterministic_export'   => $deterministic,
				'private_location'    => $location['private'],
				'post_id_fingerprint' => $post_hash,
				'schema_fingerprint'  => $schema_hash,
				'warnings'            => array_values( array_filter( array(
					$transactional ? null : 'One or more base tables are non-transactional; this backup is not accepted for Reindex.',
					$deterministic ? null : 'One or more base tables do not have a primary key; the snapshot is transaction-consistent, but export ordering for those tables is best-effort.',
					$location['private'] ? null : 'Backup storage is under a web-accessible root; server deny rules were written, but this backup is not accepted for Reindex.',
				) ) ),
			);

			update_option( 'shcd_tornado_dbm_last_backup_uuid', $uuid, false );
			$this->jobs->complete( $job_uuid, $result );
			$this->logger->log( 'info', 'Verified database backup created.', $result, $job_uuid );
			return $result;
		} catch ( \Throwable $throwable ) {
			if ( $in_transaction ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
				$wpdb->query( 'ROLLBACK' );
			}
			if ( is_resource( $handle ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the streaming backup handle created above.
				fclose( $handle );
			}
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
			$this->jobs->fail( $job_uuid, $throwable->getMessage(), 'backup_failed' );
			$this->logger->log( 'error', 'Database backup failed.', array( 'error' => $throwable->getMessage() ), $job_uuid );
			throw $throwable;
		}
	}


	/**
	 * Returns registered backups without exposing their absolute filesystem path.
	 *
	 * @return array<string, mixed>
	 */
	public function list_backups( int $limit = 100, int $offset = 0 ): array {
		$wpdb = $this->wpdb;
		$limit  = max( 1, min( 500, $limit ) );
		$offset = max( 0, $offset );
		$table  = Identifier::normalize( $this->tables->backups() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$total  = (int) $wpdb->get_var(
			 $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, uuid, status, file_path, file_size, sha256, table_count, created_by, created_at, verified_at, is_consistent, is_private_location FROM %i ORDER BY id DESC LIMIT %d OFFSET %d",
				$table,
				$limit,
				$offset
			),
			ARRAY_A
		);

		$items = array();
		$protected_backups = array_values( array_unique( array_filter( array_merge(
			$this->generations->protected_backup_uuids(),
			array( (string) get_option( 'shcd_tornado_dbm_last_prepare_backup_uuid', '' ) )
		) ) ) );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$path = (string) ( $row['file_path'] ?? '' );
			$items[] = array(
				'id'                  => (int) ( $row['id'] ?? 0 ),
				'uuid'                => (string) ( $row['uuid'] ?? '' ),
				'status'              => (string) ( $row['status'] ?? '' ),
				'file_name'           => basename( $path ),
				'file_exists'         => '' !== $path && is_file( $path ) && ! is_link( $path ),
				'file_size'           => (int) ( $row['file_size'] ?? 0 ),
				'sha256'              => (string) ( $row['sha256'] ?? '' ),
				'table_count'         => (int) ( $row['table_count'] ?? 0 ),
				'created_by'          => (int) ( $row['created_by'] ?? 0 ),
				'created_at'          => (string) ( $row['created_at'] ?? '' ),
				'verified_at'         => (string) ( $row['verified_at'] ?? '' ),
				'is_consistent'       => ! empty( $row['is_consistent'] ),
				'is_private_location' => ! empty( $row['is_private_location'] ),
				'protected_by_generation' => in_array( (string) ( $row['uuid'] ?? '' ), $protected_backups, true ),
			);
		}

		return array(
			'items'  => $items,
			'total'  => $total,
			'limit'  => $limit,
			'offset' => $offset,
		);
	}

	/**
	 * Deletes selected backup files and their registered database rows.
	 *
	 * @param list<string> $uuids Backup UUIDs.
	 * @return array<string, mixed>
	 */
	public function delete_backups( array $uuids ): array {
		$wpdb = $this->wpdb;
		$uuids = array_values(
			array_unique(
				array_filter(
					array_map( 'sanitize_text_field', $uuids ),
					static fn( string $uuid ): bool => wp_is_uuid( $uuid )
				)
			)
		);
		if ( empty( $uuids ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
			throw new \InvalidArgumentException( 'برای حذف، هیچ Backup معتبری انتخاب نشده است.' );
		}
		if ( count( $uuids ) > 100 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
			throw new \InvalidArgumentException( 'در هر درخواست حداکثر ۱۰۰ Backup قابل حذف است.' );
		}

		$protected_backups = array_values( array_unique( array_filter( array_merge(
			$this->generations->protected_backup_uuids(),
			array( (string) get_option( 'shcd_tornado_dbm_last_prepare_backup_uuid', '' ) )
		) ) ) );
		$protected = array_values( array_intersect( $uuids, $protected_backups ) );
		if ( ! empty( $protected ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
			throw new RuntimeException( 'این Backup به اجرای فعال یا یکی از نسل‌های اخیر Reindex وابسته است و تا پایان چرخه ایمنی یا ثبت نسل‌های جدیدتر نباید حذف شود.' );
		}

		$table        = Identifier::normalize( $this->tables->backups() );
		$placeholders = implode( ',', array_fill( 0, count( $uuids ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$rows          = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Generated SQL fragment is restricted to validated identifiers, fixed clauses, or a placeholder list; all external data values are passed to prepare().
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only validated identifiers, fixed internal clauses, or generated placeholder lists are interpolated; external values are passed to prepare().
				"SELECT id, uuid, file_path FROM %i WHERE uuid IN ({$placeholders}) ORDER BY id ASC",
				array_merge( array( $table ), $uuids )
			),
			ARRAY_A
		);
		if ( empty( $rows ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
			throw new RuntimeException( 'Backupهای انتخاب‌شده دیگر در دیتابیس هنوز ثبت نشده است‌اند.' );
		}

		$deleted_uuids = array();
		$deleted_ids   = array();
		$failed        = array();
		foreach ( $rows as $row ) {
			$uuid = (string) ( $row['uuid'] ?? '' );
			$path = (string) ( $row['file_path'] ?? '' );
			if ( '' !== $path && is_file( $path ) ) {
				if ( is_link( $path ) || ! $this->is_registered_backup_path( $path ) ) {
					$failed[] = array( 'uuid' => $uuid, 'message' => 'مسیر فایل Backup با قواعد حذف ایمن مطابقت ندارد.' );
					continue;
				}
				if ( ! wp_delete_file( $path ) ) {
					$failed[] = array( 'uuid' => $uuid, 'message' => 'فایل Backup از فضای ذخیره‌سازی حذف نشد.' );
					continue;
				}
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$removed = $wpdb->delete( $this->tables->backups(), array( 'uuid' => $uuid ), array( '%s' ) );
			if ( false === $removed ) {
				$failed[] = array( 'uuid' => $uuid, 'message' => 'رکورد Backup از دیتابیس حذف نشد.' );
				continue;
			}
			$deleted_uuids[] = $uuid;
			$deleted_ids[]   = (int) ( $row['id'] ?? 0 );
		}

		if ( empty( $deleted_ids ) && ! empty( $failed ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
			throw new RuntimeException( 'هیچ‌کدام از Backupهای انتخاب‌شده حذف نشد.' );
		}

		$rewind = empty( $deleted_ids )
			? null
			: $this->auto_increment->rewind_after_delete( $this->tables->backups(), $deleted_ids, 'backup_delete' );
		$this->refresh_last_backup_option();

		$result = array(
			'deleted_count' => count( $deleted_uuids ),
			'deleted_uuids' => $deleted_uuids,
			'deleted_ids'   => $deleted_ids,
			'failed'        => $failed,
			'auto_increment'=> $rewind,
		);
		$this->logger->log( empty( $failed ) ? 'warning' : 'error', 'Backup management deletion completed.', $result );
		return $result;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find_verified( string $uuid ): ?array {
		$wpdb = $this->wpdb;
		if ( ! wp_is_uuid( $uuid ) ) {
			return null;
		}

		$table = Identifier::normalize( $this->tables->backups() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE uuid = %s AND status = 'verified' LIMIT 1",
				$table,
				$uuid
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) || ! is_file( (string) $row['file_path'] ) || is_link( (string) $row['file_path'] ) ) {
			return null;
		}

		$current = hash_file( 'sha256', (string) $row['file_path'] );
		if ( ! is_string( $current ) || ! hash_equals( (string) $row['sha256'], $current ) ) {
			return null;
		}

		$row['is_consistent']       = (bool) ( $row['is_consistent'] ?? false );
		$row['is_private_location'] = (bool) ( $row['is_private_location'] ?? false );





		if ( ! $row['is_consistent'] ) {
			$header = file_get_contents( (string) $row['file_path'], false, null, 0, 4096 );
			if ( is_string( $header ) && str_contains( $header, 'Transaction snapshot: repeatable-read' ) ) {
				$row['is_consistent'] = true;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
				$wpdb->update(
					$this->tables->backups(),
					array( 'is_consistent' => 1 ),
					array( 'uuid' => $uuid ),
					array( '%d' ),
					array( '%s' )
				);
			}
		}

		return $row;
	}

	/** @param resource $handle */
	private function export_rows( $handle, string $table_name ): void {
		$wpdb = $this->wpdb;
		$table_name = Identifier::normalize( $table_name );
		$table      = Identifier::quote( $table_name );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Backup export must read the live table definition without cache.
		$columns = $wpdb->get_results(
			$wpdb->prepare( 'SHOW COLUMNS FROM %i', $table_name ),
			ARRAY_A
		);
		$columns    = is_array( $columns ) ? $columns : array();
		$column_map = array();
		$selectable = array();
		foreach ( $columns as $column ) {
			$field = (string) $column['Field'];
			$extra = strtoupper( (string) ( $column['Extra'] ?? '' ) );
			if ( str_contains( $extra, 'GENERATED' ) ) {
				continue;
			}
			$column_map[ $field ] = strtolower( (string) $column['Type'] );
			$selectable[] = Identifier::normalize( $field );
		}
		if ( empty( $selectable ) ) {
			return;
		}

		$offset              = 0;
		$limit               = 250;
		$select_placeholders = implode( ', ', array_fill( 0, count( $selectable ), '%i' ) );
		$primary_key         = array_map( array( Identifier::class, 'normalize' ), $this->primary_key_columns( $table_name ) );
		$order_placeholders  = implode( ', ', array_fill( 0, count( $primary_key ), '%i' ) );
		while ( true ) {
			if ( empty( $primary_key ) ) {
				$query_args = array_merge( $selectable, array( $table_name, $limit, $offset ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The interpolated fragment contains only generated %i placeholders; every identifier/value is passed to prepare().
				$rows = $wpdb->get_results(
					$wpdb->prepare( "SELECT {$select_placeholders} FROM %i LIMIT %d OFFSET %d", $query_args ),
					ARRAY_A
				);
			} else {
				$query_args = array_merge( $selectable, array( $table_name ), $primary_key, array( $limit, $offset ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The interpolated fragments contain only generated %i placeholders; every identifier/value is passed to prepare().
				$rows = $wpdb->get_results(
					$wpdb->prepare( "SELECT {$select_placeholders} FROM %i ORDER BY {$order_placeholders} LIMIT %d OFFSET %d", $query_args ),
					ARRAY_A
				);
			}

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$names  = array();
				$values = array();
				foreach ( $row as $column => $value ) {
					$names[]  = Identifier::quote( (string) $column );
					$values[] = $this->sql_literal( $value, $column_map[ (string) $column ] ?? '' );
				}
				$this->write( $handle, "INSERT INTO {$table} (" . implode( ',', $names ) . ') VALUES (' . implode( ',', $values ) . ");\n" );
			}

			$offset += count( $rows );
			if ( count( $rows ) < $limit ) {
				break;
			}
		}

		$this->write( $handle, "\n" );
	}

	/** @param resource $handle */
	private function export_triggers( $handle ): int {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$rows = $wpdb->get_results( 'SHOW TRIGGERS', ARRAY_A );
		$count = 0;
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$name = (string) ( $row['Trigger'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			$name   = Identifier::normalize( $name );
			$quoted = Identifier::quote( $name );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$create_row = $wpdb->get_row(
				 // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema mutation is an explicit, capability-gated maintenance operation and is never run as a cached read.
				 $wpdb->prepare( 'SHOW CREATE TRIGGER %i', $name ) ,
				ARRAY_A
			);
			$statement = '';
			foreach ( is_array( $create_row ) ? $create_row : array() as $value ) {
				if ( is_string( $value ) && preg_match( '/^CREATE\s+/i', ltrim( $value ) ) ) {
					$statement = $value;
					break;
				}
			}
			if ( '' === $statement ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
				throw new RuntimeException( 'Unable to export trigger: ' . $name );
			}
			$this->write( $handle, "DROP TRIGGER IF EXISTS {$quoted};\nDELIMITER ;;\n{$statement};;\nDELIMITER ;\n\n" );
			++$count;
		}
		return $count;
	}

	private function sql_literal( mixed $value, string $column_type ): string {
		if ( null === $value ) {
			return 'NULL';
		}
		if ( preg_match( '/^(tinyint|smallint|mediumint|int|bigint|decimal|float|double)/', $column_type ) === 1 && is_numeric( $value ) ) {
			return (string) $value;
		}
		return "X'" . bin2hex( (string) $value ) . "'";
	}

	/** @param resource $handle */
	private function write( $handle, string $contents ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Incremental streaming is required to keep backup memory usage bounded.
		if ( false === fwrite( $handle, $contents ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
			throw new RuntimeException( 'Unable to write the backup stream.' );
		}
	}

	/** @return list<string> */
	private function primary_key_columns( string $table_name ): array {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s ORDER BY SEQ_IN_INDEX ASC',
				(string) DB_NAME,
				$table_name,
				'PRIMARY'
			),
			ARRAY_A
		);

		return array_values(
			array_filter(
				array_map( static fn( array $row ): string => (string) ( $row['COLUMN_NAME'] ?? '' ), is_array( $rows ) ? $rows : array() )
			)
		);
	}

	/**
	 * @param list<array<int,mixed>> $table_rows
	 */
	private function all_base_tables_have_primary_key( array $table_rows ): bool {
		foreach ( $table_rows as $row ) {
			if ( strtoupper( (string) ( $row[1] ?? '' ) ) === 'VIEW' ) {
				continue;
			}
			if ( empty( $this->primary_key_columns( (string) ( $row[0] ?? '' ) ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param list<array<int,mixed>> $table_rows
	 */
	private function all_base_tables_transactional( array $table_rows ): bool {
		$wpdb = $this->wpdb;
		foreach ( $table_rows as $row ) {
			if ( strtoupper( (string) ( $row[1] ?? '' ) ) === 'VIEW' ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$engine = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
					(string) DB_NAME,
					(string) ( $row[0] ?? '' )
				)
			);
			if ( 'INNODB' !== strtoupper( (string) $engine ) ) {
				return false;
			}
		}
		return true;
	}


	private function refresh_last_backup_option(): void {
		$wpdb = $this->wpdb;
		$table = Identifier::normalize( $this->tables->backups() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$uuid  = (string) $wpdb->get_var(
			 $wpdb->prepare( "SELECT uuid FROM %i WHERE status = 'verified' ORDER BY id DESC LIMIT 1", $table )
		);
		if ( '' === $uuid ) {
			delete_option( 'shcd_tornado_dbm_last_backup_uuid' );
			return;
		}
		update_option( 'shcd_tornado_dbm_last_backup_uuid', $uuid, false );
	}

	private function is_registered_backup_path( string $path ): bool {
		$normalized = wp_normalize_path( $path );
		if ( preg_match( '/\/(?:shcd-tornado-dbm|tornado)-[A-Za-z0-9-]+\.sql$/', $normalized ) !== 1 ) {
			return false;
		}
		foreach ( $this->backup_root_candidates() as $root ) {
			$root = trailingslashit( wp_normalize_path( $root ) );
			if ( str_starts_with( $normalized, $root ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return list<string> */
	private function backup_root_candidates(): array {
		$candidates = array();
		if ( defined( 'SHCD_TORNADO_DBM_BACKUP_DIR' ) && is_string( SHCD_TORNADO_DBM_BACKUP_DIR ) && '' !== trim( SHCD_TORNADO_DBM_BACKUP_DIR ) ) {
			$candidates[] = wp_normalize_path( SHCD_TORNADO_DBM_BACKUP_DIR );
		}

		try {
			$candidates[] = $this->default_backup_root();
		} catch ( RuntimeException $exception ) {
			if ( empty( $candidates ) ) {
				throw $exception;
			}
		}

		return array_values( array_unique( array_map( 'strval', $candidates ) ) );
	}

	/** @return array{path:string,private:bool} */
	private function backup_directory(): array {
		$default_root = '';
		try {
			$default_root = wp_normalize_path( $this->default_backup_root() );
		} catch ( RuntimeException ) {
			// A configured SHCD_TORNADO_DBM_BACKUP_DIR may still be usable when uploads are unavailable.
		}

		foreach ( $this->backup_root_candidates() as $candidate ) {
			$candidate = wp_normalize_path( (string) $candidate );
			if ( is_link( $candidate ) ) {
				continue;
			}
			if ( ! wp_mkdir_p( $candidate ) || is_link( $candidate ) || ! Filesystem::is_writable( $candidate ) ) {
				continue;
			}
			Filesystem::chmod( $candidate, 0700 );
			$this->write_protection_files( $candidate );

			$is_default_root = '' !== $default_root && hash_equals( trailingslashit( $default_root ), trailingslashit( $candidate ) );
			$is_private      = $is_default_root || ! $this->is_under_public_root( $candidate );
			if ( ! $this->protection_files_present( $candidate ) ) {
				continue;
			}

			return array( 'path' => $candidate, 'private' => $is_private );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
		throw new RuntimeException( 'Unable to create a protected backup directory.' );
	}

	private function default_backup_root(): string {
		$upload = wp_upload_dir( null, false );
		if ( ! empty( $upload['error'] ) || empty( $upload['basedir'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
			throw new RuntimeException( 'WordPress uploads directory is not available for protected backup storage.' );
		}

		return trailingslashit( wp_normalize_path( (string) $upload['basedir'] ) )
			. 'shcd-tornado-dbm-private-backups-'
			. $this->backup_storage_token();
	}

	private function backup_storage_token(): string {
		$token = strtolower( (string) get_option( 'shcd_tornado_dbm_backup_storage_token', '' ) );
		if ( 1 === preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return $token;
		}

		$token = strtolower( str_replace( '-', '', wp_generate_uuid4() ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
			throw new RuntimeException( 'Unable to generate a protected backup storage token.' );
		}

		update_option( 'shcd_tornado_dbm_backup_storage_token', $token, false );
		$stored = strtolower( (string) get_option( 'shcd_tornado_dbm_backup_storage_token', '' ) );
		if ( ! hash_equals( $token, $stored ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
			throw new RuntimeException( 'Unable to persist the protected backup storage token.' );
		}

		return $token;
	}

	private function write_protection_files( string $dir ): void {
		Filesystem::put_contents( trailingslashit( $dir ) . 'index.php', "<?php\nif ( ! defined( 'ABSPATH' ) ) { http_response_code( 403 ); exit; }\n", 0600 );
		Filesystem::put_contents( trailingslashit( $dir ) . '.htaccess', "Options -Indexes\nRequire all denied\nDeny from all\n", 0600 );
		Filesystem::put_contents(
			trailingslashit( $dir ) . 'web.config',
			'<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><directoryBrowse enabled="false"/><authorization><remove users="*" roles="" verbs=""/><add accessType="Deny" users="*"/></authorization></system.webServer></configuration>',
			0600
		);
	}

	private function protection_files_present( string $dir ): bool {
		foreach ( array( 'index.php', '.htaccess', 'web.config' ) as $file_name ) {
			$file = trailingslashit( $dir ) . $file_name;
			if ( ! is_file( $file ) || is_link( $file ) ) {
				return false;
			}
		}
		return true;
	}

	private function is_under_public_root( string $path ): bool {
		$path  = trailingslashit( wp_normalize_path( $path ) );
		$roots = array( ABSPATH );
		$upload = wp_upload_dir( null, false );
		if ( empty( $upload['error'] ) && ! empty( $upload['basedir'] ) ) {
			$roots[] = (string) $upload['basedir'];
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
