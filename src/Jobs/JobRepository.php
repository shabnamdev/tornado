<?php
/**
 * Persistent operation jobs.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Jobs;

use Shcd\TornadoDatabaseMaintenance\Database\Identifier;
use Shcd\TornadoDatabaseMaintenance\Database\Tables;
use RuntimeException;

final class JobRepository {
	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly Tables $tables
	) {}

	/**
	 * @param array<string, mixed> $payload Initial payload.
	 */
	public function create( string $type, array $payload = array() ): string {
		$wpdb = $this->wpdb;
		$uuid  = wp_generate_uuid4();
		$table = Identifier::quote( $this->tables->jobs() );
		$tornado_sql_table = Identifier::normalize( $table );
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO %i (uuid, type, status, phase, progress, payload, result, error_message, created_by, created_at, updated_at)
				 VALUES (%s, %s, 'running', 'created', 0, %s, NULL, NULL, %d, %s, %s)",
				$tornado_sql_table,
				$uuid,
				sanitize_key( $type ),
				wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				get_current_user_id(),
				$now,
				$now
			)
		);

		if ( false === $result ) {
			throw new RuntimeException( 'Unable to create operation job.' );
		}

		return $uuid;
	}

	/**
	 * @param array<string, mixed> $result Result payload.
	 */
	public function complete( string $uuid, array $result, string $phase = 'completed' ): void {
		$this->update( $uuid, 'completed', $phase, 100.0, $result, null );
	}

	/**
	 * @param array<string, mixed> $result Result payload.
	 */
	public function progress( string $uuid, string $phase, float $progress, array $result = array() ): void {
		$this->update( $uuid, 'running', $phase, max( 0.0, min( 100.0, $progress ) ), $result, null );
	}

	public function fail( string $uuid, string $message, string $phase = 'failed' ): void {
		$this->update( $uuid, 'failed', $phase, 0.0, array(), $message );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find( string $uuid ): ?array {
		$wpdb = $this->wpdb;
		$table = Identifier::quote( $this->tables->jobs() );
		$tornado_sql_table = Identifier::normalize( $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE uuid = %s LIMIT 1",
				$tornado_sql_table,
				$uuid
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		$row['payload'] = json_decode( (string) ( $row['payload'] ?? '{}' ), true ) ?: array();
		$row['result']  = json_decode( (string) ( $row['result'] ?? '{}' ), true ) ?: array();

		return $row;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function recent( int $limit = 20 ): array {
		$wpdb = $this->wpdb;
		$table = Identifier::quote( $this->tables->jobs() );
		$tornado_sql_table = Identifier::normalize( $table );
		$limit = max( 1, min( 100, $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT uuid, type, status, phase, progress, error_message, created_by, created_at, updated_at FROM %i ORDER BY id DESC LIMIT %d",
				$tornado_sql_table,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param array<string, mixed> $result Result payload.
	 */
	private function update(
		string $uuid,
		string $status,
		string $phase,
		float $progress,
		array $result,
		?string $error
	): void {
		$wpdb = $this->wpdb;
		$table = Identifier::quote( $this->tables->jobs() );
		$tornado_sql_table = Identifier::normalize( $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = %s, phase = %s, progress = %f, result = %s, error_message = %s, updated_at = %s WHERE uuid = %s",
				$tornado_sql_table,
				$status,
				$phase,
				$progress,
				wp_json_encode( $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				$error,
				current_time( 'mysql', true ),
				$uuid
			)
		);
	}
}
