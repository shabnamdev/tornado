<?php
/**
 * Reindex adapter for the independent revision archive.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Adapter;

use Shcd\TornadoDatabaseMaintenance\Core\RevisionArchiveService;
use Shcd\TornadoDatabaseMaintenance\Database\Tables;

final class RevisionArchiveAdapter implements ReferenceAdapterInterface {
	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly Tables $tables,
		private readonly RevisionArchiveService $archive
	) {}

	public function id(): string {
		return 'shcd_tornado_dbm_revision_archive';
	}

	public function is_available(): bool {
		$wpdb = $this->wpdb;
		$table = $this->tables->revision_archive();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	public function scalar_references(): array {
		return array(
			array( 'table' => $this->tables->revision_archive(), 'column' => 'post_id', 'label' => 'شناسه نوشته در آرشیو نسخه‌های Tornado' ),
			array( 'table' => $this->tables->revision_archive(), 'column' => 'parent_post_id', 'label' => 'شناسه نوشته والد در آرشیو نسخه‌های Tornado' ),
		);
	}

	public function involved_tables(): array {
		return array( $this->tables->revision_archive() );
	}

	public function preview_structured( string $mapping_uuid ): array {
		return array( 'transformable_rows' => 0, 'unresolved' => array() );
	}

	public function transform_structured( string $mapping_uuid, string $source, string $target ): array {
		return array( 'updated' => 0, 'note' => 'ساختار داخلی Snapshotها میان نسل‌های Reindex قابل بازیابی نیست و فقط ستون‌های شناسه جاری هماهنگ می‌شوند.' );
	}

	public function preflight( string $mapping_uuid ): array {
		return array(
			'supported' => true,
			'blockers'  => array(),
			'warnings'  => array( 'Snapshotهای نسل فعلی پس از Reindex برای بازیابی قفل می‌شوند تا شناسه‌های قدیمی دوباره وارد دیتابیس نشوند.' ),
		);
	}

	public function repair(): array {
		return array( 'current_generation' => $this->archive->current_generation_uuid(), 'cross_generation_restore' => false );
	}
}
