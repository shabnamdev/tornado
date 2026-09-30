<?php
/**
 * Preview-first, chunked cleanup service.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Cleanup;

use Shcd\TornadoDatabaseMaintenance\Core\RevisionPolicyService;
use Shcd\TornadoDatabaseMaintenance\Core\Settings;
use Shcd\TornadoDatabaseMaintenance\Database\AutoIncrementService;
use Shcd\TornadoDatabaseMaintenance\Database\Identifier;
use Shcd\TornadoDatabaseMaintenance\Jobs\JobRepository;
use InvalidArgumentException;
use RuntimeException;

final class CleanupService {
	private int $revision_fallback_deleted = 0;

	private const ALLOWED = array(
		'all_revisions',
		'revisions',
		'auto_drafts',
		'trash',
		'media_cleaner_trash_missing',
		'autosaves',
		'pending_revisions',
		'orphan_postmeta',
		'orphan_comments',
		'orphan_commentmeta',
		'orphan_usermeta',
		'orphan_termmeta',
		'orphan_term_relationships',
		'orphan_wc_order_itemmeta',
		'orphan_wc_product_lookup',
		'orphan_wc_attribute_lookup',
		'orphan_wc_download_permissions',
		'expired_transients',
	);

	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly JobRepository $jobs,
		private readonly AutoIncrementService $auto_increment
	) {}

	/** @param list<string> $types @return array<string,mixed> */
	public function preview( array $types ): array {
		$wpdb = $this->wpdb;
		$types  = $this->validate_types( $types );
		$counts = array();
		foreach ( $types as $type ) {
			$counts[ $type ] = $this->candidate_count( $type );
		}

		$token      = bin2hex( random_bytes( 32 ) );
		$expires_at = time() + 600;
		set_transient(
			$this->preview_key(),
			array(
				'hash'       => hash_hmac( 'sha256', $token, wp_salt( 'auth' ) ),
				'expires_at' => $expires_at,
				'types'      => $types,
				'counts'     => $counts,
			),
			600
		);

		return array(
			'types'         => $types,
			'tables'        => array(
				'posts'    => $wpdb->posts,
				'postmeta' => $wpdb->postmeta,
			),
			'counts'        => $counts,
			'total'         => array_sum( $counts ),
			'preview_token' => $token,
			'expires_at'    => $expires_at,
		);
	}

	/** @param list<string> $types @return array<string,mixed> */
	public function execute( array $types, string $preview_token = '' ): array {
		$wpdb = $this->wpdb;
		$types = $this->validate_types( $types );
		$this->revision_fallback_deleted = 0;
		if ( ! $this->trusted_background_execution() ) {
			$this->consume_preview( $types, $preview_token );
		}

		$job_uuid = $this->jobs->create( 'cleanup', array( 'types' => $types ) );
		$lock_name = 'shcd_tornado_dbm_cleanup_' . md5( (string) DB_NAME );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$lock = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 2)', $lock_name ) );
		if ( 1 !== $lock ) {
			$this->jobs->fail( $job_uuid, 'یک عملیات پاک‌سازی دیگر در حال اجراست. پس از پایان آن دوباره تلاش کنید.', 'lock_failed' );
			throw new RuntimeException( 'یک عملیات پاک‌سازی دیگر در حال اجراست. پس از پایان آن دوباره تلاش کنید.' );
		}

		$deleted   = array();
		$remaining = array();
		$truncated = array();
		$batch     = (int) Settings::get( 'cleanup_batch_size', 500 );
		$max_rows  = wp_doing_cron() ? 5000 : 100000;

		try {
			foreach ( $types as $index => $type ) {
				$deleted[ $type ] = 0;
				while ( $deleted[ $type ] < $max_rows ) {
					$ids = $this->candidate_ids( $type, min( $batch, $max_rows - $deleted[ $type ] ) );
					if ( empty( $ids ) ) {
						break;
					}
					$removed = $this->delete_candidates( $type, $ids );
					$deleted[ $type ] += $removed;
					if ( $removed <= 0 ) {
						throw new RuntimeException( sprintf( 'پاک‌سازی مورد «%s» پیشرفتی نداشت. احتمالاً یکی از افزونه‌ها حذف رکورد را متوقف کرده است.', $type ) );
					}
					$this->jobs->progress(
						$job_uuid,
						'cleaning_' . $type,
						min( 99.0, ( ( $index + 0.5 ) / max( 1, count( $types ) ) ) * 100 ),
						array( 'deleted' => $deleted, 'current_type' => $type )
					);
				}
				$remaining[ $type ] = $this->candidate_count( $type );
				$truncated[ $type ] = $remaining[ $type ] > 0;
			}

			$auto_increment = array();
			if ( (bool) Settings::get( 'auto_increment_after_cleanup', true ) ) {
				$auto_increment = $this->auto_increment->normalize_tables( $this->affected_auto_increment_tables( $types ), 'cleanup' );
			}

			$warnings = array();
			$remaining_total = array_sum( $remaining );
			if ( $remaining_total > 0 ) {
				$warnings[] = sprintf( '%d مورد پس از پاک‌سازی باقی مانده است. ممکن است یک افزونه حذف آن‌ها را متوقف کرده باشد یا سقف پردازش به پایان رسیده باشد.', $remaining_total );
			}
			if ( isset( $remaining['all_revisions'] ) && $remaining['all_revisions'] > 0 ) {
				$warnings[] = sprintf( '%d Revision در جدول %s باقی مانده است. جزئیات Job و Log را بررسی کنید.', $remaining['all_revisions'], $wpdb->posts );
			}

			$result = array(
				'uuid'           => $job_uuid,
				'deleted'        => $deleted,
				'remaining'      => $remaining,
				'tables'         => array(
					'posts'    => $wpdb->posts,
					'postmeta' => $wpdb->postmeta,
				),
				'truncated'      => $truncated,
				'total_deleted'  => array_sum( $deleted ),
				'complete'       => 0 === $remaining_total,
				'revision_fallback_deleted' => $this->revision_fallback_deleted,
				'warnings'       => $warnings,
				'auto_increment' => $auto_increment,
			);
			$this->jobs->complete( $job_uuid, $result );
			return $result;
		} catch ( \Throwable $throwable ) {
			$this->jobs->fail( $job_uuid, $throwable->getMessage(), 'cleanup_failed' );
			throw $throwable;
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	/** @param list<string> $types */
	private function consume_preview( array $types, string $token ): void {
		$key    = $this->preview_key();
		$stored = get_transient( $key );
		delete_transient( $key );

		if ( '' === $token || ! is_array( $stored ) || (int) ( $stored['expires_at'] ?? 0 ) < time() ) {
			throw new RuntimeException( 'پیش از حذف، یک Preview تازه اجرا کنید.' );
		}
		$actual = hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
		if ( ! hash_equals( (string) ( $stored['hash'] ?? '' ), $actual ) ) {
			throw new RuntimeException( 'مجوز Preview پاک‌سازی معتبر نیست. Preview را دوباره اجرا کنید.' );
		}

		$preview_types = array_values( array_map( 'sanitize_key', (array) ( $stored['types'] ?? array() ) ) );
		if ( $preview_types !== $types ) {
			throw new RuntimeException( 'گزینه‌های انتخاب‌شده با Preview تأییدشده یکسان نیستند. Preview را دوباره اجرا کنید.' );
		}

		$current_counts = array();
		foreach ( $types as $type ) {
			$current_counts[ $type ] = $this->candidate_count( $type );
		}
		$preview_counts = array_map( 'intval', (array) ( $stored['counts'] ?? array() ) );
		if ( $preview_counts !== $current_counts ) {
			throw new RuntimeException( 'تعداد موارد قابل پاک‌سازی پس از Preview تغییر کرده است. نتیجه تازه را بررسی و دوباره تأیید کنید.' );
		}
	}

	private function preview_key(): string {
		return 'shcd_tornado_dbm_cleanup_preview_' . max( 0, get_current_user_id() );
	}

	private function trusted_background_execution(): bool {
		$is_cli  = defined( 'WP_CLI' ) && WP_CLI;
		$is_cron = function_exists( 'wp_doing_cron' ) && wp_doing_cron();
		return $is_cli || $is_cron;
	}

	/** @param list<string> $types @return list<string> */
	private function validate_types( array $types ): array {
		$types = array_values( array_unique( array_map( 'sanitize_key', $types ) ) );
		foreach ( $types as $type ) {
			if ( ! in_array( $type, self::ALLOWED, true ) ) {
				throw new InvalidArgumentException( 'نوع پاک‌سازی انتخاب‌شده پشتیبانی نمی‌شود.' );
			}
		}

		if ( in_array( 'all_revisions', $types, true ) ) {
			$types = array_values( array_diff( $types, array( 'revisions', 'autosaves', 'pending_revisions' ) ) );
		}

		return $types;
	}

	private function candidate_count( string $type ): int {
		$wpdb = $this->wpdb;
		$posts       = Identifier::quote( $wpdb->posts );
		$tornado_sql_posts = Identifier::normalize( $posts );
		$postmeta    = Identifier::quote( $wpdb->postmeta );
		$tornado_sql_postmeta = Identifier::normalize( $postmeta );
		$comments    = Identifier::quote( $wpdb->comments );
		$tornado_sql_comments = Identifier::normalize( $comments );
		$commentmeta = Identifier::quote( $wpdb->commentmeta );
		$tornado_sql_commentmeta = Identifier::normalize( $commentmeta );
		$users       = Identifier::quote( $wpdb->users );
		$tornado_sql_users = Identifier::normalize( $users );
		$usermeta    = Identifier::quote( $wpdb->usermeta );
		$tornado_sql_usermeta = Identifier::normalize( $usermeta );
		$terms       = Identifier::quote( $wpdb->terms );
		$tornado_sql_terms = Identifier::normalize( $terms );
		$termmeta    = Identifier::quote( $wpdb->termmeta );
		$tornado_sql_termmeta = Identifier::normalize( $termmeta );
		$relations   = Identifier::quote( $wpdb->term_relationships );
		$tornado_sql_relations = Identifier::normalize( $relations );
		$options     = Identifier::quote( $wpdb->options );
		$tornado_sql_options = Identifier::normalize( $options );

		return match ( $type ) {
			'all_revisions' => $this->all_revision_count(),
			'revisions' => $this->revision_count(),
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			'auto_drafts' => (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE post_status = 'auto-draft' AND post_date_gmt < (UTC_TIMESTAMP() - INTERVAL %d DAY)",
				$tornado_sql_posts,
				(int) Settings::get( 'auto_draft_days', 7 )
			) ),
			'trash' => (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE post_status = 'trash' AND post_modified_gmt < (UTC_TIMESTAMP() - INTERVAL %d DAY)",
				$tornado_sql_posts,
				(int) Settings::get( 'trash_days', 30 )
			) ),
			'media_cleaner_trash_missing' => count( $this->missing_media_cleaner_trash_ids() ),
			'autosaves' => (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE post_type = 'revision' AND post_name LIKE %s",
				$tornado_sql_posts,
				'%-autosave-%'
			) ),
			'pending_revisions' => (int) $wpdb->get_var(
				 $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_type = 'revision' AND post_status = 'pending'", $tornado_sql_posts ) 
			), 
			'orphan_postmeta' => (int) $wpdb->get_var(
				 $wpdb->prepare( "SELECT COUNT(*) FROM %i pm LEFT JOIN %i p ON p.ID = pm.post_id WHERE p.ID IS NULL", $tornado_sql_postmeta, $tornado_sql_posts ) 
			), 
			'orphan_comments' => (int) $wpdb->get_var(
				 $wpdb->prepare( "SELECT COUNT(*) FROM %i c LEFT JOIN %i p ON p.ID = c.comment_post_ID WHERE c.comment_post_ID <> 0 AND p.ID IS NULL", $tornado_sql_comments, $tornado_sql_posts ) 
			), 
			'orphan_commentmeta' => (int) $wpdb->get_var(
				 $wpdb->prepare( "SELECT COUNT(*) FROM %i cm LEFT JOIN %i c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL", $tornado_sql_commentmeta, $tornado_sql_comments ) 
			), 
			'orphan_usermeta' => (int) $wpdb->get_var(
				 $wpdb->prepare( "SELECT COUNT(*) FROM %i um LEFT JOIN %i u ON u.ID = um.user_id WHERE u.ID IS NULL", $tornado_sql_usermeta, $tornado_sql_users ) 
			), 
			'orphan_termmeta' => (int) $wpdb->get_var(
				 $wpdb->prepare( "SELECT COUNT(*) FROM %i tm LEFT JOIN %i t ON t.term_id = tm.term_id WHERE t.term_id IS NULL", $tornado_sql_termmeta, $tornado_sql_terms ) 
			), 
			'orphan_term_relationships' => (int) $wpdb->get_var(
				 $wpdb->prepare( "SELECT COUNT(*) FROM %i tr LEFT JOIN %i p ON p.ID = tr.object_id WHERE p.ID IS NULL", $tornado_sql_relations, $tornado_sql_posts ) 
			), 
			'orphan_wc_order_itemmeta' => $this->wc_count_order_itemmeta(),
			'orphan_wc_product_lookup' => $this->wc_count_lookup( 'wc_product_meta_lookup', 'product_id' ),
			'orphan_wc_attribute_lookup' => $this->wc_count_lookup( 'wc_product_attributes_lookup', 'product_or_parent_id' ),
			'orphan_wc_download_permissions' => $this->wc_count_download_permissions(),
			'expired_transients' => (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d",
				$tornado_sql_options,
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				time()
			) ),
			default => 0,
		};
	}

	/** @return list<int|string> */
	private function candidate_ids( string $type, int $limit ): array {
		$wpdb = $this->wpdb;
		$limit       = max( 1, min( 2000, $limit ) );
		$posts       = Identifier::quote( $wpdb->posts );
		$tornado_sql_posts = Identifier::normalize( $posts );
		$postmeta    = Identifier::quote( $wpdb->postmeta );
		$tornado_sql_postmeta = Identifier::normalize( $postmeta );
		$comments    = Identifier::quote( $wpdb->comments );
		$tornado_sql_comments = Identifier::normalize( $comments );
		$commentmeta = Identifier::quote( $wpdb->commentmeta );
		$tornado_sql_commentmeta = Identifier::normalize( $commentmeta );
		$users       = Identifier::quote( $wpdb->users );
		$tornado_sql_users = Identifier::normalize( $users );
		$usermeta    = Identifier::quote( $wpdb->usermeta );
		$tornado_sql_usermeta = Identifier::normalize( $usermeta );
		$terms       = Identifier::quote( $wpdb->terms );
		$tornado_sql_terms = Identifier::normalize( $terms );
		$termmeta    = Identifier::quote( $wpdb->termmeta );
		$tornado_sql_termmeta = Identifier::normalize( $termmeta );
		$relations   = Identifier::quote( $wpdb->term_relationships );
		$tornado_sql_relations = Identifier::normalize( $relations );
		$options     = Identifier::quote( $wpdb->options );
		$tornado_sql_options = Identifier::normalize( $options );

		switch ( $type ) {
			case 'all_revisions':
				return $this->all_revision_ids( $limit );
			case 'revisions':
				return $this->excess_revision_ids( $limit );
			case 'auto_drafts':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
				return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
					"SELECT ID FROM %i WHERE post_status = 'auto-draft' AND post_date_gmt < (UTC_TIMESTAMP() - INTERVAL %d DAY) ORDER BY ID ASC LIMIT %d",
					$tornado_sql_posts,
					(int) Settings::get( 'auto_draft_days', 7 ),
					$limit
				) ) );
			case 'trash':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
				return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
					"SELECT ID FROM %i WHERE post_status = 'trash' AND post_modified_gmt < (UTC_TIMESTAMP() - INTERVAL %d DAY) ORDER BY ID ASC LIMIT %d",
					$tornado_sql_posts,
					(int) Settings::get( 'trash_days', 30 ),
					$limit
				) ) );
			case 'media_cleaner_trash_missing':
				return $this->missing_media_cleaner_trash_ids( $limit );
			case 'autosaves':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
				return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
					"SELECT ID FROM %i WHERE post_type = 'revision' AND post_name LIKE %s ORDER BY ID ASC LIMIT %d",
					$tornado_sql_posts,
					'%-autosave-%',
					$limit
				) ) );
			case 'pending_revisions':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
				return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
					"SELECT ID FROM %i WHERE post_type = 'revision' AND post_status = 'pending' ORDER BY ID ASC LIMIT %d",
					$tornado_sql_posts,
					$limit
				) ) );
			case 'orphan_postmeta':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
				return array_map( 'intval', $wpdb->get_col(
					 $wpdb->prepare(
					 	"SELECT pm.meta_id FROM %i pm LEFT JOIN %i p ON p.ID = pm.post_id WHERE p.ID IS NULL LIMIT %d",
					 	$tornado_sql_postmeta,
					 	$tornado_sql_posts,
					 	$limit
					 ) 
				) ); 
			case 'orphan_comments':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
				return array_map( 'intval', $wpdb->get_col(
					 $wpdb->prepare(
					 	"SELECT c.comment_ID FROM %i c LEFT JOIN %i p ON p.ID = c.comment_post_ID WHERE c.comment_post_ID <> 0 AND p.ID IS NULL LIMIT %d",
					 	$tornado_sql_comments,
					 	$tornado_sql_posts,
					 	$limit
					 ) 
				) ); 
			case 'orphan_commentmeta':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
				return array_map( 'intval', $wpdb->get_col(
					 $wpdb->prepare(
					 	"SELECT cm.meta_id FROM %i cm LEFT JOIN %i c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL LIMIT %d",
					 	$tornado_sql_commentmeta,
					 	$tornado_sql_comments,
					 	$limit
					 ) 
				) ); 
			case 'orphan_usermeta':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
				return array_map( 'intval', $wpdb->get_col(
					 $wpdb->prepare(
					 	"SELECT um.umeta_id FROM %i um LEFT JOIN %i u ON u.ID = um.user_id WHERE u.ID IS NULL LIMIT %d",
					 	$tornado_sql_usermeta,
					 	$tornado_sql_users,
					 	$limit
					 ) 
				) ); 
			case 'orphan_termmeta':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
				return array_map( 'intval', $wpdb->get_col(
					 $wpdb->prepare(
					 	"SELECT tm.meta_id FROM %i tm LEFT JOIN %i t ON t.term_id = tm.term_id WHERE t.term_id IS NULL LIMIT %d",
					 	$tornado_sql_termmeta,
					 	$tornado_sql_terms,
					 	$limit
					 ) 
				) ); 
			case 'orphan_term_relationships':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
				$rows = $wpdb->get_results(
					 $wpdb->prepare(
					 	"SELECT tr.object_id, tr.term_taxonomy_id FROM %i tr LEFT JOIN %i p ON p.ID = tr.object_id WHERE p.ID IS NULL LIMIT %d",
					 	$tornado_sql_relations,
					 	$tornado_sql_posts,
					 	$limit
					 ) ,
					ARRAY_A
				); 
				return is_array( $rows ) ? array_map( static fn( array $row ): string => (int) $row['object_id'] . ':' . (int) $row['term_taxonomy_id'], $rows ) : array();
			case 'orphan_wc_order_itemmeta':
				return $this->wc_order_itemmeta_ids( $limit );
			case 'orphan_wc_product_lookup':
				return $this->wc_lookup_ids( 'wc_product_meta_lookup', 'product_id', $limit );
			case 'orphan_wc_attribute_lookup':
				return $this->wc_lookup_ids( 'wc_product_attributes_lookup', 'product_or_parent_id', $limit );
			case 'orphan_wc_download_permissions':
				return $this->wc_download_permission_ids( $limit );
			case 'expired_transients':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
				return array_map( 'strval', $wpdb->get_col( $wpdb->prepare(
					"SELECT option_name FROM %i WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d LIMIT %d",
					$tornado_sql_options,
					$wpdb->esc_like( '_transient_timeout_' ) . '%',
					time(),
					$limit
				) ) );
		}
		return array();
	}

	/** @param list<int|string> $ids */
	private function delete_candidates( string $type, array $ids ): int {
		$wpdb = $this->wpdb;
		$deleted = 0;
		foreach ( $ids as $id ) {
			switch ( $type ) {
				case 'all_revisions':
				case 'revisions':
				case 'autosaves':
				case 'pending_revisions':
					if ( $this->delete_revision( (int) $id ) ) {
						++$deleted;
					}
					break;
				case 'auto_drafts':
				case 'trash':
					if ( false !== wp_delete_post( (int) $id, true ) ) {
						++$deleted;
					}
					break;
				case 'media_cleaner_trash_missing':
					$post_id = (int) $id;
					if ( $this->is_missing_media_cleaner_trash( $post_id ) && false !== wp_delete_post( $post_id, true ) ) {
						++$deleted;
					}
					break;
				case 'orphan_comments':
					if ( wp_delete_comment( (int) $id, true ) ) {
						++$deleted;
					}
					break;
				case 'orphan_postmeta':
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
					$deleted += max( 0, (int) $wpdb->delete( $wpdb->postmeta, array( 'meta_id' => (int) $id ), array( '%d' ) ) );
					break;
				case 'orphan_commentmeta':
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
					$deleted += max( 0, (int) $wpdb->delete( $wpdb->commentmeta, array( 'meta_id' => (int) $id ), array( '%d' ) ) );
					break;
				case 'orphan_usermeta':
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
					$deleted += max( 0, (int) $wpdb->delete( $wpdb->usermeta, array( 'umeta_id' => (int) $id ), array( '%d' ) ) );
					break;
				case 'orphan_termmeta':
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
					$deleted += max( 0, (int) $wpdb->delete( $wpdb->termmeta, array( 'meta_id' => (int) $id ), array( '%d' ) ) );
					break;
				case 'orphan_term_relationships':
					list( $object_id, $term_taxonomy_id ) = array_map( 'intval', explode( ':', (string) $id, 2 ) );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
					$deleted += max( 0, (int) $wpdb->delete( $wpdb->term_relationships, array( 'object_id' => $object_id, 'term_taxonomy_id' => $term_taxonomy_id ), array( '%d', '%d' ) ) );
					break;
				case 'orphan_wc_order_itemmeta':
					$deleted += $this->delete_from_plugin_table( 'woocommerce_order_itemmeta', 'meta_id', (int) $id );
					break;
				case 'orphan_wc_product_lookup':
					$deleted += $this->delete_from_plugin_table( 'wc_product_meta_lookup', 'product_id', (int) $id );
					break;
				case 'orphan_wc_attribute_lookup':
					$deleted += $this->delete_from_plugin_table( 'wc_product_attributes_lookup', 'product_or_parent_id', (int) $id );
					break;
				case 'orphan_wc_download_permissions':
					$deleted += $this->delete_from_plugin_table( 'woocommerce_downloadable_product_permissions', 'permission_id', (int) $id );
					break;
				case 'expired_transients':
					$timeout_name = (string) $id;
					$transient    = substr( $timeout_name, strlen( '_transient_timeout_' ) );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
					$timeout_deleted = (int) $wpdb->delete( $wpdb->options, array( 'option_name' => $timeout_name ), array( '%s' ) );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
					$wpdb->delete( $wpdb->options, array( 'option_name' => '_transient_' . $transient ), array( '%s' ) );
					$deleted += max( 0, $timeout_deleted );
					break;
			}
		}
		return $deleted;
	}

	/**
	 * Returns tables whose AUTO_INCREMENT may have changed after the selected cleanup types.
	 *
	 * @param list<string> $types Cleanup types.
	 * @return list<string>
	 */
	private function affected_auto_increment_tables( array $types ): array {
		$wpdb = $this->wpdb;
		$tables = array();
		foreach ( $types as $type ) {
			switch ( $type ) {
				case 'all_revisions':
				case 'revisions':
				case 'auto_drafts':
				case 'trash':
				case 'autosaves':
				case 'pending_revisions':
				case 'media_cleaner_trash_missing':
					$tables[] = $wpdb->posts;
					$tables[] = $wpdb->postmeta;
					$tables[] = $wpdb->comments;
					$tables[] = $wpdb->commentmeta;
					break;
				case 'orphan_postmeta':
					$tables[] = $wpdb->postmeta;
					break;
				case 'orphan_comments':
					$tables[] = $wpdb->comments;
					$tables[] = $wpdb->commentmeta;
					break;
				case 'orphan_commentmeta':
					$tables[] = $wpdb->commentmeta;
					break;
				case 'orphan_usermeta':
					$tables[] = $wpdb->usermeta;
					break;
				case 'orphan_termmeta':
					$tables[] = $wpdb->termmeta;
					break;
				case 'orphan_wc_order_itemmeta':
					$tables[] = $wpdb->prefix . 'woocommerce_order_itemmeta';
					break;
				case 'orphan_wc_download_permissions':
					$tables[] = $wpdb->prefix . 'woocommerce_downloadable_product_permissions';
					break;
				case 'expired_transients':
					$tables[] = $wpdb->options;
					break;
			}
		}

		return array_values( array_unique( $tables ) );
	}


	/**
	 * Finds Media Cleaner trash rows whose original and generated upload files are absent.
	 * Rows backed by an existing file are intentionally retained because they may still be
	 * restorable from Media Cleaner's trash. Remote/non-upload rows are skipped fail-closed.
	 *
	 * @return list<int>
	 */
	private function missing_media_cleaner_trash_ids( int $limit = 0 ): array {
		$wpdb = $this->wpdb;
		$posts = Identifier::quote( $wpdb->posts );
		$tornado_sql_posts = Identifier::normalize( $posts );
		$meta  = Identifier::quote( $wpdb->postmeta );
		$tornado_sql_meta = Identifier::normalize( $meta );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$rows  = $wpdb->get_results(
			 $wpdb->prepare( "SELECT p.ID, p.guid, pm.meta_value AS attached_file
			 FROM %i p
			 LEFT JOIN %i pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
			 WHERE p.post_type = 'wmpc-trash'
			 ORDER BY p.ID ASC", $tornado_sql_posts, $tornado_sql_meta ) ,
			ARRAY_A
		);
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return array();
		}

		$ids = array();
		foreach ( $rows as $row ) {
			$post_id = absint( $row['ID'] ?? 0 );
			if ( $post_id <= 0 ) {
				continue;
			}
			$relative = $this->local_upload_relative_path(
				(string) ( $row['attached_file'] ?? '' ),
				(string) ( $row['guid'] ?? '' )
			);
			if ( '' === $relative || $this->media_post_has_existing_local_file( $post_id, $relative ) ) {
				continue;
			}
			$ids[] = $post_id;
			if ( $limit > 0 && count( $ids ) >= $limit ) {
				break;
			}
		}
		return $ids;
	}

	private function is_missing_media_cleaner_trash( int $post_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'wmpc-trash' !== $post->post_type ) {
			return false;
		}
		$relative = $this->local_upload_relative_path(
			(string) get_post_meta( $post_id, '_wp_attached_file', true ),
			(string) $post->guid
		);
		return '' !== $relative && ! $this->media_post_has_existing_local_file( $post_id, $relative );
	}

	private function local_upload_relative_path( string $attached_file, string $guid ): string {
		$relative = ltrim( wp_normalize_path( rawurldecode( $attached_file ) ), '/' );
		if ( '' !== $relative && ! str_contains( $relative, '../' ) ) {
			return $relative;
		}
		$uploads = wp_upload_dir();
		$baseurl = isset( $uploads['baseurl'] ) ? untrailingslashit( (string) $uploads['baseurl'] ) : '';
		if ( '' !== $baseurl && str_starts_with( $guid, $baseurl . '/' ) ) {
			$relative = ltrim( rawurldecode( substr( $guid, strlen( $baseurl ) ) ), '/' );
			return str_contains( $relative, '../' ) ? '' : $relative;
		}
		$path = rawurldecode( (string) wp_parse_url( $guid, PHP_URL_PATH ) );
		$marker = '/uploads/';
		$position = strpos( $path, $marker );
		if ( false === $position ) {
			return '';
		}
		$relative = ltrim( substr( $path, $position + strlen( $marker ) ), '/' );
		return str_contains( $relative, '../' ) ? '' : $relative;
	}

	private function media_post_has_existing_local_file( int $post_id, string $relative ): bool {
		$uploads   = wp_upload_dir();
		$base_dir  = isset( $uploads['basedir'] ) ? wp_normalize_path( (string) $uploads['basedir'] ) : '';
		$real_base = '' !== $base_dir ? realpath( $base_dir ) : false;
		if ( false === $real_base ) {
			return true; 
		}
		$real_base  = wp_normalize_path( $real_base );
		$candidates = array( $relative, $this->strip_intermediate_media_size( $relative ) );
		$metadata   = wp_get_attachment_metadata( $post_id );
		if ( is_array( $metadata ) ) {
			if ( ! empty( $metadata['file'] ) && is_string( $metadata['file'] ) ) {
				$candidates[] = $metadata['file'];
			}
			$directory = dirname( (string) ( $metadata['file'] ?? $relative ) );
			$directory = '.' === $directory ? '' : $directory;
			if ( ! empty( $metadata['original_image'] ) && is_string( $metadata['original_image'] ) ) {
				$candidates[] = ltrim( trailingslashit( $directory ) . $metadata['original_image'], '/' );
			}
			foreach ( (array) ( $metadata['sizes'] ?? array() ) as $size ) {
				if ( is_array( $size ) && ! empty( $size['file'] ) && is_string( $size['file'] ) ) {
					$candidates[] = ltrim( trailingslashit( $directory ) . $size['file'], '/' );
				}
			}
		}

		foreach ( array_values( array_unique( array_filter( array_map( 'strval', $candidates ) ) ) ) as $candidate ) {
			$candidate = ltrim( wp_normalize_path( rawurldecode( $candidate ) ), '/' );
			if ( '' === $candidate || str_contains( $candidate, '../' ) ) {
				continue;
			}
			$full = wp_normalize_path( trailingslashit( $real_base ) . $candidate );
			if ( ! str_starts_with( $full, trailingslashit( $real_base ) ) || is_link( $full ) ) {
				continue;
			}
			if ( is_file( $full ) ) {
				return true;
			}
		}
		return false;
	}

	private function strip_intermediate_media_size( string $relative ): string {
		return (string) preg_replace( '/-\d+x\d+(?=\.[A-Za-z0-9]{2,6}$)/', '', $relative );
	}

	private function all_revision_count(): int {
		$wpdb = $this->wpdb;
		$table = Identifier::quote( $wpdb->posts );
		$tornado_sql_table = Identifier::normalize( $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		return (int) $wpdb->get_var(
			 $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_type = 'revision'", $tornado_sql_table ) 
		);
	}

	/** @return list<int> */
	private function all_revision_ids( int $limit ): array {
		$wpdb = $this->wpdb;
		$table = Identifier::quote( $wpdb->posts );
		$tornado_sql_table = Identifier::normalize( $table );
		$limit = max( 1, min( 2000, $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$ids = $wpdb->get_col(
			 $wpdb->prepare(
			 	"SELECT ID FROM %i WHERE post_type = 'revision' ORDER BY ID ASC LIMIT %d",
			 	$tornado_sql_table,
			 	$limit
			 ) 
		);
		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	private function revision_count(): int {
		$wpdb = $this->wpdb;
		$keep = Settings::get( 'revision_limit', 5 );
		if ( 'unlimited' === $keep ) {
			return 0;
		}

		$table = Identifier::quote( $wpdb->posts );
		$tornado_sql_table = Identifier::normalize( $table );
		$keep  = max( 0, (int) $keep );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_parent, COUNT(*) AS revision_count FROM %i WHERE post_type = 'revision' AND post_name NOT LIKE %s AND post_status <> 'pending' GROUP BY post_parent",
				$tornado_sql_table,
				'%-autosave-%'
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $rows as $row ) {
			$count += max( 0, (int) ( $row['revision_count'] ?? 0 ) - $keep );
		}
		return $count;
	}

	/** @return list<int> */
	private function excess_revision_ids( int $limit ): array {
		$wpdb = $this->wpdb;
		$keep = Settings::get( 'revision_limit', 5 );
		if ( 'unlimited' === $keep ) {
			return array();
		}

		$table = Identifier::quote( $wpdb->posts );
		$tornado_sql_table = Identifier::normalize( $table );
		$keep  = max( 0, (int) $keep );
		$limit = max( 1, min( 2000, $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$rows  = $wpdb->get_results(
			 $wpdb->prepare( "SELECT ID, post_parent, post_date_gmt, post_modified_gmt, post_name, post_status FROM %i WHERE post_type = 'revision' ORDER BY post_parent ASC, post_date_gmt DESC, post_modified_gmt DESC, ID DESC", $tornado_sql_table ) ,
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_slice( RevisionPolicyService::select_excess_ids( $rows, $keep ), 0, $limit );
	}

	private function delete_revision( int $revision_id ): bool {
		$wpdb = $this->wpdb;
		if ( $revision_id <= 0 ) {
			return false;
		}

		$posts = Identifier::quote( $wpdb->posts );
		$tornado_sql_posts = Identifier::normalize( $posts );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$type  = (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT post_type FROM %i WHERE ID = %d",
			$tornado_sql_posts,
			$revision_id
		) );
		if ( 'revision' !== $type ) {
			return false;
		}

		$deleted = function_exists( 'wp_delete_post_revision' )
			? wp_delete_post_revision( $revision_id )
			: wp_delete_post( $revision_id, true );
		if ( false !== $deleted && ! $this->post_exists( $revision_id ) ) {
			return true;
		}

		$comments_identifier = Identifier::normalize( $wpdb->comments );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$comment_ids        = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT comment_ID FROM %i WHERE comment_post_ID = %d',
				$comments_identifier,
				$revision_id
			)
		);
		foreach ( is_array( $comment_ids ) ? $comment_ids : array() as $comment_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$wpdb->delete( $wpdb->commentmeta, array( 'comment_id' => (int) $comment_id ), array( '%d' ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$wpdb->delete( $wpdb->comments, array( 'comment_post_ID' => $revision_id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$wpdb->delete( $wpdb->postmeta, array( 'post_id' => $revision_id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$wpdb->delete( $wpdb->term_relationships, array( 'object_id' => $revision_id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$direct_deleted = $wpdb->delete( $wpdb->posts, array( 'ID' => $revision_id, 'post_type' => 'revision' ), array( '%d', '%s' ) );
		if ( (int) $direct_deleted > 0 ) {
			++$this->revision_fallback_deleted;
		}

		if ( function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( $revision_id );
		}

		return ! $this->post_exists( $revision_id );
	}

	private function post_exists( int $post_id ): bool {
		$wpdb = $this->wpdb;
		$posts = Identifier::quote( $wpdb->posts );
		$tornado_sql_posts = Identifier::normalize( $posts );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		return null !== $wpdb->get_var( $wpdb->prepare(
			"SELECT ID FROM %i WHERE ID = %d",
			$tornado_sql_posts,
			$post_id
		) );
	}

	private function wc_count_order_itemmeta(): int {
		$wpdb = $this->wpdb;
		$items = $wpdb->prefix . 'woocommerce_order_items';
		$meta = $wpdb->prefix . 'woocommerce_order_itemmeta';
		if ( ! $this->table_exists( $items ) || ! $this->table_exists( $meta ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i m LEFT JOIN %i i ON i.order_item_id = m.order_item_id WHERE i.order_item_id IS NULL',
				$meta,
				$items
			)
		);
	}

	/** @return list<int> */
	private function wc_order_itemmeta_ids( int $limit ): array {
		$wpdb = $this->wpdb;
		$items = $wpdb->prefix . 'woocommerce_order_items';
		$meta = $wpdb->prefix . 'woocommerce_order_itemmeta';
		if ( ! $this->table_exists( $items ) || ! $this->table_exists( $meta ) ) {
			return array();
		}
		return array_map(
			'intval',
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$wpdb->get_col(
				$wpdb->prepare(
					'SELECT m.meta_id FROM %i m LEFT JOIN %i i ON i.order_item_id = m.order_item_id WHERE i.order_item_id IS NULL LIMIT %d',
					$meta,
					$items,
					$limit
				)
			)
		); 
	}

	private function wc_count_lookup( string $suffix, string $column ): int {
		$wpdb = $this->wpdb;
		$table_name = $wpdb->prefix . $suffix;
		if ( ! $this->table_exists( $table_name ) ) {
			return 0;
		}
		$table = Identifier::quote( $table_name );
		$tornado_sql_table = Identifier::normalize( $table );
		$column_q = Identifier::quote( $column );
		$tornado_sql_column_q = Identifier::normalize( $column_q );
		$posts = Identifier::quote( $wpdb->posts );
		$tornado_sql_posts = Identifier::normalize( $posts );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		return (int) $wpdb->get_var(
			 $wpdb->prepare( "SELECT COUNT(*) FROM %i l LEFT JOIN %i p ON p.ID = l.%i WHERE l.%i <> 0 AND p.ID IS NULL", $tornado_sql_table, $tornado_sql_posts, $tornado_sql_column_q, $tornado_sql_column_q ) 
		); 
	}

	/** @return list<int> */
	private function wc_lookup_ids( string $suffix, string $column, int $limit ): array {
		$wpdb = $this->wpdb;
		$table_name = $wpdb->prefix . $suffix;
		if ( ! $this->table_exists( $table_name ) ) {
			return array();
		}
		$table = Identifier::quote( $table_name );
		$tornado_sql_table = Identifier::normalize( $table );
		$column_q = Identifier::quote( $column );
		$tornado_sql_column_q = Identifier::normalize( $column_q );
		$posts = Identifier::quote( $wpdb->posts );
		$tornado_sql_posts = Identifier::normalize( $posts );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		return array_map( 'intval', $wpdb->get_col(
			 $wpdb->prepare(
			 	"SELECT l.%i FROM %i l LEFT JOIN %i p ON p.ID = l.%i WHERE l.%i <> 0 AND p.ID IS NULL GROUP BY l.%i LIMIT %d",
			 	$tornado_sql_column_q,
			 	$tornado_sql_table,
			 	$tornado_sql_posts,
			 	$tornado_sql_column_q,
			 	$tornado_sql_column_q,
			 	$tornado_sql_column_q,
			 	$limit
			 ) 
		) ); 
	}

	private function wc_count_download_permissions(): int {
		$wpdb = $this->wpdb;
		$table_name = $wpdb->prefix . 'woocommerce_downloadable_product_permissions';
		if ( ! $this->table_exists( $table_name ) ) {
			return 0;
		}
		$table = Identifier::quote( $table_name );
		$tornado_sql_table = Identifier::normalize( $table );
		$posts = Identifier::quote( $wpdb->posts );
		$tornado_sql_posts = Identifier::normalize( $posts );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		return (int) $wpdb->get_var(
			 $wpdb->prepare( "SELECT COUNT(*) FROM %i d LEFT JOIN %i p ON p.ID = d.product_id WHERE d.product_id <> 0 AND p.ID IS NULL", $tornado_sql_table, $tornado_sql_posts ) 
		); 
	}

	/** @return list<int> */
	private function wc_download_permission_ids( int $limit ): array {
		$wpdb = $this->wpdb;
		$table_name = $wpdb->prefix . 'woocommerce_downloadable_product_permissions';
		if ( ! $this->table_exists( $table_name ) ) {
			return array();
		}
		$table = Identifier::quote( $table_name );
		$tornado_sql_table = Identifier::normalize( $table );
		$posts = Identifier::quote( $wpdb->posts );
		$tornado_sql_posts = Identifier::normalize( $posts );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		return array_map( 'intval', $wpdb->get_col(
			 $wpdb->prepare(
			 	"SELECT d.permission_id FROM %i d LEFT JOIN %i p ON p.ID = d.product_id WHERE d.product_id <> 0 AND p.ID IS NULL LIMIT %d",
			 	$tornado_sql_table,
			 	$tornado_sql_posts,
			 	$limit
			 ) 
		) ); 
	}

	private function delete_from_plugin_table( string $suffix, string $column, int $id ): int {
		$wpdb = $this->wpdb;
		$table = $wpdb->prefix . $suffix;
		if ( ! $this->table_exists( $table ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		return max( 0, (int) $wpdb->delete( $table, array( $column => $id ), array( '%d' ) ) );
	}

	private function table_exists( string $table ): bool {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}
}
