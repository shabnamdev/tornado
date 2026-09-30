<?php
/**
 * Deterministic database fingerprints used to bind discovery, mappings and backups.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Database;

use RuntimeException;

final class FingerprintService {
	public function __construct( private readonly \wpdb $wpdb ) {}

	/**
	 * Hashes the ordered wp_posts ID set without loading it all into memory.
	 */
	public function post_ids(): string {
		$wpdb = $this->wpdb;
		$table   = Identifier::normalize( $wpdb->posts );
		$cursor  = 0;
		$context = hash_init( 'sha256' );
		$total   = 0;

		while ( true ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM %i WHERE ID > %d ORDER BY ID ASC LIMIT %d",
					$table,
					$cursor,
					2000
				)
			);

			if ( ! is_array( $ids ) || empty( $ids ) ) {
				break;
			}

			foreach ( $ids as $raw_id ) {
				$id = (int) $raw_id;
				hash_update( $context, $id . "\n" );
				$cursor = $id;
				++$total;
			}
		}

		hash_update( $context, ':' . $total );
		return hash_final( $context );
	}

	/**
	 * Hashes tables, columns, indexes and foreign-key metadata in a stable order.
	 */
	public function schema(): string {
		$wpdb = $this->wpdb;
		$schema = (string) DB_NAME;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema fingerprinting must read live INFORMATION_SCHEMA metadata.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT object_type, table_name, object_name, definition FROM (
					SELECT 'COLUMN' object_type, TABLE_NAME table_name, COLUMN_NAME object_name,
						CONCAT_WS('|', ORDINAL_POSITION, COLUMN_TYPE, IS_NULLABLE, COALESCE(COLUMN_DEFAULT, '<NULL>'), EXTRA, COLLATION_NAME) definition
					FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s
					UNION ALL
					SELECT 'INDEX', TABLE_NAME, INDEX_NAME,
						GROUP_CONCAT(CONCAT_WS(':', SEQ_IN_INDEX, COLUMN_NAME, COALESCE(SUB_PART, 0), NON_UNIQUE) ORDER BY SEQ_IN_INDEX SEPARATOR ',')
					FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = %s GROUP BY TABLE_NAME, INDEX_NAME
					UNION ALL
					SELECT 'FOREIGN_KEY', TABLE_NAME, CONSTRAINT_NAME,
						GROUP_CONCAT(CONCAT_WS(':', ORDINAL_POSITION, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME) ORDER BY ORDINAL_POSITION SEPARATOR ',')
					FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
					WHERE TABLE_SCHEMA = %s AND REFERENCED_TABLE_NAME IS NOT NULL GROUP BY TABLE_NAME, CONSTRAINT_NAME
				) x ORDER BY object_type, table_name, object_name",
				$schema,
				$schema,
				$schema
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			throw new RuntimeException( 'Unable to calculate the database schema fingerprint.' );
		}

		$context = hash_init( 'sha256' );
		foreach ( $rows as $row ) {
			hash_update(
				$context,
				implode(
					"\x1F",
					array(
						(string) ( $row['object_type'] ?? '' ),
						(string) ( $row['table_name'] ?? '' ),
						(string) ( $row['object_name'] ?? '' ),
						(string) ( $row['definition'] ?? '' ),
					)
				) . "\x1E"
			);
		}

		return hash_final( $context );
	}
}
