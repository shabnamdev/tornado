<?php
/**
 * Deactivation routine.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Core;

final class Deactivator {
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'shcd_tornado_dbm_scheduled_cleanup' );
		wp_clear_scheduled_hook( 'shcd_tornado_dbm_retention_cleanup' );
		wp_clear_scheduled_hook( 'shcd_tornado_dbm_enforce_revision_limit' );
		wp_clear_scheduled_hook( 'shcd_tornado_dbm_revision_archive_maintenance' );
		delete_transient( 'shcd_tornado_dbm_active_reindex_lock' );
	}
}
