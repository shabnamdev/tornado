<?php
/**
 * Mapping-aware adapter for previously unknown plugin tables.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Adapter;

use RuntimeException;
use Shcd\TornadoDatabaseMaintenance\Database\Identifier;
use Shcd\TornadoDatabaseMaintenance\Database\Tables;

final class AdaptiveSchemaAdapter implements ReferenceAdapterInterface {
	/** @var list<array{table:string,column:string,label:string}>|null */
	private ?array $direct_cache = null;

	/** @var list<array<string,mixed>>|null */
	private ?array $conditional_cache = null;

	/** @var list<string>|null */
	private ?array $post_types_cache = null;

	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly Tables $tables,
		private readonly AdaptiveStructuredReferenceEngine $structured
	) {}

	public function id(): string {
		return 'adaptive-schema';
	}

	public function is_available(): bool {
		return true;
	}

	public function scalar_references(): array {
		return $this->direct_references();
	}

	/**
	 * Conditional columns owned by this Adapter must not be reinterpreted as
	 * unconditional scalar references by Discovery.
	 *
	 * @return list<array{table:string,column:string,mode:string}>
	 */
	public function claimed_columns(): array {
		$claims = array();
		foreach ( $this->conditional_references() as $reference ) {
			if ( empty( $reference['accepted'] ) ) {
				continue;
			}
			$claims[] = array(
				'table'  => (string) $reference['table'],
				'column' => (string) $reference['id_column'],
				'mode'   => 'conditional:' . (string) $reference['type_column'],
			);
		}
		return $claims;
	}

	public function involved_tables(): array {
		$tables = array();
		foreach ( $this->direct_references() as $reference ) {
			$tables[] = $reference['table'];
		}
		foreach ( $this->conditional_references() as $reference ) {
			if ( ! empty( $reference['accepted'] ) ) {
				$tables[] = (string) $reference['table'];
			}
		}
		$tables = array_merge( $tables, $this->structured->involved_tables() );
		return array_values( array_unique( $tables ) );
	}

	public function preview_structured( string $mapping_uuid ): array {
		$wpdb = $this->wpdb;
		$mapping   = Identifier::normalize( $this->tables->mappings() );
		$posts     = Identifier::normalize( $wpdb->posts );
		$rows      = 0;
		$unresolved = array();

		foreach ( $this->conditional_references() as $reference ) {
			$table_name = (string) $reference['table'];
			$id_name    = (string) $reference['id_column'];
			$type_name  = (string) $reference['type_column'];
			$table       = Identifier::normalize( $table_name );
			$id_column   = Identifier::normalize( $id_name );
			$type_column = Identifier::normalize( $type_name );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Adaptive reference validation must read the current database state.
			$matches = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i r
					 INNER JOIN %i m ON m.old_id = r.%i
					 INNER JOIN %i p ON p.ID = m.old_id
					 WHERE m.job_uuid = %s AND m.old_id <> m.new_id AND r.%i <> 0
					 AND (
						LOWER(r.%i) IN ('post','posts','wp_post')
						OR LOWER(r.%i) = LOWER(p.post_type)
						OR (LOWER(r.%i) IN ('media','image') AND LOWER(p.post_type) = 'attachment')
						OR (LOWER(r.%i) = 'template' AND LOWER(p.post_type) IN ('elementor_library','wp_template','wp_template_part'))
						OR (LEFT(LOWER(r.%i), 5) IN ('post_', 'post:', 'post-') AND SUBSTRING(LOWER(r.%i), 6) = LOWER(p.post_type))
					 )",
					$table,
					$mapping,
					$id_column,
					$posts,
					$mapping_uuid,
					$id_column,
					$type_column,
					$type_column,
					$type_column,
					$type_column,
					$type_column,
					$type_column
				)
			);

			if ( $matches <= 0 ) {
				continue;
			}
			if ( empty( $reference['accepted'] ) ) {
				$unresolved[] = array(
					'table'  => $table_name,
					'path'   => $id_name . '|' . $type_name,
					'reason' => (string) ( $reference['reason'] ?? 'رابطه شرطی با Post ID شواهد کافی ندارد.' ),
					'matching_rows' => $matches,
				);
				continue;
			}
			$rows += $matches;
		}

		$structured = $this->structured->preview( $mapping_uuid );
		$rows += (int) ( $structured['transformable_rows'] ?? 0 );
		$unresolved = array_merge(
			$unresolved,
			array_values( array_filter( (array) ( $structured['unresolved'] ?? array() ), 'is_array' ) )
		);

		return array(
			'transformable_rows' => $rows,
			'unresolved'         => $unresolved,
			'structured'         => $structured,
		);
	}

	public function transform_structured( string $mapping_uuid, string $source, string $target ): array {
		$wpdb = $this->wpdb;
		$source = $this->mapping_column( $source );
		$target = $this->mapping_column( $target );
		$mapping     = Identifier::normalize( $this->tables->mappings() );
		$posts       = Identifier::normalize( $wpdb->posts );
		$source_col  = Identifier::normalize( $source );
		$target_col  = Identifier::normalize( $target );
		$updated     = 0;
		$references  = array();

		foreach ( $this->conditional_references() as $reference ) {
			if ( empty( $reference['accepted'] ) ) {
				continue;
			}
			$table_name = (string) $reference['table'];
			$id_name    = (string) $reference['id_column'];
			$type_name  = (string) $reference['type_column'];
			$table       = Identifier::normalize( $table_name );
			$id_column   = Identifier::normalize( $id_name );
			$type_column = Identifier::normalize( $type_name );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Adaptive reference transformation must update the current database state.
			$query_result = $wpdb->query(
				$wpdb->prepare(
					"UPDATE %i r
					 INNER JOIN %i m ON r.%i = m.%i
					 INNER JOIN %i p ON p.ID = m.%i
					 SET r.%i = m.%i
					 WHERE m.job_uuid = %s AND m.old_id <> m.new_id
					 AND (
						LOWER(r.%i) IN ('post','posts','wp_post')
						OR LOWER(r.%i) = LOWER(p.post_type)
						OR (LOWER(r.%i) IN ('media','image') AND LOWER(p.post_type) = 'attachment')
						OR (LOWER(r.%i) = 'template' AND LOWER(p.post_type) IN ('elementor_library','wp_template','wp_template_part'))
						OR (LEFT(LOWER(r.%i), 5) IN ('post_', 'post:', 'post-') AND SUBSTRING(LOWER(r.%i), 6) = LOWER(p.post_type))
					 )",
					$table,
					$mapping,
					$id_column,
					$source_col,
					$posts,
					$source_col,
					$id_column,
					$target_col,
					$mapping_uuid,
					$type_column,
					$type_column,
					$type_column,
					$type_column,
					$type_column,
					$type_column
				)
			);
			if ( false === $query_result ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
				throw new RuntimeException( sprintf( 'Reference تطبیقی %s.%s اصلاح نشد: %s', $table_name, $id_name, $wpdb->last_error ) );
			}
			$count = max( 0, (int) $wpdb->rows_affected );
			$references[ $table_name . '.' . $id_name ] = $count;
			$updated += $count;
		}

		$structured = $this->structured->transform( $mapping_uuid, $source, $target );
		$updated += (int) ( $structured['updated_references'] ?? 0 );

		return array(
			'updated'    => $updated,
			'references' => $references,
			'structured' => $structured,
		);
	}

	public function preflight( string $mapping_uuid ): array {
		$preview  = $this->preview_structured( $mapping_uuid );
		$warnings = array();
		$direct   = $this->direct_references();
		$accepted = array_values( array_filter( $this->conditional_references(), static fn( array $row ): bool => ! empty( $row['accepted'] ) ) );

		if ( ! empty( $direct ) ) {
			$warnings[] = sprintf( '%d ستون افزونه‌های ناشناخته با تطبیق نام، نوع داده، Post Type و شواهد آماری به‌صورت خودکار ثبت شد.', count( $direct ) );
		}
		if ( ! empty( $accepted ) ) {
			$warnings[] = sprintf( '%d رابطه شرطی چندنوعی با ستون Type شناسایی شد و فقط ردیف‌های متعلق به Postها در Mapping شرکت می‌کنند.', count( $accepted ) );
		}
		$container_count = $this->structured->container_count();
		if ( $container_count > 0 ) {
			$warnings[] = sprintf( '%d ستون JSON/Serialized در جدول‌های افزونه‌ها با مسیرهای معنایی Post ID بررسی می‌شود.', $container_count );
		}

		$blockers = array();
		foreach ( $preview['unresolved'] as $item ) {
			$blockers[] = sprintf(
				'Reference تطبیقی %1$s.%2$s به‌صورت خودکار تغییر نمی‌کند: %3$s',
				(string) ( $item['table'] ?? '?' ),
				(string) ( $item['path'] ?? '?' ),
				(string) ( $item['reason'] ?? 'شواهد کافی برای بازنویسی ایمن وجود ندارد.' )
			);
		}

		return array(
			'supported' => empty( $blockers ),
			'blockers'  => $blockers,
			'warnings'  => $warnings,
		);
	}

	public function repair(): array {
		return array(
			'direct_references'      => count( $this->direct_references() ),
			'conditional_references' => count( array_filter( $this->conditional_references(), static fn( array $row ): bool => ! empty( $row['accepted'] ) ) ),
			'structured_containers'  => $this->structured->container_count(),
			'changed_post_ids'       => false,
		);
	}

	/** @return list<array{table:string,column:string,label:string}> */
	private function direct_references(): array {
		if ( null !== $this->direct_cache ) {
			return $this->direct_cache;
		}

		$rows = $this->numeric_columns();
		$result = array();
		foreach ( $rows as $row ) {
			$table  = (string) $row['TABLE_NAME'];
			$column = (string) $row['COLUMN_NAME'];
			if ( $this->excluded_table( $table ) || $this->protected_column( $column ) ) {
				continue;
			}
			if ( preg_match( '/^(?:(?:source|target|parent|child|related|linked|origin|destination|base)_)?(?:post|page|product|variation|attachment|media|image|thumbnail|template)_id$/i', $column ) !== 1
				&& preg_match( '/^(?:post|page|product|variation|attachment|template)_parent_id$/i', $column ) !== 1
				&& preg_match( '/^(?:post|product|variation)_or_parent_id$/i', $column ) !== 1 ) {
				continue;
			}

			$evidence = $this->scalar_evidence( $table, $column );
			if ( empty( $evidence['accepted'] ) ) {
				continue;
			}
			$result[] = array(
				'table'  => $table,
				'column' => $column,
				'label'  => sprintf( 'Adaptive schema reference (%d/%d matched)', (int) $evidence['matched'], (int) $evidence['sampled'] ),
			);
		}

		$this->direct_cache = $result;
		return $result;
	}

	/** @return list<array<string,mixed>> */
	private function conditional_references(): array {
		if ( null !== $this->conditional_cache ) {
			return $this->conditional_cache;
		}

		$columns_by_table = array();
		foreach ( $this->all_columns() as $row ) {
			$table = (string) $row['TABLE_NAME'];
			$name  = (string) $row['COLUMN_NAME'];
			$columns_by_table[ $table ][ strtolower( $name ) ] = array(
				'name' => $name,
				'type' => strtolower( (string) $row['DATA_TYPE'] ),
			);
		}

		$result = array();
		$id_candidates = array(
			'object_id', 'element_id', 'entity_id', 'content_id', 'item_id', 'resource_id',
			'source_id', 'target_id', 'parent_id', 'related_id', 'linked_id',
		);
		foreach ( $columns_by_table as $table => $columns ) {
			if ( $this->excluded_table( $table ) ) {
				continue;
			}
			foreach ( $id_candidates as $id_key ) {
				if ( ! isset( $columns[ $id_key ] ) || ! $this->numeric_type( (string) $columns[ $id_key ]['type'] ) ) {
					continue;
				}
				$id_column = (string) $columns[ $id_key ]['name'];
				$stem       = substr( $id_key, 0, -3 );
				$type_column = '';
				$type_candidates = array_values(
					array_unique(
						array(
							$stem . '_type',
							$stem . '_kind',
							$stem . '_object_type',
							'object_type',
							'element_type',
							'entity_type',
							'content_type',
							'item_type',
							'resource_type',
						)
					)
				);
				foreach ( $type_candidates as $candidate ) {
					if ( isset( $columns[ $candidate ] ) && $this->text_type( (string) $columns[ $candidate ]['type'] ) ) {
						$type_column = (string) $columns[ $candidate ]['name'];
						break;
					}
				}
				if ( '' === $type_column ) {
					continue;
				}

				$evidence = $this->conditional_evidence( $table, $id_column, $type_column );
				if ( 0 === (int) $evidence['sampled'] ) {
					continue;
				}
				$result[] = array_merge(
					array(
						'table'       => $table,
						'id_column'   => $id_column,
						'type_column' => $type_column,
					),
					$evidence
				);
			}
		}

		$this->conditional_cache = $result;
		return $result;
	}
	/** @return array{accepted:bool,sampled:int,matched:int,ratio:float,reason:string} */
	private function scalar_evidence( string $table_name, string $column_name ): array {
		$wpdb = $this->wpdb;
		$table  = Identifier::quote( $table_name );
		$tornado_sql_table = Identifier::normalize( $table );
		$column = Identifier::quote( $column_name );
		$tornado_sql_column = Identifier::normalize( $column );
		$posts  = Identifier::quote( $wpdb->posts );
		$tornado_sql_posts = Identifier::normalize( $posts );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$row    = $wpdb->get_row(
			 $wpdb->prepare( "SELECT COUNT(*) sampled, COALESCE(SUM(CASE WHEN p.ID IS NOT NULL THEN 1 ELSE 0 END),0) matched
			 FROM (SELECT %i value_id FROM %i WHERE %i IS NOT NULL AND %i <> 0) s
			 LEFT JOIN %i p ON p.ID = s.value_id", $tornado_sql_column, $tornado_sql_table, $tornado_sql_column, $tornado_sql_column, $tornado_sql_posts ) ,
			ARRAY_A
		);
		$sampled = (int) ( $row['sampled'] ?? 0 );
		$matched = (int) ( $row['matched'] ?? 0 );
		$ratio   = $sampled > 0 ? $matched / $sampled : 0.0;
		$types_ok = $matched > 0 ? $this->post_types_compatible( $table_name, $column_name ) : false;
		$accepted = $sampled > 0 && $matched > 0 && $ratio >= 0.98 && $types_ok;

		return array(
			'accepted' => $accepted,
			'sampled'  => $sampled,
			'matched'  => $matched,
			'ratio'    => round( $ratio, 4 ),
			'reason'   => $accepted ? 'شواهد آماری و معنایی رابطه را تأیید کردند.' : 'نسبت تطبیق یا Post Typeهای منطبق برای ثبت خودکار کافی نیست.',
		);
	}

	/** @return array{accepted:bool,sampled:int,matched:int,ratio:float,reason:string} */
	private function conditional_evidence( string $table_name, string $id_name, string $type_name ): array {
		$wpdb        = $this->wpdb;
		$table       = Identifier::normalize( $table_name );
		$id_column   = Identifier::normalize( $id_name );
		$type_column = Identifier::normalize( $type_name );
		$posts       = Identifier::normalize( $wpdb->posts );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema evidence must inspect live rows.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) sampled,
				 COALESCE(SUM(CASE WHEN p.ID IS NOT NULL AND (
					LOWER(s.type_value) IN ('post','posts','wp_post')
					OR LOWER(s.type_value) = LOWER(p.post_type)
					OR (LOWER(s.type_value) IN ('media','image') AND LOWER(p.post_type) = 'attachment')
					OR (LOWER(s.type_value) = 'template' AND LOWER(p.post_type) IN ('elementor_library','wp_template','wp_template_part'))
					OR (LEFT(LOWER(s.type_value), 5) IN ('post_', 'post:', 'post-') AND SUBSTRING(LOWER(s.type_value), 6) = LOWER(p.post_type))
				 ) THEN 1 ELSE 0 END),0) matched
				 FROM (
					SELECT r.%i value_id, r.%i type_value FROM %i r
					WHERE r.%i IS NOT NULL AND r.%i <> 0 AND (
						LOWER(r.%i) IN ('post','posts','wp_post','page','product','attachment','template','media','image')
						OR LEFT(LOWER(r.%i), 5) IN ('post_', 'post:', 'post-')
						OR EXISTS (SELECT 1 FROM %i pt WHERE LOWER(pt.post_type) = LOWER(r.%i) LIMIT 1)
					)
				 ) s
				 LEFT JOIN %i p ON p.ID = s.value_id",
				$id_column,
				$type_column,
				$table,
				$id_column,
				$id_column,
				$type_column,
				$type_column,
				$posts,
				$type_column,
				$posts
			),
			ARRAY_A
		);
		$sampled  = (int) ( $row['sampled'] ?? 0 );
		$matched  = (int) ( $row['matched'] ?? 0 );
		$ratio    = $sampled > 0 ? $matched / $sampled : 0.0;
		$accepted = $sampled > 0 && $matched > 0 && $ratio >= 0.98;
		return array(
			'accepted' => $accepted,
			'sampled'  => $sampled,
			'matched'  => $matched,
			'ratio'    => round( $ratio, 4 ),
			'reason'   => $accepted
				? 'ستون Type ردیف‌های Post را مشخص می‌کند و دست‌کم ۹۸٪ نمونه‌ها به Post موجود متصل‌اند.'
				: 'ستون چندنوعی است، اما نسبت تطبیق ردیف‌های Post با جدول نوشته‌ها کمتر از حد ایمن ۹۸٪ است.',
		);
	}

	private function post_types_compatible( string $table_name, string $column_name ): bool {
		$wpdb = $this->wpdb;
		$table  = Identifier::quote( $table_name );
		$tornado_sql_table = Identifier::normalize( $table );
		$column = Identifier::quote( $column_name );
		$tornado_sql_column = Identifier::normalize( $column );
		$posts  = Identifier::quote( $wpdb->posts );
		$tornado_sql_posts = Identifier::normalize( $posts );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$rows   = $wpdb->get_col(
			 $wpdb->prepare( "SELECT DISTINCT p.post_type FROM (SELECT %i value_id FROM %i
			 WHERE %i IS NOT NULL AND %i <> 0) s
			 INNER JOIN %i p ON p.ID = s.value_id", $tornado_sql_column, $tornado_sql_table, $tornado_sql_column, $tornado_sql_column, $tornado_sql_posts ) 
		);
		$types = array_values( array_unique( array_map( 'strval', is_array( $rows ) ? $rows : array() ) ) );
		if ( empty( $types ) ) {
			return false;
		}

		$expected = array();
		if ( preg_match( '/attachment|media|image|thumbnail/i', $column_name ) === 1 ) {
			$expected = array( 'attachment' );
		} elseif ( preg_match( '/variation/i', $column_name ) === 1 ) {
			$expected = array( 'product_variation' );
		} elseif ( preg_match( '/product/i', $column_name ) === 1 ) {
			$expected = array( 'product', 'product_variation' );
		} elseif ( preg_match( '/template/i', $column_name ) === 1 ) {
			$expected = array( 'elementor_library', 'wp_template', 'wp_template_part' );
		} elseif ( preg_match( '/(?:^|_)page_id$/i', $column_name ) === 1 ) {
			$expected = array( 'page' );
		}
		if ( empty( $expected ) ) {
			return true;
		}
		foreach ( $types as $type ) {
			if ( ! in_array( $type, $expected, true ) ) {
				return false;
			}
		}
		return true;
	}

	/** @return list<array<string,string>> */
	private function numeric_columns(): array {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.TABLE_NAME, c.COLUMN_NAME, c.DATA_TYPE, c.COLUMN_KEY, c.EXTRA
				 FROM INFORMATION_SCHEMA.COLUMNS c
				 INNER JOIN INFORMATION_SCHEMA.TABLES t
				 ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME AND t.TABLE_TYPE = 'BASE TABLE'
				 WHERE c.TABLE_SCHEMA = %s AND c.TABLE_NAME LIKE %s
				 AND c.DATA_TYPE IN ('tinyint','smallint','mediumint','int','bigint')
				 AND c.COLUMN_KEY <> 'PRI' AND c.EXTRA NOT LIKE %s",
				(string) DB_NAME,
				$wpdb->esc_like( $wpdb->prefix ) . '%',
				'%auto_increment%'
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/** @return list<array<string,string>> */
	private function all_columns(): array {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.TABLE_NAME, c.COLUMN_NAME, c.DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS c
				 INNER JOIN INFORMATION_SCHEMA.TABLES t
				 ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME AND t.TABLE_TYPE = 'BASE TABLE'
				 WHERE c.TABLE_SCHEMA = %s AND c.TABLE_NAME LIKE %s",
				(string) DB_NAME,
				$wpdb->esc_like( $wpdb->prefix ) . '%'
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	private function excluded_table( string $table ): bool {
		$wpdb = $this->wpdb;
		$core = array(
			$wpdb->posts,
			$wpdb->postmeta,
			$wpdb->comments,
			$wpdb->commentmeta,
			$wpdb->terms,
			$wpdb->termmeta,
			$wpdb->term_taxonomy,
			$wpdb->term_relationships,
			$wpdb->users,
			$wpdb->usermeta,
			$wpdb->options,
		);
		if ( in_array( $table, $core, true ) ) {
			return true;
		}
		if ( str_starts_with( $table, $wpdb->prefix . SHCD_TORNADO_DBM_DB_PREFIX ) ) {
			return true;
		}
		// Dedicated adapters own these schemas and may apply row-level conditions.
		return str_starts_with( $table, $wpdb->prefix . 'rank_math_' );
	}

	private function protected_column( string $column ): bool {
		return preg_match( '/(?:^|_)(?:user|term|comment|customer|author|transaction|payment|coupon|category|taxonomy|site|blog|network|order)(?:_?id)?$/i', $column ) === 1
			|| preg_match( '/(?:^|_)(?:count|total|quantity|qty|amount|price|stock|status|type|position|sort|number|index)_?id?$/i', $column ) === 1;
	}

	private function post_type_condition( string $qualified_column ): string {
		$values = array_merge(
			array( 'post', 'posts', 'wp_post', 'page', 'product', 'attachment', 'template', 'media', 'image' ),
			$this->post_types()
		);
		$values = array_values( array_unique( array_filter( array_map( 'sanitize_key', $values ) ) ) );
		$literals = array_map(
			static fn( string $value ): string => "'" . esc_sql( $value ) . "'",
			$values
		);
		$in = empty( $literals ) ? "'post'" : implode( ',', $literals );

		return "LOWER({$qualified_column}) IN ({$in})
			OR LEFT(LOWER({$qualified_column}), 5) IN ('post_', 'post:', 'post-')";
	}

	private function post_type_compatibility_condition( string $type_column, string $post_type_column ): string {
		$type = "LOWER({$type_column})";
		$post = "LOWER({$post_type_column})";
		return "(
			{$type} IN ('post','posts','wp_post')
			OR {$type} = {$post}
			OR ({$type} IN ('media','image') AND {$post} = 'attachment')
			OR ({$type} = 'template' AND {$post} IN ('elementor_library','wp_template','wp_template_part'))
			OR (LEFT({$type}, 5) IN ('post_', 'post:', 'post-') AND SUBSTRING({$type}, 6) = {$post})
		)";
	}

	/** @return list<string> */
	private function post_types(): array {
		$wpdb = $this->wpdb;
		if ( null !== $this->post_types_cache ) {
			return $this->post_types_cache;
		}
		$posts = Identifier::quote( $wpdb->posts );
		$tornado_sql_posts = Identifier::normalize( $posts );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$types = $wpdb->get_col(
			 $wpdb->prepare( "SELECT DISTINCT post_type FROM %i WHERE post_type <> ''", $tornado_sql_posts ) 
		);
		$this->post_types_cache = array_values(
			array_unique(
				array_filter( array_map( 'sanitize_key', is_array( $types ) ? $types : array() ) )
			)
		);
		return $this->post_types_cache;
	}

	private function mapping_column( string $column ): string {
		if ( ! in_array( $column, array( 'old_id', 'temp_id', 'new_id' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
			throw new RuntimeException( 'ستون Mapping برای Adapter تطبیقی معتبر نیست.' );
		}
		return $column;
	}

	private function numeric_type( string $type ): bool {
		return in_array( strtolower( $type ), array( 'tinyint', 'smallint', 'mediumint', 'int', 'bigint' ), true );
	}

	private function text_type( string $type ): bool {
		return in_array( strtolower( $type ), array( 'char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'enum', 'set' ), true );
	}
}
