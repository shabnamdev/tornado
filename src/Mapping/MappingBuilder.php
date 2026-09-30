<?php
/**
 * Immutable post ID mapping builder.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Mapping;

use Shcd\TornadoDatabaseMaintenance\Database\FingerprintService;
use Shcd\TornadoDatabaseMaintenance\Database\Identifier;
use Shcd\TornadoDatabaseMaintenance\Database\Tables;
use Shcd\TornadoDatabaseMaintenance\Jobs\JobRepository;
use Shcd\TornadoDatabaseMaintenance\Logging\Logger;
use Shcd\TornadoDatabaseMaintenance\Reindex\GenerationRepository;
use RuntimeException;

final class MappingBuilder {
	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly Tables $tables,
		private readonly FingerprintService $fingerprints,
		private readonly JobRepository $jobs,
		private readonly Logger $logger,
		private readonly GenerationRepository $generations
	) {}

	/**
	 * @return array<string, mixed>
	 */
	public function build(): array {
		$wpdb = $this->wpdb;
		$source_post_fingerprint = $this->fingerprints->post_ids();
		$source_schema_fingerprint = $this->fingerprints->schema();
		$reused = $this->reusable_mapping( $source_post_fingerprint, $source_schema_fingerprint );
		if ( null !== $reused ) {
			return $reused;
		}

		$job_uuid      = $this->jobs->create( 'mapping', array(
			'source_post_id_fingerprint' => $source_post_fingerprint,
			'source_schema_fingerprint'  => $source_schema_fingerprint,
		) );
		$mapping_table = Identifier::quote( $this->tables->mappings() );
		$tornado_sql_mapping_table = Identifier::normalize( $mapping_table );
		$posts_table   = Identifier::quote( $wpdb->posts );
		$tornado_sql_posts_table = Identifier::normalize( $posts_table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$source_stats = $wpdb->get_row(
			 $wpdb->prepare( "SELECT COUNT(*) AS row_count, COALESCE(MIN(ID), 0) AS min_id, COALESCE(MAX(ID), 0) AS max_id,
			 COUNT(DISTINCT ID) AS distinct_ids,
			 SUM(CASE WHEN post_type = 'attachment' THEN 1 ELSE 0 END) AS attachment_count
			 FROM %i", $tornado_sql_posts_table ) ,
			ARRAY_A
		);
		$source_stats  = is_array( $source_stats ) ? $source_stats : array();
		$total         = (int) ( $source_stats['row_count'] ?? 0 );
		$min_id        = (int) ( $source_stats['min_id'] ?? 0 );
		$max_id        = (int) ( $source_stats['max_id'] ?? 0 );
		$distinct_ids  = (int) ( $source_stats['distinct_ids'] ?? 0 );
		$attachments   = (int) ( $source_stats['attachment_count'] ?? 0 );
		$source_gaps   = $total > 0 ? max( 0, $max_id - $total ) : 0;
		$generation_preview = $this->generations->preview( $source_post_fingerprint, $total, $source_gaps );

		if ( $distinct_ids !== $total ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
			throw new RuntimeException( sprintf( 'در جدول %s شناسه تکراری پیدا شد؛ تا رفع این مشکل، ساخت Mapping پیوسته امکان‌پذیر نیست.', $wpdb->posts ) );
		}
		$cursor        = 0;
		$new_id        = 1;
		$processed     = 0;
		$changed       = 0;
		$chunk_size    = 1000;
		$temp_base     = max( $max_id + 100000, ( $total * 2 ) + $max_id + 1000 );
		$hash_context  = hash_init( 'sha256' );

		try {
			while ( true ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
				$ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT ID FROM %i WHERE ID > %d ORDER BY ID ASC LIMIT %d",
						$tornado_sql_posts_table,
						$cursor,
						$chunk_size
					)
				);

				if ( empty( $ids ) ) {
					break;
				}

				foreach ( $ids as $raw_id ) {
					$old_id  = (int) $raw_id;
					$temp_id = $temp_base + $processed + 1;

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
					$inserted = $wpdb->query(
						$wpdb->prepare(
							"INSERT INTO %i (job_uuid, old_id, temp_id, new_id, created_at) VALUES (%s, %d, %d, %d, %s)",
							$tornado_sql_mapping_table,
							$job_uuid,
							$old_id,
							$temp_id,
							$new_id,
							current_time( 'mysql', true )
						)
					);

					if ( false === $inserted ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
						throw new RuntimeException( 'Mapping شناسه‌ها در دیتابیس ذخیره نشد.' );
					}

					hash_update( $hash_context, $old_id . ':' . $temp_id . ':' . $new_id . ';' );
					if ( $old_id !== $new_id ) {
						++$changed;
					}

					$cursor = $old_id;
					++$new_id;
					++$processed;
				}

				$this->jobs->progress(
					$job_uuid,
					'building_mapping',
					$total > 0 ? ( $processed / $total ) * 100 : 100,
					array( 'processed' => $processed, 'total' => $total )
				);
			}


			if ( ! hash_equals( $source_post_fingerprint, $this->fingerprints->post_ids() ) || ! hash_equals( $source_schema_fingerprint, $this->fingerprints->schema() ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM %i WHERE job_uuid = %s",
						$tornado_sql_mapping_table,
						$job_uuid
					)
				);
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
				throw new RuntimeException( sprintf( 'هنگام ساخت Mapping، داده‌های جدول %s تغییر کردند. فعالیت‌های نوشتاری سایت را متوقف کنید و دوباره Mapping بسازید.', $wpdb->posts ) );
			}

			$gapless_status = $this->gapless_status( $job_uuid );
			if ( empty( $gapless_status['valid'] ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM %i WHERE job_uuid = %s",
						$tornado_sql_mapping_table,
						$job_uuid
					)
				);
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
				throw new RuntimeException( sprintf( 'Mapping ساخته‌شده پیوسته نیست یا همه رکوردهای جدول %s را پوشش نمی‌دهد.', $wpdb->posts ) );
			}

			$hash   = hash_final( $hash_context );
			$result = array(
				'uuid'            => $job_uuid,
				'posts_table'     => $wpdb->posts,
				'total_posts'     => $total,
				'attachment_count'=> $attachments,
				'changed_ids'     => $changed,
				'unchanged_ids'   => max( 0, $total - $changed ),
				'old_min_id'      => $min_id,
				'old_max_id'      => $max_id,
				'old_gap_count'   => $source_gaps,
				'new_min_id'      => $total > 0 ? 1 : 0,
				'new_max_id'      => $total,
				'new_gap_count'   => 0,
				'next_auto_increment' => $total + 1,
				'gapless_reindex' => true,
				'gapless_validation' => $gapless_status,
				'generation_preview' => $generation_preview,
				'repeatable_reindex' => true,
				'temporary_base'  => $temp_base,
				'mapping_sha256'             => $hash,
				'source_post_id_fingerprint' => $source_post_fingerprint,
				'source_schema_fingerprint'  => $source_schema_fingerprint,
				'rebuild_preview' => $this->preview_rows( $job_uuid, 50 ),
			);

			$this->jobs->complete( $job_uuid, $result );
			update_option( 'shcd_tornado_dbm_last_mapping_uuid', $job_uuid, false );
			$this->prune_old_snapshots( $job_uuid );
			$this->logger->log( 'info', 'Immutable mapping built.', $result, $job_uuid );

			return $result;
		} catch ( \Throwable $throwable ) {
			$this->jobs->fail( $job_uuid, $throwable->getMessage(), 'mapping_failed' );
			$this->logger->log( 'error', 'Mapping build failed.', array( 'exception' => $throwable->getMessage() ), $job_uuid );
			throw $throwable;
		}
	}

	/**
	 * Reuses the current immutable Mapping when the Post ID set and schema are
	 * unchanged. This prevents every Wizard retry from writing a full copy of
	 * the same hundreds or thousands of mapping rows.
	 *
	 * @return array<string,mixed>|null
	 */
	private function reusable_mapping( string $post_fingerprint, string $schema_fingerprint ): ?array {
		$wpdb = $this->wpdb;
		$uuid = (string) get_option( 'shcd_tornado_dbm_last_mapping_uuid', '' );
		$last_executed = (string) get_option( 'shcd_tornado_dbm_last_executed_mapping_uuid', '' );
		if ( wp_is_uuid( $last_executed ) && hash_equals( $last_executed, $uuid ) ) {
			return null;
		}
		if ( ! wp_is_uuid( $uuid ) || ! $this->exists( $uuid ) ) {
			return null;
		}
		$job = $this->jobs->find( $uuid );
		if ( ! is_array( $job ) || 'mapping' !== (string) ( $job['type'] ?? '' ) || 'completed' !== (string) ( $job['status'] ?? '' ) ) {
			return null;
		}
		$payload = is_array( $job['payload'] ?? null ) ? $job['payload'] : array();
		if ( ! hash_equals( $post_fingerprint, (string) ( $payload['source_post_id_fingerprint'] ?? '' ) ) || ! hash_equals( $schema_fingerprint, (string) ( $payload['source_schema_fingerprint'] ?? '' ) ) ) {
			return null;
		}
		$result = is_array( $job['result'] ?? null ) ? $job['result'] : array();
		$table  = Identifier::quote( $this->tables->mappings() );
		$tornado_sql_table = Identifier::normalize( $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$count  = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE job_uuid = %s",
				$tornado_sql_table,
				$uuid
			)
		);
		if ( $count <= 0 || (int) ( $result['total_posts'] ?? 0 ) !== $count ) {
			return null;
		}
		$gapless_status = $this->gapless_status( $uuid );
		if ( empty( $gapless_status['valid'] ) ) {
			return null;
		}
		$result['uuid']                = $uuid;
		$result['gapless_reindex']     = true;
		$result['new_gap_count']       = 0;
		$result['next_auto_increment'] = $count + 1;
		$result['gapless_validation']  = $gapless_status;
		$result['reused_mapping'] = true;
		$result['stored_rows']    = $count;
		return $result;
	}


	/**
	 * Validates that a Mapping covers every current wp_posts row exactly once and
	 * produces the dense target sequence 1..N without any interior gaps.
	 *
	 * @return array<string,int|bool>
	 */
	public function gapless_status( string $job_uuid ): array {
		$wpdb = $this->wpdb;
		if ( ! wp_is_uuid( $job_uuid ) ) {
			return array( 'valid' => false, 'mapping_rows' => 0, 'gap_count' => 0 );
		}

		$mapping = Identifier::quote( $this->tables->mappings() );
		$tornado_sql_mapping = Identifier::normalize( $mapping );
		$posts   = Identifier::quote( $wpdb->posts );
		$tornado_sql_posts = Identifier::normalize( $posts );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$stats   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS mapping_rows, COUNT(DISTINCT old_id) AS distinct_old_ids,
				 COUNT(DISTINCT new_id) AS distinct_new_ids, COALESCE(MIN(new_id), 0) AS min_new_id,
				 COALESCE(MAX(new_id), 0) AS max_new_id, COALESCE(SUM(new_id), 0) AS sum_new_ids
				 FROM %i WHERE job_uuid = %s",
				$tornado_sql_mapping,
				$job_uuid
			),
			ARRAY_A
		);
		$stats = is_array( $stats ) ? $stats : array();
		$rows  = (int) ( $stats['mapping_rows'] ?? 0 );
		$min   = (int) ( $stats['min_new_id'] ?? 0 );
		$max   = (int) ( $stats['max_new_id'] ?? 0 );
		$sum   = (int) ( $stats['sum_new_ids'] ?? 0 );
		$expected_sum = (int) ( ( $rows * ( $rows + 1 ) ) / 2 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$unmapped_posts = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i p LEFT JOIN %i m ON m.old_id = p.ID AND m.job_uuid = %s WHERE m.old_id IS NULL",
				$tornado_sql_posts,
				$tornado_sql_mapping,
				$job_uuid
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$stale_mapping_rows = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i m LEFT JOIN %i p ON p.ID = m.old_id WHERE m.job_uuid = %s AND p.ID IS NULL",
				$tornado_sql_mapping,
				$tornado_sql_posts,
				$job_uuid
			)
		);

		$gap_count = $rows > 0 ? max( 0, $max - $rows ) : 0;
		$valid = (int) ( $stats['distinct_old_ids'] ?? 0 ) === $rows
			&& (int) ( $stats['distinct_new_ids'] ?? 0 ) === $rows
			&& ( 0 === $rows || ( 1 === $min && $rows === $max && $sum === $expected_sum ) )
			&& 0 === $gap_count
			&& 0 === $unmapped_posts
			&& 0 === $stale_mapping_rows;

		return array(
			'valid'              => $valid,
			'mapping_rows'       => $rows,
			'distinct_old_ids'   => (int) ( $stats['distinct_old_ids'] ?? 0 ),
			'distinct_new_ids'   => (int) ( $stats['distinct_new_ids'] ?? 0 ),
			'min_new_id'         => $min,
			'max_new_id'         => $max,
			'gap_count'          => $gap_count,
			'unmapped_posts'     => $unmapped_posts,
			'stale_mapping_rows' => $stale_mapping_rows,
			'expected_next_id'   => $rows + 1,
		);
	}

	private function prune_old_snapshots( string $current_uuid ): void {
		$wpdb = $this->wpdb;
		$keep = array_merge( array( $current_uuid ), $this->generations->protected_mapping_uuids() );
		$executed = (string) get_option( 'shcd_tornado_dbm_last_executed_mapping_uuid', '' );
		if ( wp_is_uuid( $executed ) && $this->exists( $executed ) ) {
			$keep[] = $executed;
		}
		$keep = array_values( array_unique( $keep ) );
		$table = Identifier::quote( $this->tables->mappings() );
		$tornado_sql_table = Identifier::normalize( $table );
		$placeholders = implode( ',', array_fill( 0, count( $keep ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Generated SQL fragment is restricted to validated identifiers, fixed clauses, or a placeholder list; all external data values are passed to prepare().
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only validated identifiers, fixed internal clauses, or generated placeholder lists are interpolated; external values are passed to prepare().
				"DELETE FROM %i WHERE job_uuid NOT IN ({$placeholders})",
				array_merge( array( $tornado_sql_table ), $keep )
			)
		);
	}


	/**
	 * @return list<array<string, int>>
	 */
	public function preview_rows( string $job_uuid, int $limit = 50 ): array {
		$wpdb = $this->wpdb;
		$table = Identifier::quote( $this->tables->mappings() );
		$tornado_sql_table = Identifier::normalize( $table );
		$limit = max( 1, min( 500, $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT old_id, new_id FROM %i WHERE job_uuid = %s AND old_id <> new_id ORDER BY old_id ASC LIMIT %d",
				$tornado_sql_table,
				$job_uuid,
				$limit
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			static fn( array $row ): array => array( 'old_id' => (int) $row['old_id'], 'new_id' => (int) $row['new_id'] ),
			$rows
		);
	}

	public function exists( string $job_uuid ): bool {
		$wpdb = $this->wpdb;
		$table = Identifier::quote( $this->tables->mappings() );
		$tornado_sql_table = Identifier::normalize( $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE job_uuid = %s",
				$tornado_sql_table,
				$job_uuid
			)
		);

		return $count > 0;
	}
}
