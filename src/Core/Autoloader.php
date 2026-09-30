<?php
/**
 * PSR-4 compatible fallback autoloader.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Core;

final class Autoloader {
	private const PREFIX = 'Shcd\\TornadoDatabaseMaintenance\\';

	public static function register(): void {
		spl_autoload_register( array( self::class, 'autoload' ) );
	}

	private static function autoload( string $class ): void {
		if ( ! str_starts_with( $class, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class, strlen( self::PREFIX ) );
		$relative = str_replace( '\\', DIRECTORY_SEPARATOR, $relative );
		$file     = SHCD_TORNADO_DBM_DIR . 'src/' . $relative . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
