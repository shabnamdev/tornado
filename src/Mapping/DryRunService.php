<?php
/**
 * Read-only mapping impact simulation.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Mapping;

use Shcd\TornadoDatabaseMaintenance\Database\Identifier;
use Shcd\TornadoDatabaseMaintenance\Database\Tables;
use Shcd\TornadoDatabaseMaintenance\Jobs\JobRepository;
use InvalidArgumentException;

final class DryRunService {
	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly Tables $tables,
		private readonly MappingBuilder $builder,
		private readonly JobRepository $jobs
	) {}

	/**
	 * @return array<string, mixed>
	 */
	public function run( string $mapping_uuid ): array {
		$wpdb = $this->wpdb;
		if ( ! wp_is_uuid( $mapping_uuid ) || ! $this->builder->exists( $mapping_uuid ) ) {
			throw new InvalidArgumentException( 'Invalid mapping UUID.' );
		}

		$job_uuid      = $this->jobs->create( 'dry_run', array( 'mapping_uuid' => $mapping_uuid ) );
		$mapping_table = Identifier::quote( $this->tables->mappings() );
		$tornado_sql_mapping_table = Identifier::normalize( $mapping_table );
		$references    = $this->core_references();
		$impact        = array();
		$total_changes = 0;

		foreach ( $references as $reference ) {
			$table  = Identifier::quote( $reference['table'] );
			$tornado_sql_table = Identifier::normalize( $table );
			$column = Identifier::quote( $reference['column'] );
			$tornado_sql_column = Identifier::normalize( $column );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$count  = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i AS r INNER JOIN %i AS m ON r.%i = m.old_id WHERE m.job_uuid = %s AND m.old_id <> m.new_id",
					$tornado_sql_table,
					$tornado_sql_mapping_table,
					$tornado_sql_column,
					$mapping_uuid
				)
			);
			$impact[]      = array_merge( $reference, array( 'affected_rows' => $count ) );
			$total_changes += $count;
		}

		$posts_identifier = Identifier::normalize( $wpdb->posts );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$post_types       = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT post_type, COUNT(*) AS total FROM %i GROUP BY post_type ORDER BY total DESC',
				$posts_identifier
			),
			ARRAY_A
		);

		$discovery_uuid = (string) get_option( 'shcd_tornado_dbm_last_discovery_uuid', '' );
		$discovery      = Identifier::quote( $this->tables->discoveries() );
		$tornado_sql_discovery = Identifier::normalize( $discovery );
		$unknowns       = 0;
		$embedded       = 0;

		if ( wp_is_uuid( $discovery_uuid ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$unknowns = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i WHERE job_uuid = %s AND confidence >= 60 AND candidate_type NOT IN ('target_primary_key','known_scalar_reference','table')",
					$tornado_sql_discovery,
					$discovery_uuid
				)
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$embedded = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i WHERE job_uuid = %s AND candidate_type = 'embedded_reference_container'",
					$tornado_sql_discovery,
					$discovery_uuid
				)
			);
		}

		$mapping_job = $this->jobs->find( $mapping_uuid );
		$total_posts = (int) ( $mapping_job['result']['total_posts'] ?? 0 );
		$gapless     = $this->builder->gapless_status( $mapping_uuid );
		$estimated   = $this->estimate_duration( $total_posts, $total_changes, $embedded );
		$risk_score  = min( 100, 15 + ( $unknowns * 10 ) + min( 25, $embedded ) );
		$risk_level  = $unknowns > 0 ? 'blocked' : ( $risk_score >= 60 ? 'high' : ( $risk_score >= 35 ? 'medium' : 'low' ) );

		$result = array(
			'uuid'                      => $job_uuid,
			'mapping_uuid'              => $mapping_uuid,
			'post_types'                => is_array( $post_types ) ? $post_types : array(),
			'references'                => $impact,
			'total_reference_updates'   => $total_changes,
			'unknown_high_risk_columns' => $unknowns,
			'embedded_containers'       => $embedded,
			'gapless_reindex'           => array(
				'enabled'          => true,
				'valid_mapping'    => (bool) ( $gapless['valid'] ?? false ),
				'current_gap_count'=> (int) ( $mapping_job['result']['old_gap_count'] ?? 0 ),
				'final_gap_count'  => 0,
				'final_min_id'     => $total_posts > 0 ? 1 : 0,
				'final_max_id'     => $total_posts,
				'next_id'          => $total_posts + 1,
				'attachment_count' => (int) ( $mapping_job['result']['attachment_count'] ?? 0 ),
			),
			'estimated_duration'        => $estimated,
			'risk'                      => array( 'score' => $risk_score, 'level' => $risk_level ),
			'executable'                => 0 === $unknowns && (bool) ( $gapless['valid'] ?? false ),
		);

		$this->jobs->complete( $job_uuid, $result );
		return $result;
	}

	/**
	 * @return list<array{table:string,column:string,label:string}>
	 */
	public function core_references(): array {
		$wpdb = $this->wpdb;
		return array(
			array( 'table' => $wpdb->posts, 'column' => 'post_parent', 'label' => 'Post parent' ),
			array( 'table' => $wpdb->postmeta, 'column' => 'post_id', 'label' => 'Post meta' ),
			array( 'table' => $wpdb->comments, 'column' => 'comment_post_ID', 'label' => 'Comments' ),
			array( 'table' => $wpdb->term_relationships, 'column' => 'object_id', 'label' => 'Term relationships' ),
		);
	}

	/**
	 * @return array{minimum_seconds:int,maximum_seconds:int,confidence:string}
	 */
	private function estimate_duration( int $posts, int $references, int $embedded ): array {
		$base = max( 2, (int) ceil( $posts / 1500 ) + (int) ceil( $references / 5000 ) + ( $embedded * 2 ) );
		return array(
			'minimum_seconds' => $base,
			'maximum_seconds' => max( $base + 5, $base * 3 ),
			'confidence'      => $embedded > 0 ? 'low' : 'medium',
		);
	}
}
