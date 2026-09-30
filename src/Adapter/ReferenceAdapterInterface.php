<?php
/**
 * Extension contract for plugin-specific post references.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Adapter;

interface ReferenceAdapterInterface {
	public function id(): string;

	public function is_available(): bool;

	/**
	 * @return list<array{table:string,column:string,label:string}>
	 */
	public function scalar_references(): array;

	/**
	 * Tables that may be read or changed by structured adapter operations.
	 *
	 * @return list<string>
	 */
	public function involved_tables(): array;

	/**
	 * Reports path-aware structured references owned by the adapter.
	 *
	 * @return array{transformable_rows:int,unresolved:list<array<string,mixed>>}
	 */
	public function preview_structured( string $mapping_uuid ): array;

	/**
	 * Transforms structured references inside the active transaction.
	 *
	 * @return array<string,mixed>
	 */
	public function transform_structured( string $mapping_uuid, string $source, string $target ): array;

	/**
	 * @return array{supported:bool,blockers:list<string>,warnings:list<string>}
	 */
	public function preflight( string $mapping_uuid ): array;

	/**
	 * Repairs plugin-specific caches and derived data after a successful transaction.
	 *
	 * @return array<string, mixed>
	 */
	public function repair(): array;
}
