<?php
/**
 * Referential and mapping integrity checks.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Integrity;

use Shcd\TornadoDatabaseMaintenance\Database\Identifier;
use Shcd\TornadoDatabaseMaintenance\Database\Tables;
use Shcd\TornadoDatabaseMaintenance\Jobs\JobRepository;
use Shcd\TornadoDatabaseMaintenance\Reindex\ReferenceRegistry;

final class IntegrityChecker {
	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly Tables $tables,
		private readonly JobRepository $jobs,
		private readonly ReferenceRegistry $references
	) {}

	/** @return array<string,mixed> */
	public function check( ?string $mapping_uuid = null ): array {
		$wpdb = $this->wpdb;
		$job_uuid = $this->jobs->create( 'integrity', array( 'mapping_uuid' => $mapping_uuid ) );
		$posts = Identifier::normalize( $wpdb->posts );
		$postmeta = Identifier::normalize( $wpdb->postmeta );
		$comments = Identifier::normalize( $wpdb->comments );
		$commentmeta = Identifier::normalize( $wpdb->commentmeta );
		$term_relationships = Identifier::normalize( $wpdb->term_relationships );
		$termmeta = Identifier::normalize( $wpdb->termmeta );
		$terms = Identifier::normalize( $wpdb->terms );
		$term_taxonomy = Identifier::normalize( $wpdb->term_taxonomy );
		$usermeta = Identifier::normalize( $wpdb->usermeta );
		$users = Identifier::normalize( $wpdb->users );

		$checks = array(
			'orphan_postmeta' => $this->count_query( 'orphan_postmeta', array( $postmeta, $posts ) ),
			'orphan_comments' => $this->count_query( 'orphan_comments', array( $comments, $posts ) ),
			'orphan_commentmeta' => $this->count_query( 'orphan_commentmeta', array( $commentmeta, $comments ) ),
			'orphan_term_relationships' => $this->count_query( 'orphan_term_relationships', array( $term_relationships, $posts ) ),
			'orphan_termmeta' => $this->count_query( 'orphan_termmeta', array( $termmeta, $terms ) ),
			'orphan_term_taxonomy' => $this->count_query( 'orphan_term_taxonomy', array( $term_taxonomy, $terms ) ),
			'orphan_usermeta' => $this->count_query( 'orphan_usermeta', array( $usermeta, $users ) ),
			'orphan_revisions' => $this->count_query( 'orphan_revisions', array( $posts, $posts ) ),
			'orphan_attachments' => $this->count_query( 'orphan_attachments', array( $posts, $posts ) ),
			'orphan_post_parents' => $this->count_query( 'orphan_post_parents', array( $posts, $posts ) ),
		);

		$registered = array();
		$core_keys  = array(
			$wpdb->posts . '.post_parent',
			$wpdb->postmeta . '.post_id',
			$wpdb->comments . '.comment_post_ID',
			$wpdb->term_relationships . '.object_id',
		);
		foreach ( $this->references->scalar_columns() as $reference ) {
			$key = $reference['table'] . '.' . $reference['column'];
			if ( in_array( $key, $core_keys, true ) ) {
				continue;
			}
			$table  = Identifier::normalize( $reference['table'] );
			$column = Identifier::normalize( $reference['column'] );
			$registered[ $key ] = array(
				'label'  => $reference['label'],
				'issues' => $this->count_query( 'registered_reference', array( $table, $posts, $column, $column ) ),
			);
		}

		$woocommerce = $this->woocommerce_checks();
		$mapping     = array();
		if ( is_string( $mapping_uuid ) && wp_is_uuid( $mapping_uuid ) ) {
			$mappings = Identifier::normalize( $this->tables->mappings() );
			$mapping  = array(
				'mapping_rows' => $this->count_query( 'mapping_rows', array( $mappings, $mapping_uuid ) ),
				'duplicate_old_ids' => $this->count_query( 'duplicate_old_ids', array( $mappings, $mapping_uuid ) ),
				'duplicate_new_ids' => $this->count_query( 'duplicate_new_ids', array( $mappings, $mapping_uuid ) ),
				'duplicate_temp_ids' => $this->count_query( 'duplicate_temp_ids', array( $mappings, $mapping_uuid ) ),
			);
		}

		$registered_issues = array_sum( array_map( static fn( array $row ): int => (int) $row['issues'], $registered ) );
		$total_issues = array_sum( array_map( 'intval', $checks ) ) + array_sum( array_map( 'intval', $woocommerce ) ) + $registered_issues
			+ (int) ( $mapping['duplicate_old_ids'] ?? 0 ) + (int) ( $mapping['duplicate_new_ids'] ?? 0 ) + (int) ( $mapping['duplicate_temp_ids'] ?? 0 );

		$blocking_check_keys = array(
			'orphan_postmeta',
			'orphan_comments',
			'orphan_term_relationships',
			'orphan_revisions',
			'orphan_attachments',
			'orphan_post_parents',
		);
		$blocking_checks = 0;
		foreach ( $blocking_check_keys as $key ) {
			$blocking_checks += (int) ( $checks[ $key ] ?? 0 );
		}

		$blocking_woocommerce = 0;
		foreach ( $woocommerce as $key => $value ) {
			if ( 'orphan_order_itemmeta' !== $key ) {
				$blocking_woocommerce += (int) $value;
			}
		}
		$mapping_issues = (int) ( $mapping['duplicate_old_ids'] ?? 0 ) + (int) ( $mapping['duplicate_new_ids'] ?? 0 ) + (int) ( $mapping['duplicate_temp_ids'] ?? 0 );
		$reindex_blocking_issues = $blocking_checks + $blocking_woocommerce + $registered_issues + $mapping_issues;

		$result = array(
			'uuid'                    => $job_uuid,
			'checks'                  => $checks,
			'registered_references'   => $registered,
			'woocommerce'             => $woocommerce,
			'mapping'                 => $mapping,
			'total_issues'            => $total_issues,
			'reindex_blocking_issues' => $reindex_blocking_issues,
			'advisory_issues'         => max( 0, $total_issues - $reindex_blocking_issues ),
			'status'                  => 0 === $total_issues ? 'passed' : ( 0 === $reindex_blocking_issues ? 'advisory_only' : 'attention_required' ),
		);
		$this->jobs->complete( $job_uuid, $result );
		return $result;
	}

	/** @return array<string,int> */
	private function woocommerce_checks(): array {
		$wpdb = $this->wpdb;
		$result = array();
		$order_items = $wpdb->prefix . 'woocommerce_order_items';
		$order_itemmeta = $wpdb->prefix . 'woocommerce_order_itemmeta';
		if ( $this->table_exists( $order_items ) && $this->table_exists( $order_itemmeta ) ) {
			$result['orphan_order_itemmeta'] = $this->count_query(
				'orphan_order_itemmeta',
				array( Identifier::normalize( $order_itemmeta ), Identifier::normalize( $order_items ) )
			);
		}
		$lookup_tables = array(
			$wpdb->prefix . 'wc_product_meta_lookup' => 'product_id',
			$wpdb->prefix . 'wc_product_attributes_lookup' => 'product_or_parent_id',
		);
		$posts = Identifier::normalize( $wpdb->posts );
		foreach ( $lookup_tables as $table_name => $column_name ) {
			if ( ! $this->table_exists( $table_name ) ) {
				continue;
			}
			$table = Identifier::normalize( $table_name );
			$column = Identifier::normalize( $column_name );
			$result[ 'orphan_' . sanitize_key( $table_name ) ] = $this->count_query(
				'orphan_wc_lookup',
				array( $table, $posts, $column, $column )
			);
		}
		return $result;
	}

	private function table_exists( string $table ): bool {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/** @param list<int|float|string> $args */
	private function count_query( string $query_id, array $args ): int {
		$wpdb = $this->wpdb;

		// Each SQL template is an internal literal. Runtime table/column identifiers and values are always passed through wpdb::prepare().
		switch ( $query_id ) {
			case 'orphan_postmeta':
				return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i pm LEFT JOIN %i p ON p.ID = pm.post_id WHERE p.ID IS NULL', $args[0], $args[1] ) );
			case 'orphan_comments':
				return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i c LEFT JOIN %i p ON p.ID = c.comment_post_ID WHERE c.comment_post_ID <> 0 AND p.ID IS NULL', $args[0], $args[1] ) );
			case 'orphan_commentmeta':
				return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i cm LEFT JOIN %i c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL', $args[0], $args[1] ) );
			case 'orphan_term_relationships':
				return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i tr LEFT JOIN %i p ON p.ID = tr.object_id WHERE p.ID IS NULL', $args[0], $args[1] ) );
			case 'orphan_termmeta':
				return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i tm LEFT JOIN %i t ON t.term_id = tm.term_id WHERE t.term_id IS NULL', $args[0], $args[1] ) );
			case 'orphan_term_taxonomy':
				return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i tt LEFT JOIN %i t ON t.term_id = tt.term_id WHERE t.term_id IS NULL', $args[0], $args[1] ) );
			case 'orphan_usermeta':
				return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i um LEFT JOIN %i u ON u.ID = um.user_id WHERE u.ID IS NULL', $args[0], $args[1] ) );
			case 'orphan_revisions':
				return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i r LEFT JOIN %i p ON p.ID = r.post_parent WHERE r.post_type = 'revision' AND p.ID IS NULL", $args[0], $args[1] ) );
			case 'orphan_attachments':
				return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i a LEFT JOIN %i p ON p.ID = a.post_parent WHERE a.post_type = 'attachment' AND a.post_parent <> 0 AND p.ID IS NULL", $args[0], $args[1] ) );
			case 'orphan_post_parents':
				return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i c LEFT JOIN %i p ON p.ID = c.post_parent WHERE c.post_parent <> 0 AND p.ID IS NULL AND c.post_type NOT IN ('revision','attachment')", $args[0], $args[1] ) );
			case 'registered_reference':
			case 'orphan_wc_lookup':
				return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i r LEFT JOIN %i p ON p.ID = r.%i WHERE r.%i <> 0 AND p.ID IS NULL', $args[0], $args[1], $args[2], $args[3] ) );
			case 'orphan_order_itemmeta':
				return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i m LEFT JOIN %i i ON i.order_item_id = m.order_item_id WHERE i.order_item_id IS NULL', $args[0], $args[1] ) );
			case 'mapping_rows':
				return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE job_uuid = %s', $args[0], $args[1] ) );
			case 'duplicate_old_ids':
				return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM (SELECT old_id FROM %i WHERE job_uuid = %s GROUP BY old_id HAVING COUNT(*) > 1) x', $args[0], $args[1] ) );
			case 'duplicate_new_ids':
				return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM (SELECT new_id FROM %i WHERE job_uuid = %s GROUP BY new_id HAVING COUNT(*) > 1) x', $args[0], $args[1] ) );
			case 'duplicate_temp_ids':
				return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM (SELECT temp_id FROM %i WHERE job_uuid = %s GROUP BY temp_id HAVING COUNT(*) > 1) x', $args[0], $args[1] ) );
		}

		return 0;
	}
}
