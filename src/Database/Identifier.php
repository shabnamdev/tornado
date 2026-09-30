<?php
/**
 * SQL identifier allow-list and quoting.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Database;

use InvalidArgumentException;

final class Identifier {
	public static function normalize( string $identifier ): string {
		if ( str_starts_with( $identifier, '`' ) && str_ends_with( $identifier, '`' ) ) {
			$identifier = substr( $identifier, 1, -1 );
		}

		if ( preg_match( '/^[A-Za-z0-9_$]+$/', $identifier ) !== 1 ) {
			throw new InvalidArgumentException( 'Unsafe SQL identifier.' );
		}

		return $identifier;
	}

	public static function quote( string $identifier ): string {
		return '`' . self::normalize( $identifier ) . '`';
	}
}
