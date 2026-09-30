<?php
/**
 * Database audit logger.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Logging;

use Shcd\TornadoDatabaseMaintenance\Database\Identifier;
use Shcd\TornadoDatabaseMaintenance\Database\Tables;

final class Logger {
	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly Tables $tables
	) {}

	/**
	 * @param array<string, mixed> $context Context values.
	 */
	public function log( string $level, string $message, array $context = array(), string $job_uuid = '' ): void {
		$wpdb = $this->wpdb;
		$allowed_level = in_array( $level, array( 'debug', 'info', 'warning', 'error', 'critical' ), true ) ? $level : 'info';
		$context       = $this->redact( $context );
		$table         = Identifier::quote( $this->tables->logs() );
		$tornado_sql_table = Identifier::normalize( $table );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO %i (job_uuid, level, message, context_json, created_at) VALUES (%s, %s, %s, %s, %s)",
				$tornado_sql_table,
				$job_uuid,
				$allowed_level,
				$message,
				wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				current_time( 'mysql', true )
			)
		);
	}


	/**
	 * @param array<string, mixed> $context Context values.
	 * @return array<string, mixed>
	 */
	private function redact( array $context ): array {
		$redacted = array();

		foreach ( $context as $key => $value ) {
			$key_string = (string) $key;
			if ( preg_match( '/password|passwd|token|secret|authorization|cookie|nonce|salt|api[_-]?key/i', $key_string ) === 1 ) {
				$redacted[ $key_string ] = '[REDACTED]';
				continue;
			}

			$redacted[ $key_string ] = is_array( $value ) ? $this->redact( $value ) : $value;
		}

		return $redacted;
	}
}
