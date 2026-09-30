<?php
/**
 * Keeps Tornado's own operational tables compact and observable.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Core;

use Shcd\TornadoDatabaseMaintenance\Database\Identifier;
use Shcd\TornadoDatabaseMaintenance\Database\Tables;
use Shcd\TornadoDatabaseMaintenance\Reindex\GenerationRepository;

final class OperationalDataService {
	private const STORAGE_REVISION = '2026-08-02-repeatable-v2';

	/** @var list<string> */
	private const DISCOVERY_DISCARD_TYPES = array(
		'none',
		'numeric_unknown',
		'table',
		'target_primary_key',
		'known_scalar_reference',
	);

	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly Tables $tables,
		private readonly GenerationRepository $generations
	) {}

	/**
	 * Runs the storage migration once. It is intentionally restricted to admin/CLI
	 * requests so a public page request never pays the compaction cost.
	 */
	public function maybe_compact(): void {
		if ( (string) get_option( 'shcd_tornado_dbm_storage_revision', '' ) === self::STORAGE_REVISION ) {
			return;
		}

		$is_cli = defined( 'WP_CLI' ) && WP_CLI;
		if ( ! is_admin() && ! $is_cli ) {
			return;
		}

		$this->compact();
		update_option( 'shcd_tornado_dbm_storage_revision', self::STORAGE_REVISION, false );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function footprint(): array {
		$wpdb = $this->wpdb;
		$names = array(
			'jobs'        => $this->tables->jobs(),
			'mappings'    => $this->tables->mappings(),
			'discoveries' => $this->tables->discoveries(),
			'backups'     => $this->tables->backups(),
			'logs'        => $this->tables->logs(),
			'revision_archive' => $this->tables->revision_archive(),
		);
		$rows       = array();
		$total_rows = 0;
		$total_size = 0;

		foreach ( $names as $key => $name ) {
			$table = Identifier::quote( $name );
			$tornado_sql_table = Identifier::normalize( $table );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$count = (int) $wpdb->get_var(
				 $wpdb->prepare( "SELECT COUNT(*) FROM %i", $tornado_sql_table ) 
			); 
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$size  = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COALESCE(DATA_LENGTH,0) + COALESCE(INDEX_LENGTH,0) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
					(string) DB_NAME,
					$name
				)
			);
			$rows[ $key ] = array(
				'table'      => $name,
				'rows'       => $count,
				'size_bytes' => $size,
			);
			$total_rows += $count;
			$total_size += $size;
		}

		$discoveries = Identifier::quote( $this->tables->discoveries() );
		$tornado_sql_discoveries = Identifier::normalize( $discoveries );
		$mappings    = Identifier::quote( $this->tables->mappings() );
		$tornado_sql_mappings = Identifier::normalize( $mappings );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$rows['discoveries']['snapshots'] = (int) $wpdb->get_var(
			 $wpdb->prepare( "SELECT COUNT(DISTINCT job_uuid) FROM %i", $tornado_sql_discoveries ) 
		); 
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$rows['discoveries']['actionable_rows'] = (int) $wpdb->get_var(
			 $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE candidate_type NOT IN ('none','numeric_unknown','table','target_primary_key','known_scalar_reference')", $tornado_sql_discoveries ) 
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$rows['mappings']['snapshots'] = (int) $wpdb->get_var(
			 $wpdb->prepare( "SELECT COUNT(DISTINCT job_uuid) FROM %i", $tornado_sql_mappings ) 
		); 

		return array(
			'site_posts'  => $this->post_footprint(),
			'tables'      => $rows,
			'total_rows'  => $total_rows,
			'total_bytes' => $total_size,
			'policy'      => array(
				'discovery_snapshots' => 1,
				'mapping_snapshots'   => 4,
				'discovery_storage'   => 'actionable_only',
			),
		);
	}


	/**
	 * Explains the wp_posts footprint without assuming that MAX(ID) equals the
	 * number of rows. It deliberately reports candidates instead of deleting
	 * custom post types automatically.
	 *
	 * @return array<string,mixed>
	 */
	private function post_footprint(): array {
		$wpdb = $this->wpdb;
		$posts = Identifier::quote( $wpdb->posts );
		$tornado_sql_posts = Identifier::normalize( $posts );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$base  = $wpdb->get_row(
			 $wpdb->prepare( "SELECT COUNT(*) AS row_count, COALESCE(MIN(ID),0) AS min_id, COALESCE(MAX(ID),0) AS max_id FROM %i", $tornado_sql_posts ) ,
			ARRAY_A
		);
		$base = is_array( $base ) ? $base : array();
		$row_count = (int) ( $base['row_count'] ?? 0 );
		$min_id    = (int) ( $base['min_id'] ?? 0 );
		$max_id    = (int) ( $base['max_id'] ?? 0 );
		$span      = $row_count > 0 ? max( 0, $max_id - $min_id + 1 ) : 0;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$post_types = $wpdb->get_results(
			 $wpdb->prepare( "SELECT post_type, post_status, COUNT(*) AS rows_count FROM %i GROUP BY post_type, post_status ORDER BY rows_count DESC, post_type ASC", $tornado_sql_posts ) ,
			ARRAY_A
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$custom_trash = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT post_type, COUNT(*) AS rows_count FROM %i WHERE post_type LIKE %s OR post_type LIKE %s GROUP BY post_type ORDER BY rows_count DESC',
				$tornado_sql_posts,
				'%' . $wpdb->esc_like( 'trash' ) . '%',
				'%' . $wpdb->esc_like( 'recycle' ) . '%'
			),
			ARRAY_A
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$exact_revisions = (int) $wpdb->get_var(
			 $wpdb->prepare( "SELECT COUNT(*) FROM %i r INNER JOIN %i p ON p.ID = r.post_parent WHERE r.post_type = 'revision' AND r.post_title = p.post_title AND r.post_excerpt = p.post_excerpt AND r.post_content = p.post_content", $tornado_sql_posts, $tornado_sql_posts ) 
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$next = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(AUTO_INCREMENT,1) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				(string) DB_NAME,
				$wpdb->posts
			)
		);

		return array(
			'table'               => $wpdb->posts,
			'row_count'           => $row_count,
			'min_id'              => $min_id,
			'max_id'              => $max_id,
			'id_gaps'             => max( 0, $span - $row_count ),
			'next_auto_increment' => max( 1, $next ),
			'post_types'          => is_array( $post_types ) ? $post_types : array(),
			'cleanup_candidates'  => array(
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
				'revisions'             => (int) $wpdb->get_var(
					 $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_type = 'revision'", $tornado_sql_posts ) 
				), 
				'exact_parent_revisions' => $exact_revisions,
				'auto_drafts'           => (int) $wpdb->get_var(
					 $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_status = 'auto-draft'", $tornado_sql_posts ) 
				), 
				'trash_status'          => (int) $wpdb->get_var(
					 $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_status = 'trash'", $tornado_sql_posts ) 
				), 
				'custom_trash_types'    => is_array( $custom_trash ) ? $custom_trash : array(),
			),
			'note'                => 'MAX(ID) و AUTO_INCREMENT تعداد واقعی رکوردها نیستند؛ حذف Post Typeهای اختصاصی فقط پس از Preview و شناسایی مالک آن‌ها مجاز است.',
		);
	}

	/**
	 * Removes obsolete snapshots while retaining the data required for the active
	 * wizard and for recovery after the last completed Reindex.
	 *
	 * @return array<string,mixed>
	 */
	public function compact(): array {
		$wpdb = $this->wpdb;
		$before      = $this->footprint();
		$discoveries = Identifier::quote( $this->tables->discoveries() );
		$tornado_sql_discoveries = Identifier::normalize( $discoveries );
		$mappings    = Identifier::quote( $this->tables->mappings() );
		$tornado_sql_mappings = Identifier::normalize( $mappings );
		$deleted     = array(
			'discoveries_old_snapshots' => 0,
			'discoveries_non_actionable' => 0,
			'mappings_old_snapshots'     => 0,
		);

		$discovery_uuid = $this->resolve_latest_uuid( 'discovery', (string) get_option( 'shcd_tornado_dbm_last_discovery_uuid', '' ), $discoveries );
		if ( null !== $discovery_uuid ) {
			$deleted['discoveries_old_snapshots'] = max(
				0,
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
				(int) $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM %i WHERE job_uuid <> %s",
						$tornado_sql_discoveries,
						$discovery_uuid
					)
				)
			);

			$placeholders = implode( ',', array_fill( 0, count( self::DISCOVERY_DISCARD_TYPES ), '%s' ) );
			$deleted['discoveries_non_actionable'] = max(
				0,
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
				(int) $wpdb->query(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Generated SQL fragment is restricted to validated identifiers, fixed clauses, or a placeholder list; all external data values are passed to prepare().
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only validated identifiers, fixed internal clauses, or generated placeholder lists are interpolated; external values are passed to prepare().
						"DELETE FROM %i WHERE job_uuid = %s AND candidate_type IN ({$placeholders})",
						$tornado_sql_discoveries,
						array_merge( array( $discovery_uuid ), self::DISCOVERY_DISCARD_TYPES )
					)
				)
			);
			update_option( 'shcd_tornado_dbm_last_discovery_uuid', $discovery_uuid, false );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$deleted['discoveries_old_snapshots'] = max( 0, (int) $wpdb->query(
				 $wpdb->prepare( "DELETE FROM %i", $tornado_sql_discoveries ) 
			) ); 
		}

		$keep_mappings = $this->generations->protected_mapping_uuids();
		$current_mapping = $this->resolve_latest_uuid( 'mapping', (string) get_option( 'shcd_tornado_dbm_last_mapping_uuid', '' ), $mappings );
		if ( null !== $current_mapping ) {
			$keep_mappings[] = $current_mapping;
			update_option( 'shcd_tornado_dbm_last_mapping_uuid', $current_mapping, false );
		}

		$executed_mapping = $this->resolve_executed_mapping_uuid( $mappings );
		if ( null !== $executed_mapping ) {
			$keep_mappings[] = $executed_mapping;
			update_option( 'shcd_tornado_dbm_last_executed_mapping_uuid', $executed_mapping, false );
		}
		$keep_mappings = array_values( array_unique( $keep_mappings ) );

		if ( empty( $keep_mappings ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$deleted['mappings_old_snapshots'] = max( 0, (int) $wpdb->query(
				 $wpdb->prepare( "DELETE FROM %i", $tornado_sql_mappings ) 
			) ); 
		} else {
			$placeholders = implode( ',', array_fill( 0, count( $keep_mappings ), '%s' ) );
			$deleted['mappings_old_snapshots'] = max(
				0,
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
				(int) $wpdb->query(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Generated SQL fragment is restricted to validated identifiers, fixed clauses, or a placeholder list; all external data values are passed to prepare().
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only validated identifiers, fixed internal clauses, or generated placeholder lists are interpolated; external values are passed to prepare().
						"DELETE FROM %i WHERE job_uuid NOT IN ({$placeholders})",
						array_merge( array( $tornado_sql_mappings ), $keep_mappings )
					)
				)
			);
		}

		$this->normalize_auto_increment( $this->tables->discoveries() );
		$this->normalize_auto_increment( $this->tables->mappings() );

		$after = $this->footprint();
		return array(
			'before'  => $before,
			'after'   => $after,
			'deleted' => $deleted,
			'kept'    => array(
				'discovery_uuid' => $discovery_uuid,
				'mapping_uuids'  => $keep_mappings,
			),
		);
	}

	private function resolve_latest_uuid( string $type, string $preferred, string $data_table ): ?string {
		$wpdb                   = $this->wpdb;
		$tornado_sql_data_table = Identifier::normalize( $data_table );
		if ( wp_is_uuid( $preferred ) && $this->uuid_has_rows( $data_table, $preferred ) ) {
			return $preferred;
		}

		$jobs = Identifier::quote( $this->tables->jobs() );
		$tornado_sql_jobs = Identifier::normalize( $jobs );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$uuid = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT j.uuid FROM %i j INNER JOIN %i d ON d.job_uuid = j.uuid WHERE j.type = %s AND j.status = 'completed' GROUP BY j.uuid, j.id ORDER BY j.id DESC LIMIT 1",
				$tornado_sql_jobs,
				$tornado_sql_data_table,
				$type
			)
		);
		return wp_is_uuid( $uuid ) ? $uuid : null;
	}

	private function resolve_executed_mapping_uuid( string $mappings ): ?string {
		$wpdb = $this->wpdb;
		$preferred = (string) get_option( 'shcd_tornado_dbm_last_executed_mapping_uuid', '' );
		if ( wp_is_uuid( $preferred ) && $this->uuid_has_rows( $mappings, $preferred ) ) {
			return $preferred;
		}

		$jobs = Identifier::quote( $this->tables->jobs() );
		$tornado_sql_jobs = Identifier::normalize( $jobs );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$rows = $wpdb->get_results(
			 $wpdb->prepare( "SELECT payload, result FROM %i WHERE type = 'reindex' AND status = 'completed' ORDER BY id DESC LIMIT 10", $tornado_sql_jobs ) ,
			ARRAY_A
		);
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			foreach ( array( 'result', 'payload' ) as $field ) {
				$data = json_decode( (string) ( $row[ $field ] ?? '' ), true );
				$uuid = is_array( $data ) ? (string) ( $data['mapping_uuid'] ?? '' ) : '';
				if ( wp_is_uuid( $uuid ) && $this->uuid_has_rows( $mappings, $uuid ) ) {
					return $uuid;
				}
			}
		}
		return null;
	}

	private function uuid_has_rows( string $table, string $uuid ): bool {
		$wpdb             = $this->wpdb;
		$tornado_sql_table = Identifier::normalize( $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE job_uuid = %s",
				$tornado_sql_table,
				$uuid
			)
		);
		return $count > 0;
	}

	private function normalize_auto_increment( string $table_name ): void {
		$wpdb = $this->wpdb;
		$table = Identifier::quote( $table_name );
		$tornado_sql_table = Identifier::normalize( $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$next  = max( 1, (int) $wpdb->get_var(
			 $wpdb->prepare( "SELECT COALESCE(MAX(id),0) + 1 FROM %i", $tornado_sql_table ) 
		) ); 
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$wpdb->query(
			 $wpdb->prepare(
			 	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema mutation is an explicit, capability-gated maintenance operation and is never run as a cached read.
			 	"ALTER TABLE %i AUTO_INCREMENT = %d",
			 	$tornado_sql_table,
			 	$next
			 ) 
		); 
	}
}
