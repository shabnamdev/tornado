<?php
/**
 * Independent post history archive that does not replace WordPress editor saves.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Core;

use Shcd\TornadoDatabaseMaintenance\Database\Identifier;
use Shcd\TornadoDatabaseMaintenance\Database\Tables;
use Shcd\TornadoDatabaseMaintenance\Logging\Logger;

final class RevisionArchiveService {
	private const MAINTENANCE_HOOK = 'shcd_tornado_dbm_revision_archive_maintenance';
	private const MAX_SNAPSHOT_BYTES = 33554432;
	private const MAX_META_VALUE_BYTES = 4194304;
	private const MAX_ELEMENTOR_META_VALUE_BYTES = 16777216;

	/** @var array<int,string> */
	private array $queued_posts = array();
	private bool $restoring = false;

	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly Tables $tables,
		private readonly Logger $logger
	) {}

	public function register(): void {
		add_action( 'wp_after_insert_post', array( $this, 'queue_wordpress_save' ), 999, 4 );
		add_action( 'elementor/editor/after_save', array( $this, 'queue_elementor_save' ), 999, 2 );
		add_action( 'shutdown', array( $this, 'flush_queue' ), PHP_INT_MAX );
		add_action( self::MAINTENANCE_HOOK, array( $this, 'maintenance' ) );
		if ( false === wp_next_scheduled( self::MAINTENANCE_HOOK ) ) {
			wp_schedule_event( time() + ( 3 * HOUR_IN_SECONDS ), 'daily', self::MAINTENANCE_HOOK );
		}
	}

	public function queue_wordpress_save( int $post_id, \WP_Post $post, bool $update, ?\WP_Post $post_before ): void {
		if ( $this->restoring || ! $update || $post_id <= 0 || 'revision' === $post->post_type || 'auto-draft' === $post->post_status ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$this->queued_posts[ $post_id ] = 'wordpress';
	}

	public function queue_elementor_save( int $post_id, array $editor_data ): void {
		if ( $this->restoring || $post_id <= 0 ) {
			return;
		}
		$this->queued_posts[ $post_id ] = 'elementor';
	}

	public function flush_queue(): void {
		if ( $this->restoring || empty( $this->queued_posts ) ) {
			return;
		}

		$queue = $this->queued_posts;
		$this->queued_posts = array();
		foreach ( $queue as $post_id => $source ) {
			try {
				if ( 'wordpress' === $source && $this->is_elementor_save_request() ) {
					continue;
				}
				if ( 'archive' === Settings::get( 'revision_storage_mode', 'wordpress' ) ) {
					$this->capture( (int) $post_id, (string) $source );
				}
				$this->apply_post_save_revision_policies( (int) $post_id );
			} catch ( \Throwable $throwable ) {
				$this->logger->log(
					'error',
					'ثبت یا نگهداری تاریخچه نوشته کامل نشد.',
					array( 'post_id' => (int) $post_id, 'error' => sanitize_text_field( $throwable->getMessage() ) )
				);
			}
		}
	}

	public function capture( int $post_id, string $source = 'wordpress' ): ?int {
		if ( $post_id <= 0 || 'archive' !== Settings::get( 'revision_storage_mode', 'wordpress' ) || 0 === (int) Settings::get( 'revision_archive_limit', 20 ) ) {
			return null;
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'revision' === $post->post_type || 'auto-draft' === $post->post_status ) {
			return null;
		}

		$post_data = $this->post_snapshot_fields( $post );
		$meta_data = $this->snapshot_meta_for_id( $post->ID, $post->post_type );
		$terms_data = $this->snapshot_terms( $post );

		$snapshot_id = $this->insert_snapshot(
			$post->ID,
			max( 0, (int) $post->post_parent ),
			$post->post_type,
			$source,
			$post_data,
			$meta_data,
			$terms_data,
			get_current_user_id(),
			current_time( 'mysql', true )
		);
		$this->enforce_post_limit( $post_id );
		return $snapshot_id;

	}

	/** @return array<string,mixed> */
	public function status(): array {
		$wpdb = $this->wpdb;
		$table = Identifier::quote( $this->tables->revision_archive() );
		$tornado_sql_table = Identifier::normalize( $table );
		$posts = Identifier::quote( $wpdb->posts );
		$tornado_sql_posts = Identifier::normalize( $posts );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$normal_revisions = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM %i WHERE post_type = 'revision' AND post_name NOT LIKE %s AND post_status <> 'pending'",
			$tornado_sql_posts,
			'%-autosave-%'
		) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$autosaves = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM %i WHERE post_type = 'revision' AND post_name LIKE %s",
			$tornado_sql_posts,
			'%-autosave-%'
		) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$pending = (int) $wpdb->get_var(
			 $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_type = 'revision' AND post_status = 'pending'", $tornado_sql_posts ) 
		);
		return array(
			'mode'                         => Settings::get( 'revision_storage_mode', 'wordpress' ),
			'core_revision_limit'          => Settings::get( 'revision_limit', 5 ),
			'archive_limit'                => Settings::get( 'revision_archive_limit', 20 ),
			'archive_retention_days'       => Settings::get( 'revision_archive_days', 90 ),
			'autosave_retention_days'      => Settings::get( 'autosave_retention_days', 7 ),
			'pending_retention_days'       => Settings::get( 'pending_revision_retention_days', -1 ),
			'archive_table'                => $this->tables->revision_archive(),
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			'snapshot_count'               => (int) $wpdb->get_var(
				 $wpdb->prepare( "SELECT COUNT(*) FROM %i", $tornado_sql_table ) 
			),
			'normal_revision_count'        => $normal_revisions,
			'autosave_count'               => $autosaves,
			'pending_revision_count'       => $pending,
			'current_generation'           => $this->current_generation_uuid(),
			'elementor_detected'           => class_exists( '\\Elementor\\Plugin' ),
			'elementor_save_is_intercepted'=> false,
			'autosave_creation_is_blocked' => false,
		);
	}

	/** @return array<string,mixed> */
	public function list_snapshots( int $post_id = 0, int $limit = 100, int $offset = 0 ): array {
		$wpdb = $this->wpdb;
		$table  = Identifier::normalize( $this->tables->revision_archive() );
		$posts  = Identifier::normalize( $wpdb->posts );
		$limit  = max( 1, min( 200, $limit ) );
		$offset = max( 0, $offset );
		if ( $post_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Revision archive listing must read live rows.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT a.id, a.uuid, a.post_id, a.post_type, a.source, a.generation_uuid, a.snapshot_hash, a.created_by, a.created_at, a.restored_at, p.post_title FROM %i a LEFT JOIN %i p ON p.ID = a.post_id WHERE a.post_id = %d ORDER BY a.id DESC LIMIT %d OFFSET %d',
					$table,
					$posts,
					$post_id,
					$limit,
					$offset
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Revision archive listing must read live rows.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT a.id, a.uuid, a.post_id, a.post_type, a.source, a.generation_uuid, a.snapshot_hash, a.created_by, a.created_at, a.restored_at, p.post_title FROM %i a LEFT JOIN %i p ON p.ID = a.post_id ORDER BY a.id DESC LIMIT %d OFFSET %d',
					$table,
					$posts,
					$limit,
					$offset
				),
				ARRAY_A
			);
		}
		$current_generation = $this->current_generation_uuid();
		foreach ( is_array( $rows ) ? $rows : array() as &$row ) {
			$row['id']         = (int) $row['id'];
			$row['post_id']    = (int) $row['post_id'];
			$row['created_by'] = (int) $row['created_by'];
			$row['restorable'] = hash_equals( $current_generation, (string) $row['generation_uuid'] );
		}
		unset( $row );
		return array(
			'items'              => is_array( $rows ) ? $rows : array(),
			'current_generation' => $current_generation,
			'post_id'            => $post_id,
		);
	}

	/** @return array<string,mixed> */
	public function restore( int $snapshot_id ): array {
		$wpdb = $this->wpdb;
		if ( $snapshot_id <= 0 ) {
			throw new \InvalidArgumentException( 'شناسه Snapshot معتبر نیست.' );
		}
		$table = Identifier::quote( $this->tables->revision_archive() );
		$tornado_sql_table = Identifier::normalize( $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$row   = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM %i WHERE id = %d LIMIT 1",
			$tornado_sql_table,
			$snapshot_id
		), ARRAY_A );
		if ( ! is_array( $row ) ) {
			throw new \RuntimeException( 'Snapshot در آرشیو پیدا نشد.' );
		}
		if ( ! hash_equals( $this->current_generation_uuid(), (string) $row['generation_uuid'] ) ) {
			throw new \RuntimeException( 'این Snapshot به نسل قبلی شناسه‌ها تعلق دارد و برای جلوگیری از بازگرداندن Post IDهای قدیمی، قابل بازیابی نیست.' );
		}

		$post_id    = (int) $row['post_id'];
		$post_data  = $this->decode( (string) $row['post_json'] );
		$meta_data  = $this->decode( (string) $row['meta_json'] );
		$terms_data = $this->decode( (string) $row['terms_json'] );
		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			throw new \RuntimeException( 'نوشته مربوط به Snapshot دیگر وجود ندارد.' );
		}

		$this->restoring = true;
		try {
			$update = array_merge( array( 'ID' => $post_id, 'post_parent' => max( 0, (int) $row['parent_post_id'] ) ), array_intersect_key( $post_data, array_flip( array( 'post_title', 'post_content', 'post_excerpt', 'post_status', 'comment_status', 'ping_status', 'post_password', 'post_name', 'menu_order', 'post_mime_type' ) ) ) );
			$result = wp_update_post( wp_slash( $update ), true );
			if ( is_wp_error( $result ) ) {
				throw new \RuntimeException( $result->get_error_message() );
			}
			$this->restore_meta( $post_id, $meta_data );
			$this->restore_terms( $post_id, $terms_data );
			$this->clear_derived_caches( $post_id );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$wpdb->update(
				$this->tables->revision_archive(),
				array( 'restored_at' => current_time( 'mysql', true ) ),
				array( 'id' => $snapshot_id ),
				array( '%s' ),
				array( '%d' )
			);
		} finally {
			$this->restoring = false;
		}

		return array( 'restored' => true, 'snapshot_id' => $snapshot_id, 'post_id' => $post_id );
	}

	/** @param list<int> $ids @return array<string,mixed> */
	public function delete( array $ids ): array {
		$wpdb = $this->wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( empty( $ids ) ) {
			throw new \InvalidArgumentException( 'هیچ Snapshotی برای حذف انتخاب نشده است.' );
		}
		$table        = Identifier::quote( $this->tables->revision_archive() );
		$tornado_sql_table = Identifier::normalize( $table );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic SQL is built only from validated identifiers or fixed internal fragments and prepared values; direct uncached access is required for current maintenance state.
		$deleted      = $wpdb->query( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only validated identifiers, fixed internal clauses, or generated placeholder lists are interpolated; external values are passed to prepare().
			"DELETE FROM %i WHERE id IN ({$placeholders})",
			array_merge( array( $tornado_sql_table ), $ids )
		) );
		return array( 'deleted' => max( 0, (int) $deleted ), 'requested' => count( $ids ) );
	}

	/** @return array<string,mixed> */
	public function migrate_core_revisions( int $limit = 100 ): array {
		$wpdb = $this->wpdb;
		if ( 'archive' !== Settings::get( 'revision_storage_mode', 'wordpress' ) ) {
			throw new \RuntimeException( 'برای انتقال Revisionهای موجود، ابتدا روش نگهداری تاریخچه را روی «آرشیو مستقل Tornado» قرار دهید.' );
		}
		$limit = max( 1, min( 500, $limit ) );
		$posts = Identifier::quote( $wpdb->posts );
		$tornado_sql_posts = Identifier::normalize( $posts );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM %i WHERE post_type = 'revision' AND post_name NOT LIKE %s AND post_status <> 'pending' ORDER BY ID ASC LIMIT %d",
				$tornado_sql_posts,
				'%-autosave-%',
				$limit
			)
		);
		$migrated = 0;
		$deleted = 0;
		$failed = array();
		foreach ( is_array( $ids ) ? $ids : array() as $revision_id ) {
			$revision = get_post( (int) $revision_id );
			$parent = $revision instanceof \WP_Post ? get_post( (int) $revision->post_parent ) : null;
			if ( ! $revision instanceof \WP_Post || ! $parent instanceof \WP_Post ) {
				$failed[] = array( 'revision_id' => (int) $revision_id, 'reason' => 'Revision یا نوشته والد پیدا نشد.' );
				continue;
			}
			try {
				$this->import_core_revision( $revision, $parent );
				$this->enforce_post_limit( $parent->ID );
				++$migrated;
				if ( false !== wp_delete_post_revision( $revision->ID ) ) {
					++$deleted;
				} else {
					$failed[] = array( 'revision_id' => $revision->ID, 'reason' => 'Snapshot ذخیره شد، اما ردیف Revision از جدول نوشته‌ها حذف نشد.' );
				}
			} catch ( \Throwable $throwable ) {
				$failed[] = array( 'revision_id' => $revision->ID, 'reason' => sanitize_text_field( $throwable->getMessage() ) );
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$remaining = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM %i WHERE post_type = 'revision' AND post_name NOT LIKE %s AND post_status <> 'pending'",
			$tornado_sql_posts,
			'%-autosave-%'
		) );
		$this->logger->log(
			'info',
			'انتقال Revisionهای عادی به آرشیو مستقل پایان یافت.',
			array( 'migrated' => $migrated, 'deleted' => $deleted, 'failed' => count( $failed ), 'remaining' => $remaining )
		);
		return array(
			'migrated'        => $migrated,
			'deleted'         => $deleted,
			'failed'          => $failed,
			'remaining'       => $remaining,
			'requires_reindex'=> $deleted > 0,
			'posts_table'     => $wpdb->posts,
		);
	}

	/** @return array<string,mixed> */
	public function maintenance(): array {
		$deleted_archive  = $this->purge_expired_archive();
		$deleted_autosave = $this->purge_stale_autosaves();
		$deleted_pending  = $this->purge_stale_pending_revisions();
		return array(
			'archive_deleted'          => $deleted_archive,
			'autosaves_deleted'        => $deleted_autosave,
			'pending_revisions_deleted'=> $deleted_pending,
		);
	}

	public function current_generation_uuid(): string {
		$value = (string) get_option( 'shcd_tornado_dbm_last_generation_uuid', '' );
		return wp_is_uuid( $value ) ? $value : 'initial-generation';
	}

	private function apply_post_save_revision_policies( int $post_id ): void {
		$autosave_days = max( 0, (int) Settings::get( 'autosave_retention_days', 7 ) );
		if ( 0 === $autosave_days ) {
			$this->delete_revision_rows( $post_id, 'autosave' );
		}
		$pending_days = (int) Settings::get( 'pending_revision_retention_days', -1 );
		$post = get_post( $post_id );
		if ( 0 === $pending_days && $post instanceof \WP_Post && in_array( $post->post_status, array( 'publish', 'private' ), true ) ) {
			$this->delete_revision_rows( $post_id, 'pending' );
		}
	}

	private function delete_revision_rows( int $post_id, string $type ): int {
		$wpdb = $this->wpdb;
		$table = Identifier::quote( $wpdb->posts );
		$tornado_sql_table = Identifier::normalize( $table );
		if ( 'autosave' === $type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT ID FROM %i WHERE post_type = 'revision' AND post_parent = %d AND post_name LIKE %s",
				$tornado_sql_table,
				$post_id,
				'%-autosave-%'
			) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT ID FROM %i WHERE post_type = 'revision' AND post_parent = %d AND post_status = 'pending'",
				$tornado_sql_table,
				$post_id
			) );
		}
		$deleted = 0;
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			if ( false !== wp_delete_post_revision( (int) $id ) ) {
				++$deleted;
			}
		}
		return $deleted;
	}

	private function purge_stale_autosaves(): int {
		$days = max( 0, (int) Settings::get( 'autosave_retention_days', 7 ) );
		if ( 0 === $days ) {
			return 0;
		}
		return $this->purge_revision_type_by_age( 'autosave', $days );
	}

	private function purge_stale_pending_revisions(): int {
		$days = (int) Settings::get( 'pending_revision_retention_days', -1 );
		if ( $days <= 0 ) {
			return 0;
		}
		return $this->purge_revision_type_by_age( 'pending', $days );
	}

	private function purge_revision_type_by_age( string $type, int $days ): int {
		$wpdb = $this->wpdb;
		$posts = Identifier::normalize( $wpdb->posts );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		if ( 'autosave' === $type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Revision retention must read live rows.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT r.ID, r.post_parent FROM %i r INNER JOIN %i p ON p.ID = r.post_parent WHERE r.post_type = 'revision' AND r.post_name LIKE %s AND r.post_modified_gmt < %s AND p.post_modified_gmt >= r.post_modified_gmt LIMIT 500", $posts, $posts, '%-autosave-%', $cutoff ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Revision retention must read live rows.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT r.ID, r.post_parent FROM %i r INNER JOIN %i p ON p.ID = r.post_parent WHERE r.post_type = 'revision' AND r.post_status = 'pending' AND r.post_modified_gmt < %s AND p.post_status IN ('publish','private') LIMIT 500", $posts, $posts, $cutoff ), ARRAY_A );
		}
		$deleted = 0;
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( $this->post_has_active_edit_lock( (int) $row['post_parent'] ) ) {
				continue;
			}
			if ( false !== wp_delete_post_revision( (int) $row['ID'] ) ) {
				++$deleted;
			}
		}
		return $deleted;
	}

	private function post_has_active_edit_lock( int $post_id ): bool {
		$lock = (string) get_post_meta( $post_id, '_edit_lock', true );
		if ( '' === $lock ) {
			return false;
		}
		$parts = explode( ':', $lock, 2 );
		$timestamp = isset( $parts[0] ) ? (int) $parts[0] : 0;
		return $timestamp > ( time() - 300 );
	}

	private function enforce_post_limit( int $post_id ): int {
		$wpdb = $this->wpdb;
		$limit = max( 0, min( 500, (int) Settings::get( 'revision_archive_limit', 20 ) ) );
		if ( 0 === $limit ) {
			return 0;
		}
		$table = Identifier::quote( $this->tables->revision_archive() );
		$tornado_sql_table = Identifier::normalize( $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM %i WHERE post_id = %d ORDER BY id DESC",
			$tornado_sql_table,
			$post_id
		) );
		$excess = array_slice( array_map( 'intval', is_array( $ids ) ? $ids : array() ), $limit );
		if ( empty( $excess ) ) {
			return 0;
		}
		$result = $this->delete( $excess );
		return (int) ( $result['deleted'] ?? 0 );
	}

	private function purge_expired_archive(): int {
		$wpdb = $this->wpdb;
		$days = max( 0, min( 3650, (int) Settings::get( 'revision_archive_days', 90 ) ) );
		if ( 0 === $days ) {
			return 0;
		}
		$table  = Identifier::quote( $this->tables->revision_archive() );
		$tornado_sql_table = Identifier::normalize( $table );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		return max( 0, (int) $wpdb->query( $wpdb->prepare(
			"DELETE FROM %i WHERE created_at < %s",
			$tornado_sql_table,
			$cutoff
		) ) );
	}

	/** @return array<string,mixed> */
	private function post_snapshot_fields( \WP_Post $post ): array {
		return array(
			'post_title'     => $post->post_title,
			'post_content'   => $post->post_content,
			'post_excerpt'   => $post->post_excerpt,
			'post_status'    => $post->post_status,
			'comment_status' => $post->comment_status,
			'ping_status'    => $post->ping_status,
			'post_password'  => $post->post_password,
			'post_name'      => $post->post_name,
			'menu_order'     => (int) $post->menu_order,
			'post_mime_type' => $post->post_mime_type,
		);
	}

	/**
	 * @param array<string,mixed> $post_data
	 * @param array<string,mixed> $meta_data
	 * @param array<string,mixed> $terms_data
	 */
	private function insert_snapshot( int $post_id, int $parent_post_id, string $post_type, string $source, array $post_data, array $meta_data, array $terms_data, int $created_by, string $created_at ): int {
		$wpdb = $this->wpdb;
		$post_json = $this->encode( $post_data );
		$meta_json = $this->encode( $meta_data );
		$terms_json = $this->encode( $terms_data );
		$bytes = strlen( $post_json ) + strlen( $meta_json ) + strlen( $terms_json );
		if ( $bytes > self::MAX_SNAPSHOT_BYTES ) {
			throw new \RuntimeException( 'حجم Snapshot از سقف ایمن تعیین‌شده بیشتر است و برای جلوگیری از فشار حافظه ذخیره نشد.' );
		}
		$hash = hash( 'sha256', $post_json . "\n" . $meta_json . "\n" . $terms_json );
		$table = Identifier::quote( $this->tables->revision_archive() );
		$tornado_sql_table = Identifier::normalize( $table );
		$generation = $this->current_generation_uuid();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$existing = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM %i WHERE post_id = %d AND snapshot_hash = %s AND generation_uuid = %s LIMIT 1",
				$tornado_sql_table,
				$post_id,
				$hash,
				$generation
			)
		);
		if ( $existing > 0 ) {
			return $existing;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$inserted = $wpdb->insert(
			$this->tables->revision_archive(),
			array(
				'uuid'            => wp_generate_uuid4(),
				'post_id'         => $post_id,
				'parent_post_id'  => max( 0, $parent_post_id ),
				'post_type'       => sanitize_key( $post_type ) ?: 'post',
				'source'          => sanitize_key( $source ) ?: 'wordpress',
				'generation_uuid' => $generation,
				'snapshot_hash'   => $hash,
				'post_json'       => $post_json,
				'meta_json'       => $meta_json,
				'terms_json'      => $terms_json,
				'created_by'      => max( 0, $created_by ),
				'created_at'      => $created_at,
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);
		if ( false === $inserted ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$existing_after_insert = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM %i WHERE post_id = %d AND snapshot_hash = %s AND generation_uuid = %s LIMIT 1",
					$tornado_sql_table,
					$post_id,
					$hash,
					$generation
				)
			);
			if ( $existing_after_insert > 0 ) {
				return $existing_after_insert;
			}
			throw new \RuntimeException( 'Snapshot در جدول آرشیو Tornado ذخیره نشد.' );
		}
		return (int) $wpdb->insert_id;
	}

	private function import_core_revision( \WP_Post $revision, \WP_Post $parent ): int {
		$post_data = $this->post_snapshot_fields( $parent );
		$post_data['post_title'] = $revision->post_title;
		$post_data['post_content'] = $revision->post_content;
		$post_data['post_excerpt'] = $revision->post_excerpt;
		$meta_data = $this->snapshot_meta_for_id( $revision->ID, $parent->post_type );
		$terms_data = $this->snapshot_terms( $parent );
		$created_at = '0000-00-00 00:00:00' !== $revision->post_date_gmt && '' !== $revision->post_date_gmt ? $revision->post_date_gmt : current_time( 'mysql', true );
		return $this->insert_snapshot(
			$parent->ID,
			max( 0, (int) $parent->post_parent ),
			$parent->post_type,
			'core-migration',
			$post_data,
			$meta_data,
			$terms_data,
			(int) $revision->post_author,
			$created_at
		);
	}

	/** @return array<string,mixed> */
	private function snapshot_meta_for_id( int $object_id, string $post_type ): array {
		$all = get_post_meta( $object_id );
		if ( ! is_array( $all ) ) {
			return array();
		}
		$include_elementor = (bool) Settings::get( 'revision_archive_elementor', true );
		$include_woocommerce = (bool) Settings::get( 'revision_archive_woocommerce', true );
		$result = array();
		foreach ( $all as $key => $values ) {
			$key = (string) $key;
			if ( $this->is_volatile_meta_key( $key ) ) {
				continue;
			}
			if ( ! $include_elementor && str_starts_with( $key, '_elementor_' ) ) {
				continue;
			}
			if ( ! $include_woocommerce && in_array( $post_type, array( 'product', 'product_variation', 'shop_order' ), true ) && str_starts_with( $key, '_' ) ) {
				continue;
			}
			$clean_values = array();
			foreach ( is_array( $values ) ? $values : array( $values ) as $value ) {
				$decoded = SafeSerialization::maybe_unserialize( $value );
				$encoded = wp_json_encode( $decoded );
				$limit = str_starts_with( $key, '_elementor_' ) ? self::MAX_ELEMENTOR_META_VALUE_BYTES : self::MAX_META_VALUE_BYTES;
				if ( false === $encoded || strlen( $encoded ) > $limit ) {
					if ( in_array( $key, array( '_elementor_data', '_elementor_page_settings' ), true ) ) {
						throw new \RuntimeException( 'داده اصلی Elementor برای Snapshot بیش از حد بزرگ یا نامعتبر است؛ ذخیره صفحه انجام شده، اما این نسخه در آرشیو ثبت نشد.' );
					}
					continue;
				}
				$clean_values[] = $decoded;
			}
			if ( ! empty( $clean_values ) ) {
				$result[ $key ] = $clean_values;
			}
		}
		ksort( $result );
		return $result;
	}

	/** @return array<string,list<string>> */
	private function snapshot_terms( \WP_Post $post ): array {
		$result = array();
		$taxonomies = get_object_taxonomies( $post->post_type, 'names' );
		foreach ( is_array( $taxonomies ) ? $taxonomies : array() as $taxonomy ) {
			$slugs = wp_get_object_terms( $post->ID, (string) $taxonomy, array( 'fields' => 'slugs' ) );
			if ( ! is_wp_error( $slugs ) && is_array( $slugs ) ) {
				$result[ (string) $taxonomy ] = array_values( array_map( 'strval', $slugs ) );
			}
		}
		ksort( $result );
		return $result;
	}

	/** @param array<string,mixed> $meta_data */
	private function restore_meta( int $post_id, array $meta_data ): void {
		foreach ( $meta_data as $key => $values ) {
			$key = (string) $key;
			if ( '' === $key || $this->is_volatile_meta_key( $key ) ) {
				continue;
			}
			delete_post_meta( $post_id, $key );
			foreach ( is_array( $values ) ? $values : array( $values ) as $value ) {
				add_post_meta( $post_id, $key, $value );
			}
		}
	}

	/** @param array<string,mixed> $terms_data */
	private function restore_terms( int $post_id, array $terms_data ): void {
		foreach ( $terms_data as $taxonomy => $slugs ) {
			if ( taxonomy_exists( (string) $taxonomy ) ) {
				wp_set_object_terms( $post_id, array_values( array_map( 'strval', is_array( $slugs ) ? $slugs : array() ) ), (string) $taxonomy, false );
			}
		}
	}

	private function clear_derived_caches( int $post_id ): void {
		clean_post_cache( $post_id );
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $post_id );
		}
		if ( class_exists( '\\Elementor\\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			$manager = \Elementor\Plugin::$instance->files_manager;
			if ( is_object( $manager ) && method_exists( $manager, 'clear_cache' ) ) {
				$method = new \ReflectionMethod( $manager, 'clear_cache' );
				if ( $method->isPublic() ) {
					$manager->clear_cache();
				}
			}
		}
	}

	private function is_elementor_save_request(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request classification; no state is changed here.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) ) : '';
		if ( str_contains( $action, 'elementor' ) ) {
			return true;
		}
		$route = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
		return str_contains( $route, '/elementor/' ) || str_contains( $route, 'elementor_ajax' );
	}

	private function is_volatile_meta_key( string $key ): bool {
		if ( in_array( $key, array( '_edit_lock', '_edit_last', '_wp_old_slug', '_elementor_css', '_elementor_page_assets', '_elementor_controls_usage' ), true ) ) {
			return true;
		}
		return 1 === preg_match( '/(?:cache|transient|session|nonce|token|password|secret)/i', $key );
	}

	/** @param array<string,mixed> $value */
	private function encode( array $value ): string {
		$json = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			throw new \RuntimeException( 'داده Snapshot به JSON معتبر تبدیل نشد.' );
		}
		return $json;
	}

	/** @return array<string,mixed> */
	private function decode( string $json ): array {
		$value = json_decode( $json, true );
		if ( ! is_array( $value ) ) {
			throw new \RuntimeException( 'ساختار Snapshot معتبر نیست.' );
		}
		return $value;
	}
}
