<?php
/**
 * Safely migrates internal data created by earlier development builds.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Database;

final class LegacyPrefixMigrator {
	private const CURRENT_PREFIX = 'shcd_tornado_dbm_';

	/** @var string[] */
	private const LEGACY_PREFIXES = array(
		'shcd_tornado_',
		'shabnam_tornado_',
		'shcd_database_',
	);

	/** @var string[] */
	private const TABLE_SUFFIXES = array(
		'jobs',
		'mappings',
		'discoveries',
		'backups',
		'logs',
		'revision_archive',
	);

	public static function migrate(): void {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return;
		}

		foreach ( self::LEGACY_PREFIXES as $legacy_prefix ) {
			self::migrate_tables( $wpdb, $legacy_prefix );
			self::migrate_options( $wpdb, $legacy_prefix );
		}

		self::remove_legacy_schedules();
		self::remove_legacy_capabilities();
	}

	private static function migrate_tables( \wpdb $wpdb, string $legacy_prefix ): void {
		$old_base = $wpdb->prefix . $legacy_prefix;
		$new_base = $wpdb->prefix . self::CURRENT_PREFIX;

		foreach ( self::TABLE_SUFFIXES as $suffix ) {
			self::rename_table_if_safe( $wpdb, $old_base . $suffix, $new_base . $suffix );
		}

		$pattern = $wpdb->esc_like( $old_base . 'quarantine_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema migration requires uncached table discovery.
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pattern ) );
		$tables = is_array( $tables ) ? $tables : array();

		foreach ( $tables as $old_table ) {
			$old_table = (string) $old_table;
			if ( ! str_starts_with( $old_table, $old_base . 'quarantine_' ) ) {
				continue;
			}

			$suffix = substr( $old_table, strlen( $old_base ) );
			self::rename_table_if_safe( $wpdb, $old_table, $new_base . $suffix );
		}
	}

	private static function rename_table_if_safe( \wpdb $wpdb, string $source, string $target ): void {
		if ( ! self::valid_identifier( $source ) || ! self::valid_identifier( $target ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema migration requires uncached table discovery.
		$source_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $source ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema migration requires uncached table discovery.
		$target_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $target ) ) );

		if ( $source_exists !== $source || $target_exists === $target ) {
			return;
		}

		// %i is the WordPress identifier placeholder and quotes both validated table names safely.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- This is a one-time schema migration.
		$wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $source, $target ) );
	}

	private static function migrate_options( \wpdb $wpdb, string $legacy_prefix ): void {
		$patterns = array(
			$wpdb->esc_like( $legacy_prefix ) . '%',
			$wpdb->esc_like( '_transient_' . $legacy_prefix ) . '%',
			$wpdb->esc_like( '_transient_timeout_' . $legacy_prefix ) . '%',
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration must inspect current option names directly.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT option_id, option_name FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s',
				$wpdb->options,
				$patterns[0],
				$patterns[1],
				$patterns[2]
			),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();

		foreach ( $rows as $row ) {
			$option_id = isset( $row['option_id'] ) ? absint( $row['option_id'] ) : 0;
			$old_name  = isset( $row['option_name'] ) ? (string) $row['option_name'] : '';
			$new_name  = str_replace( $legacy_prefix, self::CURRENT_PREFIX, $old_name );

			if ( $option_id < 1 || '' === $old_name || $old_name === $new_name ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration must avoid overwriting an already-migrated option.
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT option_name FROM %i WHERE option_name = %s LIMIT 1',
					$wpdb->options,
					$new_name
				)
			);
			if ( $existing === $new_name ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Updating option_name is required for the one-time prefix migration.
			$wpdb->update(
				$wpdb->options,
				array( 'option_name' => $new_name ),
				array( 'option_id' => $option_id ),
				array( '%s' ),
				array( '%d' )
			);
		}
	}

	private static function remove_legacy_schedules(): void {
		foreach ( self::LEGACY_PREFIXES as $prefix ) {
			foreach ( array( 'scheduled_cleanup', 'retention_cleanup', 'enforce_revision_limit', 'revision_archive_maintenance' ) as $suffix ) {
				wp_clear_scheduled_hook( $prefix . $suffix );
			}
		}
	}

	private static function remove_legacy_capabilities(): void {
		$roles = wp_roles();
		if ( ! $roles instanceof \WP_Roles ) {
			return;
		}

		$capability_suffixes = array(
			'view_dashboard',
			'run_discovery',
			'run_cleanup',
			'manage_backups',
			'restore_backup',
			'execute_reindex',
			'manage_settings',
			'view_sensitive_logs',
		);

		foreach ( $roles->role_objects as $role ) {
			foreach ( self::LEGACY_PREFIXES as $prefix ) {
				foreach ( $capability_suffixes as $suffix ) {
					$role->remove_cap( $prefix . $suffix );
				}
			}
		}
	}

	private static function valid_identifier( string $identifier ): bool {
		return preg_match( '/^[A-Za-z0-9_]+$/', $identifier ) === 1;
	}
}
