<?php
/**
 * Dynamic registry for theme/plugin header, footer and template builders.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Repair;

use Shcd\TornadoDatabaseMaintenance\Core\SafeSerialization;
use Shcd\TornadoDatabaseMaintenance\Database\Identifier;
use Shcd\TornadoDatabaseMaintenance\Database\Tables;

final class BuilderRegistryService {
	private const OPTION = 'shcd_tornado_dbm_builder_registry';
	private const SCANNED_AT_OPTION = 'shcd_tornado_dbm_builder_registry_scanned_at';
	private const MAX_ENTRIES = 100;
	private const MAX_KEYS = 50;

	/** @var array<string,array<string,mixed>>|null */
	private ?array $runtime_cache = null;

	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly Tables $tables
	) {}

	/**
	 * @return array<string,mixed>
	 */
	public function list_registry( bool $scan_when_empty = true ): array {
		$items = $this->stored_entries();
		if ( empty( $items ) && $scan_when_empty ) {
			return $this->scan();
		}

		return array(
			'items'      => array_values( $items ),
			'scanned_at' => (string) get_option( self::SCANNED_AT_OPTION, '' ),
			'count'      => count( $items ),
		);
	}

	/**
	 * Discovers builder post types from registered post-type labels, Elementor data,
	 * assignment options and post-meta values. Existing manual rules take priority.
	 *
	 * @return array<string,mixed>
	 */
	public function scan(): array {
		$existing   = $this->stored_entries();
		$candidates = $this->discover_candidates();
		$id_index   = $this->candidate_id_index( array_keys( $candidates ) );
		$option_map = $this->discover_option_assignments( $id_index );
		$meta_map   = $this->discover_meta_assignments( $id_index );

		foreach ( $candidates as $post_type => &$entry ) {
			$entry['option_keys'] = array_values( array_unique( $option_map[ $post_type ] ?? array() ) );
			$entry['meta_keys']   = array_values( array_unique( $meta_map[ $post_type ] ?? array() ) );
			if ( 'unknown' === (string) $entry['role'] ) {
				$inferred_role = $this->detect_role( implode( ' ', array_merge( $entry['option_keys'], $entry['meta_keys'] ) ) );
				if ( 'unknown' !== $inferred_role ) {
					$entry['role'] = $inferred_role;
					$entry['confidence'] += 35;
				}
			}
			$entry['confidence'] += min( 20, count( $entry['option_keys'] ) * 5 );
			$entry['confidence'] += min( 15, count( $entry['meta_keys'] ) * 3 );
			$entry['confidence']  = min( 100, (int) $entry['confidence'] );
			$entry['enabled']     = (bool) ( $entry['enabled'] || $entry['confidence'] >= 45 );

			if ( 'unknown' === (string) $entry['role']
				&& 0 === (int) $entry['elementor_documents']
				&& empty( $entry['option_keys'] )
				&& empty( $entry['meta_keys'] )
				&& ! isset( $existing[ $post_type ] ) ) {
				unset( $candidates[ $post_type ] );
				continue;
			}

			if ( isset( $existing[ $post_type ] ) ) {
				$manual = $existing[ $post_type ];
				$entry['role']        = (string) ( $manual['role'] ?? $entry['role'] );
				$entry['editor']      = (string) ( $manual['editor'] ?? $entry['editor'] );
				$entry['enabled']     = (bool) ( $manual['enabled'] ?? $entry['enabled'] );
				$entry['label']       = (string) ( $manual['label'] ?? $entry['label'] );
				$entry['option_keys'] = array_values( array_unique( array_merge( $entry['option_keys'], (array) ( $manual['option_keys'] ?? array() ) ) ) );
				$entry['meta_keys']   = array_values( array_unique( array_merge( $entry['meta_keys'], (array) ( $manual['meta_keys'] ?? array() ) ) ) );
				$entry['source']      = 'automatic+manual';
				$entry['manual']      = true;
			}
		}
		unset( $entry );

		foreach ( $existing as $post_type => $manual ) {
			if ( ! isset( $candidates[ $post_type ] ) ) {
				$candidates[ $post_type ] = $manual;
			}
		}

		$candidates = $this->sanitize_entries( array_values( $candidates ) );
		update_option( self::OPTION, $candidates, false );
		update_option( self::SCANNED_AT_OPTION, current_time( 'mysql', true ), false );
		$this->runtime_cache = null;

		return array(
			'items'      => array_values( $candidates ),
			'scanned_at' => (string) get_option( self::SCANNED_AT_OPTION, '' ),
			'count'      => count( $candidates ),
		);
	}

	/**
	 * @param array<int,mixed> $entries
	 * @return array<string,mixed>
	 */
	public function save( array $entries ): array {
		$sanitized = $this->sanitize_entries( $entries );
		foreach ( $sanitized as &$entry ) {
			$entry['manual'] = true;
			$entry['source'] = 'manual';
		}
		unset( $entry );
		update_option( self::OPTION, $sanitized, false );
		$this->runtime_cache = null;

		return array(
			'items'      => array_values( $sanitized ),
			'scanned_at' => (string) get_option( self::SCANNED_AT_OPTION, '' ),
			'count'      => count( $sanitized ),
			'saved'      => true,
		);
	}

	/** @return list<array<string,mixed>> */
	public function enabled_entries(): array {
		return array_values(
			array_filter(
				$this->stored_entries(),
				static fn( array $entry ): bool => ! empty( $entry['enabled'] )
			)
		);
	}

	/** @return list<string> */
	public function elementor_post_types(): array {
		$types = array();
		foreach ( $this->enabled_entries() as $entry ) {
			if ( 'elementor' === (string) ( $entry['editor'] ?? '' ) ) {
				$types[] = (string) $entry['post_type'];
			}
		}
		return array_values( array_unique( array_filter( array_map( 'sanitize_key', $types ) ) ) );
	}

	public function role_for_post_type( string $post_type ): string {
		$post_type = sanitize_key( $post_type );
		$entries   = $this->stored_entries();
		if ( isset( $entries[ $post_type ] ) && ! empty( $entries[ $post_type ]['enabled'] ) ) {
			return (string) ( $entries[ $post_type ]['role'] ?? 'unknown' );
		}

		$object = get_post_type_object( $post_type );
		$text   = $post_type . ' ' . ( $object && isset( $object->labels->singular_name ) ? (string) $object->labels->singular_name : '' );
		return $this->detect_role( $text );
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private function stored_entries(): array {
		if ( null !== $this->runtime_cache ) {
			return $this->runtime_cache;
		}
		$raw = get_option( self::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();
		$this->runtime_cache = $this->sanitize_entries( array_values( $raw ) );
		return $this->runtime_cache;
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private function discover_candidates(): array {
		$wpdb = $this->wpdb;
		$posts = Identifier::quote( $wpdb->posts );
		$tornado_sql_posts = Identifier::normalize( $posts );
		$meta  = Identifier::quote( $wpdb->postmeta );
		$tornado_sql_meta = Identifier::normalize( $meta );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$rows  = $wpdb->get_results(
			 $wpdb->prepare( "SELECT p.post_type,
			 COUNT(DISTINCT p.ID) AS post_count,
			 COUNT(DISTINCT CASE WHEN em.meta_id IS NOT NULL THEN p.ID END) AS elementor_documents
			 FROM %i p
			 LEFT JOIN %i em ON em.post_id = p.ID AND em.meta_key = '_elementor_data'
			 WHERE p.post_status NOT IN ('trash','auto-draft','inherit')
			 GROUP BY p.post_type
			 ORDER BY p.post_type ASC", $tornado_sql_posts, $tornado_sql_meta ) ,
			ARRAY_A
		);

		$skip = array( 'post', 'page', 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request' );
		$result = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$post_type = sanitize_key( (string) ( $row['post_type'] ?? '' ) );
			if ( '' === $post_type || in_array( $post_type, $skip, true ) ) {
				continue;
			}
			$object = get_post_type_object( $post_type );
			$label  = $object && isset( $object->labels->singular_name ) ? (string) $object->labels->singular_name : $post_type;
			$plural = $object && isset( $object->labels->name ) ? (string) $object->labels->name : '';
			$text   = trim( $post_type . ' ' . $label . ' ' . $plural . ' ' . ( $object->description ?? '' ) );
			$role   = $this->detect_role( $text );
			$docs   = absint( $row['elementor_documents'] ?? 0 );
			$count  = absint( $row['post_count'] ?? 0 );
			$editor = $docs > 0 || post_type_supports( $post_type, 'elementor' ) ? 'elementor' : 'native';
			$score  = 0;
			if ( 'unknown' !== $role ) {
				$score += 45;
			}
			if ( $docs > 0 ) {
				$score += 25;
			}
			if ( $object && ! empty( $object->show_ui ) ) {
				$score += 5;
			}
			if ( $count > 0 && $count <= 1000 ) {
				$score += 5;
			}


			$result[ $post_type ] = array(
				'post_type'          => $post_type,
				'label'              => sanitize_text_field( $label ),
				'role'               => $role,
				'editor'             => $editor,
				'enabled'            => 'unknown' !== $role,
				'source'             => 'automatic',
				'manual'             => false,
				'confidence'         => min( 100, $score ),
				'post_count'         => $count,
				'elementor_documents'=> $docs,
				'option_keys'        => array(),
				'meta_keys'          => array(),
			);
		}

		return $result;
	}

	/**
	 * @param list<string> $post_types
	 * @return array<int,string>
	 */
	private function candidate_id_index( array $post_types ): array {
		$wpdb = $this->wpdb;
		if ( empty( $post_types ) ) {
			return array();
		}
		$posts        = Identifier::quote( $wpdb->posts );
		$tornado_sql_posts = Identifier::normalize( $posts );
		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$rows         = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Generated SQL fragment is restricted to validated identifiers, fixed clauses, or a placeholder list; all external data values are passed to prepare().
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only validated identifiers, fixed internal clauses, or generated placeholder lists are interpolated; external values are passed to prepare().
				"SELECT ID, post_type FROM %i WHERE post_type IN ({$placeholders}) AND post_status NOT IN ('trash','auto-draft')",
				array_merge( array( $tornado_sql_posts ), $post_types )
			),
			ARRAY_A
		);
		$index = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$id = absint( $row['ID'] ?? 0 );
			if ( $id > 0 ) {
				$index[ $id ] = sanitize_key( (string) $row['post_type'] );
			}
		}

		
		
		$mapping_uuid = (string) get_option( 'shcd_tornado_dbm_last_executed_mapping_uuid', get_option( 'shcd_tornado_dbm_last_mapping_uuid', '' ) );
		if ( '' !== $mapping_uuid && wp_is_uuid( $mapping_uuid ) ) {
			$table = Identifier::quote( $this->tables->mappings() );
			$tornado_sql_table = Identifier::normalize( $table );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$map_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT old_id, new_id FROM %i WHERE job_uuid = %s AND old_id <> new_id",
					$tornado_sql_table,
					$mapping_uuid
				),
				ARRAY_A
			);
			foreach ( is_array( $map_rows ) ? $map_rows : array() as $map_row ) {
				$old = absint( $map_row['old_id'] ?? 0 );
				$new = absint( $map_row['new_id'] ?? 0 );
				if ( $old > 0 && isset( $index[ $new ] ) ) {
					$index[ $old ] = $index[ $new ];
				}
			}
		}
		return $index;
	}

	/**
	 * @param array<int,string> $id_index
	 * @return array<string,list<string>>
	 */
	private function discover_option_assignments( array $id_index ): array {
		$wpdb = $this->wpdb;
		$result = array();
		if ( empty( $id_index ) ) {
			return $result;
		}
		$options = Identifier::quote( $wpdb->options );
		$tornado_sql_options = Identifier::normalize( $options );
		$cursor  = 0;
		while ( true ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_id, option_name, option_value FROM %i WHERE option_id > %d ORDER BY option_id ASC LIMIT %d",
					$tornado_sql_options,
					$cursor,
					500
				),
				ARRAY_A
			);
			if ( empty( $rows ) ) {
				break;
			}
			foreach ( $rows as $row ) {
				$cursor = absint( $row['option_id'] ?? 0 );
				$name   = $this->sanitize_storage_key( (string) ( $row['option_name'] ?? '' ) );
				if ( '' === $name || str_starts_with( $name, 'shcd_tornado_dbm_' ) || str_starts_with( $name, '_transient_' ) || str_starts_with( $name, '_site_transient_' ) ) {
					continue;
				}
				$value = (string) ( $row['option_value'] ?? '' );
				if ( strlen( $value ) > 2_000_000 ) {
					continue;
				}
				foreach ( $this->extract_ids( $value, $name ) as $id ) {
					if ( isset( $id_index[ $id ] ) ) {
						$result[ $id_index[ $id ] ][] = $name;
					}
				}
			}
		}
		foreach ( $result as &$keys ) {
			$keys = array_slice( array_values( array_unique( $keys ) ), 0, self::MAX_KEYS );
		}
		unset( $keys );
		return $result;
	}

	/**
	 * @param array<int,string> $id_index
	 * @return array<string,list<string>>
	 */
	private function discover_meta_assignments( array $id_index ): array {
		$wpdb = $this->wpdb;
		$result = array();
		if ( empty( $id_index ) ) {
			return $result;
		}
		$ids = array_keys( $id_index );
		foreach ( array_chunk( $ids, 250 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$meta = Identifier::normalize( $wpdb->postmeta );
			$args = array_merge( array( $meta ), array_map( 'strval', $chunk ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Only the generated value placeholder list is interpolated; table and values are prepared.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_key, meta_value, COUNT(*) AS hits FROM %i WHERE meta_value IN ({$placeholders}) AND meta_key NOT IN ('_elementor_data','_elementor_page_settings','_elementor_conditions','_thumbnail_id') GROUP BY meta_key, meta_value ORDER BY hits DESC LIMIT 1000",
					$args
				),
				ARRAY_A
			);
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$id  = absint( $row['meta_value'] ?? 0 );
				$key = $this->sanitize_storage_key( (string) ( $row['meta_key'] ?? '' ) );
				if ( $id > 0 && '' !== $key && isset( $id_index[ $id ] ) ) {
					$result[ $id_index[ $id ] ][] = $key;
				}
			}
		}
		foreach ( $result as &$keys ) {
			$keys = array_slice( array_values( array_unique( $keys ) ), 0, self::MAX_KEYS );
		}
		unset( $keys );
		return $result;
	}

	/** @return list<int> */
	private function extract_ids( string $raw, string $context ): array {
		$trimmed = trim( $raw );
		if ( '' !== $trimmed && ctype_digit( $trimmed ) ) {
			return array( absint( $trimmed ) );
		}

		$value = $raw;
		if ( is_serialized( $raw ) ) {
			$value = SafeSerialization::maybe_unserialize( $raw );
		} else {
			$decoded = json_decode( $raw, true, 512, JSON_BIGINT_AS_STRING );
			if ( JSON_ERROR_NONE === json_last_error() ) {
				$value = $decoded;
			}
		}
		$ids      = array();
		$semantic = $this->is_assignment_context( $context );
		$this->collect_ids( $value, $ids, $semantic );
		return array_slice( array_values( array_unique( $ids ) ), 0, 500 );
	}

	/** @param list<int> $ids */
	private function collect_ids( mixed $value, array &$ids, bool $semantic ): void {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$child_semantic = $semantic || $this->is_assignment_context( (string) $key );
				$this->collect_ids( $item, $ids, $child_semantic );
			}
			return;
		}
		if ( $semantic && ( is_int( $value ) || ( is_string( $value ) && ctype_digit( trim( $value ) ) ) ) ) {
			$id = absint( $value );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
	}

	private function is_assignment_context( string $context ): bool {
		$context = function_exists( 'mb_strtolower' ) ? mb_strtolower( $context, 'UTF-8' ) : strtolower( $context );
		return 1 === preg_match( '/header|footer|template|layout|builder|section|masthead|topbar|bottom|هدر|سربرگ|فوتر|پاورقی|قالب|چیدمان|سازنده/u', $context );
	}

	private function detect_role( string $text ): string {
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		if ( preg_match( '/header|masthead|topbar|top-bar|هدر|سربرگ|بالای سایت/u', $text ) ) {
			return 'header';
		}
		if ( preg_match( '/footer|bottom-bar|bottombar|فوتر|پاورقی|پایین سایت/u', $text ) ) {
			return 'footer';
		}
		if ( preg_match( '/template|layout|builder|section|قالب|چیدمان|سازنده|بخش/u', $text ) ) {
			return 'template';
		}
		return 'unknown';
	}

	/**
	 * @param array<int,mixed> $entries
	 * @return array<string,array<string,mixed>>
	 */
	private function sanitize_entries( array $entries ): array {
		$result = array();
		foreach ( array_slice( $entries, 0, self::MAX_ENTRIES ) as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$post_type = sanitize_key( (string) ( $raw['post_type'] ?? '' ) );
			if ( '' === $post_type ) {
				continue;
			}
			$role   = sanitize_key( (string) ( $raw['role'] ?? 'unknown' ) );
			$editor = sanitize_key( (string) ( $raw['editor'] ?? 'native' ) );
			$role   = in_array( $role, array( 'header', 'footer', 'template', 'unknown' ), true ) ? $role : 'unknown';
			$editor = in_array( $editor, array( 'elementor', 'native', 'unknown' ), true ) ? $editor : 'native';
			$result[ $post_type ] = array(
				'post_type'           => $post_type,
				'label'               => sanitize_text_field( (string) ( $raw['label'] ?? $post_type ) ),
				'role'                => $role,
				'editor'              => $editor,
				'enabled'             => ! empty( $raw['enabled'] ),
				'source'              => sanitize_text_field( (string) ( $raw['source'] ?? 'manual' ) ),
				'manual'              => ! empty( $raw['manual'] ),
				'confidence'          => max( 0, min( 100, absint( $raw['confidence'] ?? 0 ) ) ),
				'post_count'          => absint( $raw['post_count'] ?? 0 ),
				'elementor_documents' => absint( $raw['elementor_documents'] ?? 0 ),
				'option_keys'         => $this->sanitize_keys( $raw['option_keys'] ?? array() ),
				'meta_keys'           => $this->sanitize_keys( $raw['meta_keys'] ?? array() ),
			);
		}
		ksort( $result );
		return $result;
	}

	/** @return list<string> */
	private function sanitize_keys( mixed $keys ): array {
		if ( is_string( $keys ) ) {
			$keys = preg_split( '/[\s,\n\r]+/', $keys ) ?: array();
		}
		$keys = is_array( $keys ) ? $keys : array();
		$result = array();
		foreach ( array_slice( $keys, 0, self::MAX_KEYS ) as $key ) {
			$key = $this->sanitize_storage_key( (string) $key );
			if ( '' !== $key ) {
				$result[] = $key;
			}
		}
		return array_values( array_unique( $result ) );
	}

	private function sanitize_storage_key( string $key ): string {
		$key = trim( sanitize_text_field( $key ) );
		if ( '' === $key || strlen( $key ) > 191 || 1 !== preg_match( '/^[A-Za-z0-9_.:\-]+$/', $key ) ) {
			return '';
		}
		return $key;
	}
}
