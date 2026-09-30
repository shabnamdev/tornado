<?php
/**
 * Explicit and database-declared post-reference registry.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Reindex;

use Shcd\TornadoDatabaseMaintenance\Adapter\AdapterRegistry;
use Shcd\TornadoDatabaseMaintenance\Database\Identifier;

final class ReferenceRegistry {
	private readonly AdapterRegistry $adapters;

	public function __construct(
		private readonly \wpdb $wpdb,
		?AdapterRegistry $adapters = null
	) {
		$this->adapters = $adapters ?? new AdapterRegistry();
	}

	/**
	 * @return list<array{table:string,column:string,label:string,source:string}>
	 */
	public function scalar_columns(): array {
		$wpdb = $this->wpdb;
		$references = array(
			array( 'table' => $wpdb->posts, 'column' => 'post_parent', 'label' => 'WordPress post parent', 'source' => 'core' ),
			array( 'table' => $wpdb->postmeta, 'column' => 'post_id', 'label' => 'WordPress post meta', 'source' => 'core' ),
			array( 'table' => $wpdb->comments, 'column' => 'comment_post_ID', 'label' => 'WordPress comments', 'source' => 'core' ),
			array( 'table' => $wpdb->term_relationships, 'column' => 'object_id', 'label' => 'WordPress term relationships', 'source' => 'core' ),
		);

		
		$elementor_submissions = $wpdb->prefix . 'e_submissions';
		if ( $this->table_exists( $elementor_submissions ) && $this->column_exists( $elementor_submissions, 'post_id' ) ) {
			$references[] = array(
				'table'  => $elementor_submissions,
				'column' => 'post_id',
				'label'  => 'شناسه فرم مبدأ در Elementor Submissions',
				'source' => 'elementor_forms',
			);
		}

		$optional = array(
			$wpdb->prefix . 'wc_product_meta_lookup' => array( 'product_id' ),
			$wpdb->prefix . 'wc_product_attributes_lookup' => array( 'product_or_parent_id' ),
			$wpdb->prefix . 'wc_order_product_lookup' => array( 'order_id', 'product_id', 'variation_id' ),
			$wpdb->prefix . 'wc_order_stats' => array( 'order_id', 'parent_id' ),
			$wpdb->prefix . 'wc_order_tax_lookup' => array( 'order_id' ),
			$wpdb->prefix . 'wc_order_coupon_lookup' => array( 'order_id' ),
			$wpdb->prefix . 'woocommerce_order_items' => array( 'order_id' ),
			$wpdb->prefix . 'woocommerce_downloadable_product_permissions' => array( 'order_id', 'product_id' ),
		);

		foreach ( $optional as $table => $columns ) {
			if ( ! $this->table_exists( $table ) ) {
				continue;
			}
			foreach ( $columns as $column ) {
				if ( $this->hpos_enabled() && in_array( $column, array( 'order_id', 'parent_id' ), true ) ) {
					continue;
				}
				if ( $this->column_exists( $table, $column ) ) {
					$references[] = array( 'table' => $table, 'column' => $column, 'label' => 'Registered WooCommerce product reference', 'source' => 'woocommerce' );
				}
			}
		}

		foreach ( $this->foreign_keys_to_posts() as $reference ) {
			$references[] = $reference;
		}

		foreach ( $this->adapters->scalar_references() as $reference ) {
			$references[] = $reference;
		}

		/**
		 * Filters explicitly supported scalar post references.
		 *
		 * @param list<array{table:string,column:string,label:string,source:string}> $references References.
		 */
		$filtered = apply_filters( 'shcd_tornado_dbm_registered_scalar_references', $references );
		$filtered = is_array( $filtered ) ? $filtered : $references;

		$unique = array();
		foreach ( $filtered as $reference ) {
			if ( ! is_array( $reference ) || empty( $reference['table'] ) || empty( $reference['column'] ) ) {
				continue;
			}
			$table  = (string) $reference['table'];
			$column = (string) $reference['column'];
			try {
				Identifier::quote( $table );
				Identifier::quote( $column );
			} catch ( \Throwable ) {
				continue;
			}
			if ( ! $this->table_exists( $table ) || ! $this->column_exists( $table, $column ) ) {
				continue;
			}
			$key = $table . '.' . $column;
			$unique[ $key ] = array(
				'table'  => $table,
				'column' => $column,
				'label'  => sanitize_text_field( (string) ( $reference['label'] ?? $key ) ),
				'source' => sanitize_key( (string) ( $reference['source'] ?? 'extension' ) ),
			);
		}

		return array_values( $unique );
	}

	/**
	 * Scalar meta values that always represent wp_posts.ID.
	 *
	 * @return array<string, list<string>>
	 */
	public function scalar_meta_keys(): array {
		$wpdb = $this->wpdb;
		$keys = array(
			$wpdb->postmeta => array(
				'_thumbnail_id',
				'_menu_item_menu_item_parent',
				'_elementor_template_id',
				'_elementor_source_post_id',
			),
		);
		if ( defined( 'WC_VERSION' ) || class_exists( 'WooCommerce' ) ) {
			$keys[ $wpdb->termmeta ] = array( 'thumbnail_id' );

			$order_itemmeta = $wpdb->prefix . 'woocommerce_order_itemmeta';
			if ( $this->table_exists( $order_itemmeta ) ) {
				$keys[ $order_itemmeta ] = array( '_product_id', '_variation_id' );
			}
		}
		return $keys;
	}

	/**
	 * Metadata requiring a companion-row condition before it is a post reference.
	 *
	 * @return list<array<string,string>>
	 */
	public function conditional_meta_references(): array {
		$wpdb = $this->wpdb;
		return array(
			array(
				'table'                => $wpdb->postmeta,
				'primary_key'          => 'meta_id',
				'object_column'        => 'post_id',
				'meta_key_column'      => 'meta_key',
				'meta_value_column'    => 'meta_value',
				'target_key'           => '_menu_item_object_id',
				'condition_key'        => '_menu_item_type',
				'condition_value'      => 'post_type',
				'label'                => 'Navigation menu post object',
			),
		);
	}

	/** @return array<string,list<string>> */
	public function csv_meta_keys(): array {
		$wpdb = $this->wpdb;
		return array(
			$wpdb->postmeta => array( '_product_image_gallery' ),
		);
	}

	/** @return array<string,list<string>> */
	public function serialized_id_list_meta_keys(): array {
		$wpdb = $this->wpdb;
		return array(
			$wpdb->postmeta => array( '_crosssell_ids', '_upsell_ids', '_children' ),
		);
	}

	/** @return list<string> */
	public function option_names(): array {
		return array(
			'page_on_front',
			'page_for_posts',
			'woocommerce_shop_page_id',
			'woocommerce_cart_page_id',
			'woocommerce_checkout_page_id',
			'woocommerce_myaccount_page_id',
			'woocommerce_terms_page_id',
			'elementor_active_kit',
			'site_icon',
		);
	}

	/**
	 * @return list<array{table:string,column:string,label:string,source:string}>
	 */
	private function foreign_keys_to_posts(): array {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
				 WHERE TABLE_SCHEMA = %s AND REFERENCED_TABLE_NAME = %s AND REFERENCED_COLUMN_NAME = %s',
				(string) DB_NAME,
				$wpdb->posts,
				'ID'
			),
			ARRAY_A
		);
		$result = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$result[] = array(
				'table'  => (string) $row['TABLE_NAME'],
				'column' => (string) $row['COLUMN_NAME'],
				'label'  => 'Declared foreign key ' . (string) $row['CONSTRAINT_NAME'],
				'source' => 'foreign_key',
			);
		}
		return $result;
	}


	private function hpos_enabled(): bool {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			return false;
		}
		try {
			return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		} catch ( \Throwable ) {
			return true;
		}
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
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				(string) DB_NAME,
				$table,
				$column
			)
		);
		return $count > 0;
	}
}
