<?php
/**
 * Capability definitions.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Core;

final class Capabilities {
	public const VIEW_DASHBOARD      = 'shcd_tornado_dbm_view_dashboard';
	public const RUN_DISCOVERY       = 'shcd_tornado_dbm_run_discovery';
	public const RUN_CLEANUP         = 'shcd_tornado_dbm_run_cleanup';
	public const MANAGE_BACKUPS      = 'shcd_tornado_dbm_manage_backups';
	public const RESTORE_BACKUP      = 'shcd_tornado_dbm_restore_backup';
	public const EXECUTE_REINDEX     = 'shcd_tornado_dbm_execute_reindex';
	public const MANAGE_SETTINGS     = 'shcd_tornado_dbm_manage_settings';
	public const VIEW_SENSITIVE_LOGS = 'shcd_tornado_dbm_view_sensitive_logs';

	/**
	 * @return list<string>
	 */
	public static function all(): array {
		return array(
			self::VIEW_DASHBOARD,
			self::RUN_DISCOVERY,
			self::RUN_CLEANUP,
			self::MANAGE_BACKUPS,
			self::RESTORE_BACKUP,
			self::EXECUTE_REINDEX,
			self::MANAGE_SETTINGS,
			self::VIEW_SENSITIVE_LOGS,
		);
	}
}
