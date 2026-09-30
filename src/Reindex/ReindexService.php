<?php
/**
 * Guarded transactional core reindex engine.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Reindex;

use Shcd\TornadoDatabaseMaintenance\Adapter\AdapterRegistry;
use Shcd\TornadoDatabaseMaintenance\Backup\SqlBackupService;
use Shcd\TornadoDatabaseMaintenance\Cache\CachePurger;
use Shcd\TornadoDatabaseMaintenance\Core\Settings;
use Shcd\TornadoDatabaseMaintenance\Core\Filesystem;
use Shcd\TornadoDatabaseMaintenance\Database\AutoIncrementService;
use Shcd\TornadoDatabaseMaintenance\Database\FingerprintService;
use Shcd\TornadoDatabaseMaintenance\Database\Identifier;
use Shcd\TornadoDatabaseMaintenance\Database\Tables;
use Shcd\TornadoDatabaseMaintenance\Integrity\IntegrityChecker;
use Shcd\TornadoDatabaseMaintenance\Jobs\JobRepository;
use Shcd\TornadoDatabaseMaintenance\Logging\Logger;
use Shcd\TornadoDatabaseMaintenance\Mapping\MappingBuilder;
use Shcd\TornadoDatabaseMaintenance\Security\DestructiveActionGuard;
use RuntimeException;

final class ReindexService {
	/** @var array<string,array{auto_registered:list<array<string,mixed>>,managed:list<array<string,mixed>>,unresolved:list<array<string,mixed>>,ignored:list<array<string,mixed>>}> */
	private array $reference_evidence_cache = array();
	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly Tables $tables,
		private readonly FingerprintService $fingerprints,
		private readonly MappingBuilder $mappings,
		private readonly SqlBackupService $backups,
		private readonly AdapterRegistry $adapters,
		private readonly ReferenceRegistry $references,
		private readonly StructuredDataScanner $structured_data,
		private readonly StructuredReferenceTransformer $structured_transformer,
		private readonly IntegrityChecker $integrity,
		private readonly CachePurger $cache,
		private readonly AutoIncrementService $auto_increment,
		private readonly JobRepository $jobs,
		private readonly Logger $logger,
		private readonly GenerationRepository $generations,
		private readonly DestructiveActionGuard $guard
	) {}

	/**
	 * Repairs leftovers from the previous generation before a fresh Discovery and
	 * Mapping are created. Any Attachment row recovered here becomes part of the
	 * next Mapping, so post-commit repair never needs to create new wp_posts rows.
	 *
	 * @return array<string,mixed>
	 */
	public function prepare_repeatable_reindex(): array {
		if ( $this->generations->recovery_required() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
			throw new RuntimeException( 'نسل قبلی پس از COMMIT ناموفق ثبت شده است. پیش از هر Reindex جدید، Backup همان نسل را بازیابی کنید.' );
		}

		$job_uuid = $this->jobs->create( 'reindex_prepare', array( 'repeatable' => true ) );
		$before   = $this->current_posts_state();
		$last_mapping = (string) get_option( 'shcd_tornado_dbm_last_executed_mapping_uuid', '' );
		$last_mapping = wp_is_uuid( $last_mapping ) && $this->mappings->exists( $last_mapping ) ? $last_mapping : '';

		try {
			$this->jobs->progress( $job_uuid, 'creating_preparation_backup', 5.0 );
			$safety_backup = $this->backups->create();
			if ( empty( $safety_backup['consistent_snapshot'] ) || empty( $safety_backup['private_location'] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
				throw new RuntimeException( 'Backup ایمنی پیش از آماده‌سازی، شرایط لازم برای بازیابی مطمئن را ندارد؛ هیچ تعمیر پیش از Reindex اجرا نشد.' );
			}
			update_option( 'shcd_tornado_dbm_last_prepare_backup_uuid', (string) $safety_backup['uuid'], false );

			$this->jobs->progress( $job_uuid, 'repairing_previous_generation', 20.0 );
			$elementor = $this->cache->repair_elementor(
				'' !== $last_mapping ? $last_mapping : null,
				(bool) Settings::get( 'restore_missing_attachments_from_uploads', false ),
				'historical',
				(bool) Settings::get( 'purge_missing_attachment_rows_before_reindex', true )
			);
			$this->jobs->progress( $job_uuid, 'repairing_adapters', 65.0 );
			$adapter_repairs = $this->adapters->repair();
			$adapter_errors = array_filter( $adapter_repairs, static fn( mixed $row ): bool => is_array( $row ) && empty( $row['success'] ) );
			$after = $this->current_posts_state();
			$integrity = $this->integrity->check( null );
			$media_verification = is_array( $elementor['media_verification'] ?? null ) ? $elementor['media_verification'] : array();
			$unresolved_media = (int) ( $media_verification['unresolved_count'] ?? 0 );
			$blocking_media   = (int) ( $media_verification['blocking_unresolved_count'] ?? $unresolved_media );
			$advisory_media   = (int) ( $media_verification['advisory_unresolved_count'] ?? 0 );
			$advisory_signatures = array_values(
				array_filter(
					array_map( 'strval', is_array( $media_verification['advisory_signatures'] ?? null ) ? $media_verification['advisory_signatures'] : array() ),
					static fn( string $value ): bool => 64 === strlen( $value )
				)
			);
			$elementor_errors = is_array( $elementor['errors'] ?? null ) ? array_values( array_filter( array_map( 'strval', $elementor['errors'] ) ) ) : array();
			$elementor_critical_errors = is_array( $elementor['critical_errors'] ?? null )
				? array_values( array_filter( array_map( 'strval', $elementor['critical_errors'] ) ) )
				: $elementor_errors;
			$elementor_advisory_errors = is_array( $elementor['advisory_errors'] ?? null )
				? array_values( array_filter( array_map( 'strval', $elementor['advisory_errors'] ) ) )
				: array();
			$allowed = empty( $elementor_critical_errors ) && 0 === $blocking_media && empty( $adapter_errors );

			$stamp = array(
				'prepared_at'                 => current_time( 'mysql', true ),
				'post_id_fingerprint'         => (string) $after['post_id_fingerprint'],
				'schema_fingerprint'          => (string) $after['schema_fingerprint'],
				'previous_mapping_uuid'       => $last_mapping,
				'safety_backup_uuid'          => (string) $safety_backup['uuid'],
				'media_unresolved'            => $unresolved_media,
				'media_blocking_unresolved'   => $blocking_media,
				'media_advisory_unresolved'   => $advisory_media,
				'media_advisory_fingerprint'  => (string) ( $media_verification['advisory_fingerprint'] ?? '' ),
				'media_advisory_signatures'   => array_slice( $advisory_signatures, 0, 2000 ),
				'attachments_registered'      => (int) ( $elementor['media_attachments_registered'] ?? 0 ),
				'attachments_removed'         => (int) ( $elementor['stale_attachment_cleanup']['deleted_attachments'] ?? 0 ),
				'media_cleaner_trash_removed'  => (int) ( $elementor['stale_attachment_cleanup']['deleted_media_cleaner_trash'] ?? 0 ),
				'url_only_media_references'   => (int) ( $media_verification['url_only_detached_count'] ?? 0 ),
				'allowed'                     => $allowed,
			);
			update_option( 'shcd_tornado_dbm_last_reindex_preparation', $stamp, false );

			$blockers = array();
			$warnings = array();
			if ( $blocking_media > 0 ) {
				$blockers[] = sprintf(
					'%d Reference رسانه‌ای فاقد URL یا مسیر قابل اثبات است. جزئیات جدول، meta_id و مسیر JSON در بخش «Referenceهای رسانه» نمایش داده می‌شود.',
					$blocking_media
				);
			}
			if ( ! empty( $elementor_critical_errors ) ) {
				$blockers[] = sprintf( '%d خطای ساختاری هنگام بازنویسی Referenceهای Elementor یا Builderها رخ داده است. متن دقیق خطا در بخش «خطاهای ساختاری Elementor و Builderها» نمایش داده می‌شود.', count( $elementor_critical_errors ) );
			}
			if ( ! empty( $elementor_advisory_errors ) ) {
				$warnings[] = sprintf( '%d خطای غیرساختاری در بازسازی CSS، Cache یا فعال‌سازی Editor ثبت شد. این موارد مانع Reindex نیستند و جزئیاتشان در گزارش نمایش داده می‌شود.', count( $elementor_advisory_errors ) );
			}
			if ( ! empty( $adapter_errors ) ) {
				$blockers[] = sprintf( '%d Adapter اختصاصی نتوانست تعمیر خود را کامل کند.', count( $adapter_errors ) );
			}
			if ( $advisory_media > 0 ) {
				$warnings[] = sprintf(
					'%d Reference رسانه‌ای فقط به URL متکی است و Attachment معتبری ندارد. این موارد وارد Mapping شناسه‌ها نمی‌شوند و مانع Reindex نیستند.',
					$advisory_media
				);
			}

			$result = array(
				'uuid'                    => $job_uuid,
				'allowed'                 => $allowed,
				'repeatable_reindex'      => true,
				'previous_mapping_uuid'   => $last_mapping,
				'safety_backup'           => $safety_backup,
				'before'                  => $before,
				'after'                   => $after,
				'elementor'               => $elementor,
				'elementor_critical_errors' => $elementor_critical_errors,
				'elementor_advisory_errors' => $elementor_advisory_errors,
				'media_baseline'          => array(
					'unresolved'            => $unresolved_media,
					'blocking'              => $blocking_media,
					'advisory'              => $advisory_media,
					'advisory_fingerprint'  => (string) ( $media_verification['advisory_fingerprint'] ?? '' ),
				),
				'adapter_repairs'         => $adapter_repairs,
				'integrity'               => $integrity,
				'blockers'                => $blockers,
				'warnings'                => $warnings,
				'next_step'               => $allowed ? 'Discovery و Mapping تازه را اجرا کنید.' : 'فقط Referenceهای واقعاً مسدودکننده یا خطاهای تعمیر را برطرف کنید.',
			);
			$this->jobs->complete( $job_uuid, $result );
			$this->logger->log( $allowed ? 'info' : 'warning', 'آماده‌سازی برای Reindex دوباره پایان یافت.', $result, $job_uuid );
			return $result;
		} catch ( \Throwable $throwable ) {
			$this->jobs->fail( $job_uuid, $throwable->getMessage(), 'repeatable_prepare_failed' );
			$this->logger->log( 'error', 'آماده‌سازی برای Reindex دوباره کامل نشد.', array( 'error' => $throwable->getMessage() ), $job_uuid );
			throw $throwable;
		}
	}

	/** @return list<array<string,mixed>> */
	public function generation_history(): array {
		return $this->generations->history();
	}

	public function confirmation_phrase( string $mapping_uuid, string $backup_uuid ): string {
		return 'REINDEX-' . strtoupper( substr( str_replace( '-', '', $mapping_uuid ), 0, 8 ) ) . '-' . strtoupper( substr( str_replace( '-', '', $backup_uuid ), 0, 8 ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function preflight( string $mapping_uuid, string $backup_uuid ): array {
		$wpdb = $this->wpdb;
		$blockers = array();
		$warnings = array();

		if ( $this->generations->recovery_required() ) {
			$blockers[] = 'پردازش‌های پس از COMMIT در نسل قبلی کامل نشده‌اند. پیش از Reindex جدید، Backup همان نسل را بازیابی کنید.';
		}

		if ( is_multisite() ) {
			$blockers[] = 'در حالت Multisite، هر سایت باید Mapping مستقل داشته باشد؛ اجرای سراسری Reindex در این نسخه غیرفعال است.';
		}

		if ( defined( 'SHCD_TORNADO_DBM_DISABLE_DESTRUCTIVE_OPERATIONS' ) && true === SHCD_TORNADO_DBM_DISABLE_DESTRUCTIVE_OPERATIONS ) {
			$blockers[] = 'عملیات مخرب به‌وسیله کلید توقف اضطراری در wp-config.php غیرفعال شده است.';
		}

		if ( ! (bool) Settings::get( 'allow_destructive_reindex', false ) ) {
			$blockers[] = 'عملیات مخرب Reindex آماده اجرا است نشده است. در بخش «شناسه‌ها و AUTO_INCREMENT» سوییچ مربوط را روشن کنید و تنظیمات را ذخیره نمایید.';
		}

		$mapping_job = null;
		$gapless_mapping = array( 'valid' => false );
		$current_post_fingerprint = '';
		$current_schema_fingerprint = '';
		if ( ! wp_is_uuid( $mapping_uuid ) || ! $this->mappings->exists( $mapping_uuid ) ) {
			$blockers[] = 'Mapping انتخاب‌شده وجود ندارد یا معتبر نیست.';
		} else {
			$mapping_job = $this->jobs->find( $mapping_uuid );
			$current_post_fingerprint = $this->fingerprints->post_ids();
			$current_schema_fingerprint = $this->fingerprints->schema();
			$expected_post = (string) ( $mapping_job['result']['source_post_id_fingerprint'] ?? $mapping_job['payload']['source_post_id_fingerprint'] ?? '' );
			$expected_schema = (string) ( $mapping_job['result']['source_schema_fingerprint'] ?? $mapping_job['payload']['source_schema_fingerprint'] ?? '' );
			if ( '' === $expected_post || ! hash_equals( $expected_post, $current_post_fingerprint ) ) {
				$blockers[] = sprintf( 'پس از ساخت Mapping، اطلاعات جدول %s تغییر کرده است. ابتدا Discovery و سپس Mapping را دوباره اجرا کنید.', $wpdb->posts );
			}
			if ( '' === $expected_schema || ! hash_equals( $expected_schema, $current_schema_fingerprint ) ) {
				$blockers[] = 'ساختار دیتابیس پس از ساخت Mapping تغییر کرده است. Discovery و Mapping را دوباره اجرا کنید.';
			}
			$gapless_mapping = $this->mappings->gapless_status( $mapping_uuid );
			if ( empty( $gapless_mapping['valid'] ) ) {
				$blockers[] = sprintf( 'Mapping انتخاب‌شده تمام رکوردهای جدول %s را به توالی پیوسته ۱ تا N تبدیل نمی‌کند. Mapping جدید بسازید.', $wpdb->posts );
			}
		}

		$preparation = get_option( 'shcd_tornado_dbm_last_reindex_preparation', array() );
		$preparation = is_array( $preparation ) ? $preparation : array();
		if ( empty( $preparation['allowed'] ) ) {
			$blockers[] = 'آماده‌سازی نسل قبلی انجام نشده است. Wizard خودکار و مرحله‌ای را از مرحله «آماده‌سازی» اجرا کنید.';
		} elseif ( '' !== $current_post_fingerprint && ! hash_equals( $current_post_fingerprint, (string) ( $preparation['post_id_fingerprint'] ?? '' ) ) ) {
			$blockers[] = sprintf( 'پس از آماده‌سازی، رکوردهای جدول %s تغییر کرده‌اند. Wizard را دوباره از مرحله آماده‌سازی اجرا کنید.', $wpdb->posts );
		} elseif ( '' !== $current_schema_fingerprint && ! hash_equals( $current_schema_fingerprint, (string) ( $preparation['schema_fingerprint'] ?? '' ) ) ) {
			$blockers[] = 'ساختار دیتابیس پس از آماده‌سازی تغییر کرده است. Wizard را دوباره اجرا کنید.';
		}

		$discovery_uuid = (string) get_option( 'shcd_tornado_dbm_last_discovery_uuid', '' );
		$discovery_job = wp_is_uuid( $discovery_uuid ) ? $this->jobs->find( $discovery_uuid ) : null;
		if ( null === $discovery_job || 'completed' !== (string) ( $discovery_job['status'] ?? '' ) ) {
			$blockers[] = 'برای ادامه، یک Snapshot کامل و موفق از Discovery لازم است.';
		} elseif ( '' !== $current_schema_fingerprint && ! hash_equals( (string) ( $discovery_job['result']['schema_fingerprint'] ?? '' ), $current_schema_fingerprint ) ) {
			$blockers[] = 'Snapshot آخر Discovery دیگر با ساختار فعلی دیتابیس هماهنگ نیست؛ Discovery را دوباره اجرا کنید.';
		}

		$backup = $this->backups->find_verified( $backup_uuid );
		if ( null === $backup ) {
			$blockers[] = 'برای Reindex، یک Backup تأییدشده با Checksum معتبر لازم است.';
		} else {
			if ( empty( $backup['is_consistent'] ) ) {
				$blockers[] = 'Backup انتخاب‌شده Transaction-Consistent نیست و برای Reindex ایمن محسوب نمی‌شود.';
			}
			if ( empty( $backup['is_private_location'] ) ) {
				$blockers[] = 'Backup در مسیر قابل دسترس از وب ذخیره شده است و نمی‌تواند مجوز اجرای Reindex را صادر کند.';
			}
			if ( '' !== $current_post_fingerprint && ! hash_equals( $current_post_fingerprint, (string) ( $backup['post_id_fingerprint'] ?? '' ) ) ) {
				$blockers[] = sprintf( 'Snapshot شناسه‌های جدول %s در Backup با Mapping انتخاب‌شده یکسان نیست.', $wpdb->posts );
			}
			if ( '' !== $current_schema_fingerprint && ! hash_equals( $current_schema_fingerprint, (string) ( $backup['schema_fingerprint'] ?? '' ) ) ) {
				$blockers[] = 'اثر انگشت ساختار Backup با دیتابیس فعلی مطابقت ندارد.';
			}
			if ( isset( $backup['verified_at'] ) && strtotime( (string) $backup['verified_at'] . ' UTC' ) < time() - DAY_IN_SECONDS ) {
				$warnings[] = 'از زمان تأیید Backup بیش از ۲۴ ساعت گذشته است.';
			}
			if ( is_array( $mapping_job ) && strtotime( (string) ( $backup['created_at'] ?? '' ) . ' UTC' ) < strtotime( (string) ( $mapping_job['created_at'] ?? '' ) . ' UTC' ) ) {
				$blockers[] = 'Backup تأییدشده پیش از Mapping با موفقیت ساخته شده است؛ پس از ساخت Mapping یک Backup تازه تهیه کنید.';
			}
		}

		$posts_identifier = Identifier::normalize( $wpdb->posts );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$order_count      = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE post_type IN ('shop_order','shop_order_refund')",
				$posts_identifier
			)
		);
		if ( $order_count > 0 && ! (bool) Settings::get( 'allow_order_reindex', false ) ) {
			$blockers[] = 'سفارش‌های Legacy ووکامرس در دیتابیس وجود دارند و تغییر شناسه سفارش‌ها به‌صورت پیش‌فرض محافظت شده است.';
		}

		if ( $this->is_hpos_enabled() ) {
			$blockers[] = 'قابلیت WooCommerce HPOS فعال است. شناسه سفارش‌ها باید فقط از مسیر WooCommerce CRUD و کنترل‌های اختصاصی HPOS مدیریت شوند.';
		}

		$adapter_preflight = $this->adapters->preflight( $mapping_uuid );
		$blockers = array_merge( $blockers, $adapter_preflight['blockers'] );
		$warnings = array_merge( $warnings, $adapter_preflight['warnings'] );

		$unsupported_plugins = $this->active_unsupported_plugins();
		if ( ! empty( $unsupported_plugins ) ) {
			$warnings[] = 'افزونه‌های زیر Adapter اختصاصی نام‌دار ندارند و پوشش آن‌ها براساس ساختار واقعی جدول، ستون Type، Post Type و شواهد Mapping ارزیابی می‌شود: ' . implode( '، ', $unsupported_plugins ) . '. فقط Referenceهای حل‌نشده مانع اجرا خواهند شد.';
		}

		$reference_evidence = $this->discovery_reference_evidence( $mapping_uuid );
		$unknowns = $reference_evidence['unresolved'];
		if ( ! empty( $unknowns ) ) {
			$unknown_names = array_map(
				static fn( array $candidate ): string => (string) ( $candidate['table'] ?? $candidate['table_name'] ?? '?' ) . '.' . (string) ( $candidate['column'] ?? $candidate['column_name'] ?? '?' ),
				array_slice( $unknowns, 0, 8 )
			);
			$blockers[] = sprintf(
				'%1$d ستون هنوز رابطه قطعی با %3$s.ID ندارند: %2$s. جزئیات هر ستون در بخش «Referenceهایی که نیاز به بررسی دارند» نمایش داده می‌شود.',
				count( $unknowns ),
				implode( '، ', $unknown_names ),
				$wpdb->posts
			);
		}
		if ( ! empty( $reference_evidence['auto_registered'] ) ) {
			$warnings[] = sprintf( '%d Reference دیتابیس با شواهد کافی تأیید شد و برای این Mapping به‌صورت خودکار ثبت می‌شود.', count( $reference_evidence['auto_registered'] ) );
		}
		if ( ! empty( $reference_evidence['managed'] ) ) {
			$warnings[] = sprintf( '%d Reference اختصاصی مطابق سیاست مدیر، پیش از جابه‌جایی شناسه‌ها پاک‌سازی می‌شود.', count( $reference_evidence['managed'] ) );
		}

		$structured = array( 'complete' => false, 'scanned_rows' => 0, 'candidate_rows' => 0, 'hit_count' => 0, 'hits' => array() );
		if ( wp_is_uuid( $mapping_uuid ) && $this->mappings->exists( $mapping_uuid ) ) {
			$structured = $this->structured_data->scan( $mapping_uuid );
			if ( ! $structured['complete'] ) {
				$blockers[] = 'اسکن داده‌های Embedded کامل نشده است. Adapter مناسب را ثبت کنید یا پس از کاهش حجم داده، Discovery را دوباره اجرا نمایید.';
			}
			if ( ! empty( $structured['hits'] ) ) {
				$warnings[] = sprintf( '%d Post Reference در داده‌های Embedded از طریق مسیر معنایی تأیید شد و توسط موتور Structured Data اصلاح می‌شود.', count( $structured['hits'] ) );
			}
		}

		$structured_registered = array( 'transformable_rows' => 0, 'unresolved' => array() );
		if ( wp_is_uuid( $mapping_uuid ) && $this->mappings->exists( $mapping_uuid ) ) {
			$structured_registered = $this->structured_transformer->preview( $mapping_uuid );
			if ( ! empty( $structured_registered['unresolved'] ) ) {
				$first_unresolved = is_array( $structured_registered['unresolved'][0] ?? null ) ? $structured_registered['unresolved'][0] : array();
				$first_location   = trim(
					(string) ( $first_unresolved['table'] ?? '' )
					. ( isset( $first_unresolved['row_id'] ) ? '، ردیف ' . (string) $first_unresolved['row_id'] : '' )
					. ( ! empty( $first_unresolved['path'] ) ? '، مسیر ' . (string) $first_unresolved['path'] : '' )
				);
				$blockers[] = sprintf(
					'%1$d مسیر حل‌نشده در Structured Data پیدا شد.%2$s',
					count( $structured_registered['unresolved'] ),
					'' !== $first_location ? ' نخستین مورد: ' . $first_location . '.' : ''
				);
			}
		}

		$transaction_reference_baseline = wp_is_uuid( $mapping_uuid ) && $this->mappings->exists( $mapping_uuid )
			? $this->reference_integrity_snapshot( $mapping_uuid )
			: array();
		$gap_collision_ledger = wp_is_uuid( $mapping_uuid ) && $this->mappings->exists( $mapping_uuid )
			? $this->gap_target_collision_snapshot( $mapping_uuid )
			: array();
		$gap_collision_count = $this->reference_distribution_total( $gap_collision_ledger );
		if ( $gap_collision_count > 0 ) {
			$blockers[] = sprintf(
				'%d Reference یتیم از قبل، مقداری برابر یکی از شناسه‌های شکاف هدف دارد. ادامه Reindex می‌تواند آن‌ها را به Post اشتباه متصل کند؛ ابتدا این موارد را از بخش Integrity بررسی و تعمیر کنید.',
				$gap_collision_count
			);
		}
		$baseline_integrity = $this->integrity->check( wp_is_uuid( $mapping_uuid ) ? $mapping_uuid : null );
		$blocking_integrity = (int) ( $baseline_integrity['reindex_blocking_issues'] ?? $baseline_integrity['total_issues'] ?? 0 );
		$advisory_integrity = max( 0, (int) ( $baseline_integrity['total_issues'] ?? 0 ) - $blocking_integrity );
		if ( $blocking_integrity > 0 ) {
			$warnings[] = sprintf( '%d رابطه شکسته از قبل در دیتابیس وجود دارد. Reindex فقط زمانی مجاز است که تعداد این موارد داخل Transaction افزایش پیدا نکند.', $blocking_integrity );
		}
		if ( $advisory_integrity > 0 ) {
			$warnings[] = sprintf( '%d رکورد یتیم نامرتبط شناسایی شد؛ این موارد مانع Reindex شناسه‌های پست نیستند.', $advisory_integrity );
		}

		$elementor_repair = $this->cache->elementor_preflight();
		if ( ! empty( $elementor_repair['active'] ) ) {
			if ( ! (bool) Settings::get( 'elementor_repair_after_reindex', true ) ) {
				$blockers[] = 'Elementor فعال است؛ گزینه ترمیم خودکار تصاویر، لوگو، CSS و Cacheهای Elementor پس از Reindex باید فعال باشد.';
			}
			if ( empty( $elementor_repair['supported'] ) ) {
				$blockers[] = 'Elementor فعال است، اما API لازم برای پاک‌سازی و بازسازی CSS در دسترس نیست؛ ادامه Reindex می‌تواند ظاهر سایت را به‌هم بریزد.';
			}
			if ( empty( $elementor_repair['writable'] ) ) {
				$blockers[] = 'پوشه CSS مربوط به Elementor قابل نوشتن نیست. پیش از Reindex، سطح دسترسی wp-content/uploads/elementor/css را اصلاح کنید.';
			}
			if ( empty( $elementor_repair['active_kit_id'] ) ) {
				$warnings[] = 'Elementor فعال است، اما Active Kit معتبری پیدا نشد؛ Global Colors، Fonts و Theme Style را بررسی کنید.';
			}
		}

		$engines = $this->involved_table_engines( $mapping_uuid );
		foreach ( $engines as $table => $engine ) {
			if ( 'INNODB' !== strtoupper( $engine ) ) {
				$blockers[] = sprintf( 'جدول %s از Engine غیرتراکنشی %s استفاده می‌کند و امکان Rollback مطمئن ندارد.', $table, $engine ?: 'unknown' );
			}
		}

		$confirmation = $this->confirmation_phrase( $mapping_uuid, $backup_uuid );
		$current_state = $this->current_posts_state();
		return array(
			'posts_table' => $wpdb->posts,
			'allowed'             => empty( $blockers ),
			'destructive_reindex_enabled' => (bool) Settings::get( 'allow_destructive_reindex', false ),
			'auto_register_discovered_references' => (bool) Settings::get( 'auto_register_discovered_references', true ),
			'auto_resolve_ambiguous_references' => (bool) Settings::get( 'auto_resolve_ambiguous_references', true ),
			'blockers'            => $blockers,
			'warnings'            => $warnings,
			'confirmation_phrase' => $confirmation,
			'engines'             => $engines,
			'unknown_candidates'  => array_slice( $unknowns, 0, 50 ),
			'auto_registered_references' => array_slice( $reference_evidence['auto_registered'], 0, 100 ),
			'managed_reference_candidates' => array_slice( $reference_evidence['managed'], 0, 100 ),
			'ignored_reference_candidates' => array_slice( $reference_evidence['ignored'], 0, 100 ),
			'reference_policies'            => (array) Settings::get( 'reference_policies', array() ),
			'structured_data'              => $structured,
			'registered_structured_data'   => $structured_registered,
			'baseline_integrity'            => $baseline_integrity,
			'transaction_reference_baseline'  => $transaction_reference_baseline,
			'gap_target_reference_collisions' => array(
				'count'   => $gap_collision_count,
				'entries' => array_filter( $gap_collision_ledger ),
			),
			'adapters'                      => $adapter_preflight,
			'reference_coverage'            => $this->reference_coverage( $mapping_uuid ),
			'gapless_mapping'              => $gapless_mapping,
			'gapless_guarantee'            => array(
				'posts_and_attachments' => true,
				'final_sequence'        => '1..N',
				'final_gap_count'       => 0,
				'next_auto_increment'   => (int) ( $gapless_mapping['expected_next_id'] ?? 1 ),
			),
			'elementor_repair'              => $elementor_repair,
			'requires_reauthentication'    => false,
			'requires_one_time_token'     => true,
			'preparation'                  => $preparation,
			'generation_preview'             => $this->generations->preview( $current_post_fingerprint, (int) ( $current_state['row_count'] ?? 0 ), (int) ( $current_state['gap_count'] ?? 0 ) ),
			'post_id_fingerprint'          => $current_post_fingerprint,
			'schema_fingerprint'           => $current_schema_fingerprint,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function execute( string $mapping_uuid, string $backup_uuid, string $confirmation, string $authorization_token ): array {
		$wpdb = $this->wpdb;
		$preflight = $this->preflight( $mapping_uuid, $backup_uuid );
		if ( ! $preflight['allowed'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is not HTML output.
			throw new RuntimeException( 'بررسی پیش از اجرای Reindex متوقف شد: ' . implode( ' ', $preflight['blockers'] ) );
		}

		if ( ! hash_equals( $preflight['confirmation_phrase'], trim( $confirmation ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
			throw new RuntimeException( 'عبارت تأیید Reindex صحیح نیست.' );
		}
		$this->guard->consume( 'reindex', $authorization_token );

		$job_uuid      = $this->jobs->create( 'reindex', array( 'mapping_uuid' => $mapping_uuid, 'backup_uuid' => $backup_uuid ) );
		$generation    = $this->generations->begin( $job_uuid, $mapping_uuid, $backup_uuid, $this->current_posts_state() );
		$mapping_table = Identifier::quote( $this->tables->mappings() );
		$tornado_sql_mapping_table = Identifier::normalize( $mapping_table );
		$posts_table   = Identifier::quote( $wpdb->posts );
		$tornado_sql_posts_table = Identifier::normalize( $posts_table );
		$lock_name     = 'shcd_tornado_dbm_reindex_' . md5( (string) DB_NAME );
		$maintenance   = wp_normalize_path( trailingslashit( ABSPATH ) . '.maintenance' );
		$created_maintenance = false;
		$foreign_keys_disabled = false;
		$committed = false;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$lock = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );
		if ( 1 !== $lock ) {
			$this->generations->fail( $job_uuid, 'قفل انحصاری دیتابیس دریافت نشد.', false );
			$this->jobs->fail( $job_uuid, 'قفل انحصاری دیتابیس برای Reindex دریافت نشد؛ احتمالاً عملیات دیگری هم‌زمان در حال اجرا است.', 'lock_failed' );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
			throw new RuntimeException( 'قفل انحصاری دیتابیس برای Reindex دریافت نشد؛ احتمالاً عملیات دیگری هم‌زمان در حال اجرا است.' );
		}

		try {
			if ( ! file_exists( $maintenance ) ) {
				$maintenance_code = '<?php $upgrading = ' . time() . ';';
				Filesystem::put_contents( $maintenance, $maintenance_code, 0600 );
				$created_maintenance = true;
			}

			set_transient( 'shcd_tornado_dbm_active_reindex_lock', $job_uuid, HOUR_IN_SECONDS );
			$this->jobs->progress( $job_uuid, 'transaction_start', 5.0 );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			if ( false === $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE' ) ) { 
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
				throw new RuntimeException( 'سطح ایزولیشن SERIALIZABLE برای Transaction تنظیم نشد.' );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) { 
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
				throw new RuntimeException( 'Transaction دیتابیس آغاز نشد.' );
			}

			$this->assert_snapshot_unchanged(
				(string) $preflight['post_id_fingerprint'],
				(string) $preflight['schema_fingerprint']
			);
			$locked_mapping_status = $this->mappings->gapless_status( $mapping_uuid );
			if ( empty( $locked_mapping_status['valid'] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
				throw new RuntimeException( sprintf( 'Mapping داخل Transaction قفل‌شده دیگر پیوسته نیست یا تمام ردیف‌های جدول %s را پوشش نمی‌دهد؛ عملیات Rollback شد.', $wpdb->posts ) );
			}

			$reference_policy_actions = $this->apply_managed_reference_policies( $mapping_uuid );
			$this->jobs->progress( $job_uuid, 'custom_reference_policies_applied', 12.0, $reference_policy_actions );
			$transaction_reference_baseline = $this->reference_integrity_snapshot( $mapping_uuid );
			$reference_distribution_before = $this->reference_distribution_snapshot( $mapping_uuid, 'old_id' );
			$gap_target_collisions_locked = $this->gap_target_collision_snapshot( $mapping_uuid );
			if ( $this->reference_distribution_total( $gap_target_collisions_locked ) > 0 ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
				throw new RuntimeException( 'در Snapshot قفل‌شده، Reference یتیمی با یکی از شناسه‌های شکاف هدف برخورد دارد؛ عملیات پیش از هر تغییر Rollback شد.' );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$wpdb->query( 'SET SESSION FOREIGN_KEY_CHECKS = 0' ); 
			$foreign_keys_disabled = true;

			$this->update_scalar_references( $mapping_uuid, 'old_id', 'temp_id' );
			$this->update_scalar_meta( $mapping_uuid, 'old_id', 'temp_id' );
			$this->update_conditional_meta( $mapping_uuid, 'old_id', 'temp_id' );
			$structured_registered_to_temp = $this->structured_transformer->transform( $mapping_uuid, 'old_id', 'temp_id' );
			$structured_embedded_to_temp   = $this->structured_data->transform( $mapping_uuid, 'old_id', 'temp_id' );
			$this->update_scalar_options( $mapping_uuid, 'old_id', 'temp_id' );
			$this->jobs->progress( $job_uuid, 'references_to_temporary', 30.0 );

			$this->assert_query_result(
				$wpdb->query( $wpdb->prepare(
					"UPDATE %i AS p INNER JOIN %i AS m ON p.ID = m.old_id SET p.ID = m.temp_id WHERE m.job_uuid = %s AND m.old_id <> m.new_id",
					$tornado_sql_posts_table,
					$tornado_sql_mapping_table,
					$mapping_uuid
				) ),
				'Post IDها به محدوده موقت منتقل نشدند.'
			);
			$this->jobs->progress( $job_uuid, 'posts_to_temporary', 45.0 );

			$this->update_scalar_references( $mapping_uuid, 'temp_id', 'new_id' );
			$this->update_scalar_meta( $mapping_uuid, 'temp_id', 'new_id' );
			$this->update_conditional_meta( $mapping_uuid, 'temp_id', 'new_id' );
			$structured_registered_to_final = $this->structured_transformer->transform( $mapping_uuid, 'temp_id', 'new_id' );
			$structured_embedded_to_final   = $this->structured_data->transform( $mapping_uuid, 'temp_id', 'new_id' );
			$this->update_scalar_options( $mapping_uuid, 'temp_id', 'new_id' );
			$this->jobs->progress( $job_uuid, 'references_to_final', 65.0 );

			$this->assert_query_result(
				$wpdb->query( $wpdb->prepare(
					"UPDATE %i AS p INNER JOIN %i AS m ON p.ID = m.temp_id SET p.ID = m.new_id WHERE m.job_uuid = %s AND m.old_id <> m.new_id",
					$tornado_sql_posts_table,
					$tornado_sql_mapping_table,
					$mapping_uuid
				) ),
				'Post IDها از محدوده موقت به شناسه‌های نهایی منتقل نشدند.'
			);
			$this->jobs->progress( $job_uuid, 'posts_to_final', 80.0 );

			$gapless_transaction = $this->assert_gapless_posts( $mapping_uuid );
			$this->jobs->progress( $job_uuid, 'gapless_sequence_verified', 86.0, $gapless_transaction );
			$this->assert_structured_transform_parity(
				$structured_registered_to_temp,
				$structured_registered_to_final,
				$structured_embedded_to_temp,
				$structured_embedded_to_final
			);
			$reference_distribution_after = $this->reference_distribution_snapshot( $mapping_uuid, 'new_id' );
			$this->assert_reference_distribution_preserved( $reference_distribution_before, $reference_distribution_after );
			$this->assert_transaction_integrity( $mapping_uuid, $transaction_reference_baseline );
			$precommit_integrity = $this->integrity->check( $mapping_uuid );
			if ( $this->integrity_regressed( (array) $preflight['baseline_integrity'], $precommit_integrity ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
				throw new RuntimeException( 'کنترل Integrity پیش از COMMIT رابطه شکسته جدیدی پیدا کرد؛ تمام تغییرات Rollback شدند.' );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$wpdb->query( 'SET SESSION FOREIGN_KEY_CHECKS = 1' ); 
			$foreign_keys_disabled = false;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			if ( false === $wpdb->query( 'COMMIT' ) ) { 
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
				throw new RuntimeException( 'Transaction دیتابیس نهایی نشد.' );
			}
			$committed = true;
			update_option( 'shcd_tornado_dbm_last_executed_mapping_uuid', $mapping_uuid, false );

			$post_commit_warnings = array();
			
			
			$auto_increment_result = $this->auto_increment->normalize_tables( array( $wpdb->posts ), 'post_id_reindex_gapless' );
			if ( ! empty( $auto_increment_result['failed'] ) ) {
				$post_commit_warnings[] = sprintf( 'پس از Reindex، AUTO_INCREMENT جدول %s تنظیم نشد.', $wpdb->posts );
			}
			$gapless_committed = $this->gapless_posts_snapshot( $mapping_uuid );
			$auto_increment_state = $this->auto_increment->inspect( 'posts_only', array( $wpdb->posts ) );
			$actual_next_id = isset( $auto_increment_state[0] ) ? (int) ( $auto_increment_state[0]['current_auto_increment'] ?? 0 ) : 0;
			$expected_next_id = (int) ( $gapless_committed['next_id'] ?? 1 );
			$gapless_committed['auto_increment'] = $actual_next_id;
			$gapless_committed['expected_auto_increment'] = $expected_next_id;
			$gapless_committed['auto_increment_valid'] = $actual_next_id === $expected_next_id;
			if ( empty( $gapless_committed['valid'] ) ) {
				$post_commit_warnings[] = sprintf( 'پس از COMMIT، توالی جدول %s دوباره بررسی شد و پیوستگی کامل تأیید نشد؛ تا زمان بررسی گزارش، محتوای جدید ایجاد نکنید.', $wpdb->posts );
			}
			if ( ! $gapless_committed['auto_increment_valid'] ) {
				$post_commit_warnings[] = sprintf( 'AUTO_INCREMENT نهایی %1$d است، در حالی که مقدار مورد انتظار %2$d است؛ ابزار تنظیم شمارنده را دوباره اجرا کنید.', $actual_next_id, $expected_next_id );
			}

			$cache_result     = $this->cache->purge( $mapping_uuid, false, 'current_commit' );
			$current_media_verification = is_array( $cache_result['elementor']['media_verification'] ?? null )
				? $cache_result['elementor']['media_verification']
				: array();
			$current_media_blocking = (int) ( $current_media_verification['blocking_unresolved_count'] ?? 0 );
			if ( isset( $cache_result['elementor']['success'] ) && false === $cache_result['elementor']['success'] ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
				throw new RuntimeException( 'بررسی نهایی Referenceهای Elementor، تصاویر یا CSS کامل نشد. نسل جدید تأیید نشد و باید Backup بازیابی شود.' );
			}
			if ( $current_media_blocking > 0 ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
				throw new RuntimeException( sprintf( '%d Reference رسانه‌ای جدید یا فاقد هویت قابل اثبات پس از COMMIT پیدا شد. نسل جدید تأیید نشد و بازیابی Backup لازم است.', $current_media_blocking ) );
			}

			$prepared_advisory_signatures = array_values(
				array_filter(
					array_map( 'strval', is_array( $preflight['preparation']['media_advisory_signatures'] ?? null ) ? $preflight['preparation']['media_advisory_signatures'] : array() ),
					static fn( string $value ): bool => 64 === strlen( $value )
				)
			);
			$current_advisory_signatures = array_values(
				array_filter(
					array_map( 'strval', is_array( $current_media_verification['advisory_signatures'] ?? null ) ? $current_media_verification['advisory_signatures'] : array() ),
					static fn( string $value ): bool => 64 === strlen( $value )
				)
			);
			$new_media_advisories = array_values( array_diff( $current_advisory_signatures, $prepared_advisory_signatures ) );
			if ( ! empty( $new_media_advisories ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
				throw new RuntimeException( sprintf( '%d Reference رسانه‌ای مفقود جدید نسبت به Baseline آماده‌سازی ایجاد شده است. نسل جدید تأیید نشد و بازیابی Backup لازم است.', count( $new_media_advisories ) ) );
			}
			if ( ! empty( $current_advisory_signatures ) ) {
				$post_commit_warnings[] = sprintf(
					'%d Reference رسانه‌ای مربوط به فایل‌های از قبل مفقودشده بدون افزایش باقی مانده است؛ این موارد در گزارش رسانه ثبت شده‌اند.',
					count( $current_advisory_signatures )
				);
			}

			$media_registered = (int) ( $cache_result['elementor']['media_attachments_registered'] ?? 0 );
			if ( $media_registered > 0 ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
				throw new RuntimeException( 'پس از COMMIT نباید Attachment جدیدی ساخته شود. آماده‌سازی نسل قبل ناقص بوده و بازیابی Backup لازم است.' );
			}
			$adapter_repairs   = $this->adapters->repair();
			$adapter_failures = array_filter( $adapter_repairs, static fn( mixed $row ): bool => is_array( $row ) && empty( $row['success'] ) );
			if ( ! empty( $adapter_failures ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
				throw new RuntimeException( 'ترمیم نهایی یکی از Adapterهای ثبت‌شده کامل نشد. نسل جدید تأیید نشد و باید Backup بازیابی شود.' );
			}
			$this->auto_increment->normalize_tables( array( $wpdb->posts ), 'repeatable_reindex_final' );
			$gapless_after_media_recovery = $this->gapless_posts_sequence_state();
			if ( empty( $gapless_after_media_recovery['valid'] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
				throw new RuntimeException( sprintf( 'بررسی نهایی Reindex نشان داد پس از تمام تعمیرها هنوز در جدول %s شکاف یا شناسه نامعتبر وجود دارد. Backup را بازیابی کنید.', $wpdb->posts ) );
			}
			$final_auto_increment_state = $this->auto_increment->inspect( 'posts_only', array( $wpdb->posts ) );
			$final_auto_increment = isset( $final_auto_increment_state[0] ) ? (int) ( $final_auto_increment_state[0]['current_auto_increment'] ?? 0 ) : 0;
			if ( $final_auto_increment !== (int) $gapless_after_media_recovery['next_id'] ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
				throw new RuntimeException( 'AUTO_INCREMENT نهایی با اولین شناسه آزاد پس از توالی 1..N برابر نیست. بازیابی Backup ضروری است.' );
			}
			$integrity_result = $this->integrity->check( $mapping_uuid );
			$result           = array(
				'uuid'         => $job_uuid,
				'mapping_uuid' => $mapping_uuid,
				'backup_uuid'  => $backup_uuid,
				'integrity'    => $integrity_result,
				'cache'        => $cache_result,
				'auto_increment' => $auto_increment_result,
				'reference_policy_actions' => $reference_policy_actions,
				'reference_ledger' => array(
					'entries_verified' => count( $reference_distribution_before ),
					'scalar_distribution_preserved' => true,
					'structured_two_phase_parity' => true,
				),
				'gapless_reindex' => array(
					'guaranteed_at_commit'      => true,
					'locked_mapping_check'      => $locked_mapping_status,
					'transaction_check'         => $gapless_transaction,
					'post_commit_check'         => $gapless_committed,
					'after_media_recovery'      => $gapless_after_media_recovery,
					'media_attachments_created' => $media_registered,
					'attachments_included'      => true,
				),
				'adapter_repairs' => $adapter_repairs,
				'generation'      => $generation,
				'completed_at' => current_time( 'mysql', true ),
				'warnings'     => array_merge( $post_commit_warnings, (int) ( $integrity_result['total_issues'] ?? 0 ) > 0 ? array( 'برخی یافته‌های Integrity از قبل در دیتابیس وجود داشتند و افزایش پیدا نکردند؛ آن‌ها را در بخش پاک‌سازی و تعمیر بررسی کنید.' ) : array() ),
			);

			$final_state = $this->current_posts_state();
			$final_state['auto_increment'] = $final_auto_increment;
			$this->generations->complete( $job_uuid, $final_state );
			$result['generation']['status'] = 'completed';
			$result['generation']['verified'] = true;
			$this->jobs->complete( $job_uuid, $result );
			$this->logger->log( 'critical', 'Reindex کنترل‌شده Post IDها با موفقیت کامل شد.', $result, $job_uuid );
			return $result;
		} catch ( \Throwable $throwable ) {
			if ( ! $committed ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
				$wpdb->query( 'ROLLBACK' ); 
			}
			if ( $foreign_keys_disabled ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
				$wpdb->query( 'SET SESSION FOREIGN_KEY_CHECKS = 1' ); 
			}
			$phase = $committed ? 'post_commit_failed_restore_required' : 'rolled_back';
			$this->generations->fail( $job_uuid, $throwable->getMessage(), $committed );
			$this->jobs->fail( $job_uuid, $throwable->getMessage(), $phase );
			$this->logger->log( 'critical', $committed ? 'Reindex در دیتابیس COMMIT شد، اما پردازش‌های پس از آن کامل نشدند؛ Backup را بازیابی کنید.' : 'Reindex کامل نشد و Transaction فعال با موفقیت Rollback شد.', array( 'error' => $throwable->getMessage(), 'committed' => $committed ), $job_uuid );
			throw $throwable;
		} finally {
			delete_transient( 'shcd_tornado_dbm_active_reindex_lock' );
			if ( $created_maintenance && is_file( $maintenance ) ) {
				wp_delete_file( $maintenance );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	/** @return array<string,int|string|bool> */
	private function current_posts_state(): array {
		$state = $this->gapless_posts_sequence_state();
		$state['post_id_fingerprint'] = $this->fingerprints->post_ids();
		$state['schema_fingerprint']  = $this->fingerprints->schema();
		return $state;
	}

	private function assert_snapshot_unchanged( string $expected_post_fingerprint, string $expected_schema_fingerprint ): void {
		$wpdb = $this->wpdb;
		$current_post_fingerprint = $this->fingerprints->post_ids();
		$current_schema_fingerprint = $this->fingerprints->schema();

		if ( '' === $expected_post_fingerprint || ! hash_equals( $expected_post_fingerprint, $current_post_fingerprint ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal table name in an exception, not HTML output.
			throw new RuntimeException( sprintf( 'جدول %s بین Preflight و Transaction قفل‌شده تغییر کرده است؛ Wizard را از ابتدا اجرا کنید.', $wpdb->posts ) );
		}
		if ( '' === $expected_schema_fingerprint || ! hash_equals( $expected_schema_fingerprint, $current_schema_fingerprint ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
			throw new RuntimeException( 'ساختار دیتابیس بین Preflight و Transaction قفل‌شده تغییر کرده است؛ Wizard را از ابتدا اجرا کنید.' );
		}
	}

	private function update_scalar_references( string $mapping_uuid, string $source, string $target ): void {
		$wpdb    = $this->wpdb;
		$mapping = Identifier::normalize( $this->tables->mappings() );
		$source  = Identifier::normalize( $source );
		$target  = Identifier::normalize( $target );

		foreach ( $this->all_scalar_references( $mapping_uuid ) as $reference ) {
			$table  = Identifier::normalize( (string) $reference['table'] );
			$column = Identifier::normalize( (string) $reference['column'] );
			$this->assert_query_result(
				$wpdb->query( $wpdb->prepare(
					'UPDATE %i AS r INNER JOIN %i AS m ON r.%i = m.%i SET r.%i = m.%i WHERE m.job_uuid = %s AND m.old_id <> m.new_id',
					$table,
					$mapping,
					$column,
					$source,
					$column,
					$target,
					$mapping_uuid
				) ),
				'یکی از Referenceهای عددی ثبت‌شده به‌روزرسانی نشد.'
			);
		}
	}

	private function update_scalar_meta( string $mapping_uuid, string $source, string $target ): void {
		$wpdb       = $this->wpdb;
		$mapping    = Identifier::normalize( $this->tables->mappings() );
		$source     = Identifier::normalize( $source );
		$target     = Identifier::normalize( $target );
		$meta_key   = 'meta_key';
		$meta_value = 'meta_value';

		foreach ( $this->references->scalar_meta_keys() as $table_name => $keys ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reindexing must verify the current schema directly.
			if ( empty( $keys ) || $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
				continue;
			}

			$table        = Identifier::normalize( (string) $table_name );
			$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
			$query_args   = array_merge(
				array(
					$table,
					$mapping,
					$meta_value,
					$source,
					$meta_value,
					$target,
					$mapping_uuid,
					$meta_key,
				),
				$keys,
				array( $meta_value )
			);

			$this->assert_query_result(
				$wpdb->query( $wpdb->prepare(
					"UPDATE %i AS r
					 INNER JOIN %i AS m ON CAST(r.%i AS UNSIGNED) = m.%i
					 SET r.%i = CAST(m.%i AS CHAR)
					 WHERE m.job_uuid = %s AND m.old_id <> m.new_id
					 AND r.%i IN ({$placeholders}) AND r.%i REGEXP '^[0-9]+$'",
					$query_args
				) ),
				'Referenceهای عددی ثبت‌شده در Metadata به‌روزرسانی نشدند.'
			);
		}
	}

	private function update_conditional_meta( string $mapping_uuid, string $source, string $target ): void {
		$wpdb    = $this->wpdb;
		$mapping = Identifier::normalize( $this->tables->mappings() );
		$source  = Identifier::normalize( $source );
		$target  = Identifier::normalize( $target );

		foreach ( $this->references->conditional_meta_references() as $reference ) {
			$table      = Identifier::normalize( (string) $reference['table'] );
			$object     = Identifier::normalize( (string) $reference['object_column'] );
			$meta_key   = Identifier::normalize( (string) $reference['meta_key_column'] );
			$meta_value = Identifier::normalize( (string) $reference['meta_value_column'] );

			$this->assert_query_result(
				$wpdb->query( $wpdb->prepare(
					"UPDATE %i target_meta
					 INNER JOIN %i condition_meta ON condition_meta.%i = target_meta.%i
					 AND condition_meta.%i = %s AND condition_meta.%i = %s
					 INNER JOIN %i m ON CAST(target_meta.%i AS UNSIGNED) = m.%i
					 SET target_meta.%i = CAST(m.%i AS CHAR)
					 WHERE m.job_uuid = %s AND m.old_id <> m.new_id
					 AND target_meta.%i = %s AND target_meta.%i REGEXP '^[0-9]+$'",
					$table,
					$table,
					$object,
					$object,
					$meta_key,
					(string) $reference['condition_key'],
					$meta_value,
					(string) $reference['condition_value'],
					$mapping,
					$meta_value,
					$source,
					$meta_value,
					$target,
					$mapping_uuid,
					$meta_key,
					(string) $reference['target_key'],
					$meta_value
				) ),
				'Reference شرطی در Metadata به‌روزرسانی نشد.'
			);
		}
	}

	private function update_scalar_options( string $mapping_uuid, string $source, string $target ): void {
		$wpdb = $this->wpdb;
		$keys = $this->references->option_names();
		if ( empty( $keys ) ) {
			return;
		}

		$table        = Identifier::normalize( $wpdb->options );
		$mapping      = Identifier::normalize( $this->tables->mappings() );
		$source       = Identifier::normalize( $source );
		$target       = Identifier::normalize( $target );
		$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
		$query_args   = array_merge( array( $table, $mapping, $source, $target, $mapping_uuid ), $keys );

		$this->assert_query_result(
				$wpdb->query( $wpdb->prepare(
				"UPDATE %i AS r
				 INNER JOIN %i AS m ON CAST(r.option_value AS UNSIGNED) = m.%i
				 SET r.option_value = CAST(m.%i AS CHAR)
				 WHERE m.job_uuid = %s AND m.old_id <> m.new_id
				 AND r.option_name IN ({$placeholders}) AND r.option_value REGEXP '^[0-9]+$'",
				$query_args
			) ),
				'Referenceهای ثبت‌شده در Options به‌روزرسانی نشدند.'
			);
	}


	/**
	 * Finds only genuinely orphaned scalar values that collide with a target ID.
	 *
	 * A valid current reference can equal another row's future new_id while still
	 * pointing to a real old_id that will be transformed in the first phase. Such
	 * values are safe and MUST NOT be treated as collisions. A collision exists
	 * only when the numeric value matches a target new_id but matches no old_id in
	 * the same complete Mapping. Leaving that orphan untouched would silently make
	 * it point to an unrelated Post after the dense 1..N sequence is committed.
	 *
	 * @return array<string,array<string,int>>
	 */
	private function gap_target_collision_snapshot( string $mapping_uuid ): array {
		$wpdb    = $this->wpdb;
		$mapping = Identifier::normalize( $this->tables->mappings() );
		$ledger  = array();

		foreach ( $this->all_scalar_references( $mapping_uuid ) as $reference ) {
			$table_name  = Identifier::normalize( (string) $reference['table'] );
			$column_name = Identifier::normalize( (string) $reference['column'] );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Safety snapshot must read the live references directly.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT target_map.old_id, target_map.new_id, COUNT(*) AS reference_count
					 FROM %i r
					 INNER JOIN %i target_map ON r.%i = target_map.new_id AND target_map.job_uuid = %s
					 LEFT JOIN %i source_map ON r.%i = source_map.old_id AND source_map.job_uuid = target_map.job_uuid
					 WHERE target_map.old_id <> target_map.new_id AND source_map.old_id IS NULL
					 GROUP BY target_map.old_id, target_map.new_id ORDER BY target_map.old_id ASC",
					$table_name,
					$mapping,
					$column_name,
					$mapping_uuid,
					$mapping,
					$column_name
				),
				ARRAY_A
			);
			$ledger[ 'column:' . $table_name . '.' . $column_name ] = $this->normalize_reference_distribution( $rows );
		}

		foreach ( $this->references->scalar_meta_keys() as $table_name => $keys ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Safety snapshot must validate the current schema directly.
			if ( empty( $keys ) || $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
				continue;
			}

			$table_name   = Identifier::normalize( (string) $table_name );
			$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
			$query_args   = array_merge( array( $table_name, $mapping, $mapping_uuid, $mapping ), $keys );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Safety snapshot must read the live references directly.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT r.meta_key AS reference_key, target_map.old_id, target_map.new_id, COUNT(*) AS reference_count
					 FROM %i r
					 INNER JOIN %i target_map ON CAST(r.meta_value AS UNSIGNED) = target_map.new_id AND target_map.job_uuid = %s
					 LEFT JOIN %i source_map ON CAST(r.meta_value AS UNSIGNED) = source_map.old_id AND source_map.job_uuid = target_map.job_uuid
					 WHERE target_map.old_id <> target_map.new_id AND source_map.old_id IS NULL
					 AND r.meta_key IN ({$placeholders}) AND r.meta_value REGEXP '^[0-9]+$'
					 GROUP BY r.meta_key, target_map.old_id, target_map.new_id ORDER BY r.meta_key, target_map.old_id",
					$query_args
				),
				ARRAY_A
			);
			$ledger[ 'meta:' . $table_name ] = $this->normalize_reference_distribution( $rows, 'reference_key' );
		}

		foreach ( $this->references->conditional_meta_references() as $index => $reference ) {
			$table_name = Identifier::normalize( (string) $reference['table'] );
			$object     = Identifier::normalize( (string) $reference['object_column'] );
			$meta_key   = Identifier::normalize( (string) $reference['meta_key_column'] );
			$meta_value = Identifier::normalize( (string) $reference['meta_value_column'] );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Safety snapshot must read the live references directly.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT target_map.old_id, target_map.new_id, COUNT(*) AS reference_count
					 FROM %i target_meta
					 INNER JOIN %i condition_meta ON condition_meta.%i = target_meta.%i
					 AND condition_meta.%i = %s AND condition_meta.%i = %s
					 INNER JOIN %i target_map ON CAST(target_meta.%i AS UNSIGNED) = target_map.new_id AND target_map.job_uuid = %s
					 LEFT JOIN %i source_map ON CAST(target_meta.%i AS UNSIGNED) = source_map.old_id AND source_map.job_uuid = target_map.job_uuid
					 WHERE target_map.old_id <> target_map.new_id AND source_map.old_id IS NULL
					 AND target_meta.%i = %s AND target_meta.%i REGEXP '^[0-9]+$'
					 GROUP BY target_map.old_id, target_map.new_id ORDER BY target_map.old_id",
					$table_name,
					$table_name,
					$object,
					$object,
					$meta_key,
					(string) $reference['condition_key'],
					$meta_value,
					(string) $reference['condition_value'],
					$mapping,
					$meta_value,
					$mapping_uuid,
					$mapping,
					$meta_value,
					$meta_key,
					(string) $reference['target_key'],
					$meta_value
				),
				ARRAY_A
			);
			$ledger[ 'conditional:' . $table_name . ':' . (string) $index . ':' . (string) $reference['target_key'] ] = $this->normalize_reference_distribution( $rows );
		}

		$option_names = $this->references->option_names();
		if ( ! empty( $option_names ) ) {
			$table_name   = Identifier::normalize( $wpdb->options );
			$placeholders = implode( ', ', array_fill( 0, count( $option_names ), '%s' ) );
			$query_args   = array_merge( array( $table_name, $mapping, $mapping_uuid, $mapping ), $option_names );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Safety snapshot must read the live references directly.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT r.option_name AS reference_key, target_map.old_id, target_map.new_id, COUNT(*) AS reference_count
					 FROM %i r
					 INNER JOIN %i target_map ON CAST(r.option_value AS UNSIGNED) = target_map.new_id AND target_map.job_uuid = %s
					 LEFT JOIN %i source_map ON CAST(r.option_value AS UNSIGNED) = source_map.old_id AND source_map.job_uuid = target_map.job_uuid
					 WHERE target_map.old_id <> target_map.new_id AND source_map.old_id IS NULL
					 AND r.option_name IN ({$placeholders}) AND r.option_value REGEXP '^[0-9]+$'
					 GROUP BY r.option_name, target_map.old_id, target_map.new_id ORDER BY r.option_name, target_map.old_id",
					$query_args
				),
				ARRAY_A
			);
			$ledger['options'] = $this->normalize_reference_distribution( $rows, 'reference_key' );
		}

		ksort( $ledger );
		return $ledger;
	}

	/**
	 * Captures the exact distribution of every registered scalar reference across
	 * changed Mapping pairs. This is stronger than an orphan check: a stale Old ID
	 * can still point to an existing but wrong Post after dense renumbering.
	 *
	 * @return array<string,array<string,int>>
	 */
	private function reference_distribution_snapshot( string $mapping_uuid, string $mapping_column ): array {
		$wpdb = $this->wpdb;
		if ( ! in_array( $mapping_column, array( 'old_id', 'new_id' ), true ) ) {
			throw new RuntimeException( 'ستون Mapping برای کنترل توزیع Reference معتبر نیست.' );
		}

		$mapping   = Identifier::normalize( $this->tables->mappings() );
		$map_value = Identifier::normalize( $mapping_column );
		$ledger    = array();

		foreach ( $this->all_scalar_references( $mapping_uuid ) as $reference ) {
			$table_name  = Identifier::normalize( (string) $reference['table'] );
			$column_name = Identifier::normalize( (string) $reference['column'] );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Safety snapshot must read the live references directly.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT m.old_id, m.new_id, COUNT(*) AS reference_count
					 FROM %i r INNER JOIN %i m ON r.%i = m.%i
					 WHERE m.job_uuid = %s AND m.old_id <> m.new_id
					 GROUP BY m.old_id, m.new_id ORDER BY m.old_id ASC",
					$table_name,
					$mapping,
					$column_name,
					$map_value,
					$mapping_uuid
				),
				ARRAY_A
			);
			$ledger[ 'column:' . $table_name . '.' . $column_name ] = $this->normalize_reference_distribution( $rows );
		}

		foreach ( $this->references->scalar_meta_keys() as $table_name => $keys ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Safety snapshot must validate the current schema directly.
			if ( empty( $keys ) || $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
				continue;
			}

			$table_name   = Identifier::normalize( (string) $table_name );
			$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
			$query_args   = array_merge( array( $table_name, $mapping, $map_value, $mapping_uuid ), $keys );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Safety snapshot must read the live references directly.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT r.meta_key AS reference_key, m.old_id, m.new_id, COUNT(*) AS reference_count
					 FROM %i r INNER JOIN %i m ON CAST(r.meta_value AS UNSIGNED) = m.%i
					 WHERE m.job_uuid = %s AND m.old_id <> m.new_id
					 AND r.meta_key IN ({$placeholders}) AND r.meta_value REGEXP '^[0-9]+$'
					 GROUP BY r.meta_key, m.old_id, m.new_id ORDER BY r.meta_key, m.old_id",
					$query_args
				),
				ARRAY_A
			);
			$ledger[ 'meta:' . $table_name ] = $this->normalize_reference_distribution( $rows, 'reference_key' );
		}

		foreach ( $this->references->conditional_meta_references() as $index => $reference ) {
			$table_name = Identifier::normalize( (string) $reference['table'] );
			$object     = Identifier::normalize( (string) $reference['object_column'] );
			$meta_key   = Identifier::normalize( (string) $reference['meta_key_column'] );
			$meta_value = Identifier::normalize( (string) $reference['meta_value_column'] );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Safety snapshot must read the live references directly.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT m.old_id, m.new_id, COUNT(*) AS reference_count
					 FROM %i target_meta
					 INNER JOIN %i condition_meta ON condition_meta.%i = target_meta.%i
					 AND condition_meta.%i = %s AND condition_meta.%i = %s
					 INNER JOIN %i m ON CAST(target_meta.%i AS UNSIGNED) = m.%i
					 WHERE m.job_uuid = %s AND m.old_id <> m.new_id
					 AND target_meta.%i = %s AND target_meta.%i REGEXP '^[0-9]+$'
					 GROUP BY m.old_id, m.new_id ORDER BY m.old_id",
					$table_name,
					$table_name,
					$object,
					$object,
					$meta_key,
					(string) $reference['condition_key'],
					$meta_value,
					(string) $reference['condition_value'],
					$mapping,
					$meta_value,
					$map_value,
					$mapping_uuid,
					$meta_key,
					(string) $reference['target_key'],
					$meta_value
				),
				ARRAY_A
			);
			$ledger[ 'conditional:' . $table_name . ':' . (string) $index . ':' . (string) $reference['target_key'] ] = $this->normalize_reference_distribution( $rows );
		}

		$option_names = $this->references->option_names();
		if ( ! empty( $option_names ) ) {
			$table_name   = Identifier::normalize( $wpdb->options );
			$placeholders = implode( ', ', array_fill( 0, count( $option_names ), '%s' ) );
			$query_args   = array_merge( array( $table_name, $mapping, $map_value, $mapping_uuid ), $option_names );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Safety snapshot must read the live references directly.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT r.option_name AS reference_key, m.old_id, m.new_id, COUNT(*) AS reference_count
					 FROM %i r INNER JOIN %i m ON CAST(r.option_value AS UNSIGNED) = m.%i
					 WHERE m.job_uuid = %s AND m.old_id <> m.new_id
					 AND r.option_name IN ({$placeholders}) AND r.option_value REGEXP '^[0-9]+$'
					 GROUP BY r.option_name, m.old_id, m.new_id ORDER BY r.option_name, m.old_id",
					$query_args
				),
				ARRAY_A
			);
			$ledger['options'] = $this->normalize_reference_distribution( $rows, 'reference_key' );
		}

		ksort( $ledger );
		return $ledger;
	}

	/**
	 * @param mixed $rows
	 * @return array<string,int>
	 */
	private function normalize_reference_distribution( mixed $rows, string $prefix_column = '' ): array {
		$distribution = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$prefix = '' !== $prefix_column ? (string) ( $row[ $prefix_column ] ?? '' ) . '|' : '';
			$key = $prefix . (int) ( $row['old_id'] ?? 0 ) . '>' . (int) ( $row['new_id'] ?? 0 );
			$distribution[ $key ] = (int) ( $row['reference_count'] ?? 0 );
		}
		ksort( $distribution );
		return $distribution;
	}

	/** @param array<string,array<string,int>> $ledger */
	private function reference_distribution_total( array $ledger ): int {
		$total = 0;
		foreach ( $ledger as $distribution ) {
			$total += array_sum( array_map( 'intval', $distribution ) );
		}
		return $total;
	}

	/** @param array<string,array<string,int>> $before @param array<string,array<string,int>> $after */
	private function assert_reference_distribution_preserved( array $before, array $after ): void {
		$keys = array_values( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) );
		sort( $keys );
		foreach ( $keys as $key ) {
			$expected = $before[ $key ] ?? array();
			$actual   = $after[ $key ] ?? array();
			ksort( $expected );
			ksort( $actual );
			if ( $expected !== $actual ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
				throw new RuntimeException(
					sprintf(
						'بررسی تطبیقی Reference «%s» نشان داد توزیع روابط پیش و پس از Reindex یکسان نیست. همه تغییرات Rollback شدند.',
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal reference key in an exception, not HTML output.
						$key
					)
				);
			}
		}
	}

	/**
	 * The two-phase transformer must update exactly the same number of structured
	 * rows/references from Old→Temporary and Temporary→Final. Temporary IDs cannot
	 * collide with real content, so any difference signals a partial transform.
	 *
	 * @param array<string,mixed> $registered_to_temp
	 * @param array<string,mixed> $registered_to_final
	 * @param array<string,mixed> $embedded_to_temp
	 * @param array<string,mixed> $embedded_to_final
	 */
	private function assert_structured_transform_parity(
		array $registered_to_temp,
		array $registered_to_final,
		array $embedded_to_temp,
		array $embedded_to_final
	): void {
		foreach ( array( 'csv_meta', 'serialized_meta', 'elementor', 'elementor_page_settings', 'gutenberg' ) as $key ) {
			if ( (int) ( $registered_to_temp[ $key ] ?? 0 ) !== (int) ( $registered_to_final[ $key ] ?? 0 ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal metric key in an exception, not HTML output.
				throw new RuntimeException( sprintf( 'تبدیل دو‌مرحله‌ای Structured Data در بخش «%s» ناقص بود؛ تمام تغییرات Rollback شدند.', $key ) );
			}
		}
		foreach ( array( 'updated_rows', 'updated_references' ) as $key ) {
			if ( (int) ( $embedded_to_temp[ $key ] ?? 0 ) !== (int) ( $embedded_to_final[ $key ] ?? 0 ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal metric key in an exception, not HTML output.
				throw new RuntimeException( sprintf( 'تبدیل دو‌مرحله‌ای داده‌های Embedded در شاخص «%s» ناقص بود؛ تمام تغییرات Rollback شدند.', $key ) );
			}
		}

		$adapter_temp_metrics  = $this->numeric_adapter_metrics( (array) ( $registered_to_temp['adapters'] ?? array() ) );
		$adapter_final_metrics = $this->numeric_adapter_metrics( (array) ( $registered_to_final['adapters'] ?? array() ) );
		if ( $adapter_temp_metrics !== $adapter_final_metrics ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
			throw new RuntimeException( 'تعداد Referenceهای اصلاح‌شده توسط Adapterها در مرحله موقت و نهایی برابر نیست؛ تمام تغییرات Rollback شدند.' );
		}
	}

	/**
	 * Flattens only deterministic numeric counters from Adapter results.
	 * Source/target labels and explanatory strings are intentionally ignored.
	 *
	 * @param array<string,mixed> $results Adapter results.
	 * @return array<string,int>
	 */
	private function numeric_adapter_metrics( array $results ): array {
		$metrics = array();
		$walk = static function ( mixed $value, string $path ) use ( &$metrics, &$walk ): void {
			if ( is_array( $value ) ) {
				ksort( $value );
				foreach ( $value as $key => $child ) {
					$walk( $child, '' === $path ? (string) $key : $path . '.' . (string) $key );
				}
				return;
			}
			if ( is_int( $value ) || is_bool( $value ) || ( is_string( $value ) && preg_match( '/^-?[0-9]+$/', $value ) === 1 ) ) {
				$metrics[ $path ] = (int) $value;
			}
		};
		$walk( $results, '' );
		ksort( $metrics );
		return $metrics;
	}

	/**
	 * Captures orphan counts for the exact reference set used by this Mapping.
	 *
	 * Discovery-only references must be part of the baseline as well; otherwise
	 * pre-existing orphan rows can be mistaken for a Transaction regression.
	 *
	 * @return array<string,array{label:string,issues:int}>
	 */
	private function reference_integrity_snapshot( string $mapping_uuid ): array {
		$wpdb = $this->wpdb;
		$posts    = Identifier::quote( $wpdb->posts );
		$tornado_sql_posts = Identifier::normalize( $posts );
		$snapshot = array();

		foreach ( $this->all_scalar_references( $mapping_uuid ) as $reference ) {
			$table_name  = (string) $reference['table'];
			$column_name = (string) $reference['column'];
			$key         = $table_name . '.' . $column_name;
			$table       = Identifier::quote( $table_name );
			$tornado_sql_table = Identifier::normalize( $table );
			$column      = Identifier::quote( $column_name );
			$tornado_sql_column = Identifier::normalize( $column );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$issues      = (int) $wpdb->get_var(
				 $wpdb->prepare( "SELECT COUNT(*) FROM %i r LEFT JOIN %i p ON p.ID = r.%i WHERE r.%i <> 0 AND p.ID IS NULL", $tornado_sql_table, $tornado_sql_posts, $tornado_sql_column, $tornado_sql_column ) 
			);
			$snapshot[ $key ] = array(
				'label'  => (string) $reference['label'],
				'issues' => $issues,
			);
		}

		return $snapshot;
	}


	/**
	 * Confirms that wp_posts, including attachments in the Media Library, is the
	 * exact dense sequence 1..N and that every row is covered by the Mapping.
	 *
	 * @return array<string,int|bool>
	 */
	private function assert_gapless_posts( string $mapping_uuid ): array {
		$snapshot = $this->gapless_posts_snapshot( $mapping_uuid );
		if ( empty( $snapshot['valid'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
			throw new RuntimeException(
				sprintf(
					'اعتبارسنجی Reindex بدون شکاف پذیرفته نشد: تعداد رکوردها %1$d، کمترین ID برابر %2$d، بیشترین ID برابر %3$d و تعداد شکاف‌ها %4$d است. همه تغییرات Rollback شدند.',
					(int) ( $snapshot['row_count'] ?? 0 ),
					(int) ( $snapshot['min_id'] ?? 0 ),
					(int) ( $snapshot['max_id'] ?? 0 ),
					(int) ( $snapshot['gap_count'] ?? 0 )
				)
			);
		}
		return $snapshot;
	}

	/** @return array<string,int|bool> */
	private function gapless_posts_sequence_state(): array {
		$wpdb = $this->wpdb;
		$posts = Identifier::quote( $wpdb->posts );
		$tornado_sql_posts = Identifier::normalize( $posts );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$stats = $wpdb->get_row(
			 $wpdb->prepare( "SELECT COUNT(*) AS row_count, COUNT(DISTINCT ID) AS distinct_ids, COALESCE(MIN(ID), 0) AS min_id,
			 COALESCE(MAX(ID), 0) AS max_id, COALESCE(SUM(ID), 0) AS sum_ids
			 FROM %i", $tornado_sql_posts ) ,
			ARRAY_A
		);
		$stats = is_array( $stats ) ? $stats : array();
		$count = (int) ( $stats['row_count'] ?? 0 );
		$min   = (int) ( $stats['min_id'] ?? 0 );
		$max   = (int) ( $stats['max_id'] ?? 0 );
		$sum   = (int) ( $stats['sum_ids'] ?? 0 );
		$expected_sum = (int) ( ( $count * ( $count + 1 ) ) / 2 );
		$valid = (int) ( $stats['distinct_ids'] ?? 0 ) === $count
			&& ( 0 === $count || ( 1 === $min && $max === $count && $sum === $expected_sum ) );

		return array(
			'valid'        => $valid,
			'row_count'    => $count,
			'distinct_ids' => (int) ( $stats['distinct_ids'] ?? 0 ),
			'min_id'       => $min,
			'max_id'       => $max,
			'gap_count'    => $count > 0 ? max( 0, $max - $count ) : 0,
			'next_id'      => $count + 1,
		);
	}

	/** @return array<string,int|bool> */
	private function gapless_posts_snapshot( string $mapping_uuid ): array {
		$wpdb = $this->wpdb;
		$posts   = Identifier::quote( $wpdb->posts );
		$tornado_sql_posts = Identifier::normalize( $posts );
		$mapping = Identifier::quote( $this->tables->mappings() );
		$tornado_sql_mapping = Identifier::normalize( $mapping );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$stats   = $wpdb->get_row(
			 $wpdb->prepare( "SELECT COUNT(*) AS row_count, COUNT(DISTINCT ID) AS distinct_ids, COALESCE(MIN(ID), 0) AS min_id,
			 COALESCE(MAX(ID), 0) AS max_id, COALESCE(SUM(ID), 0) AS sum_ids,
			 SUM(CASE WHEN post_type = 'attachment' THEN 1 ELSE 0 END) AS attachment_count
			 FROM %i", $tornado_sql_posts ) ,
			ARRAY_A
		);
		$stats = is_array( $stats ) ? $stats : array();
		$count = (int) ( $stats['row_count'] ?? 0 );
		$min   = (int) ( $stats['min_id'] ?? 0 );
		$max   = (int) ( $stats['max_id'] ?? 0 );
		$sum   = (int) ( $stats['sum_ids'] ?? 0 );
		$expected_sum = (int) ( ( $count * ( $count + 1 ) ) / 2 );
		$gap_count = $count > 0 ? max( 0, $max - $count ) : 0;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$unmapped_final_rows = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i p LEFT JOIN %i m ON m.new_id = p.ID AND m.job_uuid = %s WHERE m.new_id IS NULL",
				$tornado_sql_posts,
				$tornado_sql_mapping,
				$mapping_uuid
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$missing_final_posts = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i m LEFT JOIN %i p ON p.ID = m.new_id WHERE m.job_uuid = %s AND p.ID IS NULL",
				$tornado_sql_mapping,
				$tornado_sql_posts,
				$mapping_uuid
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$temp_rows = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i p INNER JOIN %i m ON p.ID = m.temp_id WHERE m.job_uuid = %s",
				$tornado_sql_posts,
				$tornado_sql_mapping,
				$mapping_uuid
			)
		);

		$valid = (int) ( $stats['distinct_ids'] ?? 0 ) === $count
			&& ( 0 === $count || ( 1 === $min && $max === $count && $sum === $expected_sum ) )
			&& 0 === $gap_count
			&& 0 === $unmapped_final_rows
			&& 0 === $missing_final_posts
			&& 0 === $temp_rows;

		return array(
			'valid'               => $valid,
			'row_count'           => $count,
			'distinct_ids'        => (int) ( $stats['distinct_ids'] ?? 0 ),
			'min_id'              => $min,
			'max_id'              => $max,
			'gap_count'           => $gap_count,
			'next_id'             => $count + 1,
			'attachment_count'    => (int) ( $stats['attachment_count'] ?? 0 ),
			'unmapped_final_rows' => $unmapped_final_rows,
			'missing_final_posts' => $missing_final_posts,
			'temporary_rows'      => $temp_rows,
		);
	}

	private function assert_transaction_integrity( string $mapping_uuid, array $reference_baseline ): void {
		$wpdb = $this->wpdb;
		$mapping = Identifier::quote( $this->tables->mappings() );
		$tornado_sql_mapping = Identifier::normalize( $mapping );
		$posts   = Identifier::quote( $wpdb->posts );
		$tornado_sql_posts = Identifier::normalize( $posts );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$missing = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i m LEFT JOIN %i p ON p.ID = m.new_id WHERE m.job_uuid = %s AND p.ID IS NULL",
				$tornado_sql_mapping,
				$tornado_sql_posts,
				$mapping_uuid
			)
		);

		if ( $missing > 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message only; UI and REST presentation layers escape it before output.
			throw new RuntimeException( 'بررسی Integrity داخل Transaction نشان داد یک یا چند Post ID موجود در Mapping پس از تغییر پیدا نمی‌شوند.' );
		}

		foreach ( $this->all_scalar_references( $mapping_uuid ) as $reference ) {
			$table  = Identifier::quote( $reference['table'] );
			$tornado_sql_table = Identifier::normalize( $table );
			$column = Identifier::quote( $reference['column'] );
			$tornado_sql_column = Identifier::normalize( $column );
			$key    = $reference['table'] . '.' . $reference['column'];
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$orphans = (int) $wpdb->get_var(
				 $wpdb->prepare( "SELECT COUNT(*) FROM %i r LEFT JOIN %i p ON p.ID = r.%i WHERE r.%i <> 0 AND p.ID IS NULL", $tornado_sql_table, $tornado_sql_posts, $tornado_sql_column, $tornado_sql_column ) 
			);
			$baseline_issues = (int) ( $reference_baseline[ $key ]['issues'] ?? 0 );
			if ( $orphans > $baseline_issues ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal reference label and counters in an exception, not HTML output.
				throw new RuntimeException(
					sprintf(
						'بررسی Integrity برای Reference «%1$s» نشان داد تعداد روابط شکسته از %2$d به %3$d افزایش یافته است.',
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception context, not HTML output.
						(string) $reference['label'],
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Integer counter in an internal exception message.
						$baseline_issues,
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Integer counter in an internal exception message.
						$orphans
					)
				);
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$temp_rows = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i r INNER JOIN %i m ON r.%i = m.temp_id WHERE m.job_uuid = %s AND m.old_id <> m.new_id",
					$tornado_sql_table,
					$tornado_sql_mapping,
					$tornado_sql_column,
					$mapping_uuid
				)
			);
			if ( $temp_rows > 0 ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal reference label and counters in an exception, not HTML output.
				throw new RuntimeException(
					sprintf(
						'بررسی Integrity برای Reference «%1$s» نشان داد %2$d مقدار هنوز در محدوده موقت باقی مانده است.',
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception context, not HTML output.
						(string) $reference['label'],
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Integer counter in an internal exception message.
						$temp_rows
					)
				);
			}
		}
	}

	private function integrity_regressed( array $before, array $after ): bool {
		$check_keys = array( 'orphan_postmeta', 'orphan_comments', 'orphan_term_relationships', 'orphan_revisions', 'orphan_attachments', 'orphan_post_parents' );
		foreach ( $check_keys as $key ) {
			if ( (int) ( $after['checks'][ $key ] ?? 0 ) > (int) ( $before['checks'][ $key ] ?? 0 ) ) {
				return true;
			}
		}
		foreach ( (array) ( $after['registered_references'] ?? array() ) as $key => $row ) {
			if ( (int) ( $row['issues'] ?? 0 ) > (int) ( $before['registered_references'][ $key ]['issues'] ?? 0 ) ) {
				return true;
			}
		}
		foreach ( (array) ( $after['woocommerce'] ?? array() ) as $key => $value ) {
			if ( 'orphan_order_itemmeta' !== $key && (int) $value > (int) ( $before['woocommerce'][ $key ] ?? 0 ) ) {
				return true;
			}
		}
		foreach ( array( 'duplicate_old_ids', 'duplicate_new_ids', 'duplicate_temp_ids' ) as $key ) {
			if ( (int) ( $after['mapping'][ $key ] ?? 0 ) > (int) ( $before['mapping'][ $key ] ?? 0 ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Combines explicit references with strongly verified discovery references.
	 *
	 * @return list<array{table:string,column:string,label:string,source:string}>
	 */
	private function all_scalar_references( string $mapping_uuid ): array {
		$references = $this->references->scalar_columns();
		$evidence   = $this->discovery_reference_evidence( $mapping_uuid );

		foreach ( $evidence['auto_registered'] as $reference ) {
			$references[] = array(
				'table'  => (string) $reference['table'],
				'column' => (string) $reference['column'],
				'label'  => 'Reference تأییدشده از طریق Discovery: ' . (string) $reference['table'] . '.' . (string) $reference['column'],
				'source' => 'discovery_evidence',
			);
		}

		$unique = array();
		foreach ( $references as $reference ) {
			$key = (string) $reference['table'] . '.' . (string) $reference['column'];
			$unique[ $key ] = $reference;
		}

		return array_values( $unique );
	}

	private function reference_policy( string $table, string $column ): string {
		$policies = Settings::get( 'reference_policies', array() );
		$policies = is_array( $policies ) ? $policies : array();
		$policy   = sanitize_key( (string) ( $policies[ $table . '.' . $column ] ?? 'update' ) );
		return in_array( $policy, array( 'update', 'ignore', 'detach', 'delete_rows' ), true ) ? $policy : 'update';
	}

	/**
	 * @return array{detached_rows:int,deleted_rows:int,actions:list<array<string,mixed>>}
	 */
	private function apply_managed_reference_policies( string $mapping_uuid ): array {
		$wpdb     = $this->wpdb;
		$evidence = $this->discovery_reference_evidence( $mapping_uuid );
		$mapping  = Identifier::normalize( $this->tables->mappings() );
		$result   = array(
			'detached_rows' => 0,
			'deleted_rows'  => 0,
			'actions'       => array(),
		);

		foreach ( $evidence['managed'] as $reference ) {
			$table_name  = (string) ( $reference['table'] ?? '' );
			$column_name = (string) ( $reference['column'] ?? '' );
			$policy      = (string) ( $reference['policy'] ?? 'update' );
			$table       = Identifier::normalize( $table_name );
			$column      = Identifier::normalize( $column_name );

			if ( 'detach' === $policy ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Managed-reference maintenance must update live rows.
				$query_result = $wpdb->query(
					$wpdb->prepare(
						'UPDATE %i r
						 LEFT JOIN %i source_map ON r.%i = source_map.old_id AND source_map.job_uuid = %s
						 LEFT JOIN %i target_map ON r.%i = target_map.new_id AND target_map.job_uuid = %s AND target_map.old_id <> target_map.new_id
						 SET r.%i = 0
						 WHERE r.%i <> 0
						 AND ((source_map.old_id IS NOT NULL AND source_map.old_id <> source_map.new_id)
						 OR (target_map.new_id IS NOT NULL AND source_map.old_id IS NULL))',
						$table,
						$mapping,
						$column,
						$mapping_uuid,
						$mapping,
						$column,
						$mapping_uuid,
						$column,
						$column
					)
				);
				$this->assert_query_result( $query_result, 'صفرکردن ارتباط‌های انتخاب‌شده در جدول اختصاصی کامل نشد.' );
				$affected = max( 0, (int) $wpdb->rows_affected );
				$result['detached_rows'] += $affected;
			} elseif ( 'delete_rows' === $policy ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Managed-reference maintenance must update live rows.
				$query_result = $wpdb->query(
					$wpdb->prepare(
						'DELETE r FROM %i r
						 LEFT JOIN %i source_map ON r.%i = source_map.old_id AND source_map.job_uuid = %s
						 LEFT JOIN %i target_map ON r.%i = target_map.new_id AND target_map.job_uuid = %s AND target_map.old_id <> target_map.new_id
						 WHERE r.%i <> 0
						 AND ((source_map.old_id IS NOT NULL AND source_map.old_id <> source_map.new_id)
						 OR (target_map.new_id IS NOT NULL AND source_map.old_id IS NULL))',
						$table,
						$mapping,
						$column,
						$mapping_uuid,
						$mapping,
						$column,
						$mapping_uuid,
						$column
					)
				);
				$this->assert_query_result( $query_result, 'حذف ردیف‌های انتخاب‌شده از جدول اختصاصی کامل نشد.' );
				$affected = max( 0, (int) $wpdb->rows_affected );
				$result['deleted_rows'] += $affected;
			} else {
				continue;
			}

			$result['actions'][] = array(
				'reference'     => $table_name . '.' . $column_name,
				'policy'        => $policy,
				'affected_rows' => $affected,
			);
		}

		return $result;
	}

	/**
	 * Uses actual Mapping intersections and referential evidence instead of column names alone.
	 *
	 * @return array{auto_registered:list<array<string,mixed>>,managed:list<array<string,mixed>>,unresolved:list<array<string,mixed>>,ignored:list<array<string,mixed>>}
	 */
	private function discovery_reference_evidence( string $mapping_uuid ): array {
		$wpdb = $this->wpdb;
		if ( isset( $this->reference_evidence_cache[ $mapping_uuid ] ) ) {
			return $this->reference_evidence_cache[ $mapping_uuid ];
		}

		$result = array(
			'auto_registered' => array(),
			'managed'         => array(),
			'unresolved'      => array(),
			'ignored'         => array(),
		);
		$discovery_uuid = (string) get_option( 'shcd_tornado_dbm_last_discovery_uuid', '' );
		if ( ! wp_is_uuid( $discovery_uuid ) || ! wp_is_uuid( $mapping_uuid ) || ! $this->mappings->exists( $mapping_uuid ) ) {
			$this->reference_evidence_cache[ $mapping_uuid ] = $result;
			return $result;
		}

		$discovery = Identifier::quote( $this->tables->discoveries() );
		$tornado_sql_discovery = Identifier::normalize( $discovery );
		$mapping   = Identifier::quote( $this->tables->mappings() );
		$tornado_sql_mapping = Identifier::normalize( $mapping );
		$posts     = Identifier::quote( $wpdb->posts );
		$tornado_sql_posts = Identifier::normalize( $posts );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$rows      = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT table_name, column_name, data_type, candidate_type, confidence FROM %i
				 WHERE job_uuid = %s AND confidence >= 60
				 AND candidate_type IN ('probable_scalar_reference','possible_scalar_reference','protected_order_reference')",
				$tornado_sql_discovery,
				$discovery_uuid
			),
			ARRAY_A
		);

		$registered = array();
		foreach ( $this->references->scalar_columns() as $reference ) {
			$registered[ $reference['table'] . '.' . $reference['column'] ] = true;
		}
		// Conditional Adapter ownership is intentionally treated as registered here.
		// Otherwise a polymorphic object_id/element_id column could be incorrectly
		// promoted to an unconditional scalar update by statistical discovery.
		foreach ( $this->adapters->claimed_columns() as $claim ) {
			$registered[ (string) $claim['table'] . '.' . (string) $claim['column'] ] = true;
		}

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$table_name = (string) ( $row['table_name'] ?? '' );
			$column_name = (string) ( $row['column_name'] ?? '' );
			$key = $table_name . '.' . $column_name;
			if ( isset( $registered[ $key ] ) ) {
				continue;
			}

			try {
				$table  = Identifier::quote( $table_name );
				$tornado_sql_table = Identifier::normalize( $table );
				$column = Identifier::quote( $column_name );
				$tornado_sql_column = Identifier::normalize( $column );
			} catch ( \Throwable ) {
				$result['unresolved'][] = array_merge( $row, array( 'reason' => 'شناسه جدول یا ستون از نظر امنیتی معتبر نیست.' ) );
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$matching_rows = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i r INNER JOIN %i m ON m.old_id = r.%i
					 WHERE m.job_uuid = %s AND m.old_id <> m.new_id AND r.%i <> 0",
					$tornado_sql_table,
					$tornado_sql_mapping,
					$tornado_sql_column,
					$tornado_sql_column,
					$mapping_uuid
				)
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$gap_collision_rows = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i r
					 INNER JOIN %i target_map ON r.%i = target_map.new_id AND target_map.job_uuid = %s
					 LEFT JOIN %i source_map ON r.%i = source_map.old_id AND source_map.job_uuid = target_map.job_uuid
					 WHERE target_map.old_id <> target_map.new_id AND source_map.old_id IS NULL AND r.%i <> 0",
					$tornado_sql_table,
					$tornado_sql_mapping,
					$tornado_sql_column,
					$tornado_sql_mapping,
					$tornado_sql_column,
					$tornado_sql_column,
					$mapping_uuid
				)
			);

			$inside_wordpress_scope = str_starts_with( $table_name, $wpdb->prefix );
			$declared_post_fk       = $this->has_declared_post_foreign_key( $table_name, $column_name );
			if ( ! $inside_wordpress_scope && ! $declared_post_fk ) {
				$evidence = array_merge(
					$row,
					array(
						'table'              => $table_name,
						'column'             => $column_name,
						'matching_rows'      => $matching_rows,
						'gap_collision_rows' => $gap_collision_rows,
						'policy'             => 'adapter_required',
					)
				);
				if ( $matching_rows > 0 || $gap_collision_rows > 0 ) {
					$evidence['reason'] = sprintf( 'جدول خارج از پیشوند فعال وردپرس «%s» است و Foreign Key صریح به %s.ID ندارد؛ برای جلوگیری از تغییر جدول نامرتبط، Adapter اختصاصی لازم است.', $wpdb->prefix, $wpdb->posts );
					$result['unresolved'][] = $evidence;
				} else {
					$evidence['reason'] = 'جدول خارج از محدوده پیشوند فعال وردپرس است و با Mapping جاری برخورد ندارد.';
					$result['ignored'][] = $evidence;
				}
				continue;
			}

			$policy = $this->reference_policy( $table_name, $column_name );
			$evidence = array_merge(
				$row,
				array(
					'table'              => $table_name,
					'column'             => $column_name,
					'matching_rows'      => $matching_rows,
					'gap_collision_rows' => $gap_collision_rows,
					'policy'             => $policy,
				)
			);

			if ( 'ignore' === $policy ) {
				if ( 0 === $matching_rows && 0 === $gap_collision_rows ) {
					$evidence['reason'] = 'این Reference با انتخاب مدیر نادیده گرفته شد؛ در Mapping جاری هیچ مقدار درگیر یا برخورد خطرناکی ندارد.';
					$result['ignored'][] = $evidence;
				} else {
					$evidence['reason'] = 'نادیده‌گرفتن این Reference ایمن نیست؛ مقدارهای آن با شناسه‌های در حال تغییر یا شناسه‌های هدف برخورد دارند. گزینه «هماهنگ‌سازی»، «صفرکردن ارتباط» یا «حذف ردیف‌های درگیر» را انتخاب کنید.';
					$result['unresolved'][] = $evidence;
				}
				continue;
			}

			if ( in_array( $policy, array( 'detach', 'delete_rows' ), true ) ) {
				$evidence['reason'] = 'این Reference طبق سیاست انتخاب‌شده پیش از جابه‌جایی شناسه‌ها پاک‌سازی می‌شود و وارد تبدیل عددی Mapping نخواهد شد.';
				$result['managed'][] = $evidence;
				continue;
			}

			if ( 0 === $matching_rows ) {
				$evidence['reason'] = 'هیچ مقدار این ستون با شناسه‌های در حال تغییر Mapping برخورد ندارد.';
				$result['ignored'][] = $evidence;
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$nonzero_rows = (int) $wpdb->get_var(
				 $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE %i <> 0", $tornado_sql_table, $tornado_sql_column ) 
			); 
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$orphan_rows  = (int) $wpdb->get_var(
				 $wpdb->prepare( "SELECT COUNT(*) FROM %i r LEFT JOIN %i p ON p.ID = r.%i WHERE r.%i <> 0 AND p.ID IS NULL", $tornado_sql_table, $tornado_sql_posts, $tornado_sql_column, $tornado_sql_column ) 
			);
			$resolved_rows  = max( 0, $nonzero_rows - $orphan_rows );
			$resolved_ratio = $nonzero_rows > 0 ? $resolved_rows / $nonzero_rows : 0.0;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$type_rows      = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.post_type, COUNT(*) AS matched_rows FROM %i r
					 INNER JOIN %i m ON m.old_id = r.%i AND m.job_uuid = %s AND m.old_id <> m.new_id
					 INNER JOIN %i p ON p.ID = m.old_id
					 WHERE r.%i <> 0 GROUP BY p.post_type",
					$tornado_sql_table,
					$tornado_sql_mapping,
					$tornado_sql_column,
					$tornado_sql_posts,
					$tornado_sql_column,
					$mapping_uuid
				),
				ARRAY_A
			);
			$matched_types = array();
			foreach ( is_array( $type_rows ) ? $type_rows : array() as $type_row ) {
				$matched_types[ (string) $type_row['post_type'] ] = (int) $type_row['matched_rows'];
			}
			$evidence['nonzero_rows']       = $nonzero_rows;
			$evidence['orphan_rows']        = $orphan_rows;
			$evidence['resolved_ratio']     = round( $resolved_ratio, 4 );
			$evidence['matched_post_types'] = $matched_types;

			$is_order       = 'protected_order_reference' === (string) $row['candidate_type'] || preg_match( '/(?:^|_)(?:order|order_parent|parent_order)(?:_?id|_?ids)?$/i', $column_name ) === 1;
			$order_allowed  = (bool) Settings::get( 'allow_order_reindex', false ) && ! $this->is_hpos_enabled();
			$auto_register  = (bool) Settings::get( 'auto_register_discovered_references', true );
			$auto_resolve   = (bool) Settings::get( 'auto_resolve_ambiguous_references', true );
			$semantic_name  = preg_match( '/post|page|product|variation|attachment|template|media|image|thumbnail|object/i', $column_name ) === 1;
			$table_semantic = preg_match( '/post|page|product|variation|attachment|template|media|image|elementor|content/i', $table_name ) === 1;
			$protected_name = preg_match( '/(?:^|_)(?:user|term|comment|customer|author|transaction|payment|coupon|category|taxonomy|site|blog|network)(?:_?id|_?ids)?$/i', $column_name ) === 1;
			$counter_name   = preg_match( '/(?:^|_)(?:count|total|quantity|qty|amount|price|stock|status|type|position|sort|number|index)$/i', $column_name ) === 1;
			$strong_name    = (int) $row['confidence'] >= 80 && 'probable_scalar_reference' === (string) $row['candidate_type'];
			$verified_name  = $strong_name || ( $semantic_name && (int) $row['confidence'] >= 60 );

			if ( $protected_name || $counter_name ) {
				$evidence['reason'] = 'نام ستون نشان می‌دهد این مقدار Post ID نیست؛ هم‌پوشانی عددی با Mapping تصادفی تشخیص داده شد و ستون از Reindex کنار گذاشته شد.';
				$result['ignored'][] = $evidence;
				continue;
			}

			if ( $is_order ) {
				$order_matches = 0;
				foreach ( array( 'shop_order', 'shop_order_refund' ) as $order_type ) {
					$order_matches += (int) ( $matched_types[ $order_type ] ?? 0 );
				}
				if ( 0 === $order_matches ) {
					$evidence['reason'] = 'نام ستون به سفارش شباهت دارد، اما IDهای منطبق متعلق به Post Type سفارش نیستند؛ این مورد برخورد عددی است و نباید تغییر کند.';
					$result['ignored'][] = $evidence;
					continue;
				}
				if ( $order_matches !== $matching_rows ) {
					$evidence['reason'] = 'ستون سفارش شامل ترکیبی از شناسه‌های سفارش و Post Typeهای دیگر است؛ برای این ساختار باید Adapter اختصاصی ثبت شود.';
					$result['unresolved'][] = $evidence;
					continue;
				}
				if ( ! $order_allowed ) {
					$evidence['reason'] = 'این ستون واقعاً به سفارش‌های WooCommerce اشاره می‌کند، اما Reindex سفارش‌ها در تنظیمات فعال نشده است.';
					$result['unresolved'][] = $evidence;
					continue;
				}
				$evidence['reason'] = 'نوع Postهای منطبق، رابطه این ستون را با سفارش‌های WooCommerce تأیید کرد.';
				$result['auto_registered'][] = $evidence;
				continue;
			}

			$expected_types = array();
			if ( preg_match( '/attachment|media|image|thumbnail/i', $column_name ) === 1 ) {
				$expected_types = array( 'attachment' );
			} elseif ( preg_match( '/variation/i', $column_name ) === 1 ) {
				$expected_types = array( 'product_variation' );
			} elseif ( preg_match( '/product/i', $column_name ) === 1 ) {
				$expected_types = array( 'product', 'product_variation' );
			} elseif ( preg_match( '/template/i', $column_name ) === 1 ) {
				$expected_types = array( 'elementor_library', 'wp_template', 'wp_template_part' );
			} elseif ( preg_match( '/(?:^|_)page(?:_?id|_?ids)?$/i', $column_name ) === 1 ) {
				$expected_types = array( 'page' );
			}
			$type_compatible = true;
			if ( ! empty( $expected_types ) && ! empty( $matched_types ) ) {
				foreach ( array_keys( $matched_types ) as $matched_type ) {
					if ( ! in_array( $matched_type, $expected_types, true ) ) {
						$type_compatible = false;
						break;
					}
				}
			}
			$evidence['type_compatible'] = $type_compatible;

			$semantic_confirmation = $verified_name && $type_compatible;
			$statistical_confirmation = $auto_resolve
				&& $type_compatible
				&& $resolved_ratio >= 0.95
				&& ( $semantic_name || $table_semantic )
				&& $matching_rows > 0;

			if ( $auto_register && ( $semantic_confirmation || $statistical_confirmation ) ) {
				$evidence['reason'] = $orphan_rows > 0
					? sprintf( 'نام ستون، Post Typeهای منطبق و شواهد آماری رابطه با %s.ID را تأیید می‌کنند؛ رکوردهای یتیم قدیمی فقط در گزارش ثبت شدند.', $wpdb->posts )
					: sprintf( 'نام ستون، Post Typeهای منطبق و شواهد آماری، رابطه مستقیم با %s.ID را تأیید می‌کنند.', $wpdb->posts );
				$result['auto_registered'][] = $evidence;
				continue;
			}

			if ( ! $type_compatible && $resolved_ratio < 0.80 ) {
				$evidence['reason'] = sprintf( 'نوع Postهای منطبق با معنای ستون سازگار نیست و بخش بزرگی از مقادیر نیز به جدول %s اشاره نمی‌کنند؛ این مورد هم‌پوشانی عددی تشخیص داده شد و از Reindex کنار گذاشته شد.', $wpdb->posts );
				$result['ignored'][] = $evidence;
				continue;
			}
			if ( ! $verified_name && ! $table_semantic && $resolved_ratio < 0.50 ) {
				$evidence['reason'] = sprintf( 'شواهد نام‌گذاری و آماری برای رابطه با %s.ID کافی نیست و برخورد موجود تصادفی تشخیص داده شد.', $wpdb->posts );
				$result['ignored'][] = $evidence;
				continue;
			}

			$evidence['reason'] = ! $type_compatible
				? 'نام ستون با نوع Postهای منطبق سازگار نیست؛ برای جلوگیری از تغییر اشتباه داده، این رابطه نیازمند Adapter اختصاصی است.'
				: 'شواهد موجود برای تشخیص قطعی رابطه کافی نیست. نام جدول، نام ستون و Post Typeهای منطبق را در گزارش بررسی کنید.';
			$result['unresolved'][] = $evidence;
		}

		$this->reference_evidence_cache[ $mapping_uuid ] = $result;
		return $result;
	}

	/**
	 * @return array<string, string>
	 */
	private function involved_table_engines( string $mapping_uuid ): array {
		$wpdb = $this->wpdb;
		$tables = array( $wpdb->posts, $wpdb->postmeta, $wpdb->comments, $wpdb->term_relationships, $wpdb->options );
		foreach ( $this->all_scalar_references( $mapping_uuid ) as $reference ) {
			$tables[] = $reference['table'];
		}
		foreach ( array_keys( $this->references->scalar_meta_keys() ) as $table ) {
			$tables[] = $table;
		}
		foreach ( array_keys( $this->references->csv_meta_keys() ) as $table ) {
			$tables[] = $table;
		}
		foreach ( array_keys( $this->references->serialized_id_list_meta_keys() ) as $table ) {
			$tables[] = $table;
		}
		foreach ( $this->references->conditional_meta_references() as $reference ) {
			$tables[] = $reference['table'];
		}
		foreach ( $this->adapters->involved_tables() as $table ) {
			$tables[] = $table;
		}
		$tables = array_values( array_unique( $tables ) );
		$result = array();

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$engine = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
					(string) DB_NAME,
					$table
				)
			);
			if ( null !== $engine ) {
				$result[ $table ] = (string) $engine;
			}
		}

		return $result;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function reference_coverage( string $mapping_uuid ): array {
		$scalar = $this->all_scalar_references( $mapping_uuid );
		$tables = array();
		foreach ( $scalar as $reference ) {
			$tables[] = (string) $reference['table'];
		}
		foreach ( array_keys( $this->references->scalar_meta_keys() ) as $table ) {
			$tables[] = $table;
		}
		foreach ( array_keys( $this->references->csv_meta_keys() ) as $table ) {
			$tables[] = $table;
		}
		foreach ( array_keys( $this->references->serialized_id_list_meta_keys() ) as $table ) {
			$tables[] = $table;
		}

		return array(
			'scalar_reference_count'     => count( $scalar ),
			'scalar_meta_group_count'    => count( $this->references->scalar_meta_keys() ),
			'conditional_meta_count'     => count( $this->references->conditional_meta_references() ),
			'csv_meta_group_count'       => count( $this->references->csv_meta_keys() ),
			'serialized_group_count'     => count( $this->references->serialized_id_list_meta_keys() ),
			'option_reference_count'     => count( $this->references->option_names() ),
			'involved_table_count'       => count( array_unique( $tables ) ),
			'tables'                     => array_values( array_unique( $tables ) ),
			'scalar_references'          => $scalar,
		);
	}

	private function has_declared_post_foreign_key( string $table, string $column ): bool {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
				 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s
				 AND REFERENCED_TABLE_NAME = %s AND REFERENCED_COLUMN_NAME = %s',
				(string) DB_NAME,
				$table,
				$column,
				$wpdb->posts,
				'ID'
			)
		) > 0;
	}

	/**
	 * @return list<string>
	 */
	private function active_unsupported_plugins(): array {
		$plugins = (array) get_option( 'active_plugins', array() );
		$needles = array(
			'advanced-custom-fields/'      => array( 'label' => 'ACF', 'adapter' => 'acf' ),
			'acf-pro/'                     => array( 'label' => 'ACF Pro', 'adapter' => 'acf' ),
			'sitepress-multilingual-cms/' => array( 'label' => 'WPML', 'adapter' => 'wpml' ),
			'dokan-lite/'                  => array( 'label' => 'Dokan', 'adapter' => 'dokan' ),
			'dokan-pro/'                   => array( 'label' => 'Dokan Pro', 'adapter' => 'dokan' ),
			'seo-by-rank-math/'            => array( 'label' => 'Rank Math', 'adapter' => 'rank-math' ),
		);
		$found = array();
		foreach ( $plugins as $plugin ) {
			foreach ( $needles as $needle => $definition ) {
				if ( str_starts_with( (string) $plugin, $needle ) && ! $this->adapters->has( $definition['adapter'] ) ) {
					$found[] = $definition['label'];
				}
			}
		}
		return array_values( array_unique( $found ) );
	}

	private function is_hpos_enabled(): bool {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			return false;
		}

		try {
			return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		} catch ( \Throwable ) {
			return true;
		}
	}

	private function assert_query_result( int|bool $result, string $error ): void {
		if ( false === $result ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Database exception text is not HTML output.
			throw new RuntimeException( $error . ' Database error: ' . $this->wpdb->last_error );
		}
	}
}
