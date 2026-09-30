<?php
/**
 * Cache and repair integrations.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Cache;

use Shcd\TornadoDatabaseMaintenance\Core\Settings;
use Shcd\TornadoDatabaseMaintenance\Database\AutoIncrementService;
use Shcd\TornadoDatabaseMaintenance\Repair\ElementorRepairService;

final class CachePurger {
	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly AutoIncrementService $auto_increment,
		private readonly ElementorRepairService $elementor_repair
	) {}

	/**
	 * @return array<string, mixed>
	 */
	public function purge( ?string $mapping_uuid = null, bool $allow_attachment_registration = false, string $mapping_mode = 'historical', bool $purge_missing_attachment_rows = false ): array {
		$wpdb = $this->wpdb;
		$result = array();

		if ( (bool) Settings::get( 'elementor_repair_after_reindex', true ) || null === $mapping_uuid ) {
			try {
				$result['elementor'] = $this->elementor_repair->repair( $mapping_uuid, $allow_attachment_registration, $mapping_mode, $purge_missing_attachment_rows );
			} catch ( \Throwable $throwable ) {
				$result['elementor'] = array(
					'success' => false,
					'error'   => sanitize_text_field( $throwable->getMessage() ),
				);
			}
		}

		$result['object_cache'] = wp_cache_flush() ? 'پاک شد' : 'درخواست پاک‌سازی ارسال شد';
		delete_expired_transients( true );
		$result['transients'] = 'Transientهای منقضی‌شده پاک شدند';

		flush_rewrite_rules( false );
		$result['rewrite_rules'] = 'Rewrite Rules بازسازی شد';

		if ( class_exists( '\\WC_Cache_Helper' ) ) {
			\WC_Cache_Helper::get_transient_version( 'product', true );
			\WC_Cache_Helper::get_transient_version( 'shipping', true );
			$result['woocommerce'] = 'نسخه Cache محصولات و حمل‌ونقل WooCommerce بازسازی شد';
		}

		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
			$result['wp_rocket'] = 'Cache دامنه WP Rocket پاک شد';
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party integration hook name is defined by the provider.
		do_action( 'litespeed_purge_all' );
		$result['litespeed'] = 'درخواست Purge کامل LiteSpeed ارسال شد';

		if ( (bool) Settings::get( 'allow_opcache_reset', false ) && function_exists( 'opcache_reset' ) ) {
			$result['opcache'] = opcache_reset() ? 'بازنشانی شد' : 'بازنشانی انجام نشد';
		} else {
			$result['opcache'] = 'درخواست نشد';
		}

		do_action( 'shcd_tornado_dbm_cloudflare_purge_requested' );
		$result['cloudflare'] = 'Hook اختیاری Cloudflare اجرا شد';

		if ( (bool) Settings::get( 'auto_increment_after_cache', true ) ) {
			$result['auto_increment'] = $this->auto_increment->normalize_tables( array( $wpdb->options ), 'cache_purge' );
		}

		return $result;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function repair_elementor(
		?string $mapping_uuid = null,
		bool $allow_attachment_registration = false,
		string $mapping_mode = 'historical',
		bool $purge_missing_attachment_rows = false
	): array {
		return $this->elementor_repair->repair(
			$mapping_uuid,
			$allow_attachment_registration,
			$mapping_mode,
			$purge_missing_attachment_rows
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function elementor_preflight(): array {
		return $this->elementor_repair->preflight();
	}
}
