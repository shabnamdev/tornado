<?php
/**
 * INFORMATION_SCHEMA discovery engine.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Discovery;

use Shcd\TornadoDatabaseMaintenance\Database\FingerprintService;
use Shcd\TornadoDatabaseMaintenance\Database\Identifier;
use Shcd\TornadoDatabaseMaintenance\Database\Tables;
use Shcd\TornadoDatabaseMaintenance\Jobs\JobRepository;
use Shcd\TornadoDatabaseMaintenance\Logging\Logger;
use RuntimeException;

final class SchemaInspector {
	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly Tables $tables,
		private readonly CandidateDetector $detector,
		private readonly FingerprintService $fingerprints,
		private readonly JobRepository $jobs,
		private readonly Logger $logger
	) {}

	/**
	 * @return array<string, mixed>
	 */
	public function discover(): array {
		$wpdb = $this->wpdb;
		$schema_fingerprint = $this->fingerprints->schema();
		$post_fingerprint   = $this->fingerprints->post_ids();
		$reused             = $this->reusable_snapshot( $schema_fingerprint, $post_fingerprint );
		if ( null !== $reused ) {
			return $reused;
		}

		$uuid               = $this->jobs->create( 'discovery', array(
			'schema_fingerprint'  => $schema_fingerprint,
			'post_id_fingerprint' => $post_fingerprint,
		) );
		$schema             = (string) DB_NAME;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$tables = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME, ENGINE, TABLE_COLLATION, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, CREATE_TIME, UPDATE_TIME
				 FROM INFORMATION_SCHEMA.TABLES
				 WHERE TABLE_SCHEMA = %s
				 ORDER BY TABLE_NAME',
				$schema
			),
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$columns = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, COLUMN_DEFAULT, IS_NULLABLE, DATA_TYPE, COLUMN_TYPE,
				 CHARACTER_MAXIMUM_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE, COLUMN_KEY, EXTRA, COLLATION_NAME
				 FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = %s
				 ORDER BY TABLE_NAME, ORDINAL_POSITION',
				$schema
			),
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$indexes = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, INDEX_TYPE
				 FROM INFORMATION_SCHEMA.STATISTICS
				 WHERE TABLE_SCHEMA = %s
				 ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX',
				$schema
			),
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$foreign_keys = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
				 FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
				 WHERE TABLE_SCHEMA = %s AND REFERENCED_TABLE_NAME IS NOT NULL
				 ORDER BY TABLE_NAME, COLUMN_NAME',
				$schema
			),
			ARRAY_A
		);

		if ( ! is_array( $tables ) || ! is_array( $columns ) ) {
			throw new RuntimeException( 'Unable to read INFORMATION_SCHEMA.' );
		}

		$internal = Identifier::quote( $this->tables->discoveries() );
		$tornado_sql_internal = Identifier::normalize( $internal );
		$now      = current_time( 'mysql', true );
		$counts   = array(
			'tables'                   => count( $tables ),
			'columns'                  => count( $columns ),
			'indexes'                  => is_array( $indexes ) ? count( $indexes ) : 0,
			'foreign_keys'             => is_array( $foreign_keys ) ? count( $foreign_keys ) : 0,
			'known_references'         => 0,
			'probable_references'      => 0,
			'embedded_containers'      => 0,
			'high_risk_unknowns'       => 0,
			'non_transactional_tables' => 0,
		);

		$table_map = array();
		foreach ( $tables as $table ) {
			$table_name               = (string) $table['TABLE_NAME'];
			$table_map[ $table_name ] = $table;
			$engine                   = strtoupper( (string) ( $table['ENGINE'] ?? '' ) );
			if ( '' !== $engine && 'INNODB' !== $engine ) {
				++$counts['non_transactional_tables'];
			}

		}

		$candidates       = array();
		$stored_candidates = 0;
		foreach ( $columns as $column ) {
			$class = $this->detector->classify( $column, $wpdb->posts );
			$type  = $class['type'];

			if ( 'known_scalar_reference' === $type || 'target_primary_key' === $type ) {
				++$counts['known_references'];
			} elseif ( in_array( $type, array( 'probable_scalar_reference', 'possible_scalar_reference', 'protected_order_reference' ), true ) ) {
				++$counts['probable_references'];
				$candidates[] = array_merge( $column, $class );
			} elseif ( 'embedded_reference_container' === $type ) {
				++$counts['embedded_containers'];
				$candidates[] = array_merge( $column, $class );
			}

			if ( $class['confidence'] >= 60 && ! in_array( $type, array( 'known_scalar_reference', 'target_primary_key' ), true ) ) {
				++$counts['high_risk_unknowns'];
			}

			$meta = array(
				'column' => $column,
				'reason' => $class['reason'],
				'table'  => $table_map[ (string) $column['TABLE_NAME'] ] ?? array(),
			);

			if ( $this->should_persist( $type, (int) $class['confidence'] ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
				$inserted = $wpdb->query(
					$wpdb->prepare(
						"INSERT INTO %i (job_uuid, table_name, column_name, data_type, column_type, key_type, candidate_type, confidence, meta_json, created_at)
						 VALUES (%s, %s, %s, %s, %s, %s, %s, %d, %s, %s)",
						$tornado_sql_internal,
						$uuid,
						(string) $column['TABLE_NAME'],
						(string) $column['COLUMN_NAME'],
						(string) $column['DATA_TYPE'],
						(string) $column['COLUMN_TYPE'],
						(string) $column['COLUMN_KEY'],
						$type,
						$class['confidence'],
						wp_json_encode( $meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
						$now
					)
				);
				if ( false === $inserted ) {
					throw new RuntimeException( 'Candidateهای Discovery در دیتابیس ذخیره نشدند.' );
				}
				++$stored_candidates;
			}
		}


		if ( ! hash_equals( $schema_fingerprint, $this->fingerprints->schema() ) || ! hash_equals( $post_fingerprint, $this->fingerprints->post_ids() ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM %i WHERE job_uuid = %s",
					$tornado_sql_internal,
					$uuid
				)
			);
			$this->jobs->fail( $uuid, 'The database changed during Discovery.', 'stale_snapshot' );
			throw new RuntimeException( 'The database changed during Discovery. Retry while writes are paused.' );
		}

		$result = array(
			'uuid'                => $uuid,
			'schema_fingerprint'  => $schema_fingerprint,
			'post_id_fingerprint' => $post_fingerprint,
			'counts'       => $counts,
			'tables'       => $tables,
			'foreign_keys' => $foreign_keys,
			'candidates'   => array_slice( $candidates, 0, 250 ),
			'risk'                => $this->calculate_risk( $counts ),
			'stored_candidates'   => $stored_candidates,
			'storage_mode'        => 'actionable_only',
		);

		update_option( 'shcd_tornado_dbm_last_discovery_uuid', $uuid, false );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM %i WHERE job_uuid <> %s",
				$tornado_sql_internal,
				$uuid
			)
		);
		$this->jobs->complete( $uuid, $result );
		$this->logger->log( 'info', 'Database discovery completed.', array( 'counts' => $counts, 'schema_fingerprint' => $schema_fingerprint ), $uuid );

		return $result;
	}

	/**
	 * Reuses the latest immutable schema snapshot when neither the schema nor the
	 * Post ID set has changed. Repeated Wizard clicks therefore do not create a
	 * new Job or hundreds of duplicate rows.
	 *
	 * @return array<string,mixed>|null
	 */
	private function reusable_snapshot( string $schema_fingerprint, string $post_fingerprint ): ?array {
		$wpdb = $this->wpdb;
		$uuid = (string) get_option( 'shcd_tornado_dbm_last_discovery_uuid', '' );
		if ( ! wp_is_uuid( $uuid ) ) {
			return null;
		}

		$job = $this->jobs->find( $uuid );
		if ( ! is_array( $job ) || 'discovery' !== (string) ( $job['type'] ?? '' ) || 'completed' !== (string) ( $job['status'] ?? '' ) ) {
			return null;
		}
		$payload = is_array( $job['payload'] ?? null ) ? $job['payload'] : array();
		if ( ! hash_equals( $schema_fingerprint, (string) ( $payload['schema_fingerprint'] ?? '' ) ) || ! hash_equals( $post_fingerprint, (string) ( $payload['post_id_fingerprint'] ?? '' ) ) ) {
			return null;
		}

		$result = is_array( $job['result'] ?? null ) ? $job['result'] : array();
		$counts = is_array( $result['counts'] ?? null ) ? $result['counts'] : array();
		$expected = (int) ( $counts['probable_references'] ?? 0 ) + (int) ( $counts['embedded_containers'] ?? 0 );
		$table = Identifier::quote( $this->tables->discoveries() );
		$tornado_sql_table = Identifier::normalize( $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$stored = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE job_uuid = %s",
				$tornado_sql_table,
				$uuid
			)
		);
		if ( $expected > 0 && $stored < $expected ) {
			return null;
		}

		$result['uuid']              = $uuid;
		$result['reused_snapshot']   = true;
		$result['stored_candidates'] = $stored;
		$result['storage_mode']      = 'actionable_only';
		return $result;
	}

	private function should_persist( string $type, int $confidence ): bool {
		if ( 'embedded_reference_container' === $type ) {
			return true;
		}
		return $confidence >= 60 && ! in_array( $type, array( 'target_primary_key', 'known_scalar_reference', 'table' ), true );
	}


	/**
	 * @param array<string, int> $counts Discovery counts.
	 * @return array{level:string,score:int,reasons:list<string>}
	 */
	private function calculate_risk( array $counts ): array {
		$score   = 10;
		$reasons = array();

		if ( $counts['high_risk_unknowns'] > 0 ) {
			$score     += min( 60, $counts['high_risk_unknowns'] * 5 );
			$reasons[] = 'High-confidence unknown references require an adapter or explicit exclusion.';
		}

		if ( $counts['embedded_containers'] > 0 ) {
			$score     += min( 20, $counts['embedded_containers'] );
			$reasons[] = 'Serialized, JSON, block, or custom text containers may include embedded IDs.';
		}

		if ( $counts['non_transactional_tables'] > 0 ) {
			$score     += 20;
			$reasons[] = 'One or more database tables do not use InnoDB.';
		}

		$score = min( 100, $score );
		$level = $score >= 80 ? 'blocked' : ( $score >= 60 ? 'high' : ( $score >= 35 ? 'medium' : 'low' ) );

		return array( 'level' => $level, 'score' => $score, 'reasons' => $reasons );
	}
}
