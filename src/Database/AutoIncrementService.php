<?php
/**
 * Safe AUTO_INCREMENT inspection and normalization.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Database;

use Shcd\TornadoDatabaseMaintenance\Jobs\JobRepository;
use Shcd\TornadoDatabaseMaintenance\Logging\Logger;
use InvalidArgumentException;
use RuntimeException;

final class AutoIncrementService {
	private const ALLOWED_SCOPES = array( 'posts_only', 'wordpress_core', 'all_prefixed' );

	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly JobRepository $jobs,
		private readonly Logger $logger
	) {}

	/**
	 * Creates an expiring preview token for a manual AUTO_INCREMENT normalization.
	 *
	 * @return array<string, mixed>
	 */
	public function preview( string $scope = 'all_prefixed' ): array {
		$scope = $this->sanitize_scope( $scope );
		$rows  = $this->inspect( $scope );
		$token = bin2hex( random_bytes( 32 ) );
		$hash  = $this->rows_hash( $rows );
		$expires_at = time() + 600;

		set_transient(
			$this->preview_key(),
			array(
				'hash'       => hash_hmac( 'sha256', $token, wp_salt( 'auth' ) ),
				'rows_hash'  => $hash,
				'scope'      => $scope,
				'expires_at' => $expires_at,
			),
			600
		);

		return array(
			'scope'         => $scope,
			'tables'        => $rows,
			'table_count'   => count( $rows ),
			'change_count'  => count( array_filter( $rows, static fn( array $row ): bool => ! empty( $row['needs_change'] ) ) ),
			'preview_token' => $token,
			'expires_at'    => $expires_at,
		);
	}

	/**
	 * Executes a manual normalization after a fresh preview.
	 *
	 * @return array<string, mixed>
	 */
	public function execute( string $scope, string $preview_token ): array {
		$scope = $this->sanitize_scope( $scope );
		$key   = $this->preview_key();
		$stored = get_transient( $key );
		delete_transient( $key );

		if ( '' === $preview_token || ! is_array( $stored ) || (int) ( $stored['expires_at'] ?? 0 ) < time() ) {
			throw new RuntimeException( 'A fresh AUTO_INCREMENT preview is required.' );
		}
		if ( ! hash_equals( (string) ( $stored['hash'] ?? '' ), hash_hmac( 'sha256', $preview_token, wp_salt( 'auth' ) ) ) ) {
			throw new RuntimeException( 'The AUTO_INCREMENT preview authorization is invalid.' );
		}
		if ( ! hash_equals( (string) ( $stored['scope'] ?? '' ), $scope ) ) {
			throw new RuntimeException( 'The AUTO_INCREMENT scope differs from the confirmed preview.' );
		}

		$current = $this->inspect( $scope );
		if ( ! hash_equals( (string) ( $stored['rows_hash'] ?? '' ), $this->rows_hash( $current ) ) ) {
			throw new RuntimeException( 'AUTO_INCREMENT candidates changed after Preview. Review the updated state.' );
		}

		return $this->normalize_rows( $current, 'manual', true );
	}

	/**
	 * Normalizes only explicitly affected tables after a cleanup or cache purge.
	 * Existing row identifiers are never changed by this method.
	 *
	 * @param list<string> $tables Table names.
	 * @return array<string, mixed>
	 */
	public function normalize_tables( array $tables, string $reason = 'automatic' ): array {
		$tables = array_values( array_unique( array_filter( array_map( 'strval', $tables ) ) ) );
		if ( empty( $tables ) ) {
			return array( 'reason' => $reason, 'changed' => array(), 'unchanged' => array(), 'failed' => array() );
		}

		$rows = $this->inspect( 'all_prefixed', $tables );
		return $this->normalize_rows( $rows, $reason, false );
	}


	/**
	 * Rewinds a table counter after deletion without renumbering existing rows.
	 *
	 * When the deleted range was at the end of the table, the next generated ID
	 * becomes the first deleted tail ID. Interior gaps cannot be reused by the
	 * native MySQL AUTO_INCREMENT mechanism while higher IDs still exist.
	 *
	 * @param list<int|string> $deleted_ids Deleted primary-key values.
	 * @return array<string, mixed>
	 */
	public function rewind_after_delete( string $table, array $deleted_ids, string $reason = 'delete' ): array {
		$wpdb = $this->wpdb;
		$table = trim( $table );
		if ( '' === $table || preg_match( '/^[A-Za-z0-9_]+$/', $table ) !== 1 ) {
			throw new InvalidArgumentException( 'نام جدول برای تنظیم AUTO_INCREMENT معتبر نیست.' );
		}
		if ( ! str_starts_with( $table, $wpdb->prefix ) ) {
			throw new InvalidArgumentException( 'تنظیم AUTO_INCREMENT فقط برای جدول‌های همین سایت مجاز است.' );
		}

		$deleted_ids = array_values(
			array_unique(
				array_filter(
					array_map( 'absint', $deleted_ids ),
					static fn( int $id ): bool => $id > 0
				)
			)
		);

		$rows = $this->inspect( 'all_prefixed', array( $table ) );
		if ( empty( $rows ) ) {
			return array(
				'table'          => $table,
				'changed'        => false,
				'reason'         => $reason,
				'message'        => 'این جدول ستون AUTO_INCREMENT ندارد و نیازی به تنظیم شمارنده نیست.',
				'deleted_ids'    => $deleted_ids,
			);
		}

		$row      = $rows[0];
		$next     = max( 1, (int) ( $row['proposed_next_id'] ?? 1 ) );
		$current  = max( 0, (int) ( $row['current_auto_increment'] ?? 0 ) );
		$max_id   = max( 0, (int) ( $row['max_existing_id'] ?? 0 ) );
		$reuses_deleted_id = in_array( $next, $deleted_ids, true );
		$interior_deleted  = array_values(
			array_filter(
				$deleted_ids,
				static fn( int $id ): bool => $id < $next
			)
		);

		$table_q = Identifier::quote( $table );
		$tornado_sql_table_q = Identifier::normalize( $table_q );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$result  = $wpdb->query(
			 $wpdb->prepare(
			 	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema mutation is an explicit, capability-gated maintenance operation and is never run as a cached read.
			 	"ALTER TABLE %i AUTO_INCREMENT = %d",
			 	$tornado_sql_table_q,
			 	$next
			 ) 
		); 
		if ( false === $result ) {
			throw new RuntimeException( 'پس از حذف رکوردها، AUTO_INCREMENT جدول تنظیم نشد.' );
		}

		$verified_rows = $this->inspect( 'all_prefixed', array( $table ) );
		$verified_next = isset( $verified_rows[0] ) ? (int) ( $verified_rows[0]['current_auto_increment'] ?? $next ) : $next;

		return array(
			'table'                  => $table,
			'column'                 => (string) ( $row['column'] ?? '' ),
			'changed'                => $current !== $verified_next,
			'previous_auto_increment'=> $current,
			'next_id'                => $verified_next,
			'max_existing_id'        => $max_id,
			'deleted_ids'            => $deleted_ids,
			'reused_deleted_tail_id' => $reuses_deleted_id && $verified_next === $next,
			'interior_gaps_not_reused' => $interior_deleted,
			'reason'                 => sanitize_key( $reason ),
			'message'                => $reuses_deleted_id
				? 'شناسه بعدی روی نخستین ID حذف‌شده از انتهای جدول تنظیم شد.'
				: ( empty( $interior_deleted )
					? 'AUTO_INCREMENT روی نخستین شناسه امن بعد از بیشترین ID موجود تنظیم شد.'
					: 'شناسه حذف‌شده در میانه جدول قابل استفاده مجدد نیست؛ زیرا IDهای بزرگ‌تر هنوز وجود دارند.' ),
		);
	}

	/**
	 * @param list<string> $requested_tables Optional allow-list.
	 * @return list<array<string, mixed>>
	 */
	public function inspect( string $scope = 'all_prefixed', array $requested_tables = array() ): array {
		$wpdb = $this->wpdb;
		$scope = $this->sanitize_scope( $scope );
		$prefix_like = $wpdb->esc_like( $wpdb->prefix ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.TABLE_NAME, c.COLUMN_NAME, c.DATA_TYPE, t.ENGINE, t.AUTO_INCREMENT, t.TABLE_ROWS
				 FROM INFORMATION_SCHEMA.COLUMNS c
				 INNER JOIN INFORMATION_SCHEMA.TABLES t
				   ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
				 WHERE c.TABLE_SCHEMA = %s
				   AND t.TABLE_TYPE = 'BASE TABLE'
				   AND c.EXTRA LIKE %s
				   AND c.TABLE_NAME LIKE %s
				 ORDER BY c.TABLE_NAME ASC",
				(string) DB_NAME,
				'%auto_increment%',
				$prefix_like
			),
			ARRAY_A
		);

		$requested_lookup = array_fill_keys( $requested_tables, true );
		$core_tables = array_fill_keys(
			array(
				$wpdb->posts,
				$wpdb->postmeta,
				$wpdb->comments,
				$wpdb->commentmeta,
				$wpdb->users,
				$wpdb->usermeta,
				$wpdb->terms,
				$wpdb->term_taxonomy,
				$wpdb->termmeta,
				$wpdb->links,
				$wpdb->options,
			),
			true
		);

		$result = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$table  = (string) $row['TABLE_NAME'];
			$column = (string) $row['COLUMN_NAME'];

			if ( ! empty( $requested_lookup ) && ! isset( $requested_lookup[ $table ] ) ) {
				continue;
			}
			if ( 'posts_only' === $scope && $table !== $wpdb->posts ) {
				continue;
			}
			if ( 'wordpress_core' === $scope && ! isset( $core_tables[ $table ] ) ) {
				continue;
			}

			$table_q  = Identifier::quote( $table );
			$tornado_sql_table_q = Identifier::normalize( $table_q );
			$column_q = Identifier::quote( $column );
			$tornado_sql_column_q = Identifier::normalize( $column_q );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$max      = (int) $wpdb->get_var(
				 $wpdb->prepare( "SELECT COALESCE(MAX(%i), 0) FROM %i", $tornado_sql_column_q, $tornado_sql_table_q ) 
			); 
			$proposed = max( 1, $max + 1 );
			$current  = max( 0, (int) ( $row['AUTO_INCREMENT'] ?? 0 ) );

			$result[] = array(
				'table'                  => $table,
				'column'                 => $column,
				'data_type'              => (string) $row['DATA_TYPE'],
				'engine'                 => (string) ( $row['ENGINE'] ?? '' ),
				'estimated_rows'         => max( 0, (int) ( $row['TABLE_ROWS'] ?? 0 ) ),
				'current_auto_increment' => $current,
				'max_existing_id'        => $max,
				'proposed_next_id'       => $proposed,
				'needs_change'           => $current !== $proposed,
			);
		}

		return $result;
	}

	/**
	 * @param list<array<string, mixed>> $rows Rows from inspect().
	 * @return array<string, mixed>
	 */
	private function normalize_rows( array $rows, string $reason, bool $create_job ): array {
		$wpdb = $this->wpdb;
		$job_uuid = $create_job ? $this->jobs->create( 'auto_increment', array( 'reason' => $reason ) ) : '';
		$lock_name = 'shcd_tornado_dbm_auto_increment_' . md5( (string) DB_NAME );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$lock = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', $lock_name ) );
		if ( 1 !== $lock ) {
			if ( '' !== $job_uuid ) {
				$this->jobs->fail( $job_uuid, 'Unable to acquire the AUTO_INCREMENT lock.', 'lock_failed' );
			}
			throw new RuntimeException( 'Another AUTO_INCREMENT operation is active.' );
		}

		$changed = array();
		$unchanged = array();
		$failed = array();
		try {
			foreach ( $rows as $row ) {
				$table = (string) ( $row['table'] ?? '' );
				$next  = max( 1, (int) ( $row['proposed_next_id'] ?? 1 ) );
				if ( empty( $row['needs_change'] ) ) {
					$unchanged[] = array( 'table' => $table, 'next_id' => $next );
					continue;
				}

				try {
					$table_q = Identifier::quote( $table );
					$tornado_sql_table_q = Identifier::normalize( $table_q );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
					$result = $wpdb->query(
						 $wpdb->prepare(
						 	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema mutation is an explicit, capability-gated maintenance operation and is never run as a cached read.
						 	"ALTER TABLE %i AUTO_INCREMENT = %d",
						 	$tornado_sql_table_q,
						 	$next
						 ) 
					); 
					if ( false === $result ) {
						throw new RuntimeException( $wpdb->last_error ?: 'ALTER TABLE failed.' );
					}
					$changed[] = array(
						'table'       => $table,
						'column'      => (string) ( $row['column'] ?? '' ),
						'previous'    => (int) ( $row['current_auto_increment'] ?? 0 ),
						'next_id'     => $next,
						'max_id'      => (int) ( $row['max_existing_id'] ?? 0 ),
					);
				} catch ( \Throwable $throwable ) {
					$failed[] = array( 'table' => $table, 'error' => sanitize_text_field( $throwable->getMessage() ) );
				}
			}

			$result = array(
				'uuid'      => $job_uuid,
				'reason'    => $reason,
				'changed'   => $changed,
				'unchanged' => $unchanged,
				'failed'    => $failed,
			);
			if ( '' !== $job_uuid ) {
				$this->jobs->complete( $job_uuid, $result );
			}
			$this->logger->log( empty( $failed ) ? 'info' : 'warning', 'AUTO_INCREMENT normalization completed.', $result, $job_uuid );
			return $result;
		} catch ( \Throwable $throwable ) {
			if ( '' !== $job_uuid ) {
				$this->jobs->fail( $job_uuid, $throwable->getMessage(), 'auto_increment_failed' );
			}
			throw $throwable;
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	private function sanitize_scope( string $scope ): string {
		$scope = sanitize_key( $scope );
		if ( ! in_array( $scope, self::ALLOWED_SCOPES, true ) ) {
			throw new InvalidArgumentException( 'Unsupported AUTO_INCREMENT scope.' );
		}
		return $scope;
	}

	/** @param list<array<string, mixed>> $rows */
	private function rows_hash( array $rows ): string {
		$minimal = array_map(
			static fn( array $row ): array => array(
				'table'    => (string) ( $row['table'] ?? '' ),
				'column'   => (string) ( $row['column'] ?? '' ),
				'current'  => (int) ( $row['current_auto_increment'] ?? 0 ),
				'max'      => (int) ( $row['max_existing_id'] ?? 0 ),
				'proposed' => (int) ( $row['proposed_next_id'] ?? 1 ),
			),
			$rows
		);
		return hash( 'sha256', (string) wp_json_encode( $minimal ) );
	}

	private function preview_key(): string {
		return 'shcd_tornado_dbm_auto_increment_preview_' . max( 0, get_current_user_id() );
	}
}
