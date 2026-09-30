<?php
/**
 * Plugin Name:       Shabnam Tornado Database Maintenance
 * Description:       Database cleanup, integrity checks, verified backups, and controlled post ID reindexing for WordPress.
 * Version:           1.0.0
 * Requires at least: 7.0
 * Requires PHP:      8.3
 * Plugin URI:        https://shabnam.dev
 * Author:            SHABNAM
 * Author URI:        https://shcd.ir
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       shcd-database-maintenance
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	http_response_code( 403 );
	exit;
}

define( 'SHCD_TORNADO_DBM_VERSION', '1.0.0' );
define( 'SHCD_TORNADO_DBM_BUILD', '2026.08.13-text-domain-review-r16' );
define( 'SHCD_TORNADO_DBM_FILE', __FILE__ );
define( 'SHCD_TORNADO_DBM_DIR', plugin_dir_path( __FILE__ ) );
define( 'SHCD_TORNADO_DBM_URL', plugin_dir_url( __FILE__ ) );
define( 'SHCD_TORNADO_DBM_BASENAME', plugin_basename( __FILE__ ) );
define( 'SHCD_TORNADO_DBM_PLUGIN_SLUG', 'shcd-database-maintenance' );
define( 'SHCD_TORNADO_DBM_TEXT_DOMAIN', 'shcd-database-maintenance' );
define( 'SHCD_TORNADO_DBM_DB_PREFIX', 'shcd_tornado_dbm_' );
define( 'SHCD_TORNADO_DBM_OPTION_PREFIX', 'shcd_tornado_dbm_' );
define( 'SHCD_TORNADO_DBM_TRANSIENT_PREFIX', 'shcd_tornado_dbm_' );

if ( version_compare( PHP_VERSION, '8.3.0', '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			echo '<div class="notice notice-error"><p>' .
				esc_html__( 'Tornado برای اجرا به PHP 8.3 یا نسخه جدیدتر نیاز دارد.', 'shcd-database-maintenance' ) .
				'</p></div>';
		}
	);

	return;
}

require_once SHCD_TORNADO_DBM_DIR . 'src/Core/Autoloader.php';
\Shcd\TornadoDatabaseMaintenance\Core\Autoloader::register();

register_activation_hook( __FILE__, array( \Shcd\TornadoDatabaseMaintenance\Core\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Shcd\TornadoDatabaseMaintenance\Core\Deactivator::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		\Shcd\TornadoDatabaseMaintenance\Core\Plugin::instance()->boot();
	}
);
