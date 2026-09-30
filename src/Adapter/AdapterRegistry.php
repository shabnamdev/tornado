<?php
/**
 * Runtime adapter registry.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Adapter;

use RuntimeException;

final class AdapterRegistry {
	/** @var array<string, ReferenceAdapterInterface> */
	private array $adapters = array();

	public function register( ReferenceAdapterInterface $adapter ): void {
		$id = sanitize_key( $adapter->id() );
		if ( '' === $id ) {
			throw new RuntimeException( 'Adapter ID cannot be empty.' );
		}
		$this->adapters[ $id ] = $adapter;
	}

	/**
	 * @return list<ReferenceAdapterInterface>
	 */
	public function available(): array {
		return array_values(
			array_filter(
				$this->adapters,
				static fn( ReferenceAdapterInterface $adapter ): bool => $adapter->is_available()
			)
		);
	}

	public function has( string $id ): bool {
		$id = sanitize_key( $id );
		return isset( $this->adapters[ $id ] ) && $this->adapters[ $id ]->is_available();
	}

	/**
	 * @return list<array{table:string,column:string,label:string,source:string}>
	 */
	public function scalar_references(): array {
		$references = array();
		foreach ( $this->available() as $adapter ) {
			$source = 'adapter:' . sanitize_key( $adapter->id() );
			foreach ( $adapter->scalar_references() as $reference ) {
				if ( ! is_array( $reference ) ) {
					continue;
				}
				$references[] = array(
					'table'  => (string) ( $reference['table'] ?? '' ),
					'column' => (string) ( $reference['column'] ?? '' ),
					'label'  => (string) ( $reference['label'] ?? $adapter->id() ),
					'source' => $source,
				);
			}
		}
		return $references;
	}

	/**
	 * Returns columns already owned by an Adapter, including conditional/polymorphic
	 * references. Discovery must not reinterpret these columns as unconditional
	 * scalar IDs.
	 *
	 * Adapters may expose an optional claimed_columns() method without extending the
	 * public interface, preserving compatibility with third-party adapters.
	 *
	 * @return list<array{table:string,column:string,adapter:string,mode:string}>
	 */
	public function claimed_columns(): array {
		$claims = array();
		foreach ( $this->available() as $adapter ) {
			$id = sanitize_key( $adapter->id() );
			foreach ( $adapter->scalar_references() as $reference ) {
				if ( ! is_array( $reference ) || empty( $reference['table'] ) || empty( $reference['column'] ) ) {
					continue;
				}
				$claims[] = array(
					'table'   => (string) $reference['table'],
					'column'  => (string) $reference['column'],
					'adapter' => $id,
					'mode'    => 'scalar',
				);
			}

			if ( ! method_exists( $adapter, 'claimed_columns' ) ) {
				continue;
			}
			try {
				$adapter_claims = call_user_func( array( $adapter, 'claimed_columns' ) );
			} catch ( \Throwable ) {
				continue;
			}
			foreach ( is_array( $adapter_claims ) ? $adapter_claims : array() as $claim ) {
				if ( ! is_array( $claim ) || empty( $claim['table'] ) || empty( $claim['column'] ) ) {
					continue;
				}
				$claims[] = array(
					'table'   => (string) $claim['table'],
					'column'  => (string) $claim['column'],
					'adapter' => $id,
					'mode'    => sanitize_key( (string) ( $claim['mode'] ?? 'conditional' ) ),
				);
			}
		}

		$unique = array();
		foreach ( $claims as $claim ) {
			$key = $claim['table'] . '.' . $claim['column'];
			$unique[ $key ] = $claim;
		}
		return array_values( $unique );
	}

	/** @return list<string> */
	public function involved_tables(): array {
		$tables = array();
		foreach ( $this->available() as $adapter ) {
			foreach ( $adapter->involved_tables() as $table ) {
				$table = (string) $table;
				if ( '' !== $table ) {
					$tables[] = $table;
				}
			}
		}
		return array_values( array_unique( $tables ) );
	}

	/**
	 * @return array{transformable_rows:int,unresolved:list<array<string,mixed>>,adapters:array<string,mixed>}
	 */
	public function preview_structured( string $mapping_uuid ): array {
		$rows       = 0;
		$unresolved = array();
		$details    = array();

		foreach ( $this->available() as $adapter ) {
			$id = sanitize_key( $adapter->id() );
			try {
				$preview = $adapter->preview_structured( $mapping_uuid );
				$adapter_rows = max( 0, (int) ( $preview['transformable_rows'] ?? 0 ) );
				$adapter_unresolved = array_values( array_filter( (array) ( $preview['unresolved'] ?? array() ), 'is_array' ) );
				$rows += $adapter_rows;
				foreach ( $adapter_unresolved as $item ) {
					$item['adapter'] = $id;
					$unresolved[] = $item;
				}
				$details[ $id ] = array( 'transformable_rows' => $adapter_rows, 'unresolved' => count( $adapter_unresolved ) );
			} catch ( \Throwable $throwable ) {
				$unresolved[] = array( 'adapter' => $id, 'reason' => sanitize_text_field( $throwable->getMessage() ) );
				$details[ $id ] = array( 'error' => sanitize_text_field( $throwable->getMessage() ) );
			}
		}

		return array(
			'transformable_rows' => $rows,
			'unresolved'         => $unresolved,
			'adapters'           => $details,
		);
	}

	/** @return array<string,array<string,mixed>> */
	public function transform_structured( string $mapping_uuid, string $source, string $target ): array {
		$result = array();
		foreach ( $this->available() as $adapter ) {
			$id = sanitize_key( $adapter->id() );
			$result[ $id ] = $adapter->transform_structured( $mapping_uuid, $source, $target );
		}
		return $result;
	}

	/**
	 * @return array{blockers:list<string>,warnings:list<string>,adapters:list<string>}
	 */
	public function preflight( string $mapping_uuid ): array {
		$blockers = array();
		$warnings = array();
		$adapters = array();

		foreach ( $this->available() as $adapter ) {
			$id         = sanitize_key( $adapter->id() );
			$adapters[] = $id;
			try {
				$result = $adapter->preflight( $mapping_uuid );
			} catch ( \Throwable $throwable ) {
				$blockers[] = sprintf( 'بررسی پیش از اجرای Adapter «%s» کامل نشد: %s', $id, $throwable->getMessage() );
				continue;
			}

			if ( empty( $result['supported'] ) ) {
				$blockers[] = sprintf( 'Adapter «%s» با وضعیت فعلی دیتابیس سازگار نیست.', $id );
			}
			foreach ( (array) ( $result['blockers'] ?? array() ) as $message ) {
				$blockers[] = sprintf( '[%s] %s', $id, sanitize_text_field( (string) $message ) );
			}
			foreach ( (array) ( $result['warnings'] ?? array() ) as $message ) {
				$warnings[] = sprintf( '[%s] %s', $id, sanitize_text_field( (string) $message ) );
			}
		}

		return array(
			'blockers' => array_values( array_unique( $blockers ) ),
			'warnings' => array_values( array_unique( $warnings ) ),
			'adapters' => array_values( array_unique( $adapters ) ),
		);
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public function repair(): array {
		$result = array();
		foreach ( $this->available() as $adapter ) {
			$id = sanitize_key( $adapter->id() );
			try {
				$result[ $id ] = array(
					'success' => true,
					'result'  => $adapter->repair(),
				);
			} catch ( \Throwable $throwable ) {
				$result[ $id ] = array(
					'success' => false,
					'error'   => sanitize_text_field( $throwable->getMessage() ),
				);
			}
		}
		return $result;
	}
}
