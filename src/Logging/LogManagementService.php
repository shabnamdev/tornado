<?php
/**
 * Administrative log listing and deletion.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Logging;

use Shcd\TornadoDatabaseMaintenance\Database\AutoIncrementService;
use Shcd\TornadoDatabaseMaintenance\Database\Identifier;
use Shcd\TornadoDatabaseMaintenance\Database\Tables;
use Shcd\TornadoDatabaseMaintenance\Jobs\JobRepository;
use InvalidArgumentException;
use RuntimeException;

final class LogManagementService {
	private const ALLOWED_LEVELS = array( '', 'debug', 'info', 'warning', 'error', 'critical' );

	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly Tables $tables,
		private readonly AutoIncrementService $auto_increment,
		private readonly JobRepository $jobs
	) {}

	/**
	 * @return array<string, mixed>
	 */
	public function list( int $limit = 100, int $offset = 0, string $level = '', string $job_uuid = '' ): array {
		$wpdb = $this->wpdb;
		$limit  = max( 1, min( 500, $limit ) );
		$offset = max( 0, $offset );
		$level  = sanitize_key( $level );
		if ( ! in_array( $level, self::ALLOWED_LEVELS, true ) ) {
			throw new InvalidArgumentException( 'سطح انتخاب‌شده برای Log معتبر نیست.' );
		}
		if ( '' !== $job_uuid && ! wp_is_uuid( $job_uuid ) ) {
			throw new InvalidArgumentException( 'Job UUID واردشده معتبر نیست.' );
		}

		$table = Identifier::normalize( $this->tables->logs() );

		if ( '' !== $level && '' !== $job_uuid ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Log management must read live rows.
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE level = %s AND job_uuid = %s', $table, $level, $job_uuid ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Log management must read live rows.
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, job_uuid, level, message, context_json, created_at FROM %i WHERE level = %s AND job_uuid = %s ORDER BY id DESC LIMIT %d OFFSET %d', $table, $level, $job_uuid, $limit, $offset ), ARRAY_A );
		} elseif ( '' !== $level ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Log management must read live rows.
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE level = %s', $table, $level ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Log management must read live rows.
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, job_uuid, level, message, context_json, created_at FROM %i WHERE level = %s ORDER BY id DESC LIMIT %d OFFSET %d', $table, $level, $limit, $offset ), ARRAY_A );
		} elseif ( '' !== $job_uuid ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Log management must read live rows.
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE job_uuid = %s', $table, $job_uuid ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Log management must read live rows.
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, job_uuid, level, message, context_json, created_at FROM %i WHERE job_uuid = %s ORDER BY id DESC LIMIT %d OFFSET %d', $table, $job_uuid, $limit, $offset ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Log management must read live rows.
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Log management must read live rows.
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, job_uuid, level, message, context_json, created_at FROM %i ORDER BY id DESC LIMIT %d OFFSET %d', $table, $limit, $offset ), ARRAY_A );
		}

		$normalized   = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$row['id']      = (int) ( $row['id'] ?? 0 );
			$row['context'] = json_decode( (string) ( $row['context_json'] ?? '{}' ), true ) ?: array();
			unset( $row['context_json'] );
			$normalized[] = $row;
		}

		return array(
			'items'  => $normalized,
			'total'  => $total,
			'limit'  => $limit,
			'offset' => $offset,
			'level'  => $level,
		);
	}

	/**
	 * @param list<int|string> $ids Log IDs.
	 * @return array<string, mixed>
	 */
	public function delete( array $ids ): array {
		$wpdb = $this->wpdb;
		$ids = array_values(
			array_unique(
				array_filter(
					array_map( 'absint', $ids ),
					static fn( int $id ): bool => $id > 0
				)
			)
		);
		if ( empty( $ids ) ) {
			throw new InvalidArgumentException( 'برای حذف، هیچ Logی انتخاب نشده است.' );
		}
		if ( count( $ids ) > 1000 ) {
			throw new InvalidArgumentException( 'در هر درخواست حداکثر ۱۰۰۰ Log قابل حذف است.' );
		}

		$table = Identifier::normalize( $this->tables->logs() );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$existing     = $wpdb->get_col(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Generated SQL fragment is restricted to validated identifiers, fixed clauses, or a placeholder list; all external data values are passed to prepare().
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only validated identifiers, fixed internal clauses, or generated placeholder lists are interpolated; external values are passed to prepare().
				"SELECT id FROM %i WHERE id IN ({$placeholders}) ORDER BY id ASC",
				array_merge( array( $table ), $ids )
			)
		);
		$existing = array_values( array_map( 'intval', is_array( $existing ) ? $existing : array() ) );
		if ( empty( $existing ) ) {
			throw new RuntimeException( 'Logهای انتخاب‌شده در دیتابیس پیدا نشدند.' );
		}

		$job_uuid = $this->jobs->create( 'log_management_delete', array( 'ids' => $existing ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$deleted  = $wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Generated SQL fragment is restricted to validated identifiers, fixed clauses, or a placeholder list; all external data values are passed to prepare().
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only validated identifiers, fixed internal clauses, or generated placeholder lists are interpolated; external values are passed to prepare().
				"DELETE FROM %i WHERE id IN ({$placeholders})",
				array_merge( array( $table ), $existing )
			)
		);
		if ( false === $deleted ) {
			$this->jobs->fail( $job_uuid, $wpdb->last_error, 'delete_failed' );
			throw new RuntimeException( 'Logهای انتخاب‌شده از دیتابیس حذف نشدند.' );
		}

		$rewind = $this->auto_increment->rewind_after_delete( $this->tables->logs(), $existing, 'log_delete' );
		$result = array(
			'deleted_count' => (int) $deleted,
			'deleted_ids'   => $existing,
			'auto_increment' => $rewind,
			'job_uuid'      => $job_uuid,
		);
		$this->jobs->complete( $job_uuid, $result );
		return $result;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function purge_older_than( int $days ): array {
		$wpdb = $this->wpdb;
		$days   = max( 1, min( 3650, $days ) );
		$table  = Identifier::normalize( $this->tables->logs() );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$deleted_ids = array();
		$job_uuids   = array();
		$last_rewind = null;

		for ( $batch = 0; $batch < 20; ++$batch ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM %i WHERE created_at < %s ORDER BY id ASC LIMIT 1000",
					$table,
					$cutoff
				)
			);
			$ids = array_values( array_map( 'intval', is_array( $ids ) ? $ids : array() ) );
			if ( empty( $ids ) ) {
				break;
			}

			$result       = $this->delete( $ids );
			$deleted_ids  = array_merge( $deleted_ids, (array) ( $result['deleted_ids'] ?? array() ) );
			$job_uuids[]  = (string) ( $result['job_uuid'] ?? '' );
			$last_rewind  = $result['auto_increment'] ?? $last_rewind;
			if ( count( $ids ) < 1000 ) {
				break;
			}
		}

		return array(
			'deleted_count' => count( $deleted_ids ),
			'deleted_ids'   => $deleted_ids,
			'cutoff'        => $cutoff,
			'auto_increment'=> $last_rewind,
			'job_uuids'     => array_values( array_filter( $job_uuids ) ),
			'limit_reached' => count( $deleted_ids ) >= 20000,
		);
	}
}
