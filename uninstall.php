<?php
/**
 * Uninstall handler for Shabnam Tornado Database Maintenance.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	http_response_code( 403 );
	exit;
}

/**
 * Removes Tornado data only when the administrator enabled the uninstall policy.
 */
function shcd_tornado_dbm_uninstall_plugin(): void {
	global $wpdb, $wp_filesystem;

	wp_clear_scheduled_hook( 'shcd_tornado_dbm_scheduled_cleanup' );
	wp_clear_scheduled_hook( 'shcd_tornado_dbm_retention_cleanup' );
	wp_clear_scheduled_hook( 'shcd_tornado_dbm_enforce_revision_limit' );
	wp_clear_scheduled_hook( 'shcd_tornado_dbm_revision_archive_maintenance' );
	delete_transient( 'shcd_tornado_dbm_active_reindex_lock' );

	$capabilities = array(
		'shcd_tornado_dbm_view_dashboard',
		'shcd_tornado_dbm_run_discovery',
		'shcd_tornado_dbm_run_cleanup',
		'shcd_tornado_dbm_manage_backups',
		'shcd_tornado_dbm_restore_backup',
		'shcd_tornado_dbm_execute_reindex',
		'shcd_tornado_dbm_manage_settings',
		'shcd_tornado_dbm_view_sensitive_logs',
	);

	$roles = wp_roles();
	if ( $roles instanceof WP_Roles ) {
		foreach ( $roles->role_objects as $role ) {
			foreach ( $capabilities as $capability ) {
				$role->remove_cap( $capability );
			}
		}
	}

	$settings             = get_option( 'shcd_tornado_dbm_settings', array() );
	$settings             = is_array( $settings ) ? $settings : array();
	$remove_data          = ! empty( $settings['remove_data_on_uninstall'] ) || (bool) get_option( 'shcd_tornado_dbm_remove_data_on_uninstall', false );
	$remove_backups       = ! empty( $settings['remove_backups_on_uninstall'] ) || (bool) get_option( 'shcd_tornado_dbm_remove_backups_on_uninstall', false );
	$backup_storage_token = strtolower( (string) get_option( 'shcd_tornado_dbm_backup_storage_token', '' ) );

	if ( ! $remove_data ) {
		return;
	}

	$backup_files = array();
	if ( $remove_backups ) {
		$backup_table = $wpdb->prefix . 'shcd_tornado_dbm_backups';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct database access is required for schema maintenance and must observe current uncached rows.
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $backup_table ) ) );
		if ( $table_exists === $backup_table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall must read plugin-owned records before deleting its schema.
			$backup_files = $wpdb->get_col( $wpdb->prepare( 'SELECT file_path FROM %i', $backup_table ) );
			$backup_files = is_array( $backup_files ) ? $backup_files : array();
		}
	}

	$table_suffixes = array(
		'shcd_tornado_dbm_jobs',
		'shcd_tornado_dbm_mappings',
		'shcd_tornado_dbm_discoveries',
		'shcd_tornado_dbm_backups',
		'shcd_tornado_dbm_logs',
		'shcd_tornado_dbm_revision_archive',
	);

	foreach ( $table_suffixes as $suffix ) {
		$table = $wpdb->prefix . $suffix;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Explicit removal of plugin-owned tables during uninstall.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
	}

	$transient_patterns = array(
		$wpdb->esc_like( '_transient_shcd_tornado_dbm_arm_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_shcd_tornado_dbm_arm_' ) . '%',
		$wpdb->esc_like( '_transient_shcd_tornado_dbm_reauth_fail_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_shcd_tornado_dbm_reauth_fail_' ) . '%',
		$wpdb->esc_like( '_transient_shcd_tornado_dbm_cleanup_preview_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_shcd_tornado_dbm_cleanup_preview_' ) . '%',
		$wpdb->esc_like( '_transient_shcd_tornado_dbm_auto_increment_preview_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_shcd_tornado_dbm_auto_increment_preview_' ) . '%',
	);
	$transient_placeholders = implode( ' OR ', array_fill( 0, count( $transient_patterns ), 'option_name LIKE %s' ) );
	$transient_args = array_merge( array( $wpdb->options ), $transient_patterns );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Only the generated option-name placeholder list is interpolated; the options-table identifier and every value are prepared.
	$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE {$transient_placeholders}", $transient_args ) );

	$options = array(
		'shcd_tornado_dbm_version',
		'shcd_tornado_dbm_settings',
		'shcd_tornado_dbm_remove_data_on_uninstall',
		'shcd_tornado_dbm_remove_backups_on_uninstall',
		'shcd_tornado_dbm_last_discovery_uuid',
		'shcd_tornado_dbm_last_mapping_uuid',
		'shcd_tornado_dbm_last_executed_mapping_uuid',
		'shcd_tornado_dbm_reindex_generations',
		'shcd_tornado_dbm_last_generation_uuid',
		'shcd_tornado_dbm_last_reindex_preparation',
		'shcd_tornado_dbm_last_prepare_backup_uuid',
		'shcd_tornado_dbm_storage_revision',
		'shcd_tornado_dbm_revision_policy_build',
		'shcd_tornado_dbm_last_backup_uuid',
		'shcd_tornado_dbm_backup_storage_token',
	);

	foreach ( $options as $option_name ) {
		delete_option( $option_name );
		delete_site_option( $option_name );
	}

	if ( ! $remove_backups ) {
		return;
	}

	$allowed_roots = array();
	if ( 1 === preg_match( '/^[a-f0-9]{32}$/', $backup_storage_token ) ) {
		$upload = wp_upload_dir( null, false );
		if ( empty( $upload['error'] ) && ! empty( $upload['basedir'] ) ) {
			$allowed_roots[] = trailingslashit( wp_normalize_path( (string) $upload['basedir'] ) )
				. 'shcd-tornado-dbm-private-backups-'
				. $backup_storage_token;
		}
	}
	if ( defined( 'SHCD_TORNADO_DBM_BACKUP_DIR' ) && is_string( SHCD_TORNADO_DBM_BACKUP_DIR ) && '' !== trim( SHCD_TORNADO_DBM_BACKUP_DIR ) ) {
		$allowed_roots[] = SHCD_TORNADO_DBM_BACKUP_DIR;
	}
	$allowed_roots = array_values(
		array_unique(
			array_map(
				static fn( string $root ): string => trailingslashit( wp_normalize_path( $root ) ),
				$allowed_roots
			)
		)
	);

	$directories = array();
	foreach ( $backup_files as $backup_file ) {
		$normalized = wp_normalize_path( (string) $backup_file );
		$allowed    = false;
		foreach ( $allowed_roots as $root ) {
			if ( str_starts_with( $normalized, $root ) ) {
				$allowed = true;
				break;
			}
		}

		if ( ! $allowed || preg_match( '/\/(?:shcd-tornado-dbm|tornado)-[A-Za-z0-9-]+\.sql$/', $normalized ) !== 1 ) {
			continue;
		}
		if ( is_file( $normalized ) && ! is_link( $normalized ) && wp_delete_file( $normalized ) ) {
			$directories[] = dirname( $normalized );
		}
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem();
	foreach ( array_unique( $directories ) as $directory ) {
		foreach ( array( 'index.php', '.htaccess', 'web.config' ) as $protection_file ) {
			$file = trailingslashit( $directory ) . $protection_file;
			if ( is_file( $file ) && ! is_link( $file ) ) {
				wp_delete_file( $file );
			}
		}
		if ( is_object( $wp_filesystem ) ) {
			$wp_filesystem->rmdir( $directory, false );
		}
	}
}

shcd_tornado_dbm_uninstall_plugin();
