<?php
/**
 * Conservative structured-reference engine for unknown plugin tables.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Adapter;

use RuntimeException;
use Shcd\TornadoDatabaseMaintenance\Database\Identifier;
use Shcd\TornadoDatabaseMaintenance\Database\Tables;

final class AdaptiveStructuredReferenceEngine {
	private const BATCH_SIZE = 300;
	private const MAX_UNRESOLVED = 100;
	private const DIRECT_KEY_PATTERN = '/^(?:(?:(?:source|target|parent|child|related|linked|origin|destination|base|featured|hero|cover)_)?(?:post|page|product|variation|attachment|media|image|thumbnail|template)_ids?|(?:post|page|product|variation|attachment|template)_parent_id|(?:post|product|variation)_or_parent_id|post_parent)$/i';
	private const GENERIC_ID_PATTERN = '/^(?:object|element|entity|content|item|resource|source|target|parent|related|linked)_id$/i';
	private const TYPE_KEY_PATTERN = '/^(?:object|element|entity|content|item|resource|source|target|parent|related|linked)_(?:type|kind|object_type)$/i';
	private const MEDIA_PARENT_PATTERN = '/^(?:image|images|gallery|thumbnail|logo|media|attachment|poster|background_image|fallback_image)$/i';
	private const SEMANTIC_COLUMN_SQL_REGEX = '(^|_)(post|page|product|variation|attachment|media|image|thumbnail|template)(_parent)?_ids?$|^post_parent$|(^|_)(post|product|variation)_or_parent_id$';

	/** @var list<array<string,mixed>>|null */
	private ?array $containers_cache = null;

	/** @var list<string>|null */
	private ?array $post_types_cache = null;

	/** @var list<string>|null */
	private ?array $active_tables_cache = null;

	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly Tables $tables
	) {}

	/**
	 * @return array{transformable_rows:int,transformable_references:int,unresolved:list<array<string,mixed>>,containers:int}
	 */
	public function preview( string $mapping_uuid ): array {
		return $this->analyze( $mapping_uuid, 'old_id', 'new_id', false );
	}

	/**
	 * @return array{updated_rows:int,updated_references:int,containers:int}
	 */
	public function transform( string $mapping_uuid, string $source, string $target ): array {
		$result = $this->analyze( $mapping_uuid, $source, $target, true );
		if ( ! empty( $result['unresolved'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
			throw new RuntimeException( 'در یکی از جدول‌های افزونه، داده ساختاریافته دارای Post Reference پیدا شد اما بازنویسی ایمن آن ممکن نبود.' );
		}
		return array(
			'updated_rows'       => (int) $result['transformable_rows'],
			'updated_references' => (int) $result['transformable_references'],
			'containers'         => (int) $result['containers'],
		);
	}

	/** @return list<string> */
	public function involved_tables(): array {
		return null === $this->active_tables_cache
			? array()
			: array_values( array_unique( $this->active_tables_cache ) );
	}

	/** @return int */
	public function container_count(): int {
		return count( $this->containers() );
	}

	/**
	 * @return array{transformable_rows:int,transformable_references:int,unresolved:list<array<string,mixed>>,containers:int}
	 */
	private function analyze( string $mapping_uuid, string $source, string $target, bool $write ): array {
		$wpdb = $this->wpdb;
		$source = $this->mapping_column( $source );
		$target = $this->mapping_column( $target );
		$rows_count = 0;
		$reference_count = 0;
		$unresolved = array();
		$scanned_containers = 0;
		if ( ! $write ) {
			$this->active_tables_cache = array();
		}

		foreach ( $this->containers() as $container ) {
			++$scanned_containers;
			if ( empty( $container['supported'] ) ) {
				$hits = $this->unsupported_container_hits( $container, $mapping_uuid, $source );
				if ( $hits > 0 ) {
					$this->active_tables_cache ??= array();
					$this->active_tables_cache[] = (string) $container['table'];
				}
				if ( $hits > 0 && count( $unresolved ) < self::MAX_UNRESOLVED ) {
					$unresolved[] = array(
						'table'  => (string) $container['table'],
						'path'   => (string) $container['value'],
						'reason' => (string) ( $container['reason'] ?? 'کلید یکتای عددی برای بازنویسی ایمن پیدا نشد.' ),
						'matching_references' => $hits,
					);
				}
				continue;
			}

			$table_name = (string) $container['table'];
			$id_name    = (string) $container['id'];
			$value_name = (string) $container['value'];
			$semantic_key = (string) ( $container['semantic_key'] ?? '' );
			$table        = Identifier::normalize( $table_name );
			$id_column    = Identifier::normalize( $id_name );
			$value_column = Identifier::normalize( $value_name );
			$cursor      = 0;

			do {
				if ( '' !== $semantic_key ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Structured-reference scanning requires current database state.
					$rows = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT %i AS row_id, %i AS container_value FROM %i WHERE (%i REGEXP '^(a:[0-9]+:|O:[0-9]+:|i:[0-9]+;|s:[0-9]+:\"[0-9]+\";)' OR LEFT(LTRIM(%i), 1) IN ('{', '[') OR %i REGEXP '^[[:space:]]*[0-9]+([[:space:]]*[,;|][[:space:]]*[0-9]+)*[[:space:]]*$') AND %i > %d ORDER BY %i ASC LIMIT %d",
							$id_column, $value_column, $table, $value_column, $value_column, $value_column, $id_column, $cursor, $id_column, self::BATCH_SIZE
						),
						ARRAY_A
					);
				} else {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Structured-reference scanning requires current database state.
					$rows = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT %i AS row_id, %i AS container_value FROM %i WHERE (%i REGEXP '^(a:[0-9]+:|O:[0-9]+:)' OR LEFT(LTRIM(%i), 1) IN ('{', '[')) AND %i > %d ORDER BY %i ASC LIMIT %d",
							$id_column, $value_column, $table, $value_column, $value_column, $id_column, $cursor, $id_column, self::BATCH_SIZE
						),
						ARRAY_A
					);
				}
				if ( ! is_array( $rows ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
					throw new RuntimeException( sprintf( 'خواندن داده ساختاریافته از %s.%s ناموفق بود.', $table_name, $value_name ) );
				}

				foreach ( $rows as $row ) {
					$row_id = (int) ( $row['row_id'] ?? 0 );
					$cursor = max( $cursor, $row_id );
					$raw = (string) ( $row['container_value'] ?? '' );
					$decoded = $this->decode( $raw );
					$delimited = null === $decoded && '' !== $semantic_key ? $this->delimited_ids( $raw ) : null;
					if ( null === $decoded && null === $delimited ) {
						if ( ( '' !== $semantic_key || $this->contains_reference_signal( $raw ) )
							&& $this->malformed_has_mapping_hit( $raw, $mapping_uuid, $source )
							&& count( $unresolved ) < self::MAX_UNRESOLVED ) {
							$unresolved[] = array(
								'table'  => $table_name,
								'row_id' => $row_id,
								'path'   => $value_name,
								'reason' => 'ستون یا داده دارای نشانه Post Reference است، اما قالب آن JSON، PHP Serialized یا فهرست عددی معتبر نیست.',
							);
						}
						continue;
					}

					$ids = array();
					if ( null !== $delimited ) {
						$this->collect_numeric_values( $delimited, '$', $this->expected_types_for_key( $semantic_key ), $ids );
					} else {
						if ( '' !== $semantic_key ) {
							$this->collect_numeric_values( $decoded['value'], '$', $this->expected_types_for_key( $semantic_key ), $ids );
						}
						$this->collect_references( $decoded['value'], '$', '', $ids );
					}
					if ( empty( $ids ) ) {
						continue;
					}
					$map = $this->mapping_for_ids( $mapping_uuid, $source, $target, array_keys( $ids ) );
					if ( empty( $map ) ) {
						continue;
					}
					$mapped_references = 0;
					foreach ( $map as $mapped_id => $mapping_row ) {
						foreach ( $ids[ $mapped_id ] ?? array() as $occurrence ) {
							if ( $this->post_type_is_compatible( (string) $mapping_row['post_type'], (array) ( $occurrence['expected'] ?? array() ) ) ) {
								++$mapped_references;
							}
						}
					}
					if ( 0 === $mapped_references ) {
						continue;
					}
					$this->active_tables_cache ??= array();
					$this->active_tables_cache[] = $table_name;
					++$rows_count;
					$reference_count += $mapped_references;

					if ( ! $write ) {
						continue;
					}

					if ( null !== $delimited ) {
						$changed = 0;
						$encoded = $this->replace_delimited_ids(
							$raw,
							$map,
							$this->expected_types_for_key( $semantic_key ),
							$changed
						);
					} else {
						$value = $decoded['value'];
						$changed = '' !== $semantic_key
							? $this->replace_numeric_values( $value, $map, $this->expected_types_for_key( $semantic_key ) )
							: 0;
						$changed += $this->replace_references( $value, '', $map );
						$encoded = 'serialized' === $decoded['format']
							? serialize( $value )
							: wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
					}
					if ( $changed !== $mapped_references ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
						throw new RuntimeException( sprintf( 'تعداد Referenceهای بازنویسی‌شده در %s.%s با Preview برابر نیست.', $table_name, $value_name ) );
					}
					if ( ! is_string( $encoded ) ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
						throw new RuntimeException( sprintf( 'بازسازی داده ساختاریافته در %s.%s ناموفق بود.', $table_name, $value_name ) );
					}
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
					$affected = $wpdb->query(
						$wpdb->prepare(
							"UPDATE %i SET %i = %s WHERE %i = %d AND %i = %s",
							$table,
							$value_column,
							$encoded,
							$id_column,
							$row_id,
							$value_column,
							$raw
						)
					);
					if ( 1 !== $affected ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
						throw new RuntimeException( sprintf( 'ردیف %d از %s هم‌زمان تغییر کرده یا قابل ذخیره نیست.', $row_id, $table_name ) );
					}
				}
			} while ( count( $rows ) === self::BATCH_SIZE );
		}

		return array(
			'transformable_rows'       => $rows_count,
			'transformable_references' => $reference_count,
			'unresolved'               => $unresolved,
			'containers'               => $scanned_containers,
		);
	}

	/** @return list<array<string,mixed>> */
	private function containers(): array {
		$wpdb = $this->wpdb;
		if ( null !== $this->containers_cache ) {
			return $this->containers_cache;
		}

		$names = array(
			'meta_value', 'option_value', 'value', 'settings', 'data', 'config', 'configuration', 'payload',
			'attributes', 'parameters', 'params', 'metadata', 'meta_data', 'state', 'model', 'record',
			'extra_data', 'post_content', 'post_excerpt', 'content_json', 'settings_json', 'data_json', 'json_data', 'serialized_data',
		);
		$placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );
		$args = array_merge(
			array( (string) DB_NAME, $wpdb->esc_like( $wpdb->prefix ) . '%' ),
			$names,
			array( self::SEMANTIC_COLUMN_SQL_REGEX )
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Only the generated semantic-column placeholder list is interpolated; every value is prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.TABLE_NAME, c.COLUMN_NAME, c.DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS c
				 INNER JOIN INFORMATION_SCHEMA.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME AND t.TABLE_TYPE = 'BASE TABLE'
				 WHERE c.TABLE_SCHEMA = %s AND c.TABLE_NAME LIKE %s
				 AND c.DATA_TYPE IN ('char','varchar','tinytext','text','mediumtext','longtext','json')
				 AND (c.DATA_TYPE = 'json' OR LOWER(c.COLUMN_NAME) IN ({$placeholders}) OR LOWER(c.COLUMN_NAME) REGEXP %s)
				 ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION",
				$args
			),
			ARRAY_A
		);
		$result = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$table = (string) $row['TABLE_NAME'];
			$value = (string) $row['COLUMN_NAME'];
			if ( $this->excluded_table( $table ) ) {
				continue;
			}
			$primary = $this->single_integer_primary_key( $table );
			$result[] = array(
				'table'        => $table,
				'id'           => (string) ( $primary['column'] ?? '' ),
				'value'        => $value,
				'semantic_key' => $this->semantic_container_key( $value ),
				'supported' => ! empty( $primary['supported'] ),
				'reason'    => (string) ( $primary['reason'] ?? '' ),
			);
		}
		$this->containers_cache = $result;
		return $result;
	}

	/** @return array{supported:bool,column?:string,reason?:string} */
	private function single_integer_primary_key( string $table ): array {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_KEY = 'PRI'
				 ORDER BY ORDINAL_POSITION",
				(string) DB_NAME,
				$table
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || 1 !== count( $rows ) ) {
			return array( 'supported' => false, 'reason' => 'جدول کلید اصلی عددی و تک‌ستونی ندارد.' );
		}
		$type = strtolower( (string) ( $rows[0]['DATA_TYPE'] ?? '' ) );
		if ( ! in_array( $type, array( 'tinyint', 'smallint', 'mediumint', 'int', 'bigint' ), true ) ) {
			return array( 'supported' => false, 'reason' => 'کلید اصلی جدول عدد صحیح نیست.' );
		}
		return array( 'supported' => true, 'column' => (string) $rows[0]['COLUMN_NAME'] );
	}

	/** @param array<string,mixed> $container */
	private function unsupported_container_hits( array $container, string $mapping_uuid, string $source ): int {
		$wpdb = $this->wpdb;
		$table = Identifier::normalize( (string) $container['table'] );
		$value = Identifier::normalize( (string) $container['value'] );
		$semantic_key = (string) ( $container['semantic_key'] ?? '' );
		$offset = 0;
		$hits = 0;
		do {
			if ( '' !== $semantic_key ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Unsupported-container inspection requires current database state.
				$rows = $wpdb->get_col( $wpdb->prepare( "SELECT %i FROM %i WHERE (%i REGEXP '^(a:[0-9]+:|O:[0-9]+:|i:[0-9]+;|s:[0-9]+:\"[0-9]+\";)' OR LEFT(LTRIM(%i), 1) IN ('{', '[') OR %i REGEXP '^[[:space:]]*[0-9]+([[:space:]]*[,;|][[:space:]]*[0-9]+)*[[:space:]]*$') LIMIT %d OFFSET %d", $value, $table, $value, $value, $value, self::BATCH_SIZE, $offset ) );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Unsupported-container inspection requires current database state.
				$rows = $wpdb->get_col( $wpdb->prepare( "SELECT %i FROM %i WHERE (%i REGEXP '^(a:[0-9]+:|O:[0-9]+:)' OR LEFT(LTRIM(%i), 1) IN ('{', '[')) LIMIT %d OFFSET %d", $value, $table, $value, $value, self::BATCH_SIZE, $offset ) );
			}
			if ( ! is_array( $rows ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
				throw new RuntimeException( 'خواندن جدول فاقد کلید اصلی برای Preflight ناموفق بود.' );
			}
			foreach ( $rows as $raw_value ) {
				$raw = (string) $raw_value;
				$decoded = $this->decode( $raw );
				$delimited = null === $decoded && '' !== $semantic_key ? $this->delimited_ids( $raw ) : null;
				if ( null === $decoded && null === $delimited ) {
					if ( ( '' !== $semantic_key || $this->contains_reference_signal( $raw ) ) && $this->malformed_has_mapping_hit( $raw, $mapping_uuid, $source ) ) {
						++$hits;
					}
					continue;
				}
				$ids = array();
				if ( null !== $delimited ) {
					$this->collect_numeric_values( $delimited, '$', $this->expected_types_for_key( $semantic_key ), $ids );
				} else {
					if ( '' !== $semantic_key ) {
						$this->collect_numeric_values( $decoded['value'], '$', $this->expected_types_for_key( $semantic_key ), $ids );
					}
					$this->collect_references( $decoded['value'], '$', '', $ids );
				}
				if ( empty( $ids ) ) {
					continue;
				}
				$map = $this->mapping_for_ids( $mapping_uuid, $source, 'new_id', array_keys( $ids ) );
				foreach ( $map as $id => $mapping_row ) {
					foreach ( $ids[ $id ] ?? array() as $occurrence ) {
						if ( $this->post_type_is_compatible( (string) $mapping_row['post_type'], (array) ( $occurrence['expected'] ?? array() ) ) ) {
							++$hits;
						}
					}
				}
			}
			$offset += self::BATCH_SIZE;
		} while ( count( $rows ) === self::BATCH_SIZE );
		return $hits;
	}

	/** @return array{format:string,value:mixed}|null */
	private function decode( string $raw ): ?array {
		if ( is_serialized( $raw ) ) {
			$value = @unserialize( $raw, array( 'allowed_classes' => false ) );
			return is_object( $value ) ? null : array( 'format' => 'serialized', 'value' => $value );
		}
		$value = json_decode( $raw, true, 512, JSON_BIGINT_AS_STRING );
		return JSON_ERROR_NONE === json_last_error()
			? array( 'format' => 'json', 'value' => $value )
			: null;
	}

	/** @return list<int>|null */
	private function delimited_ids( string $raw ): ?array {
		if ( preg_match( '/^\s*[0-9]+(?:\s*[,;|]\s*[0-9]+)*\s*$/', $raw ) !== 1 ) {
			return null;
		}
		preg_match_all( '/(?<![0-9])[0-9]+(?![0-9])/', $raw, $matches );
		return array_map( 'intval', (array) ( $matches[0] ?? array() ) );
	}

	/**
	 * @param array<int,array{target:int,post_type:string}> $mapping
	 * @param list<string> $expected
	 */
	private function replace_delimited_ids( string $raw, array $mapping, array $expected, int &$changed ): string {
		$changed = 0;
		$result = preg_replace_callback(
			'/(?<![0-9])[0-9]+(?![0-9])/',
			function ( array $match ) use ( $mapping, $expected, &$changed ): string {
				$id = (int) $match[0];
				if ( $id <= 0 || ! isset( $mapping[ $id ] ) || ! $this->post_type_is_compatible( $mapping[ $id ]['post_type'], $expected ) ) {
					return $match[0];
				}
				++$changed;
				return (string) $mapping[ $id ]['target'];
			},
			$raw
		);
		if ( ! is_string( $result ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
			throw new RuntimeException( 'بازنویسی فهرست عددی Post ID ناموفق بود.' );
		}
		return $result;
	}

	private function semantic_container_key( string $column ): string {
		return preg_match( self::DIRECT_KEY_PATTERN, $column ) === 1 ? strtolower( $column ) : '';
	}

	/** @param array<int,list<array{path:string,expected:list<string>}>> $ids */
	private function collect_references( mixed $value, string $path, string $parent_key, array &$ids ): void {
		if ( ! is_array( $value ) ) {
			return;
		}
		$type = $this->node_type( $value );
		foreach ( $value as $key => $child ) {
			$key_string = (string) $key;
			$child_path = $path . '.' . $key_string;
			$is_direct = preg_match( self::DIRECT_KEY_PATTERN, $key_string ) === 1;
			$is_generic = preg_match( self::GENERIC_ID_PATTERN, $key_string ) === 1 && $this->is_post_type_value( $type );
			$is_media_id = 'id' === strtolower( $key_string ) && preg_match( self::MEDIA_PARENT_PATTERN, $parent_key ) === 1;
			if ( $is_direct || $is_generic || $is_media_id ) {
				$expected = $is_media_id
					? array( 'attachment' )
					: ( $is_generic ? $this->expected_types_for_type( $type ) : $this->expected_types_for_key( $key_string ) );
				$this->collect_numeric_values( $child, $child_path, $expected, $ids );
			}
			if ( is_array( $child ) ) {
				$this->collect_references( $child, $child_path, $key_string, $ids );
			}
		}
	}

	/** @param list<string> $expected @param array<int,list<array{path:string,expected:list<string>}>> $ids */
	private function collect_numeric_values( mixed $value, string $path, array $expected, array &$ids ): void {
		if ( is_array( $value ) ) {
			if ( ! array_is_list( $value ) ) {
				return;
			}
			foreach ( $value as $index => $child ) {
				$this->collect_numeric_values( $child, $path . '.' . (string) $index, $expected, $ids );
			}
			return;
		}
		$id = $this->numeric_id( $value );
		if ( $id > 0 ) {
			$ids[ $id ][] = array( 'path' => $path, 'expected' => $expected );
		}
	}

	/** @param array<int,array{target:int,post_type:string}> $mapping */
	private function replace_references( mixed &$value, string $key, array $mapping ): int {
		if ( ! is_array( $value ) ) {
			return 0;
		}
		$type = $this->node_type( $value );
		$changed = 0;
		foreach ( $value as $child_key => &$child ) {
			$key_string = (string) $child_key;
			$is_direct = preg_match( self::DIRECT_KEY_PATTERN, $key_string ) === 1;
			$is_generic = preg_match( self::GENERIC_ID_PATTERN, $key_string ) === 1 && $this->is_post_type_value( $type );
			$is_media_id = 'id' === strtolower( $key_string ) && preg_match( self::MEDIA_PARENT_PATTERN, $key ) === 1;
			if ( $is_direct || $is_generic || $is_media_id ) {
				$expected = $is_media_id
					? array( 'attachment' )
					: ( $is_generic ? $this->expected_types_for_type( $type ) : $this->expected_types_for_key( $key_string ) );
				$changed += $this->replace_numeric_values( $child, $mapping, $expected );
			}
			if ( is_array( $child ) ) {
				$changed += $this->replace_references( $child, $key_string, $mapping );
			}
		}
		unset( $child );
		return $changed;
	}

	/** @param array<int,array{target:int,post_type:string}> $mapping @param list<string> $expected */
	private function replace_numeric_values( mixed &$value, array $mapping, array $expected ): int {
		if ( is_array( $value ) ) {
			if ( ! array_is_list( $value ) ) {
				return 0;
			}
			$changed = 0;
			foreach ( $value as &$child ) {
				$changed += $this->replace_numeric_values( $child, $mapping, $expected );
			}
			unset( $child );
			return $changed;
		}
		$id = $this->numeric_id( $value );
		if ( $id <= 0 || ! isset( $mapping[ $id ] ) || ! $this->post_type_is_compatible( $mapping[ $id ]['post_type'], $expected ) ) {
			return 0;
		}
		$target = (int) $mapping[ $id ]['target'];
		$value = is_string( $value ) ? (string) $target : $target;
		return 1;
	}

	/** @return list<string> */
	private function expected_types_for_key( string $key ): array {
		$key = strtolower( $key );
		if ( str_contains( $key, 'attachment' ) || str_contains( $key, 'media' ) || str_contains( $key, 'image' ) || str_contains( $key, 'thumbnail' ) ) {
			return array( 'attachment' );
		}
		if ( str_contains( $key, 'variation' ) ) {
			return array( 'product_variation' );
		}
		if ( str_contains( $key, 'product' ) ) {
			return array( 'product', 'product_variation' );
		}
		if ( str_contains( $key, 'template' ) ) {
			return array( 'elementor_library', 'wp_template', 'wp_template_part' );
		}
		if ( str_contains( $key, 'page' ) ) {
			return array( 'page' );
		}
		return array();
	}

	/** @return list<string> */
	private function expected_types_for_type( string $type ): array {
		$type = strtolower( trim( $type ) );
		if ( in_array( $type, array( 'post', 'posts', 'wp_post' ), true ) ) {
			return array();
		}
		if ( in_array( $type, array( 'media', 'image' ), true ) ) {
			return array( 'attachment' );
		}
		if ( 'template' === $type ) {
			return array( 'elementor_library', 'wp_template', 'wp_template_part' );
		}
		foreach ( array( 'post_', 'post:', 'post-' ) as $prefix ) {
			if ( str_starts_with( $type, $prefix ) ) {
				$suffix = sanitize_key( substr( $type, strlen( $prefix ) ) );
				return '' === $suffix ? array() : array( $suffix );
			}
		}
		$type = sanitize_key( $type );
		return in_array( $type, $this->post_types(), true ) ? array( $type ) : array();
	}

	/** @param list<string> $expected */
	private function post_type_is_compatible( string $actual, array $expected ): bool {
		return empty( $expected ) || in_array( sanitize_key( $actual ), $expected, true );
	}

	/** @param array<mixed> $node */
	private function node_type( array $node ): string {
		foreach ( $node as $key => $value ) {
			if ( preg_match( self::TYPE_KEY_PATTERN, (string) $key ) === 1 && is_scalar( $value ) ) {
				return strtolower( trim( (string) $value ) );
			}
		}
		foreach ( array( 'type', 'kind' ) as $key ) {
			if ( isset( $node[ $key ] ) && is_scalar( $node[ $key ] ) ) {
				return strtolower( trim( (string) $node[ $key ] ) );
			}
		}
		return '';
	}

	private function is_post_type_value( string $type ): bool {
		$type = strtolower( trim( $type ) );
		if ( '' === $type ) {
			return false;
		}
		if ( in_array( sanitize_key( $type ), $this->post_types(), true ) ) {
			return true;
		}
		return in_array( $type, array( 'post', 'posts', 'wp_post', 'page', 'product', 'attachment', 'template', 'media', 'image' ), true )
			|| str_starts_with( $type, 'post_' )
			|| str_starts_with( $type, 'post:' )
			|| str_starts_with( $type, 'post-' );
	}

	/** @return list<string> */
	private function post_types(): array {
		$wpdb = $this->wpdb;
		if ( null !== $this->post_types_cache ) {
			return $this->post_types_cache;
		}
		$posts = Identifier::normalize( $wpdb->posts );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$types = $wpdb->get_col(
			 $wpdb->prepare( "SELECT DISTINCT post_type FROM %i WHERE post_type <> ''", $posts )
		);
		$this->post_types_cache = array_values(
			array_unique(
				array_filter( array_map( 'sanitize_key', is_array( $types ) ? $types : array() ) )
			)
		);
		return $this->post_types_cache;
	}

	private function malformed_has_mapping_hit( string $raw, string $mapping_uuid, string $source ): bool {
		if ( preg_match_all( '/(?<![0-9])([1-9][0-9]*)(?![0-9])/', $raw, $matches ) < 1 ) {
			return false;
		}
		$ids = array_slice( array_values( array_unique( array_map( 'intval', $matches[1] ?? array() ) ) ), 0, 500 );
		return ! empty( $this->mapping_for_ids( $mapping_uuid, $source, 'new_id', $ids ) );
	}

	private function contains_reference_signal( string $raw ): bool {
		return preg_match( '/(?:post|page|product|variation|attachment|media|image|thumbnail|template|object|element|entity|content|resource)[_\\"\':-]*(?:id|ids|type)/i', $raw ) === 1;
	}

	private function numeric_id( mixed $value ): int {
		if ( is_int( $value ) ) {
			return $value;
		}
		return is_string( $value ) && ctype_digit( $value ) ? (int) $value : 0;
	}

	/** @param list<int> $ids @return array<int,array{target:int,post_type:string}> */
	private function mapping_for_ids( string $mapping_uuid, string $source, string $target, array $ids ): array {
		$wpdb = $this->wpdb;
		$source = $this->mapping_column( $source );
		$target = $this->mapping_column( $target );
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) ) );
		if ( empty( $ids ) ) {
			return array();
		}
		$mapping = Identifier::normalize( $this->tables->mappings() );
		$posts = Identifier::normalize( $wpdb->posts );
		$source_column = Identifier::normalize( $source );
		$target_column = Identifier::normalize( $target );
		$result = array();
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$args = array_merge( array( $source_column, $target_column, $mapping, $posts, $source_column, $mapping_uuid, $source_column ), $chunk );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Only the generated numeric placeholder list is interpolated; all identifiers and values are prepared.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT m.%i source_id, m.%i target_id, p.post_type FROM %i m INNER JOIN %i p ON p.ID = m.%i WHERE m.job_uuid = %s AND m.old_id <> m.new_id AND m.%i IN ({$placeholders})", $args ), ARRAY_A );
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$result[ (int) $row['source_id'] ] = array(
					'target'    => (int) $row['target_id'],
					'post_type' => (string) $row['post_type'],
				);
			}
		}
		return $result;
	}
	private function mapping_column( string $column ): string {
		if ( ! in_array( $column, array( 'old_id', 'temp_id', 'new_id' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
			throw new RuntimeException( 'ستون Mapping برای موتور Structured تطبیقی معتبر نیست.' );
		}
		return $column;
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
		return str_starts_with( $table, $wpdb->prefix . 'rank_math_' );
	}
}
