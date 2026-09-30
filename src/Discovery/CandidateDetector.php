<?php
/**
 * Reference candidate classifier.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Discovery;

final class CandidateDetector {
	/**
	 * @param array<string, mixed> $column Column metadata.
	 * @return array{type:string,confidence:int,reason:string}
	 */
	public function classify( array $column, string $posts_table ): array {
		$table       = (string) ( $column['TABLE_NAME'] ?? '' );
		$name        = (string) ( $column['COLUMN_NAME'] ?? '' );
		$data_type   = strtolower( (string) ( $column['DATA_TYPE'] ?? '' ) );
		$column_type = strtolower( (string) ( $column['COLUMN_TYPE'] ?? '' ) );
		$is_numeric  = preg_match( '/^(tinyint|smallint|mediumint|int|bigint)$/', $data_type ) === 1;
		$is_text     = preg_match( '/(char|text|json|blob)/', $data_type ) === 1;

		if ( $table === $posts_table && 'ID' === $name ) {
			return array( 'type' => 'target_primary_key', 'confidence' => 100, 'reason' => 'WordPress posts primary key.' );
		}

		$core = array(
			$posts_table . '.post_parent'                  => 'Core post parent reference.',
			$GLOBALS['wpdb']->postmeta . '.post_id'        => 'Core post meta reference.',
			$GLOBALS['wpdb']->comments . '.comment_post_ID' => 'Core comment reference.',
			$GLOBALS['wpdb']->term_relationships . '.object_id' => 'Core taxonomy object reference.',
		);
		$key = $table . '.' . $name;

		if ( isset( $core[ $key ] ) ) {
			return array( 'type' => 'known_scalar_reference', 'confidence' => 100, 'reason' => $core[ $key ] );
		}

		$is_primary = 'PRI' === strtoupper( (string) ( $column['COLUMN_KEY'] ?? '' ) ) || str_contains( strtolower( (string) ( $column['EXTRA'] ?? '' ) ), 'auto_increment' );
		if ( $is_numeric && $is_primary ) {
			return array( 'type' => 'none', 'confidence' => 0, 'reason' => 'Primary and AUTO_INCREMENT keys are not treated as post references.' );
		}

		if ( $is_numeric && (
			preg_match( '/^(?:(?:source|target|parent|child|related|linked|origin|destination|base|featured|hero|cover)_)?(?:post|page|product|variation|attachment|media|image|thumbnail|template)_id$/i', $name ) === 1
			|| preg_match( '/^(?:post|page|product|variation|attachment|template)_parent_id$/i', $name ) === 1
			|| preg_match( '/^(?:post|product|variation)_or_parent_id$/i', $name ) === 1
			|| 'post_parent' === strtolower( $name )
		) ) {
			return array( 'type' => 'probable_scalar_reference', 'confidence' => 90, 'reason' => 'Numeric column name is a deterministic post-object reference candidate.' );
		}

		if ( $is_numeric && preg_match( '/^(?:object|element|entity|content|item|resource|source|target|parent|related|linked)_id$/i', $name ) === 1 ) {
			$table_semantic = preg_match( '/post|content|element|rank_math|seo|product|media|template|translation|link/i', $table ) === 1;
			return array(
				'type'       => 'possible_scalar_reference',
				'confidence' => $table_semantic ? 65 : 55,
				'reason'     => 'Generic object identifier; companion type columns and Mapping evidence are required before registration.',
			);
		}

		if ( $is_numeric && preg_match( '/post|product|variation|attachment|template|page|media|image|thumbnail/i', $name ) === 1 ) {
			return array( 'type' => 'possible_scalar_reference', 'confidence' => 60, 'reason' => 'Numeric column name contains a post-object token.' );
		}

		if ( $is_numeric && preg_match( '/(^|_)(order|order_parent|parent_order)(_?id)?$/i', $name ) === 1 ) {
			return array( 'type' => 'protected_order_reference', 'confidence' => 70, 'reason' => 'Order-like references are protected by default.' );
		}

		if ( $is_text && in_array( strtolower( $name ), array( 'meta_value', 'option_value', 'post_content', 'post_excerpt', 'value', 'settings', 'data', 'config' ), true ) ) {
			return array( 'type' => 'embedded_reference_container', 'confidence' => 45, 'reason' => 'Text-like container may include serialized, JSON, block, or custom references.' );
		}

		if ( str_contains( $column_type, 'unsigned' ) && $is_numeric ) {
			return array( 'type' => 'numeric_unknown', 'confidence' => 10, 'reason' => 'Unsigned numeric column; no semantic relationship inferred.' );
		}

		return array( 'type' => 'none', 'confidence' => 0, 'reason' => 'No post reference signal detected.' );
	}
}
