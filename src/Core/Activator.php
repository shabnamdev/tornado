<?php
/**
 * Activation routine.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Core;

use Shcd\TornadoDatabaseMaintenance\Database\LegacyPrefixMigrator;
use Shcd\TornadoDatabaseMaintenance\Database\Schema;

final class Activator {
	public static function activate(): void {
		if ( version_compare( PHP_VERSION, '8.3.0', '<' ) ) {
			deactivate_plugins( SHCD_TORNADO_DBM_BASENAME );
			wp_die( esc_html__( 'Tornado requires PHP 8.3 or newer.', 'shcd-database-maintenance' ) );
		}

		LegacyPrefixMigrator::migrate();
		Schema::install();
		self::sync_capabilities();

		if ( false === get_option( 'shcd_tornado_dbm_settings', false ) ) {
			add_option( 'shcd_tornado_dbm_settings', Settings::defaults(), '', false );
		}

		update_option( 'shcd_tornado_dbm_version', SHCD_TORNADO_DBM_VERSION, false );
	}

	public static function sync_capabilities(): void {
		$role = get_role( 'administrator' );
		if ( ! $role ) {
			return;
		}

		foreach ( Capabilities::all() as $capability ) {
			$role->add_cap( $capability );
		}
	}
}
