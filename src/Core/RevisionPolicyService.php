<?php
/**
 * Runtime revision retention policy.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Core;

use Shcd\TornadoDatabaseMaintenance\Database\Identifier;

final class RevisionPolicyService {
	private const ENFORCE_HOOK = 'shcd_tornado_dbm_enforce_revision_limit';
	private const POLICY_BUILD = '2026.08.03.2';

	/** @var array<string,bool> */
	private array $registered_post_type_filters = array();

	public function register(): void {
		add_filter( 'wp_revisions_to_keep', array( $this, 'filter_limit' ), PHP_INT_MAX, 2 );
		add_action( 'registered_post_type', array( $this, 'register_post_type_filter' ), PHP_INT_MAX, 2 );
		add_action( 'init', array( $this, 'register_existing_post_type_filters' ), PHP_INT_MAX );
		add_action( 'wp_after_insert_post', array( $this, 'queue_post' ), PHP_INT_MAX, 4 );
		add_action( self::ENFORCE_HOOK, array( $this, 'enforce_batch' ) );
		add_action( 'update_option_shcd_tornado_dbm_settings', array( $this, 'settings_updated' ), 10, 3 );
		add_action( 'add_option_shcd_tornado_dbm_settings', array( $this, 'settings_added' ), 10, 2 );
		$this->schedule_initial_enforcement();
	}


	public function register_existing_post_type_filters(): void {
		$post_types = get_post_types( array(), 'objects' );
		foreach ( is_array( $post_types ) ? $post_types : array() as $post_type => $object ) {
			if ( $object instanceof \WP_Post_Type ) {
				$this->register_post_type_filter( (string) $post_type, $object );
			}
		}
	}

	public function register_post_type_filter( string $post_type, \WP_Post_Type $object ): void {
		$post_type = sanitize_key( $post_type );
		if ( '' === $post_type || isset( $this->registered_post_type_filters[ $post_type ] ) || ! post_type_supports( $post_type, 'revisions' ) ) {
			return;
		}
		add_filter( 'wp_' . $post_type . '_revisions_to_keep', array( $this, 'filter_limit' ), PHP_INT_MAX, 2 );
		$this->registered_post_type_filters[ $post_type ] = true;
	}

	public function filter_limit( int $current, \WP_Post $post ): int {
		$mode = (string) Settings::get( 'revision_storage_mode', 'wordpress' );
		if ( in_array( $mode, array( 'archive', 'off' ), true ) ) {
			return 0;
		}
		$limit = Settings::get( 'revision_limit', 5 );
		if ( 'wordpress' !== Settings::get( 'revision_storage_mode', 'wordpress' ) || 'unlimited' === $limit ) {
			return $current;
		}

		return max( 0, (int) $limit );
	}

	public function queue_post( int $post_id, \WP_Post $post, bool $update, ?\WP_Post $post_before ): void {
		if ( 'wordpress' !== Settings::get( 'revision_storage_mode', 'wordpress' ) ) {
			return;
		}
		if ( $post_id <= 0 || 'revision' === $post->post_type || ! post_type_supports( $post->post_type, 'revisions' ) || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		$this->enforce_parent( $post_id );
	}

	private function schedule_initial_enforcement(): void {
		$stored = (string) get_option( 'shcd_tornado_dbm_revision_policy_build', '' );
		if ( self::POLICY_BUILD === $stored ) {
			return;
		}

		update_option( 'shcd_tornado_dbm_revision_policy_build', self::POLICY_BUILD, false );
		if ( 'wordpress' === Settings::get( 'revision_storage_mode', 'wordpress' ) && 'unlimited' !== Settings::get( 'revision_limit', 5 ) ) {
			$this->schedule_enforcement();
		}
	}

	public function settings_added( string $option, mixed $value ): void {
		$new = is_array( $value ) ? ( $value['revision_limit'] ?? 5 ) : 5;
		$mode = is_array( $value ) ? (string) ( $value['revision_storage_mode'] ?? 'wordpress' ) : 'wordpress';
		if ( 'wordpress' === $mode && 'unlimited' !== $new ) {
			$this->schedule_enforcement();
		}
	}

	public function settings_updated( mixed $old_value, mixed $value, string $option ): void {
		$old = is_array( $old_value ) ? ( $old_value['revision_limit'] ?? 5 ) : 5;
		$new = is_array( $value ) ? ( $value['revision_limit'] ?? 5 ) : 5;
		$old_mode = is_array( $old_value ) ? (string) ( $old_value['revision_storage_mode'] ?? 'wordpress' ) : 'wordpress';
		$new_mode = is_array( $value ) ? (string) ( $value['revision_storage_mode'] ?? 'wordpress' ) : 'wordpress';
		if ( ( $old === $new && $old_mode === $new_mode ) || 'wordpress' !== $new_mode || 'unlimited' === $new ) {
			return;
		}

		$this->schedule_enforcement();
	}

	private function schedule_enforcement( int $delay = 10 ): void {
		if ( false === wp_next_scheduled( self::ENFORCE_HOOK ) ) {
			wp_schedule_single_event( time() + max( 1, $delay ), self::ENFORCE_HOOK );
		}
	}

	public function enforce_batch(): void {
		$limit = Settings::get( 'revision_limit', 5 );
		if ( 'wordpress' !== Settings::get( 'revision_storage_mode', 'wordpress' ) || 'unlimited' === $limit ) {
			return;
		}

		global $wpdb;
		$keep    = max( 0, (int) $limit );
		$posts   = Identifier::quote( $wpdb->posts );
		$tornado_sql_posts = Identifier::normalize( $posts );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct database access is required for schema maintenance and must observe current uncached rows.
		$parents = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_parent FROM %i WHERE post_type = 'revision' AND post_parent > 0 AND post_name NOT LIKE %s AND post_status <> 'pending' GROUP BY post_parent HAVING COUNT(*) > %d ORDER BY post_parent ASC LIMIT 200",
				$tornado_sql_posts,
				'%-autosave-%',
				$keep
			)
		);

		foreach ( is_array( $parents ) ? $parents : array() as $parent_id ) {
			$this->enforce_parent( (int) $parent_id );
		}

		if ( count( is_array( $parents ) ? $parents : array() ) >= 200 ) {
			wp_schedule_single_event( time() + 15, self::ENFORCE_HOOK );
		}
	}

	public function enforce_parent( int $post_id ): int {
		$limit = Settings::get( 'revision_limit', 5 );
		if ( $post_id <= 0 || 'wordpress' !== Settings::get( 'revision_storage_mode', 'wordpress' ) || 'unlimited' === $limit ) {
			return 0;
		}

		global $wpdb;
		$posts = Identifier::quote( $wpdb->posts );
		$tornado_sql_posts = Identifier::normalize( $posts );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct database access is required for schema maintenance and must observe current uncached rows.
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_parent, post_date_gmt, post_modified_gmt, post_name, post_status FROM %i WHERE post_type = 'revision' AND post_parent = %d ORDER BY post_date_gmt DESC, post_modified_gmt DESC, ID DESC",
				$tornado_sql_posts,
				$post_id
			),
			ARRAY_A
		);
		$ids = self::select_excess_ids( is_array( $rows ) ? $rows : array(), max( 0, (int) $limit ) );
		$deleted = 0;
		foreach ( $ids as $revision_id ) {
			$result = wp_delete_post_revision( $revision_id );
			if ( false !== $result ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * @param list<array<string, mixed>> $rows Revision rows ordered newest first.
	 * @return list<int>
	 */
	public static function select_excess_ids( array $rows, int $keep ): array {
		$keep = max( 0, $keep );
		$seen = array();
		$ids  = array();
		foreach ( $rows as $row ) {
			$name   = (string) ( $row['post_name'] ?? '' );
			$status = (string) ( $row['post_status'] ?? '' );
			if ( str_contains( $name, '-autosave-' ) || 'pending' === $status ) {
				continue;
			}
			$parent = (int) ( $row['post_parent'] ?? 0 );
			$seen[ $parent ] = ( $seen[ $parent ] ?? 0 ) + 1;
			if ( $seen[ $parent ] > $keep ) {
				$id = (int) ( $row['ID'] ?? 0 );
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
		}

		return $ids;
	}
}
