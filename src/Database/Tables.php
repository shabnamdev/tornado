<?php
/**
 * Internal table names.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Database;

final class Tables {
	public function __construct( private readonly \wpdb $wpdb ) {}

	private function name( string $suffix ): string {
		$wpdb = $this->wpdb;
		return $wpdb->prefix . SHCD_TORNADO_DBM_DB_PREFIX . $suffix;
	}

	public function jobs(): string {
		return $this->name( 'jobs' );
	}

	public function mappings(): string {
		return $this->name( 'mappings' );
	}

	public function discoveries(): string {
		return $this->name( 'discoveries' );
	}

	public function backups(): string {
		return $this->name( 'backups' );
	}

	public function logs(): string {
		return $this->name( 'logs' );
	}

	public function revision_archive(): string {
		return $this->name( 'revision_archive' );
	}
}
