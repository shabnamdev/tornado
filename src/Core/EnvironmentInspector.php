<?php
/**
 * Read-only environment and privilege preflight.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Core;

use Shcd\TornadoDatabaseMaintenance\Database\Identifier;

final class EnvironmentInspector {
	public function __construct( private readonly \wpdb $wpdb ) {}

	/**
	 * @return array<string, mixed>
	 */
	public function inspect(): array {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$engines = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT ENGINE, COUNT(*) total FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_TYPE = %s GROUP BY ENGINE',
				(string) DB_NAME,
				'BASE TABLE'
			),
			ARRAY_A
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$grants = $wpdb->get_col( 'SHOW GRANTS' ); 
		$grant_text = strtoupper( implode( ' ', is_array( $grants ) ? $grants : array() ) );
		$privileges = array();
		foreach ( array( 'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'ALTER', 'DROP', 'INDEX', 'LOCK TABLES' ) as $privilege ) {
			$privileges[ strtolower( str_replace( ' ', '_', $privilege ) ) ] = str_contains( $grant_text, 'ALL PRIVILEGES' ) || str_contains( $grant_text, $privilege );
		}

		$posts = Identifier::quote( $wpdb->posts );
		$tornado_sql_posts = Identifier::normalize( $posts );
		return array(
			'destructive_emergency_stop' => defined( 'SHCD_TORNADO_DBM_DISABLE_DESTRUCTIVE_OPERATIONS' ) && true === SHCD_TORNADO_DBM_DISABLE_DESTRUCTIVE_OPERATIONS,
			'database_engines'           => is_array( $engines ) ? $engines : array(),
			'database_privileges'        => $privileges,
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			'posts_count'                => (int) $wpdb->get_var(
				 $wpdb->prepare( "SELECT COUNT(*) FROM %i", $tornado_sql_posts ) 
			), 
			'cron_disabled'              => defined( 'DISABLE_WP_CRON' ) && true === DISABLE_WP_CRON,
			'object_cache_external'      => wp_using_ext_object_cache(),
			'memory_limit'               => ini_get( 'memory_limit' ) ?: '',
			'max_execution_time'         => (int) ini_get( 'max_execution_time' ),
			'filesystem_method'          => function_exists( 'get_filesystem_method' ) ? get_filesystem_method() : 'direct',
		);
	}
}
