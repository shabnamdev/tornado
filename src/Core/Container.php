<?php
/**
 * Lightweight dependency injection container.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Core;

use Closure;
use RuntimeException;

final class Container {
	/** @var array<string, Closure(self):mixed> */
	private array $factories = array();

	/** @var array<string, mixed> */
	private array $instances = array();

	/**
	 * @param Closure(self):mixed $factory Service factory.
	 */
	public function set( string $id, Closure $factory ): void {
		$this->factories[ $id ] = $factory;
	}

	public function instance( string $id, mixed $service ): void {
		$this->instances[ $id ] = $service;
	}

	public function get( string $id ): mixed {
		if ( array_key_exists( $id, $this->instances ) ) {
			return $this->instances[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal service identifier in an exception, not browser output.
			throw new RuntimeException( 'Service is not registered: ' . $id );
		}

		$this->instances[ $id ] = ( $this->factories[ $id ] )( $this );
		return $this->instances[ $id ];
	}
}
