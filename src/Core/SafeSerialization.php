<?php
/**
 * Safe decoding helpers for serialized values read directly from the database.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Core;

final class SafeSerialization {
	public static function maybe_unserialize( mixed $value ): mixed {
		if ( ! is_string( $value ) || ! is_serialized( $value ) ) {
			return $value;
		}

		try {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Object instantiation is explicitly disabled; this is required to inspect legacy WordPress serialized arrays safely.
			$decoded = @unserialize( trim( $value ), array( 'allowed_classes' => false ) );
		} catch ( \Throwable ) {
			return $value;
		}

		return self::contains_object( $decoded ) ? $value : $decoded;
	}

	private static function contains_object( mixed $value ): bool {
		if ( is_object( $value ) || is_resource( $value ) ) {
			return true;
		}
		if ( ! is_array( $value ) ) {
			return false;
		}
		foreach ( $value as $item ) {
			if ( self::contains_object( $item ) ) {
				return true;
			}
		}
		return false;
	}
}
