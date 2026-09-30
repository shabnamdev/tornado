<?php
/**
 * Typed plugin settings.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Core;

final class Settings {
	private const OPTION = 'shcd_tornado_dbm_settings';

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'revision_storage_mode'     => 'wordpress',
			'revision_limit'            => 5,
			'revision_archive_limit'    => 20,
			'revision_archive_days'     => 90,
			'revision_archive_elementor'=> true,
			'revision_archive_woocommerce' => true,
			'autosave_retention_days'   => 7,
			'pending_revision_retention_days' => -1,
			'auto_draft_days'            => 7,
			'trash_days'                 => 30,
			'scheduled_cleanup'          => false,
			'rebuild_auto_increment'     => true,
			'auto_increment_after_reindex' => true,
			'auto_increment_after_cleanup' => true,
			'auto_increment_after_cache'   => true,
			'auto_increment_scope'         => 'all_prefixed',
			'allow_destructive_reindex'  => false,
			'auto_register_discovered_references' => true,
			'auto_resolve_ambiguous_references'   => true,
			'reference_policies'                    => array(),
			'elementor_repair_after_reindex'       => true,
			'purge_missing_attachment_rows_before_reindex' => true,
			'purge_media_cleaner_trash_before_reindex'     => true,
			'restore_missing_attachments_from_uploads'     => false,
			'allow_order_reindex'        => false,
			'allow_opcache_reset'        => false,
			'theme'                      => 'auto',
			'backup_retention_days'      => 14,
			'job_retention_days'         => 30,
			'log_retention_days'         => 30,
			'cleanup_batch_size'         => 500,
			'delete_unknown_tables'      => false,
			'remove_data_on_uninstall'    => false,
			'remove_backups_on_uninstall' => false,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$value = get_option( self::OPTION, array() );
		$value = is_array( $value ) ? $value : array();

		return array_replace( self::defaults(), $value );
	}

	public static function get( string $key, mixed $default = null ): mixed {
		$settings = self::all();
		return $settings[ $key ] ?? $default;
	}

	/**
	 * @param array<string, mixed> $input Raw settings.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input ): array {
		$defaults = self::defaults();
		$output   = $defaults;

		$revision_mode = sanitize_key( (string) ( $input['revision_storage_mode'] ?? $defaults['revision_storage_mode'] ) );
		$output['revision_storage_mode'] = in_array( $revision_mode, array( 'wordpress', 'archive', 'off' ), true ) ? $revision_mode : 'wordpress';

		$revision = $input['revision_limit'] ?? $defaults['revision_limit'];
		if ( 'unlimited' === $revision ) {
			$output['revision_limit'] = 'unlimited';
		} else {
			$output['revision_limit'] = max( 0, min( 100, absint( $revision ) ) );
		}

		$output['revision_archive_limit'] = max( 0, min( 500, absint( $input['revision_archive_limit'] ?? 20 ) ) );
		$output['revision_archive_days'] = max( 0, min( 3650, absint( $input['revision_archive_days'] ?? 90 ) ) );
		$output['revision_archive_elementor'] = ! empty( $input['revision_archive_elementor'] );
		$output['revision_archive_woocommerce'] = ! empty( $input['revision_archive_woocommerce'] );
		$output['autosave_retention_days'] = max( 0, min( 3650, absint( $input['autosave_retention_days'] ?? 7 ) ) );
		$output['pending_revision_retention_days'] = max( -1, min( 3650, (int) ( $input['pending_revision_retention_days'] ?? -1 ) ) );

		$output['auto_draft_days']           = max( 0, min( 3650, absint( $input['auto_draft_days'] ?? 7 ) ) );
		$output['trash_days']                = max( 0, min( 3650, absint( $input['trash_days'] ?? 30 ) ) );
		$output['scheduled_cleanup']         = ! empty( $input['scheduled_cleanup'] );
		$output['rebuild_auto_increment']      = ! empty( $input['rebuild_auto_increment'] );
		$output['auto_increment_after_reindex'] = ! empty( $input['auto_increment_after_reindex'] );
		$output['auto_increment_after_cleanup'] = ! empty( $input['auto_increment_after_cleanup'] );
		$output['auto_increment_after_cache']   = ! empty( $input['auto_increment_after_cache'] );

		$auto_increment_scope = sanitize_key( (string) ( $input['auto_increment_scope'] ?? 'all_prefixed' ) );
		$output['auto_increment_scope'] = in_array( $auto_increment_scope, array( 'posts_only', 'wordpress_core', 'all_prefixed' ), true ) ? $auto_increment_scope : 'all_prefixed';
		$output['allow_destructive_reindex'] = ! empty( $input['allow_destructive_reindex'] );
		$output['auto_register_discovered_references'] = ! empty( $input['auto_register_discovered_references'] );
		$output['auto_resolve_ambiguous_references'] = ! empty( $input['auto_resolve_ambiguous_references'] );
		$output['reference_policies'] = self::sanitize_reference_policies( $input['reference_policies'] ?? array() );
		$output['elementor_repair_after_reindex'] = ! empty( $input['elementor_repair_after_reindex'] );
		$output['purge_missing_attachment_rows_before_reindex'] = ! empty( $input['purge_missing_attachment_rows_before_reindex'] );
		$output['purge_media_cleaner_trash_before_reindex'] = ! empty( $input['purge_media_cleaner_trash_before_reindex'] );
		$output['restore_missing_attachments_from_uploads'] = ! empty( $input['restore_missing_attachments_from_uploads'] );
		$output['allow_order_reindex']       = ! empty( $input['allow_order_reindex'] );
		$output['allow_opcache_reset']       = ! empty( $input['allow_opcache_reset'] );
		$output['backup_retention_days']     = max( 1, min( 3650, absint( $input['backup_retention_days'] ?? 14 ) ) );
		$output['job_retention_days']        = max( 1, min( 3650, absint( $input['job_retention_days'] ?? 30 ) ) );
		$output['log_retention_days']        = max( 1, min( 3650, absint( $input['log_retention_days'] ?? 30 ) ) );
		$output['cleanup_batch_size']        = max( 50, min( 2000, absint( $input['cleanup_batch_size'] ?? 500 ) ) );
		$output['delete_unknown_tables']      = false;
		$output['remove_data_on_uninstall']    = ! empty( $input['remove_data_on_uninstall'] );
		$output['remove_backups_on_uninstall'] = ! empty( $input['remove_backups_on_uninstall'] );

		$theme = sanitize_key( (string) ( $input['theme'] ?? 'auto' ) );
		$output['theme'] = in_array( $theme, array( 'auto', 'light', 'dark' ), true ) ? $theme : 'auto';

		return $output;
	}

	/**
	 * @return array<string, string>
	 */
	private static function sanitize_reference_policies( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$allowed = array( 'update', 'ignore', 'detach', 'delete_rows' );
		$output  = array();
		foreach ( $value as $reference => $policy ) {
			$reference = trim( (string) $reference );
			$policy    = sanitize_key( (string) $policy );
			if ( 1 !== preg_match( '/^[A-Za-z0-9_]+\.[A-Za-z0-9_]+$/', $reference ) ) {
				continue;
			}
			if ( ! in_array( $policy, $allowed, true ) ) {
				continue;
			}
			$output[ $reference ] = $policy;
		}
		ksort( $output );
		return $output;
	}

	/**
	 * @param array<string, mixed> $settings Sanitized settings.
	 */
	public static function update( array $settings ): bool {
		$sanitized = self::sanitize( $settings );
		$result    = update_option( self::OPTION, $sanitized, false );
		$stored    = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) || $stored !== $sanitized ) {
			global $wpdb;
			$options_table = isset( $wpdb->options ) ? (string) $wpdb->options : 'options';
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is not HTML output; the table name is supplied by wpdb.
			throw new \RuntimeException( sprintf( 'تنظیمات افزونه در جدول %s ذخیره نشد. دسترسی نوشتن دیتابیس و وضعیت Object Cache را بررسی کنید.', $options_table ) );
		}

		return $result || $stored === $sanitized;
	}
}
