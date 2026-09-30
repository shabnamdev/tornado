<?php
/**
 * Path-aware transformers for registered structured post references.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Reindex;

use Shcd\TornadoDatabaseMaintenance\Core\SafeSerialization;
use Shcd\TornadoDatabaseMaintenance\Adapter\AdapterRegistry;
use Shcd\TornadoDatabaseMaintenance\Database\Identifier;
use Shcd\TornadoDatabaseMaintenance\Database\Tables;
use RuntimeException;

final class StructuredReferenceTransformer {
	private const BATCH_SIZE = 250;

	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly Tables $tables,
		private readonly ReferenceRegistry $registry,
		private readonly AdapterRegistry $adapters
	) {}

	/**
	 * Read-only analysis of the structured contexts this service owns.
	 *
	 * @return array{transformable_rows:int,unresolved:list<array<string,mixed>>}
	 */
	public function preview( string $mapping_uuid ): array {
		$unresolved = array();
		$rows       = 0;

		foreach ( $this->registry->csv_meta_keys() as $table => $keys ) {
			$rows += $this->count_meta_rows( $table, $keys );
		}
		foreach ( $this->registry->serialized_id_list_meta_keys() as $table => $keys ) {
			$rows += $this->count_meta_rows( $table, $keys );
		}

		$elementor = $this->analyze_elementor( $mapping_uuid, false );
		$rows += $elementor['rows'];
		$unresolved = array_merge( $unresolved, $elementor['unresolved'] );

		$elementor_settings = $this->analyze_elementor_page_settings( $mapping_uuid, false );
		$rows += $elementor_settings['rows'];
		$unresolved = array_merge( $unresolved, $elementor_settings['unresolved'] );

		$blocks = $this->analyze_blocks( $mapping_uuid, false );
		$rows += $blocks['rows'];
		$unresolved = array_merge( $unresolved, $blocks['unresolved'] );

		$adapter_preview = $this->adapters->preview_structured( $mapping_uuid );
		$rows += (int) $adapter_preview['transformable_rows'];
		$unresolved = array_merge( $unresolved, $adapter_preview['unresolved'] );

		return array(
			'transformable_rows' => $rows,
			'unresolved'         => array_slice( $unresolved, 0, 100 ),
		);
	}

	/**
	 * @return array<string,int>
	 */
	public function transform( string $mapping_uuid, string $source, string $target ): array {
		$source = $this->mapping_column( $source );
		$target = $this->mapping_column( $target );
		$result = array(
			'csv_meta'        => $this->transform_csv_meta( $mapping_uuid, $source, $target ),
			'serialized_meta' => $this->transform_serialized_meta( $mapping_uuid, $source, $target ),
			'elementor'                => 0,
			'elementor_page_settings'  => 0,
			'gutenberg'                => 0,
		);

		$elementor = $this->analyze_elementor( $mapping_uuid, true, $source, $target );
		if ( ! empty( $elementor['unresolved'] ) ) {
			throw new RuntimeException( 'در داده‌های Elementor چند مسیر مربوط به Post Reference هنوز قابل تشخیص نیست.' );
		}
		$result['elementor'] = $elementor['updated'];

		$elementor_settings = $this->analyze_elementor_page_settings( $mapping_uuid, true, $source, $target );
		if ( ! empty( $elementor_settings['unresolved'] ) ) {
			throw new RuntimeException( 'در تنظیمات صفحه Elementor چند مسیر مربوط به Post Reference هنوز قابل تشخیص نیست.' );
		}
		$result['elementor_page_settings'] = $elementor_settings['updated'];

		$blocks = $this->analyze_blocks( $mapping_uuid, true, $source, $target );
		if ( ! empty( $blocks['unresolved'] ) ) {
			throw new RuntimeException( 'در محتوای Gutenberg چند ویژگی مربوط به Post Reference هنوز قابل تشخیص نیست.' );
		}
		$result['gutenberg'] = $blocks['updated'];
		$result['adapters'] = $this->adapters->transform_structured( $mapping_uuid, $source, $target );

		return $result;
	}

	private function transform_csv_meta( string $mapping_uuid, string $source, string $target ): int {
		$wpdb = $this->wpdb;
		$updated = 0;
		foreach ( $this->registry->csv_meta_keys() as $table_name => $keys ) {
			if ( empty( $keys ) ) {
				continue;
			}
			$table = Identifier::normalize( $table_name );
			$cursor = 0;
			$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
			while ( true ) {
				$args = array_merge( array( $table, $cursor ), $keys, array( self::BATCH_SIZE ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Only the generated meta-key placeholder list is interpolated; table and values are prepared.
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_value FROM %i WHERE meta_id > %d AND meta_key IN ({$placeholders}) ORDER BY meta_id ASC LIMIT %d", $args ), ARRAY_A );
				if ( empty( $rows ) ) {
					break;
				}
				foreach ( $rows as $row ) {
					$cursor = (int) $row['meta_id'];
					$parts = preg_split( '/\s*,\s*/', trim( (string) $row['meta_value'] ), -1, PREG_SPLIT_NO_EMPTY ) ?: array();
					if ( empty( $parts ) || count( array_filter( $parts, 'ctype_digit' ) ) !== count( $parts ) ) {
						throw new RuntimeException( 'قالب یکی از فهرست‌های ID جداشده با ویرگول معتبر نیست.' );
					}
					$ids = array_map( 'intval', $parts );
					$map = $this->mapping_for_ids( $mapping_uuid, $source, $target, $ids );
					$new = implode( ',', array_map( static fn( int $id ): int => $map[ $id ] ?? $id, $ids ) );
					if ( $new !== (string) $row['meta_value'] ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
						$changed = $wpdb->update( $table_name, array( 'meta_value' => $new ), array( 'meta_id' => $cursor ), array( '%s' ), array( '%d' ) );
						if ( false === $changed ) {
							throw new RuntimeException( 'یکی از فهرست‌های ID در دیتابیس به‌روزرسانی نشد.' );
						}
						$updated += (int) $changed;
					}
				}
			}
		}
		return $updated;
	}

	private function transform_serialized_meta( string $mapping_uuid, string $source, string $target ): int {
		$wpdb = $this->wpdb;
		$updated = 0;
		foreach ( $this->registry->serialized_id_list_meta_keys() as $table_name => $keys ) {
			$table = Identifier::normalize( $table_name );
			$cursor = 0;
			$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
			while ( true ) {
				$args = array_merge( array( $table, $cursor ), $keys, array( self::BATCH_SIZE ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Only the generated meta-key placeholder list is interpolated; table and values are prepared.
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_value FROM %i WHERE meta_id > %d AND meta_key IN ({$placeholders}) ORDER BY meta_id ASC LIMIT %d", $args ), ARRAY_A );
				if ( empty( $rows ) ) {
					break;
				}
				foreach ( $rows as $row ) {
					$cursor = (int) $row['meta_id'];
					$raw = (string) $row['meta_value'];
					$value = SafeSerialization::maybe_unserialize( $raw );
					if ( ! is_array( $value ) ) {
						throw new RuntimeException( 'یکی از فیلدهای Serialized ثبت‌شده، ساختار آرایه‌ای مورد انتظار را ندارد.' );
					}
					$ids = array();
					foreach ( $value as $item ) {
						if ( ! is_int( $item ) && ! ( is_string( $item ) && ctype_digit( $item ) ) ) {
							throw new RuntimeException( 'در یکی از فهرست‌های ID داخل داده Serialized، مقدار غیرعددی پیدا شد.' );
						}
						$ids[] = (int) $item;
					}
					$map = $this->mapping_for_ids( $mapping_uuid, $source, $target, $ids );
					$new_value = array_map( static fn( mixed $item ): int => $map[ (int) $item ] ?? (int) $item, $value );
					$new_raw = maybe_serialize( $new_value );
					if ( $new_raw !== $raw ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
						$changed = $wpdb->update( $table_name, array( 'meta_value' => $new_raw ), array( 'meta_id' => $cursor ), array( '%s' ), array( '%d' ) );
						if ( false === $changed ) {
							throw new RuntimeException( 'یکی از فهرست‌های ID داخل داده Serialized به‌روزرسانی نشد.' );
						}
						$updated += (int) $changed;
					}
				}
			}
		}
		return $updated;
	}

	/**
	 * @return array{rows:int,updated:int,unresolved:list<array<string,mixed>>}
	 */
	private function analyze_elementor( string $mapping_uuid, bool $write, string $source = 'old_id', string $target = 'new_id' ): array {
		$wpdb = $this->wpdb;
		$table = Identifier::quote( $wpdb->postmeta );
		$tornado_sql_table = Identifier::normalize( $table );
		$cursor = 0;
		$rows_count = 0;
		$updated = 0;
		$unresolved = array();

		while ( true ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_id, meta_value FROM %i WHERE meta_id > %d AND meta_key = %s ORDER BY meta_id ASC LIMIT %d",
					$tornado_sql_table,
					$cursor,
					'_elementor_data',
					self::BATCH_SIZE
				),
				ARRAY_A
			);
			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$cursor = (int) $row['meta_id'];
				++$rows_count;
				$raw = (string) $row['meta_value'];
				$data = json_decode( $raw, true, 512, JSON_BIGINT_AS_STRING );
				if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
					$unresolved[] = array( 'table' => $wpdb->postmeta, 'row_id' => $cursor, 'path' => '$', 'reason' => 'ساختار JSON مربوط به Elementor معتبر نیست.' );
					continue;
				}

				$ids = array();
				$this->collect_elementor_ids( $data, '$', null, $ids, $unresolved, $cursor );
				$map = $this->mapping_for_ids( $mapping_uuid, $source, $target, array_keys( $ids ) );
				if ( $write && ! empty( $map ) ) {
					$this->replace_elementor_ids( $data, null, $map );
					$new = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
					if ( ! is_string( $new ) ) {
						throw new RuntimeException( 'پس از اصلاح شناسه‌ها، JSON داده‌های Elementor دوباره ساخته نشد.' );
					}
					if ( $new !== $raw ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
						$changed = $wpdb->update( $wpdb->postmeta, array( 'meta_value' => $new ), array( 'meta_id' => $cursor ), array( '%s' ), array( '%d' ) );
						if ( false === $changed ) {
							throw new RuntimeException( 'داده‌های اصلاح‌شده Elementor در دیتابیس ذخیره نشد.' );
						}
						$updated += (int) $changed;
					}
				}
			}
		}

		return array( 'rows' => $rows_count, 'updated' => $updated, 'unresolved' => $unresolved );
	}

	/**
	 * @param array<int|string,mixed> $data
	 * @param array<int,list<string>> $ids
	 * @param list<array<string,mixed>> $unresolved
	 */
	private function collect_elementor_ids( array $data, string $path, ?string $parent_key, array &$ids, array &$unresolved, int $row_id ): void {
		$wpdb = $this->wpdb;
		$known_scalar = array(
			'post_id', 'page_id', 'product_id', 'attachment_id', 'media_id', 'image_id',
			'thumbnail_id', 'poster_id', 'logo_id', 'site_logo_id', 'template_id', 'source_post_id',
			'woocommerce_purchase_summary_page_id', 'woocommerce_cart_page_id',
			'woocommerce_checkout_page_id', 'woocommerce_myaccount_page_id',
			'woocommerce_terms_page_id', 'woocommerce_shop_page_id',
		);
		$known_lists  = array(
			'post_ids', 'page_ids', 'product_ids', 'attachment_ids', 'media_ids', 'image_ids',
			'gallery_ids', 'template_ids',
		);
		$media_parents = array(
			'image', 'background_image', 'background_overlay_image', 'hover_image', 'poster',
			'video_image', 'background_video_fallback', 'fallback_image', 'thumbnail', 'logo',
			'site_logo', 'site_favicon', 'icon_image', 'gallery', 'images', 'slides',
		);
		$media_object_id = $this->elementor_media_object_id( $data );
		if ( $media_object_id > 0 ) {
			$ids[ $media_object_id ][] = $path . '.id#media-object';
		}

		foreach ( $data as $key => $value ) {
			$key_string = (string) $key;
			$child_path = $path . '.' . $key_string;
			if ( $media_object_id > 0 && in_array( $key_string, array( 'id', 'attachment_id', 'attachmentId', 'media_id', 'mediaId', 'image_id', 'imageId' ), true ) ) {
				
			} elseif ( in_array( $key_string, $known_scalar, true ) ) {
				$scalar_id = $this->numeric_id( $value );
				if ( $scalar_id > 0 ) {
					$ids[ $scalar_id ][] = $child_path;
				}
			} elseif ( 'id' === $key_string && in_array( (string) $parent_key, $media_parents, true ) && $this->numeric_id( $value ) > 0 ) {
				$ids[ $this->numeric_id( $value ) ][] = $child_path;
			} elseif ( in_array( $key_string, $known_lists, true ) && is_array( $value ) ) {
				foreach ( $value as $index => $item ) {
					if ( $this->numeric_id( $item ) > 0 ) {
						$ids[ $this->numeric_id( $item ) ][] = $child_path . '.' . $index;
					}
				}
			} elseif ( preg_match( '/(?:post|page|product|attachment|media|image|gallery|template|source).*ids?$/i', $key_string ) && ( is_scalar( $value ) || is_array( $value ) ) ) {
				$unresolved[] = array( 'table' => $wpdb->postmeta, 'row_id' => $row_id, 'path' => $child_path, 'reason' => 'در داده‌های Elementor یک کلید ناشناخته پیدا شد که ممکن است Post Reference باشد.' );
			}

			if ( is_string( $value ) ) {
				foreach ( $this->elementor_selector_ids( $value ) as $selector_id ) {
					$ids[ $selector_id ][] = $child_path . '#css-selector';
				}
			}

			if ( is_array( $value ) ) {
				$this->collect_elementor_ids( $value, $child_path, $key_string, $ids, $unresolved, $row_id );
			}
		}
	}

	/** @param array<int|string,mixed> $data @param array<int,int> $map */
	private function replace_elementor_ids( array &$data, ?string $parent_key, array $map ): void {
		$known_scalar = array(
			'post_id', 'page_id', 'product_id', 'attachment_id', 'media_id', 'image_id',
			'thumbnail_id', 'poster_id', 'logo_id', 'site_logo_id', 'template_id', 'source_post_id',
			'woocommerce_purchase_summary_page_id', 'woocommerce_cart_page_id',
			'woocommerce_checkout_page_id', 'woocommerce_myaccount_page_id',
			'woocommerce_terms_page_id', 'woocommerce_shop_page_id',
		);
		$known_lists  = array(
			'post_ids', 'page_ids', 'product_ids', 'attachment_ids', 'media_ids', 'image_ids',
			'gallery_ids', 'template_ids',
		);
		$media_parents = array(
			'image', 'background_image', 'background_overlay_image', 'hover_image', 'poster',
			'video_image', 'background_video_fallback', 'fallback_image', 'thumbnail', 'logo',
			'site_logo', 'site_favicon', 'icon_image', 'gallery', 'images', 'slides',
		);

		$media_id_key = $this->elementor_media_object_id_key( $data );
		if ( null !== $media_id_key ) {
			$media_id = $this->numeric_id( $data[ $media_id_key ] );
			if ( $media_id > 0 && isset( $map[ $media_id ] ) ) {
				$data[ $media_id_key ] = is_string( $data[ $media_id_key ] ) ? (string) $map[ $media_id ] : $map[ $media_id ];
			}
		}

		foreach ( $data as $key => &$value ) {
			$key_string = (string) $key;
			$id = $this->numeric_id( $value );
			if ( null !== $media_id_key && $key_string === $media_id_key ) {
				
			} elseif ( $id > 0 && ( in_array( $key_string, $known_scalar, true ) || ( 'id' === $key_string && in_array( (string) $parent_key, $media_parents, true ) ) ) && isset( $map[ $id ] ) ) {
				$value = is_string( $value ) ? (string) $map[ $id ] : $map[ $id ];
			} elseif ( in_array( $key_string, $known_lists, true ) && is_array( $value ) ) {
				foreach ( $value as &$item ) {
					$item_id = $this->numeric_id( $item );
					if ( $item_id > 0 && isset( $map[ $item_id ] ) ) {
						$item = is_string( $item ) ? (string) $map[ $item_id ] : $map[ $item_id ];
					}
				}
				unset( $item );
			}
			if ( is_string( $value ) ) {
				$value = $this->replace_elementor_selector_ids( $value, $map );
			}
			if ( is_array( $value ) ) {
				$this->replace_elementor_ids( $value, $key_string, $map );
			}
		}
		unset( $value );
	}

	/** @param array<int|string,mixed> $data */
	private function elementor_media_object_id( array $data ): int {
		$key = $this->elementor_media_object_id_key( $data );
		return null === $key ? 0 : $this->numeric_id( $data[ $key ] );
	}

	/** @param array<int|string,mixed> $data */
	private function elementor_media_object_id_key( array $data ): ?string {
		$url_present = false;
		foreach ( array( 'url', 'src', 'image_url', 'imageUrl', 'full_url', 'preview_url' ) as $url_key ) {
			if ( isset( $data[ $url_key ] ) && is_string( $data[ $url_key ] ) && '' !== trim( $data[ $url_key ] ) ) {
				$url_present = true;
				break;
			}
		}
		if ( ! $url_present ) {
			return null;
		}
		foreach ( array( 'id', 'attachment_id', 'attachmentId', 'media_id', 'mediaId', 'image_id', 'imageId' ) as $id_key ) {
			if ( isset( $data[ $id_key ] ) && $this->numeric_id( $data[ $id_key ] ) > 0 ) {
				return $id_key;
			}
		}
		return null;
	}

	/**
	 * Elementor Page Settings contain Global Custom CSS and Theme Style values.
	 * Known post-ID selectors are mapped without broad numeric replacement.
	 *
	 * @return array{rows:int,updated:int,unresolved:list<array<string,mixed>>}
	 */
	private function analyze_elementor_page_settings( string $mapping_uuid, bool $write, string $source = 'old_id', string $target = 'new_id' ): array {
		$wpdb = $this->wpdb;
		$table      = Identifier::quote( $wpdb->postmeta );
		$tornado_sql_table = Identifier::normalize( $table );
		$cursor     = 0;
		$rows_count = 0;
		$updated    = 0;
		$unresolved = array();

		while ( true ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_id, meta_value FROM %i WHERE meta_id > %d AND meta_key = %s ORDER BY meta_id ASC LIMIT %d",
					$tornado_sql_table,
					$cursor,
					'_elementor_page_settings',
					self::BATCH_SIZE
				),
				ARRAY_A
			);
			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$cursor = (int) $row['meta_id'];
				++$rows_count;
				$raw    = (string) $row['meta_value'];
				$format = 'serialized';
				$data   = SafeSerialization::maybe_unserialize( $raw );

				if ( ! is_array( $data ) ) {
					$decoded = json_decode( $raw, true, 512, JSON_BIGINT_AS_STRING );
					if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
						$data   = $decoded;
						$format = 'json';
					} else {
						continue;
					}
				}

				$ids = array();
				$this->collect_elementor_ids( $data, '$settings', null, $ids, $unresolved, $cursor );
				$map = $this->mapping_for_ids( $mapping_uuid, $source, $target, array_keys( $ids ) );
				if ( $write && ! empty( $map ) ) {
					$this->replace_elementor_ids( $data, null, $map );
					$new = 'json' === $format
						? wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
						: maybe_serialize( $data );
					if ( ! is_string( $new ) ) {
						throw new RuntimeException( 'پس از اصلاح شناسه‌ها، تنظیمات صفحه Elementor دوباره ساخته نشد.' );
					}
					if ( $new !== $raw ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
						$changed = $wpdb->update( $wpdb->postmeta, array( 'meta_value' => $new ), array( 'meta_id' => $cursor ), array( '%s' ), array( '%d' ) );
						if ( false === $changed ) {
							throw new RuntimeException( 'تنظیمات اصلاح‌شده صفحه Elementor در دیتابیس ذخیره نشد.' );
						}
						$updated += (int) $changed;
					}
				}
			}
		}

		return array( 'rows' => $rows_count, 'updated' => $updated, 'unresolved' => $unresolved );
	}

	/** @return list<int> */
	private function elementor_selector_ids( string $value ): array {
		$ids      = array();
		$patterns = array(
			'/(?<![A-Za-z0-9_-])\.elementor-(\d+)(?![A-Za-z0-9_-])/',
			'/\b(?:elementor-page-|postid-|page-id-)(\d+)\b/',
			'/\bpost-(\d+)\.css\b/',
			'/\b(?:wp-image-|attachment-|attachment_)(\d+)\b/i',
			'/\bdata-(?:elementor|attachment|media)-id\s*=\s*["\']?(\d+)/i',
		);
		foreach ( $patterns as $pattern ) {
			if ( preg_match_all( $pattern, $value, $matches ) ) {
				foreach ( (array) ( $matches[1] ?? array() ) as $id ) {
					$id = absint( $id );
					if ( $id > 0 ) {
						$ids[] = $id;
					}
				}
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/** @param array<int,int> $map */
	private function replace_elementor_selector_ids( string $value, array $map ): string {
		$callbacks = array(
			'/(?<![A-Za-z0-9_-])\.elementor-(\d+)(?![A-Za-z0-9_-])/' => static fn( array $m ): string => '.elementor-' . ( $map[ (int) $m[1] ] ?? $m[1] ),
			'/\b(elementor-page-|postid-|page-id-)(\d+)\b/' => static fn( array $m ): string => $m[1] . ( $map[ (int) $m[2] ] ?? $m[2] ),
			'/\bpost-(\d+)\.css\b/' => static fn( array $m ): string => 'post-' . ( $map[ (int) $m[1] ] ?? $m[1] ) . '.css',
			'/\b(wp-image-|attachment-|attachment_)(\d+)\b/i' => static fn( array $m ): string => $m[1] . ( $map[ (int) $m[2] ] ?? $m[2] ),
			'/(\bdata-(?:elementor|attachment|media)-id\s*=\s*["\']?)(\d+)/i' => static fn( array $m ): string => $m[1] . ( $map[ (int) $m[2] ] ?? $m[2] ),
		);
		foreach ( $callbacks as $pattern => $callback ) {
			$value = (string) preg_replace_callback( $pattern, $callback, $value );
		}
		return $value;
	}

	/**
	 * @return array{rows:int,updated:int,unresolved:list<array<string,mixed>>}
	 */
	private function analyze_blocks( string $mapping_uuid, bool $write, string $source = 'old_id', string $target = 'new_id' ): array {
		$wpdb = $this->wpdb;
		if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			return array( 'rows' => 0, 'updated' => 0, 'unresolved' => array( array( 'reason' => 'پردازشگر بلوک‌های WordPress در دسترس نیست.' ) ) );
		}
		$table = Identifier::quote( $wpdb->posts );
		$tornado_sql_table = Identifier::normalize( $table );
		$cursor = 0;
		$rows_count = 0;
		$updated = 0;
		$unresolved = array();
		while ( true ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_content FROM %i WHERE ID > %d AND post_content LIKE %s ORDER BY ID ASC LIMIT %d",
					$tornado_sql_table,
					$cursor,
					'%<!-- wp:%',
					self::BATCH_SIZE
				),
				ARRAY_A
			);
			if ( empty( $rows ) ) {
				break;
			}
			foreach ( $rows as $row ) {
				$cursor = (int) $row['ID'];
				++$rows_count;
				$blocks = parse_blocks( (string) $row['post_content'] );
				$ids = array();
				$this->collect_block_ids( $blocks, $ids, $unresolved, $cursor );
				$map = $this->mapping_for_ids( $mapping_uuid, $source, $target, array_keys( $ids ) );
				if ( $write && ! empty( $map ) ) {
					$this->replace_block_ids( $blocks, $map );
					$new = serialize_blocks( $blocks );
					if ( $new !== (string) $row['post_content'] ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
						$changed = $wpdb->update( $wpdb->posts, array( 'post_content' => $new ), array( 'ID' => $cursor ), array( '%s' ), array( '%d' ) );
						if ( false === $changed ) {
							throw new RuntimeException( 'محتوای اصلاح‌شده بلوک‌های Gutenberg در دیتابیس ذخیره نشد.' );
						}
						$updated += (int) $changed;
					}
				}
			}
		}
		return array( 'rows' => $rows_count, 'updated' => $updated, 'unresolved' => $unresolved );
	}

	/** @param list<array<string,mixed>> $blocks @param array<int,list<string>> $ids @param list<array<string,mixed>> $unresolved */
	private function collect_block_ids( array $blocks, array &$ids, array &$unresolved, int $post_id ): void {
		$wpdb = $this->wpdb;
		$rules = $this->block_rules();
		foreach ( $blocks as $index => $block ) {
			$name  = (string) ( $block['blockName'] ?? '' );
			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			foreach ( $rules[ $name ] ?? array() as $attribute => $mode ) {
				if ( ! array_key_exists( $attribute, $attrs ) ) {
					continue;
				}
				if ( 'scalar' === $mode && $this->numeric_id( $attrs[ $attribute ] ) > 0 ) {
					$ids[ $this->numeric_id( $attrs[ $attribute ] ) ][] = $name . '.' . $attribute;
				} elseif ( 'list' === $mode && is_array( $attrs[ $attribute ] ) ) {
					foreach ( $attrs[ $attribute ] as $item ) {
						if ( $this->numeric_id( $item ) > 0 ) {
							$ids[ $this->numeric_id( $item ) ][] = $name . '.' . $attribute;
						}
					}
				}
			}
			foreach ( $attrs as $attribute => $value ) {
				if ( preg_match( '/(?:post|page|product|attachment|media|template).*ids?$/i', (string) $attribute ) && ! isset( ( $rules[ $name ] ?? array() )[ $attribute ] ) ) {
					$unresolved[] = array( 'table' => $wpdb->posts, 'row_id' => $post_id, 'path' => 'blocks.' . $index . '.' . $attribute, 'reason' => 'این ویژگی بلوک ممکن است به Post ID اشاره کند، اما هنوز برای تبدیل ساختاری ثبت نشده است.' );
				}
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$this->collect_block_ids( $block['innerBlocks'], $ids, $unresolved, $post_id );
			}
		}
	}

	/** @param list<array<string,mixed>> $blocks @param array<int,int> $map */
	private function replace_block_ids( array &$blocks, array $map ): void {
		$rules = $this->block_rules();
		foreach ( $blocks as &$block ) {
			$name = (string) ( $block['blockName'] ?? '' );
			if ( ! isset( $block['attrs'] ) || ! is_array( $block['attrs'] ) ) {
				$block['attrs'] = array();
			}
			foreach ( $rules[ $name ] ?? array() as $attribute => $mode ) {
				if ( ! array_key_exists( $attribute, $block['attrs'] ) ) {
					continue;
				}
				if ( 'scalar' === $mode ) {
					$id = $this->numeric_id( $block['attrs'][ $attribute ] );
					if ( $id > 0 && isset( $map[ $id ] ) ) {
						$block['attrs'][ $attribute ] = $map[ $id ];
					}
				} elseif ( 'list' === $mode && is_array( $block['attrs'][ $attribute ] ) ) {
					foreach ( $block['attrs'][ $attribute ] as &$item ) {
						$id = $this->numeric_id( $item );
						if ( $id > 0 && isset( $map[ $id ] ) ) {
							$item = $map[ $id ];
						}
					}
					unset( $item );
				}
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$this->replace_block_ids( $block['innerBlocks'], $map );
			}
		}
		unset( $block );
	}

	/** @return array<string,array<string,string>> */
	private function block_rules(): array {
		$rules = array(
			'core/image'       => array( 'id' => 'scalar' ),
			'core/cover'       => array( 'id' => 'scalar' ),
			'core/audio'       => array( 'id' => 'scalar' ),
			'core/video'       => array( 'id' => 'scalar' ),
			'core/file'        => array( 'id' => 'scalar' ),
			'core/media-text'  => array( 'mediaId' => 'scalar' ),
			'core/gallery'     => array( 'ids' => 'list' ),
			'core/block'       => array( 'ref' => 'scalar' ),
			'core/navigation'  => array( 'ref' => 'scalar' ),
		);
		$filtered = apply_filters( 'shcd_tornado_dbm_gutenberg_reference_attributes', $rules );
		return is_array( $filtered ) ? $filtered : $rules;
	}

	/** @return array<int,int> */
	private function mapping_for_ids( string $mapping_uuid, string $source, string $target, array $ids ): array {
		$wpdb = $this->wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) ) );
		if ( empty( $ids ) ) {
			return array();
		}
		$mapping  = Identifier::normalize( $this->tables->mappings() );
		$source_q = Identifier::normalize( $source );
		$target_q = Identifier::normalize( $target );
		$result = array();
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$args = array_merge( array( $source_q, $target_q, $mapping, $mapping_uuid, $source_q ), $chunk );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Only the generated numeric placeholder list is interpolated; all identifiers and values are prepared.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT %i source_id, %i target_id FROM %i WHERE job_uuid = %s AND %i IN ({$placeholders})", $args ), ARRAY_A );
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

	private function numeric_id( mixed $value ): int {
		return is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ? (int) $value : 0;
	}

	/** @param list<string> $keys */
	private function count_meta_rows( string $table_name, array $keys ): int {
		$wpdb = $this->wpdb;
		if ( empty( $keys ) ) {
			return 0;
		}
		$table = Identifier::quote( $table_name );
		$tornado_sql_table = Identifier::normalize( $table );
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic SQL is built only from validated identifiers or fixed internal fragments and prepared values; direct uncached access is required for current maintenance state.
		return (int) $wpdb->get_var( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only validated identifiers, fixed internal clauses, or generated placeholder lists are interpolated; external values are passed to prepare().
			"SELECT COUNT(*) FROM %i WHERE meta_key IN ({$placeholders})",
			array_merge( array( $tornado_sql_table ), $keys )
		) ); 
	}
}
