<?php
/**
 * Local filesystem operations routed through WordPress' filesystem abstraction.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Core;

use RuntimeException;

final class Filesystem {
	private static ?\WP_Filesystem_Direct $instance = null;

	public static function direct(): \WP_Filesystem_Direct {
		if ( self::$instance instanceof \WP_Filesystem_Direct ) {
			return self::$instance;
		}

		if ( ! class_exists( '\WP_Filesystem_Direct' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		}

		self::$instance = new \WP_Filesystem_Direct( null );
		return self::$instance;
	}

	public static function put_contents( string $path, string $contents, int|false $mode = false ): void {
		if ( ! self::direct()->put_contents( $path, $contents, $mode ) ) {
			throw new RuntimeException( 'WordPress could not write the requested file.' );
		}
	}

	public static function chmod( string $path, int $mode ): bool {
		return self::direct()->chmod( $path, $mode );
	}

	public static function is_writable( string $path ): bool {
		return self::direct()->is_writable( $path );
	}

	public static function rmdir( string $path, bool $recursive = false ): bool {
		return self::direct()->rmdir( $path, $recursive );
	}
}
