<?php
/**
 * Rank Math post-reference adapter.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Adapter;

use RuntimeException;
use Shcd\TornadoDatabaseMaintenance\Database\Identifier;
use Shcd\TornadoDatabaseMaintenance\Database\Tables;

final class RankMathAdapter implements ReferenceAdapterInterface {
	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly Tables $tables
	) {}

	public function id(): string {
		return 'rank-math';
	}

	public function is_available(): bool {
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( '\\RankMath\\Helper' ) ) {
			return true;
		}

		foreach ( $this->known_tables() as $table ) {
			if ( $this->table_exists( $table ) ) {
				return true;
			}
		}

		return false;
	}

	public function scalar_references(): array {
		$wpdb = $this->wpdb;
		$references = array();
		$definitions = array(
			$wpdb->prefix . 'rank_math_internal_links' => array(
				'post_id'        => 'Rank Math internal-link source post',
				'target_post_id' => 'Rank Math internal-link target post',
			),
			$wpdb->prefix . 'rank_math_internal_meta' => array(
				'object_id' => 'Rank Math link-counter object post',
			),
		);

		foreach ( $definitions as $table => $columns ) {
			if ( ! $this->table_exists( $table ) ) {
				continue;
			}
			foreach ( $columns as $column => $label ) {
				if ( $this->column_exists( $table, $column ) ) {
					$references[] = array(
						'table'  => $table,
						'column' => $column,
						'label'  => $label,
					);
				}
			}
		}

		return $references;
	}

	/** @return list<array{table:string,column:string,mode:string}> */
	public function claimed_columns(): array {
		$wpdb = $this->wpdb;
		$table = $wpdb->prefix . 'rank_math_analytics_objects';
		if ( ! $this->table_exists( $table ) || ! $this->column_exists( $table, 'object_id' ) || ! $this->column_exists( $table, 'object_type' ) ) {
			return array();
		}
		return array(
			array(
				'table'  => $table,
				'column' => 'object_id',
				'mode'   => 'conditional:object_type=post',
			),
		);
	}

	public function involved_tables(): array {
		return array_values(
			array_filter(
				$this->known_tables(),
				fn( string $table ): bool => $this->table_exists( $table )
			)
		);
	}

	public function preview_structured( string $mapping_uuid ): array {
		$wpdb = $this->wpdb;
		$unresolved = array();
		$rows       = 0;
		$table      = $wpdb->prefix . 'rank_math_analytics_objects';

		if ( ! $this->table_exists( $table ) ) {
			return array( 'transformable_rows' => 0, 'unresolved' => array() );
		}

		if ( ! $this->column_exists( $table, 'object_id' ) || ! $this->column_exists( $table, 'object_type' ) ) {
			$unresolved[] = array(
				'table'  => $table,
				'path'   => 'object_id',
				'reason' => 'ساختار جدول Analytics در Rank Math شناخته‌شده نیست؛ ستون‌های object_id و object_type لازم‌اند.',
			);
			return array( 'transformable_rows' => 0, 'unresolved' => $unresolved );
		}

		$mapping = Identifier::normalize( $this->tables->mappings() );
		$quoted  = Identifier::normalize( $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$rows    = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i r INNER JOIN %i m ON m.old_id = r.object_id
				 WHERE m.job_uuid = %s AND m.old_id <> m.new_id AND LOWER(r.object_type) = 'post' AND r.object_id <> 0",
				$quoted,
				$mapping,
				$mapping_uuid
			)
		);

		return array( 'transformable_rows' => $rows, 'unresolved' => array() );
	}

	public function transform_structured( string $mapping_uuid, string $source, string $target ): array {
		$wpdb = $this->wpdb;
		$source = $this->mapping_column( $source );
		$target = $this->mapping_column( $target );
		$table  = $wpdb->prefix . 'rank_math_analytics_objects';

		if ( ! $this->table_exists( $table ) ) {
			return array( 'updated' => 0, 'references' => array() );
		}
		if ( ! $this->column_exists( $table, 'object_id' ) || ! $this->column_exists( $table, 'object_type' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
			throw new RuntimeException( 'ساختار جدول Rank Math Analytics برای تبدیل دو‌مرحله‌ای شناخته‌شده نیست.' );
		}

		$mapping      = Identifier::normalize( $this->tables->mappings() );
		$quoted_table = Identifier::normalize( $table );
		$source_col   = Identifier::normalize( $source );
		$target_col   = Identifier::normalize( $target );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Rank Math maintenance must update live reference rows.
		if ( false === $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i r INNER JOIN %i m ON r.object_id = m.%i
				 SET r.object_id = m.%i
				 WHERE m.job_uuid = %s AND m.old_id <> m.new_id AND LOWER(r.object_type) = 'post'",
				$quoted_table,
				$mapping,
				$source_col,
				$target_col,
				$mapping_uuid
			)
		) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
			throw new RuntimeException( 'Referenceهای Rank Math Analytics با Mapping هماهنگ نشدند: ' . $wpdb->last_error );
		}

		$updated = max( 0, (int) $wpdb->rows_affected );
		return array(
			'updated'    => $updated,
			'references' => array( $table . '.object_id' => $updated ),
		);
	}

	public function preflight( string $mapping_uuid ): array {
		$wpdb = $this->wpdb;
		$blockers = array();
		$warnings = array();
		$schemas  = array(
			$wpdb->prefix . 'rank_math_internal_links' => array( 'post_id', 'target_post_id' ),
			$wpdb->prefix . 'rank_math_internal_meta'  => array( 'object_id' ),
			$wpdb->prefix . 'rank_math_analytics_objects' => array( 'object_id', 'object_type' ),
		);

		foreach ( $schemas as $table => $required_columns ) {
			if ( ! $this->table_exists( $table ) ) {
				continue;
			}
			$missing = array_values(
				array_filter(
					$required_columns,
					fn( string $column ): bool => ! $this->column_exists( $table, $column )
				)
			);
			if ( ! empty( $missing ) ) {
				$blockers[] = sprintf( 'ساختار %1$s ناشناخته است؛ ستون‌های %2$s پیدا نشدند.', $table, implode( '، ', $missing ) );
			}
		}

		$analytics = $wpdb->prefix . 'rank_math_analytics_objects';
		if ( $this->table_exists( $analytics ) && $this->column_exists( $analytics, 'object_id' ) && $this->column_exists( $analytics, 'object_type' ) ) {
			$table  = Identifier::normalize( $analytics );
			$posts  = Identifier::normalize( $wpdb->posts );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$orphans = (int) $wpdb->get_var(
				 $wpdb->prepare( "SELECT COUNT(*) FROM %i r LEFT JOIN %i p ON p.ID = r.object_id
				 WHERE LOWER(r.object_type) = 'post' AND r.object_id <> 0 AND p.ID IS NULL", $table, $posts )
			);
			if ( $orphans > 0 ) {
				$warnings[] = sprintf( '%d رکورد قدیمی و یتیم در Rank Math Analytics وجود دارد؛ Reindex آن‌ها را به Post دیگری متصل نمی‌کند.', $orphans );
			}
		}

		return array(
			'supported' => empty( $blockers ),
			'blockers'  => $blockers,
			'warnings'  => $warnings,
		);
	}

	public function repair(): array {
		$wpdb = $this->wpdb;
		$purged = 0;
		foreach ( array( 'rank_math_analytics_data_info', 'rank_math_posts_summary', 'rank_math_dashboard_stats_widget' ) as $key ) {
			if ( delete_transient( $key ) ) {
				++$purged;
			}
		}

		if ( class_exists( '\\RankMath\\Analytics\\DB' ) && is_callable( array( '\\RankMath\\Analytics\\DB', 'purge_cache' ) ) ) {
			try {
				\RankMath\Analytics\DB::purge_cache();
			} catch ( \Throwable ) {
				// Direct transient and object-cache invalidation below remains sufficient.
			}
		}

		wp_cache_flush();
		return array(
			'cache_cleared'      => true,
			'transients_purged'  => $purged,
			'scalar_references'  => count( $this->scalar_references() ),
			'analytics_guarded'  => $this->table_exists( $wpdb->prefix . 'rank_math_analytics_objects' ),
		);
	}

	/** @return list<string> */
	private function known_tables(): array {
		$wpdb = $this->wpdb;
		return array(
			$wpdb->prefix . 'rank_math_internal_links',
			$wpdb->prefix . 'rank_math_internal_meta',
			$wpdb->prefix . 'rank_math_analytics_objects',
		);
	}

	private function mapping_column( string $column ): string {
		if ( ! in_array( $column, array( 'old_id', 'temp_id', 'new_id' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
			throw new RuntimeException( 'ستون Mapping برای Rank Math معتبر نیست.' );
		}
		return $column;
	}

	private function table_exists( string $table ): bool {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
				 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND TABLE_TYPE = 'BASE TABLE'",
				(string) DB_NAME,
				$table
			)
		) > 0;
	}

	private function column_exists( string $table, string $column ): bool {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				(string) DB_NAME,
				$table,
				$column
			)
		) > 0;
	}
}
