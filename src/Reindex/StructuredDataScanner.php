<?php
/**
 * Conservative scanner and generic path-aware transformer for embedded post IDs.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Reindex;

use Shcd\TornadoDatabaseMaintenance\Database\Identifier;
use Shcd\TornadoDatabaseMaintenance\Database\Tables;
use RuntimeException;

final class StructuredDataScanner {
	private const BATCH_SIZE = 500;
	private const MAX_REPORTED_HITS = 250;
	private const REFERENCE_KEY_PATTERN  = '/(?:(?:^|_)(?:post|page|product|attachment|template|media|image|thumbnail|object)(?:_?id|_?ids)?$)|^(?:post_parent|parent_post_id|parent_page_id)$/i';

	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly Tables $tables
	) {}

	/**
	 * @return array{complete:bool,scanned_rows:int,candidate_rows:int,hits:list<array<string,mixed>>}
	 */
	public function scan( string $mapping_uuid ): array {
		$wpdb = $this->wpdb;
		$hits           = array();
		$hit_count      = 0;
		$scanned_rows   = 0;
		$candidate_rows = 0;

		foreach ( $this->containers() as $container ) {
			$table   = Identifier::normalize( $container['table'] );
			$id      = Identifier::normalize( $container['id'] );
			$context = Identifier::normalize( $container['context'] );
			$value   = Identifier::normalize( $container['value'] );
			$mode    = (string) $container['mode'];

			if ( 'postmeta' === $mode ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Structured-data scanning requires current database state.
				$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE meta_key NOT IN ('_elementor_data','_crosssell_ids','_upsell_ids','_children') AND (meta_value REGEXP '^(a:[0-9]+:|O:[0-9]+:)' OR LEFT(LTRIM(meta_value), 1) IN ('{', '['))", $table ) );
			} elseif ( 'order_itemmeta' === $mode ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Structured-data scanning requires current database state.
				$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE meta_key NOT IN ('_product_id','_variation_id') AND (meta_value REGEXP '^(a:[0-9]+:|O:[0-9]+:)' OR LEFT(LTRIM(meta_value), 1) IN ('{', '['))", $table ) );
			} elseif ( 'option' === $mode ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Structured-data scanning requires current database state.
				$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE option_value REGEXP '^(a:[0-9]+:|O:[0-9]+:)' OR LEFT(LTRIM(option_value), 1) IN ('{', '[')", $table ) );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Structured-data scanning requires current database state.
				$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE meta_value REGEXP '^(a:[0-9]+:|O:[0-9]+:)' OR LEFT(LTRIM(meta_value), 1) IN ('{', '[')", $table ) );
			}
			$candidate_rows += $count;
			$last_id = 0;

			do {
				if ( 'postmeta' === $mode ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Structured-data scanning requires current database state.
					$rows = $wpdb->get_results( $wpdb->prepare( "SELECT %i AS row_id, %i AS context_name, %i AS container_value FROM %i WHERE meta_key NOT IN ('_elementor_data','_crosssell_ids','_upsell_ids','_children') AND (meta_value REGEXP '^(a:[0-9]+:|O:[0-9]+:)' OR LEFT(LTRIM(meta_value), 1) IN ('{', '[')) AND %i > %d ORDER BY %i ASC LIMIT %d", $id, $context, $value, $table, $id, $last_id, $id, self::BATCH_SIZE ), ARRAY_A );
				} elseif ( 'order_itemmeta' === $mode ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Structured-data scanning requires current database state.
					$rows = $wpdb->get_results( $wpdb->prepare( "SELECT %i AS row_id, %i AS context_name, %i AS container_value FROM %i WHERE meta_key NOT IN ('_product_id','_variation_id') AND (meta_value REGEXP '^(a:[0-9]+:|O:[0-9]+:)' OR LEFT(LTRIM(meta_value), 1) IN ('{', '[')) AND %i > %d ORDER BY %i ASC LIMIT %d", $id, $context, $value, $table, $id, $last_id, $id, self::BATCH_SIZE ), ARRAY_A );
				} elseif ( 'option' === $mode ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Structured-data scanning requires current database state.
					$rows = $wpdb->get_results( $wpdb->prepare( "SELECT %i AS row_id, %i AS context_name, %i AS container_value FROM %i WHERE (option_value REGEXP '^(a:[0-9]+:|O:[0-9]+:)' OR LEFT(LTRIM(option_value), 1) IN ('{', '[')) AND %i > %d ORDER BY %i ASC LIMIT %d", $id, $context, $value, $table, $id, $last_id, $id, self::BATCH_SIZE ), ARRAY_A );
				} else {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Structured-data scanning requires current database state.
					$rows = $wpdb->get_results( $wpdb->prepare( "SELECT %i AS row_id, %i AS context_name, %i AS container_value FROM %i WHERE (meta_value REGEXP '^(a:[0-9]+:|O:[0-9]+:)' OR LEFT(LTRIM(meta_value), 1) IN ('{', '[')) AND %i > %d ORDER BY %i ASC LIMIT %d", $id, $context, $value, $table, $id, $last_id, $id, self::BATCH_SIZE ), ARRAY_A );
				}

				if ( ! is_array( $rows ) ) {
					throw new RuntimeException( 'به‌دلیل خطای دیتابیس، بررسی داده‌های Embedded کامل نشد.' );
				}

				foreach ( $rows as $row ) {
					$last_id = max( $last_id, (int) $row['row_id'] );
					++$scanned_rows;
					$references = $this->extract_reference_candidates( (string) $row['container_value'] );
					if ( empty( $references ) ) {
						continue;
					}

					$mapped = $this->mapped_ids( $mapping_uuid, array_keys( $references ), 'old_id' );
					foreach ( $mapped as $old_id ) {
						++$hit_count;
						if ( count( $hits ) < self::MAX_REPORTED_HITS ) {
							$hits[] = array(
								'table'   => $container['table'],
								'row_id'  => (string) $row['row_id'],
								'context' => (string) $row['context_name'],
								'old_id'  => $old_id,
								'paths'   => array_values( array_unique( $references[ $old_id ] ?? array() ) ),
								'reason'  => 'این Post ID در یک مسیر معنایی تأییدشده شناسایی شده و هنگام Reindex به‌صورت ساختاری اصلاح می‌شود.',
							);
						}
					}
				}
			} while ( count( $rows ) === self::BATCH_SIZE );
		}

		return array(
			'complete'       => true,
			'scanned_rows'   => $scanned_rows,
			'candidate_rows' => $candidate_rows,
			'hit_count'      => $hit_count,
			'hits'           => $hits,
		);
	}

	/**
	 * Transforms only numeric values found below semantically post-ID-like keys.
	 *
	 * @return array{updated_rows:int,updated_references:int}
	 */
	public function transform( string $mapping_uuid, string $source, string $target ): array {
		$wpdb = $this->wpdb;
		$source = $this->mapping_column( $source );
		$target = $this->mapping_column( $target );
		$updated_rows = 0;
		$updated_references = 0;

		foreach ( $this->containers() as $container ) {
			$table   = Identifier::normalize( $container['table'] );
			$id      = Identifier::normalize( $container['id'] );
			$value   = Identifier::normalize( $container['value'] );
			$mode    = (string) $container['mode'];
			$last_id = 0;

			do {
				if ( 'postmeta' === $mode ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Structured-data transformation requires current database state.
					$rows = $wpdb->get_results( $wpdb->prepare( "SELECT %i AS row_id, %i AS container_value FROM %i WHERE meta_key NOT IN ('_elementor_data','_crosssell_ids','_upsell_ids','_children') AND (meta_value REGEXP '^(a:[0-9]+:|O:[0-9]+:)' OR LEFT(LTRIM(meta_value), 1) IN ('{', '[')) AND %i > %d ORDER BY %i ASC LIMIT %d", $id, $value, $table, $id, $last_id, $id, self::BATCH_SIZE ), ARRAY_A );
				} elseif ( 'order_itemmeta' === $mode ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Structured-data transformation requires current database state.
					$rows = $wpdb->get_results( $wpdb->prepare( "SELECT %i AS row_id, %i AS container_value FROM %i WHERE meta_key NOT IN ('_product_id','_variation_id') AND (meta_value REGEXP '^(a:[0-9]+:|O:[0-9]+:)' OR LEFT(LTRIM(meta_value), 1) IN ('{', '[')) AND %i > %d ORDER BY %i ASC LIMIT %d", $id, $value, $table, $id, $last_id, $id, self::BATCH_SIZE ), ARRAY_A );
				} elseif ( 'option' === $mode ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Structured-data transformation requires current database state.
					$rows = $wpdb->get_results( $wpdb->prepare( "SELECT %i AS row_id, %i AS container_value FROM %i WHERE (option_value REGEXP '^(a:[0-9]+:|O:[0-9]+:)' OR LEFT(LTRIM(option_value), 1) IN ('{', '[')) AND %i > %d ORDER BY %i ASC LIMIT %d", $id, $value, $table, $id, $last_id, $id, self::BATCH_SIZE ), ARRAY_A );
				} else {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Structured-data transformation requires current database state.
					$rows = $wpdb->get_results( $wpdb->prepare( "SELECT %i AS row_id, %i AS container_value FROM %i WHERE (meta_value REGEXP '^(a:[0-9]+:|O:[0-9]+:)' OR LEFT(LTRIM(meta_value), 1) IN ('{', '[')) AND %i > %d ORDER BY %i ASC LIMIT %d", $id, $value, $table, $id, $last_id, $id, self::BATCH_SIZE ), ARRAY_A );
				}
				if ( ! is_array( $rows ) ) {
					throw new RuntimeException( 'داده‌های Embedded لازم برای اصلاح Referenceها خوانده نشد.' );
				}

				foreach ( $rows as $row ) {
					$last_id = max( $last_id, (int) $row['row_id'] );
					$raw = (string) $row['container_value'];
					$decoded = $this->decode( $raw );
					if ( null === $decoded || ! is_array( $decoded['value'] ) ) {
						continue;
					}

					$references = array();
					$this->walk( $decoded['value'], '$', $references );
					if ( empty( $references ) ) {
						continue;
					}

					$mapping = $this->mapping_for_ids( $mapping_uuid, $source, $target, array_keys( $references ) );
					if ( empty( $mapping ) ) {
						continue;
					}

					$value_data = $decoded['value'];
					$changed = $this->replace_references( $value_data, '', $mapping, false );
					if ( 0 === $changed ) {
						continue;
					}

					$encoded = 'serialized' === $decoded['format']
						? serialize( $value_data )
						: wp_json_encode( $value_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
					if ( ! is_string( $encoded ) ) {
						throw new RuntimeException( 'پس از اصلاح Post ID، داده ساختاریافته دوباره ساخته نشد.' );
					}

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
					$affected = $wpdb->query(
						$wpdb->prepare(
							"UPDATE %i SET %i = %s WHERE %i = %d AND %i = %s",
							$table,
							$value,
							$encoded,
							$id,
							(int) $row['row_id'],
							$value,
							$raw
						)
					);
					if ( false === $affected ) {
						throw new RuntimeException( 'Referenceهای ساختاریافته اصلاح‌شده در دیتابیس ذخیره نشدند.' );
					}
					if ( 1 === $affected ) {
						++$updated_rows;
						$updated_references += $changed;
					}
				}
			} while ( count( $rows ) === self::BATCH_SIZE );
		}

		return array(
			'updated_rows'       => $updated_rows,
			'updated_references' => $updated_references,
		);
	}

	/** @return list<array{table:string,id:string,context:string,value:string,where:string}> */
	private function containers(): array {
		$wpdb = $this->wpdb;
		$containers = array(
			array(
				'table'   => $wpdb->postmeta,
				'id'      => 'meta_id',
				'context' => 'meta_key',
				'value'   => 'meta_value',
				'mode'    => 'postmeta',
			),
			array(
				'table'   => $wpdb->options,
				'id'      => 'option_id',
				'context' => 'option_name',
				'value'   => 'option_value',
				'mode'    => 'option',
			),
			array(
				'table'   => $wpdb->usermeta,
				'id'      => 'umeta_id',
				'context' => 'meta_key',
				'value'   => 'meta_value',
				'mode'    => 'usermeta',
			),
		);

		$order_itemmeta = $wpdb->prefix . 'woocommerce_order_itemmeta';
		if ( $this->table_exists( $order_itemmeta ) ) {
			$containers[] = array(
				'table'   => $order_itemmeta,
				'id'      => 'meta_id',
				'context' => 'meta_key',
				'value'   => 'meta_value',
				'mode'    => 'order_itemmeta',
			);
		}

		return $containers;
	}

	/** @return array{format:string,value:mixed}|null */
	private function decode( string $raw ): ?array {
		if ( is_serialized( $raw ) ) {
			$value = @unserialize( $raw, array( 'allowed_classes' => false ) ); 
			return is_array( $value ) ? array( 'format' => 'serialized', 'value' => $value ) : null;
		}

		$value = json_decode( $raw, true, 512, JSON_BIGINT_AS_STRING );
		return JSON_ERROR_NONE === json_last_error() && is_array( $value ) ? array( 'format' => 'json', 'value' => $value ) : null;
	}

	/** @return array<int,list<string>> */
	private function extract_reference_candidates( string $raw ): array {
		$decoded = $this->decode( $raw );
		if ( null === $decoded ) {
			return array();
		}
		$result = array();
		$this->walk( $decoded['value'], '$', $result );
		return $result;
	}

	/** @param array<int,list<string>> $result */
	private function walk( mixed $value, string $path, array &$result, string $key = '', bool $inherited_reference = false ): void {
		$is_reference = $inherited_reference || preg_match( self::REFERENCE_KEY_PATTERN, $key ) === 1;
		if ( is_array( $value ) ) {
			foreach ( $value as $child_key => $child ) {
				$child_key_string = (string) $child_key;
				$this->walk( $child, $path . '.' . $child_key_string, $result, $child_key_string, $is_reference );
			}
			return;
		}

		if ( ! $is_reference ) {
			return;
		}
		if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
			$id = (int) $value;
			if ( $id > 0 ) {
				$result[ $id ][] = $path;
			}
		}
	}

	/** @param array<int,int> $mapping */
	private function replace_references( mixed &$value, string $key, array $mapping, bool $inherited_reference ): int {
		$is_reference = $inherited_reference || preg_match( self::REFERENCE_KEY_PATTERN, $key ) === 1;
		$changed = 0;
		if ( is_array( $value ) ) {
			foreach ( $value as $child_key => &$child ) {
				$changed += $this->replace_references( $child, (string) $child_key, $mapping, $is_reference );
			}
			unset( $child );
			return $changed;
		}
		if ( ! $is_reference ) {
			return 0;
		}

		$id = is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ? (int) $value : 0;
		if ( $id <= 0 || ! isset( $mapping[ $id ] ) ) {
			return 0;
		}
		$value = is_string( $value ) ? (string) $mapping[ $id ] : $mapping[ $id ];
		return 1;
	}

	private function table_exists( string $table ): bool {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/** @param list<int> $ids @return list<int> */
	private function mapped_ids( string $mapping_uuid, array $ids, string $source ): array {
		$mapping = $this->mapping_for_ids( $mapping_uuid, $source, 'new_id', $ids );
		return array_keys( $mapping );
	}

	/** @param list<int> $ids @return array<int,int> */
	private function mapping_for_ids( string $mapping_uuid, string $source, string $target, array $ids ): array {
		$wpdb = $this->wpdb;
		$source = $this->mapping_column( $source );
		$target = $this->mapping_column( $target );
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$table         = Identifier::normalize( $this->tables->mappings() );
		$source_column = Identifier::normalize( $source );
		$target_column = Identifier::normalize( $target );
		$result        = array();
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$args = array_merge( array( $source_column, $target_column, $table, $mapping_uuid, $source_column, $target_column, $source_column ), $chunk );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Only the generated numeric placeholder list is interpolated; all identifiers and values are prepared.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT %i source_id, %i target_id FROM %i WHERE job_uuid = %s AND %i <> %i AND %i IN ({$placeholders})", $args ), ARRAY_A );
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$result[ (int) $row['source_id'] ] = (int) $row['target_id'];
			}
		}
		return $result;
	}

	private function mapping_column( string $column ): string {
		if ( ! in_array( $column, array( 'old_id', 'temp_id', 'new_id' ), true ) ) {
			throw new RuntimeException( 'ستون انتخاب‌شده برای Mapping معتبر نیست.' );
		}
		return $column;
	}
}
