<?php
/**
 * Elementor, Theme Builder and custom template repair after post-ID reindexing.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Repair;

use Shcd\TornadoDatabaseMaintenance\Core\SafeSerialization;
use Shcd\TornadoDatabaseMaintenance\Core\Settings;
use Shcd\TornadoDatabaseMaintenance\Core\Filesystem;
use Shcd\TornadoDatabaseMaintenance\Database\Identifier;
use Shcd\TornadoDatabaseMaintenance\Database\Tables;
use Shcd\TornadoDatabaseMaintenance\Jobs\JobRepository;
use Shcd\TornadoDatabaseMaintenance\Logging\Logger;
use RuntimeException;

final class ElementorRepairService {
	private const BATCH_SIZE = 250;
	private const MEDIA_SAMPLE_LIMIT = 40;

	private const URL_ONLY_EXAMPLE_LIMIT = 8;

	/** @var array<int,string|false> */
	private array $post_type_cache = array();

	/** @var array<int,string> */
	private array $template_type_cache = array();

	/** @var array<string,int> */
	private array $attachment_url_cache = array();

	private int $attachments_registered = 0;

	private bool $allow_attachment_registration = false;

	private bool $purge_missing_attachment_rows = false;

	private bool $purge_media_cleaner_trash_rows = false;

	/** @var list<int> */
	private array $removed_attachment_ids = array();

	private bool $aggressive_mapping_remap = false;

	private bool $normalizing_saved_document = false;

	private string $mapping_mode = 'historical';

	/** @var list<int> */
	private array $registered_attachment_ids = array();

	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly Tables $tables,
		private readonly JobRepository $jobs,
		private readonly Logger $logger,
		private readonly BuilderRegistryService $builders
	) {}

	/**
	 * Keeps custom header/footer post types editable with Elementor on every request.
	 */
	public function register_runtime_compatibility(): void {
		add_action( 'init', array( $this, 'apply_runtime_compatibility' ), 99 );

		/*
		 * Rebuild only the stylesheet/cache of the document that Elementor has just
		 * saved. The live Image Box guard below is deliberately read-only and does not
		 * rewrite wp_posts, post IDs, meta IDs, or Elementor document data.
		 */
		add_action( 'elementor/editor/after_save', array( $this, 'repair_saved_document_css' ), 1001, 2 );

		/*
		 * Legacy Image Box markup can be present while a theme/Elementor-v4 rule
		 * collapses the image figure in both the editor preview and the frontend. Keep
		 * the compatibility layer scoped to Elementor Image Box widgets only.
		 */
		add_filter( 'elementor/widget/render_content', array( $this, 'ensure_image_box_media_markup' ), 1000, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_image_box_visibility_guard' ), 100 );
	}

	/**
	 * Forces a real per-document CSS rebuild after an Elementor editor save.
	 *
	 * @param array<int|string,mixed> $editor_data Saved Elementor payload.
	 */
	public function repair_saved_document_css( int $post_id, array $editor_data ): void {
		unset( $editor_data );

		if ( $post_id <= 0 || ! class_exists( '\Elementor\Plugin' ) || ! get_post( $post_id ) ) {
			return;
		}

		$errors = array();

		/*
		 * Do not normalize or rewrite _elementor_data automatically on every save.
		 * Media-reference repair remains available through the explicit repair workflow,
		 * while the normal editor-save path stays non-destructive.
		 */
		if ( ! $this->regenerate_document( $post_id, $errors ) ) {
			$this->logger->log(
				'warning',
				'CSS سند ذخیره‌شده Elementor بازسازی نشد.',
				array( 'post_id' => $post_id, 'errors' => $errors )
			);
			return;
		}

		$this->purge_elementor_object_cache( array( $post_id ) );
		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $post_id );
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party integration hook name is defined by the provider.
		do_action( 'litespeed_purge_post', $post_id );
		do_action( 'shcd_tornado_dbm_after_elementor_document_css_rebuild', $post_id );
	}

	/**
	 * Ensures a legacy Image Box still has image markup when its media setting is valid.
	 *
	 * This filter only changes the generated response HTML. It does not update any
	 * WordPress table or Elementor meta value.
	 */
	public function ensure_image_box_media_markup( string $content, object $widget ): string {
		if ( ! method_exists( $widget, 'get_name' ) || 'image-box' !== (string) $widget->get_name() ) {
			return $content;
		}

		if ( preg_match( '/class=(?:\"|\')[^\"\']*elementor-image-box-img[^\"\']*(?:\"|\')/i', $content )
			&& preg_match( '/<img\b/i', $content ) ) {
			return $content;
		}

		if ( ! method_exists( $widget, 'get_settings_for_display' ) ) {
			return $content;
		}

		$settings = $widget->get_settings_for_display();
		if ( ! is_array( $settings ) ) {
			return $content;
		}

		$image = isset( $settings['image'] ) && is_array( $settings['image'] ) ? $settings['image'] : array();
		$id    = absint( $image['id'] ?? 0 );
		$url   = isset( $image['url'] ) && is_string( $image['url'] ) ? trim( $image['url'] ) : '';
		if ( '' === $url && $id > 0 ) {
			$attachment_url = wp_get_attachment_url( $id );
			$url            = is_string( $attachment_url ) ? trim( $attachment_url ) : '';
		}
		if ( '' === $url ) {
			return $content;
		}

		$image_html = '';
		if ( class_exists( '\Elementor\Group_Control_Image_Size' )
			&& is_callable( array( '\Elementor\Group_Control_Image_Size', 'get_attachment_image_html' ) ) ) {
			try {
				$image_html = (string) \Elementor\Group_Control_Image_Size::get_attachment_image_html( $settings, 'image' );
			} catch ( \Throwable ) {
				$image_html = '';
			}
		}

		if ( '' === trim( $image_html ) ) {
			$alt = $id > 0 ? (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) : '';
			if ( '' === trim( $alt ) && isset( $settings['title_text'] ) && is_string( $settings['title_text'] ) ) {
				$alt = wp_strip_all_tags( $settings['title_text'] );
			}
			$image_html = sprintf(
				'<img src="%1$s" alt="%2$s" loading="lazy" decoding="async">',
				esc_url( $url ),
				esc_attr( $alt )
			);
		}

		$link = isset( $settings['link'] ) && is_array( $settings['link'] ) ? $settings['link'] : array();
		if ( isset( $link['url'] ) && is_string( $link['url'] ) && '' !== trim( $link['url'] ) ) {
			$attributes = ' href="' . esc_url( $link['url'] ) . '"';
			if ( ! empty( $link['is_external'] ) ) {
				$attributes .= ' target="_blank"';
			}
			if ( ! empty( $link['nofollow'] ) ) {
				$attributes .= ' rel="nofollow"';
			}
			$image_html = '<a' . $attributes . '>' . $image_html . '</a>';
		}

		$figure = '<figure class="elementor-image-box-img shcd-tornado-dbm-image-box-fallback">' . $image_html . '</figure>';
		$marker = '<div class="elementor-image-box-content">';
		if ( str_contains( $content, $marker ) ) {
			return str_replace( $marker, $figure . $marker, $content );
		}

		return $figure . $content;
	}

	/**
	 * Loads a tiny read-only visibility guard for Elementor Image Box widgets.
	 */
	public function enqueue_image_box_visibility_guard(): void {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return;
		}

		$post_id = get_queried_object_id();
		$is_preview = isset( $_GET['elementor-preview'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$is_built   = false;
		if ( $post_id > 0 && isset( \Elementor\Plugin::$instance->db )
			&& method_exists( \Elementor\Plugin::$instance->db, 'is_built_with_elementor' ) ) {
			$is_built = (bool) \Elementor\Plugin::$instance->db->is_built_with_elementor( $post_id );
		}

		if ( ! $is_preview && ! $is_built ) {
			return;
		}

		wp_enqueue_style(
			'shcd-tornado-dbm-elementor-image-box-guard',
			SHCD_TORNADO_DBM_URL . 'assets/css/elementor-image-box-guard.css',
			array(),
			SHCD_TORNADO_DBM_BUILD
		);
		wp_enqueue_script(
			'shcd-tornado-dbm-elementor-image-box-guard',
			SHCD_TORNADO_DBM_URL . 'assets/js/elementor-image-box-guard.js',
			array(),
			SHCD_TORNADO_DBM_BUILD,
			true
		);
	}

	/**
	 * Normalizes only the Elementor document that has just been saved.
	 *
	 * The editor media control can display its URL preview while the widget renderer
	 * still receives a stale Attachment ID. Core Image Box rendering prefers that ID
	 * and may therefore output no image. Resolve the ID from the saved URL before the
	 * document CSS and caches are rebuilt.
	 *
	 * @param list<string> $errors
	 * @return array<string,int>
	 */
	private function normalize_saved_document_media( int $post_id, array &$errors ): array {
		$wpdb = $this->wpdb;
		$stats = array(
			'rows_checked'             => 0,
			'rows_updated'             => 0,
			'references_updated'       => 0,
			'media_objects_checked'    => 0,
			'media_ids_remapped'       => 0,
			'media_recovered_by_url'   => 0,
			'media_urls_synchronized'  => 0,
			'media_recovered'          => 0,
			'media_stale_ids_cleared'  => 0,
			'media_url_only_preserved' => 0,
		);

		if ( $this->normalizing_saved_document || $post_id <= 0 ) {
			return $stats;
		}

		$this->normalizing_saved_document = true;
		$this->attachment_url_cache       = array();

		try {
			$table = Identifier::quote( $wpdb->postmeta );
			$tornado_sql_table = Identifier::normalize( $table );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_id, meta_key, meta_value FROM %i
					 WHERE post_id = %d AND meta_key IN (%s, %s)
					 ORDER BY meta_id ASC",
					$tornado_sql_table,
					$post_id,
					'_elementor_data',
					'_elementor_page_settings'
				),
				ARRAY_A
			);

			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$meta_id  = absint( $row['meta_id'] ?? 0 );
				$meta_key = (string) ( $row['meta_key'] ?? '' );
				$raw      = (string) ( $row['meta_value'] ?? '' );
				if ( $meta_id <= 0 || '' === $meta_key || '' === trim( $raw ) ) {
					continue;
				}
				++$stats['rows_checked'];

				$format = 'json';
				$value  = json_decode( $raw, true, 512, JSON_BIGINT_AS_STRING );
				if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $value ) ) {
					$value  = SafeSerialization::maybe_unserialize( $raw );
					$format = 'serialized';
				}
				if ( ! is_array( $value ) ) {
					$errors[] = sprintf( 'ساختار %1$s سند Elementor با ID %2$d قابل خواندن نبود.', $meta_key, $post_id );
					continue;
				}

				$before = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				$this->remap_semantic_value( $value, $meta_key, null, array(), $stats );
				$after = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				if ( $before === $after ) {
					continue;
				}

				$new_raw = 'json' === $format
					? wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
					: maybe_serialize( $value );
				if ( ! is_string( $new_raw ) ) {
					$errors[] = sprintf( 'ساختار %1$s سند Elementor با ID %2$d بازنویسی نشد.', $meta_key, $post_id );
					continue;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
				$updated = $wpdb->update(
					$wpdb->postmeta,
					array( 'meta_value' => $new_raw ),
					array( 'meta_id' => $meta_id ),
					array( '%s' ),
					array( '%d' )
				);
				if ( false === $updated ) {
					$errors[] = sprintf( 'ساختار %1$s سند Elementor با ID %2$d ذخیره نشد.', $meta_key, $post_id );
					continue;
				}
				$stats['rows_updated'] += (int) $updated;
			}
		} finally {
			$this->normalizing_saved_document = false;
		}

		if ( $stats['rows_updated'] > 0 ) {
			clean_post_cache( $post_id );
			wp_cache_delete( $post_id, 'post_meta' );
		}

		return $stats;
	}

	public function apply_runtime_compatibility(): void {
		$types = $this->builders->elementor_post_types();
		if ( empty( $types ) ) {
			$legacy = get_option( 'shcd_tornado_dbm_elementor_template_post_types', array() );
			$types  = is_array( $legacy ) ? $legacy : array();
		}

		foreach ( array_values( array_unique( array_filter( array_map( 'sanitize_key', $types ) ) ) ) as $post_type ) {
			if ( ! post_type_exists( $post_type ) ) {
				continue;
			}
			add_post_type_support( $post_type, 'elementor' );
			add_post_type_support( $post_type, 'editor' );
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	public function preflight(): array {
		$active = class_exists( '\Elementor\Plugin' );
		if ( ! $active ) {
			return array(
				'active'       => false,
				'supported'    => true,
				'print_method' => 'not_applicable',
				'writable'     => true,
				'message'      => 'Elementor فعال نیست و مرحله تعمیر لازم نخواهد بود.',
			);
		}

		$plugin             = \Elementor\Plugin::instance();
		$clear_supported    = isset( $plugin->files_manager ) && method_exists( $plugin->files_manager, 'clear_cache' );
		$generate_supported = isset( $plugin->files_manager ) && method_exists( $plugin->files_manager, 'generate_css' );
		$legacy_supported   = class_exists( '\\Elementor\\Core\\Files\\CSS\\Post' );
		$print_method       = sanitize_key( (string) ExternalOptionBridge::get( ExternalOptionBridge::ELEMENTOR_CSS_PRINT_METHOD, 'external' ) );
		$uploads            = wp_upload_dir();
		$base_dir           = isset( $uploads['basedir'] ) ? wp_normalize_path( (string) $uploads['basedir'] ) : '';
		$css_dir            = '' !== $base_dir ? trailingslashit( $base_dir ) . 'elementor/css' : '';
		$writable           = 'internal' === $print_method || $this->directory_is_writable( $css_dir );
		$mapping_uuid       = $this->resolve_mapping_uuid( null );
		$builder_registry   = $this->builders->list_registry();
		$custom_types       = $this->discover_custom_template_post_types();

		return array(
			'active'                     => true,
			'supported'                  => $clear_supported && ( $generate_supported || $legacy_supported ),
			'clear_supported'            => $clear_supported,
			'generate_supported'         => $generate_supported,
			'legacy_supported'           => $legacy_supported,
			'print_method'               => $print_method,
			'css_directory'              => $css_dir,
			'writable'                   => $writable,
			'active_kit_id'              => absint( get_option( ExternalOptionBridge::ELEMENTOR_ACTIVE_KIT, 0 ) ),
			'custom_logo_id'             => absint( get_theme_mod( 'custom_logo', 0 ) ),
			'site_icon_id'               => absint( get_option( ExternalOptionBridge::WORDPRESS_SITE_ICON, 0 ) ),
			'elementor_version'          => defined( 'ELEMENTOR_VERSION' ) ? (string) ELEMENTOR_VERSION : '',
			'elementor_pro_active'       => defined( 'ELEMENTOR_PRO_VERSION' ),
			'mapping_uuid'               => $mapping_uuid,
			'smart_media_recovery'        => true,
			'media_policy'                => 'url_only_without_attachment',
			'restore_missing_attachments_from_uploads' => (bool) Settings::get( 'restore_missing_attachments_from_uploads', false ),
			'purge_missing_attachment_rows_before_reindex' => (bool) Settings::get( 'purge_missing_attachment_rows_before_reindex', true ),
			'media_recovery_sources'      => array( 'mapping', 'attachment_url', '_wp_attached_file', 'guid' ),
			'custom_template_post_types' => $custom_types,
			'builder_registry'            => $builder_registry,
		);
	}

	/**
	 * Repairs persistent post-ID references first, then rebuilds derived Elementor data.
	 *
	 * @return array<string,mixed>
	 */
	public function repair( ?string $mapping_uuid = null, bool $allow_attachment_registration = false, string $mapping_mode = 'historical', bool $purge_missing_attachment_rows = false ): array {
		$this->mapping_mode = 'current_commit' === sanitize_key( $mapping_mode ) ? 'current_commit' : 'historical';
		$this->allow_attachment_registration = $allow_attachment_registration
			&& (bool) Settings::get( 'restore_missing_attachments_from_uploads', false )
			&& 'historical' === $this->mapping_mode;
		$this->purge_missing_attachment_rows = $purge_missing_attachment_rows
			&& (bool) Settings::get( 'purge_missing_attachment_rows_before_reindex', true )
			&& 'historical' === $this->mapping_mode;
		$this->purge_media_cleaner_trash_rows = $purge_missing_attachment_rows
			&& (bool) Settings::get( 'purge_media_cleaner_trash_before_reindex', true )
			&& 'historical' === $this->mapping_mode;
		/*
		 * Repair must be idempotent. The two-phase Transaction already applies the
		 * current Mapping to registered scalar and structured references. Reapplying
		 * the same Mapping after COMMIT is unsafe because a new ID can also exist as an
		 * old key and would be translated twice. Mapping is therefore fallback-only
		 * when the current target is invalid or URL/path evidence proves the identity.
		 */
		$this->aggressive_mapping_remap = false;
		$this->attachment_url_cache      = array();
		$this->attachments_registered    = 0;
		$this->registered_attachment_ids = array();
		$this->removed_attachment_ids    = array();
		$mapping_uuid = $this->resolve_mapping_uuid( $mapping_uuid );
		$job_uuid     = $this->jobs->create(
			'elementor_repair',
			array( 'mapping_uuid' => $mapping_uuid )
		);

		try {
			$stale_attachment_cleanup = $this->purge_missing_local_attachment_rows();
			$builder_registry = $this->builders->scan();
			$preflight        = $this->preflight();
			if ( empty( $preflight['active'] ) ) {
				$reference_errors = array();
				$map    = null !== $mapping_uuid ? $this->load_mapping( $mapping_uuid ) : array();
				$this->jobs->progress( $job_uuid, 'repairing_registered_builders', 20.0 );
				$reference_repair = array(
					'mapping_available'   => ! empty( $map ),
					'mapping_uuid'        => $mapping_uuid,
					'mapping_mode'        => $this->mapping_mode,
					'elementor_meta'      => $this->repair_elementor_meta( $map, $reference_errors ),
					'theme_assignments'   => $this->repair_semantic_assignments( $map, $reference_errors ),
					'registered_builders' => $this->repair_registered_builder_assignments( $map, $reference_errors ),
					'site_identity'       => $this->repair_site_identity( $map, $reference_errors ),
				);
				$media_verification = $this->verify_elementor_media_references( $map );
				$warnings = array();
				if ( (int) ( $media_verification['advisory_unresolved_count'] ?? 0 ) > 0 ) {
					$warnings[] = sprintf(
						'%d Reference رسانه‌ای مربوط به فایل‌های از قبل مفقودشده به‌عنوان خط مبنا ثبت شد و مانع Reindex نیست.',
						(int) $media_verification['advisory_unresolved_count']
					);
				}
				if ( ! empty( $reference_repair['site_identity']['invalid_logo_removed'] ) ) {
					$warnings[] = 'شناسه خراب Site Logo حذف شد تا Warning مربوط به site-logo.php متوقف شود؛ در صورت نبود لوگوی قابل بازیابی، Site Logo را دوباره انتخاب کنید.';
				}
				$builder_caches = $this->purge_registered_builder_caches();
				do_action( 'shcd_tornado_dbm_after_custom_builder_repair', $mapping_uuid, $builder_registry, $reference_repair );
				$result = array(
					'uuid'             => $job_uuid,
					'available'        => true,
					'success'                      => empty( $reference_errors ) && ! empty( $media_verification['safe_for_reindex'] ),
					'elementor_active'             => false,
					'message'                      => 'Elementor فعال نیست؛ Referenceهای رسانه و سازنده‌های اختصاصی بدون اجرای APIهای CSS ترمیم شدند.',
					'builder_registry'             => $builder_registry,
					'stale_attachment_cleanup'      => $stale_attachment_cleanup,
					'reference_repair'             => $reference_repair,
					'media_verification'           => $media_verification,
					'media_attachments_registered' => $this->attachments_registered,
					'registered_attachment_ids'    => array_slice( $this->registered_attachment_ids, 0, self::MEDIA_SAMPLE_LIMIT ),
					'builder_caches'               => $builder_caches,
					'warnings'                     => array_values( array_unique( $warnings ) ),
					'critical_errors'              => array_values( array_unique( $reference_errors ) ),
					'advisory_errors'              => array(),
					'errors'                       => array_values( array_unique( $reference_errors ) ),
				);
				$this->jobs->complete( $job_uuid, $result );
				$this->logger->log( ! empty( $result['success'] ) ? 'info' : 'warning', 'تعمیر Referenceهای رسانه و سازنده‌های اختصاصی بدون وابستگی به Elementor پایان یافت.', $result, $job_uuid );
				return $result;
			}

			if ( empty( $preflight['supported'] ) ) {
				throw new RuntimeException( 'نسخه فعال Elementor، API لازم برای پاک‌سازی و بازسازی CSS را در اختیار افزونه قرار نمی‌دهد.' );
			}
			if ( empty( $preflight['writable'] ) ) {
				throw new RuntimeException( 'پوشه CSS مربوط به Elementor قابل نوشتن نیست. سطح دسترسی wp-content/uploads/elementor/css را اصلاح کنید.' );
			}

			$reference_errors = array();
			$builder_warnings = array();
			$css_warnings     = array();
			$warnings         = array();
			$map      = null !== $mapping_uuid ? $this->load_mapping( $mapping_uuid ) : array();

			$this->jobs->progress( $job_uuid, 'repairing_elementor_references', 5.0 );
			$reference_repair = array(
				'mapping_available'       => ! empty( $map ),
				'mapping_uuid'            => $mapping_uuid,
				'mapping_mode'            => $this->mapping_mode,
				'elementor_meta'          => $this->repair_elementor_meta( $map, $reference_errors ),
				'theme_builder_templates' => $this->repair_theme_builder_templates( $map, $reference_errors ),
				'custom_template_types'   => $this->repair_custom_template_post_types( $builder_warnings ),
				'theme_assignments'       => $this->repair_semantic_assignments( $map, $reference_errors ),
				'registered_builders'     => $this->repair_registered_builder_assignments( $map, $reference_errors ),
			);
			$reference_repair['site_identity'] = $this->repair_site_identity( $map, $reference_errors );

			$this->jobs->progress( $job_uuid, 'clearing_elementor_cache', 25.0 );
			$plugin       = \Elementor\Plugin::instance();
			$document_ids = $this->document_ids();
			$generated    = 0;
			$method       = 'legacy_per_document';

			try {
				$plugin->files_manager->clear_cache();
			} catch ( \Throwable $throwable ) {
				$css_warnings[] = 'فایل‌ها و Cache مربوط به Elementor پاک نشدند؛ این مورد مانع اصلاح Referenceهای دیتابیس نیست: ' . sanitize_text_field( $throwable->getMessage() );
			}

			$this->jobs->progress( $job_uuid, 'regenerating_elementor_css', 40.0 );
			$active_kit_id = absint( get_option( ExternalOptionBridge::ELEMENTOR_ACTIVE_KIT, 0 ) );
			if ( $active_kit_id > 0 && get_post( $active_kit_id ) ) {
				$this->regenerate_document( $active_kit_id, $css_warnings ) && ++$generated;
			}

			if ( method_exists( $plugin->files_manager, 'generate_css' ) ) {
				$method = 'elementor_generate_css_plus_per_document_verification';
				try {
					$plugin->files_manager->generate_css();
				} catch ( \Throwable $throwable ) {
					$css_warnings[] = 'بازسازی گروهی CSS با API رسمی Elementor انجام نشد؛ بازسازی قطعی سندبه‌سند ادامه یافت: ' . sanitize_text_field( $throwable->getMessage() );
					$method = 'fallback_per_document';
				}

				/*
				 * generate_css() may only invalidate files and defer their actual creation
				 * until a later uncached frontend request. Never report every document as
				 * generated merely because that method returned without an exception.
				 */
				$generated = 0;
				foreach ( $document_ids as $post_id ) {
					$this->regenerate_document( $post_id, $css_warnings ) && ++$generated;
				}
			} else {
				foreach ( $document_ids as $post_id ) {
					$this->regenerate_document( $post_id, $css_warnings ) && ++$generated;
				}
			}

			$this->jobs->progress( $job_uuid, 'rebuilding_theme_builder_conditions', 70.0 );
			$theme_builder     = $this->clear_theme_builder_conditions();
			$verification      = $this->verify_css_files( $document_ids );
			$documents         = $this->verify_custom_template_documents();
			$media_verification = $this->verify_elementor_media_references( $map );
			if ( (int) ( $media_verification['blocking_unresolved_count'] ?? 0 ) > 0 ) {
				$warnings[] = sprintf(
					'%d Reference رسانه‌ای بدون هویت قابل اثبات باقی مانده است و اجرای Reindex را مسدود می‌کند.',
					(int) $media_verification['blocking_unresolved_count']
				);
			}
			if ( (int) ( $media_verification['advisory_unresolved_count'] ?? 0 ) > 0 ) {
				$warnings[] = sprintf(
					'%d Reference رسانه‌ای به فایل داخلی از قبل مفقودشده اشاره می‌کند. این موارد در Baseline ثبت می‌شوند و فقط در صورت افزایش، Reindex را متوقف خواهند کرد.',
					(int) $media_verification['advisory_unresolved_count']
				);
			}
			if ( ! empty( $reference_repair['site_identity']['invalid_logo_removed'] ) ) {
				$warnings[] = 'شناسه خراب Site Logo حذف شد تا Warning مربوط به site-logo.php متوقف شود؛ در صورت نبود لوگوی قابل بازیابی، Site Logo را دوباره انتخاب کنید.';
			}
			$warnings = array_merge( $warnings, $builder_warnings, $css_warnings );
			$this->purge_elementor_object_cache( $document_ids );
			$builder_caches = $this->purge_registered_builder_caches();
			$this->jobs->progress( $job_uuid, 'verifying_elementor_repair', 90.0 );

			do_action( 'shcd_tornado_dbm_after_custom_builder_repair', $mapping_uuid, $builder_registry, $reference_repair );
			do_action( 'shcd_tornado_dbm_after_elementor_repair', $mapping_uuid, $document_ids );

			$result = array(
				'uuid'               => $job_uuid,
				'available'          => true,
				'success'            => empty( $reference_errors ) && ! empty( $media_verification['safe_for_reindex'] ),
				'mapping_uuid'       => $mapping_uuid,
				'reference_repair'   => $reference_repair,
				'documents_detected' => count( $document_ids ),
				'css_generated'      => $generated,
				'generation_method'  => $method,
				'active_kit_id'      => $active_kit_id,
				'theme_builder'      => $theme_builder,
				'custom_documents'   => $documents,
				'builder_registry'    => $builder_registry,
				'stale_attachment_cleanup' => $stale_attachment_cleanup,
				'builder_caches'      => $builder_caches,
				'verification'       => $verification,
				'media_verification'            => $media_verification,
				'media_attachments_registered'   => $this->attachments_registered,
				'registered_attachment_ids'       => array_slice( $this->registered_attachment_ids, 0, self::MEDIA_SAMPLE_LIMIT ),
				'warnings'           => array_values( array_unique( $warnings ) ),
				'critical_errors'    => array_values( array_unique( $reference_errors ) ),
				'advisory_errors'    => array_values( array_unique( array_merge( $builder_warnings, $css_warnings ) ) ),
				'errors'             => array_values( array_unique( array_merge( $reference_errors, $builder_warnings, $css_warnings ) ) ),
				'preflight'          => $preflight,
			);
			$this->jobs->complete( $job_uuid, $result );
			$this->logger->log( ! empty( $result['success'] ) ? 'info' : 'warning', 'ترمیم Referenceهای رسانه، Theme Builder، Header/Footer و CSSهای Elementor پایان یافت.', $result, $job_uuid );
			return $result;
		} catch ( \Throwable $throwable ) {
			$message = sanitize_text_field( $throwable->getMessage() );
			$this->jobs->fail( $job_uuid, $message, 'elementor_repair_failed' );
			$this->logger->log( 'error', 'ترمیم Elementor کامل نشد.', array( 'error' => $message, 'mapping_uuid' => $mapping_uuid ), $job_uuid );
			throw $throwable;
		}
	}

	private function resolve_mapping_uuid( ?string $mapping_uuid ): ?string {
		$candidates = array(
			$mapping_uuid,
			(string) get_option( 'shcd_tornado_dbm_last_executed_mapping_uuid', '' ),
			(string) get_option( 'shcd_tornado_dbm_last_mapping_uuid', '' ),
		);

		foreach ( $candidates as $candidate ) {
			$candidate = is_string( $candidate ) ? trim( $candidate ) : '';
			if ( '' !== $candidate && wp_is_uuid( $candidate ) && $this->mapping_exists( $candidate ) ) {
				return $candidate;
			}
		}
		return null;
	}

	private function mapping_exists( string $mapping_uuid ): bool {
		$wpdb = $this->wpdb;
		$table = Identifier::quote( $this->tables->mappings() );
		$tornado_sql_table = Identifier::normalize( $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE job_uuid = %s",
				$tornado_sql_table,
				$mapping_uuid
			)
		);
		return $count > 0;
	}

	/** @return array<int,int> */
	private function load_mapping( string $mapping_uuid ): array {
		$wpdb = $this->wpdb;
		$table  = Identifier::quote( $this->tables->mappings() );
		$tornado_sql_table = Identifier::normalize( $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT old_id, new_id FROM %i WHERE job_uuid = %s AND old_id <> new_id ORDER BY old_id ASC",
				$tornado_sql_table,
				$mapping_uuid
			),
			ARRAY_A
		);
		$result = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$old = (int) ( $row['old_id'] ?? 0 );
			$new = (int) ( $row['new_id'] ?? 0 );
			if ( $old > 0 && $new > 0 ) {
				$result[ $old ] = $new;
			}
		}
		return $result;
	}

	/**
	 * @param array<int,int> $map
	 * @param list<string>    $errors
	 * @return array<string,int>
	 */
	private function repair_elementor_meta( array $map, array &$errors ): array {
		$wpdb = $this->wpdb;
		$stats = array(
			'rows_checked'            => 0,
			'rows_updated'            => 0,
			'references_updated'      => 0,
			'media_objects_checked'   => 0,
			'media_ids_remapped'      => 0,
			'media_recovered_by_url'  => 0,
			'media_urls_synchronized' => 0,
			'media_recovered'         => 0,
			'media_stale_ids_cleared' => 0,
			'media_url_only_preserved' => 0,
		);

		$table  = Identifier::normalize( $wpdb->postmeta );
		$cursor = 0;
		while ( true ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_id, post_id, meta_key, meta_value FROM %i
					 WHERE meta_id > %d AND meta_key IN (%s, %s)
					 ORDER BY meta_id ASC LIMIT %d",
					$tornado_sql_table,
					$cursor,
					'_elementor_data',
					'_elementor_page_settings',
					self::BATCH_SIZE
				),
				ARRAY_A
			);
			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$cursor = (int) $row['meta_id'];
				++$stats['rows_checked'];
				$raw    = (string) $row['meta_value'];
				$key    = (string) $row['meta_key'];
				$format = 'json';
				$value  = json_decode( $raw, true, 512, JSON_BIGINT_AS_STRING );

				if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $value ) ) {
					$value  = SafeSerialization::maybe_unserialize( $raw );
					$format = 'serialized';
				}
				if ( ! is_array( $value ) ) {
					$errors[] = sprintf( 'ساختار %1$s در meta_id=%2$d قابل خواندن نبود و بدون تغییر باقی ماند.', $key, $cursor );
					continue;
				}

				$before = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				$this->remap_semantic_value( $value, $key, null, $map, $stats );
				$after = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				if ( $before === $after ) {
					continue;
				}

				$new_raw = 'json' === $format
					? wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
					: maybe_serialize( $value );
				if ( ! is_string( $new_raw ) ) {
					$errors[] = sprintf( 'مقدار %1$s در meta_id=%2$d بازنویسی نشد.', $key, $cursor );
					continue;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
				$changed = $wpdb->update(
					$wpdb->postmeta,
					array( 'meta_value' => $new_raw ),
					array( 'meta_id' => $cursor ),
					array( '%s' ),
					array( '%d' )
				);
				if ( false === $changed ) {
					$errors[] = sprintf( 'تغییرات %1$s در meta_id=%2$d ذخیره نشد.', $key, $cursor );
					continue;
				}
				$stats['rows_updated'] += (int) $changed;
				wp_cache_delete( absint( $row['post_id'] ?? 0 ), 'post_meta' );
			}
		}
		return $stats;
	}

	/**
	 * @param array<int,int> $map
	 * @param list<string>    $errors
	 * @return array<string,int>
	 */
	private function repair_theme_builder_templates( array $map, array &$errors ): array {
		$wpdb = $this->wpdb;
		$stats = array(
			'templates_checked'   => 0,
			'conditions_updated'  => 0,
			'locations_restored'  => 0,
			'types_restored'      => 0,
			'cache_rows_removed'  => 0,
		);

		$posts = Identifier::quote( $wpdb->posts );
		$tornado_sql_posts = Identifier::normalize( $posts );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$ids   = $wpdb->get_col(
			 $wpdb->prepare( "SELECT ID FROM %i WHERE post_type = 'elementor_library' AND post_status NOT IN ('trash','auto-draft') ORDER BY ID ASC", $tornado_sql_posts ) 
		);

		foreach ( is_array( $ids ) ? $ids : array() as $raw_id ) {
			$post_id = absint( $raw_id );
			if ( $post_id <= 0 ) {
				continue;
			}
			++$stats['templates_checked'];

			$conditions = get_post_meta( $post_id, '_elementor_conditions', true );
			if ( ! empty( $conditions ) && ! empty( $map ) ) {
				$updated_conditions = $conditions;
				$this->remap_condition_value( $updated_conditions, $map, $stats );
				if ( maybe_serialize( $conditions ) !== maybe_serialize( $updated_conditions ) ) {
					if ( false === update_post_meta( $post_id, '_elementor_conditions', $updated_conditions ) ) {
						$errors[] = sprintf( 'Display Conditions قالب Elementor با ID %d ذخیره نشد.', $post_id );
					} else {
						++$stats['conditions_updated'];
					}
				}
			}

			$template_type = sanitize_key( (string) get_post_meta( $post_id, '_elementor_template_type', true ) );
			if ( '' === $template_type ) {
				$template_type = $this->infer_elementor_template_type( $post_id );
				if ( '' !== $template_type ) {
					update_post_meta( $post_id, '_elementor_template_type', $template_type );
					++$stats['types_restored'];
				}
			}

			$location_map = array(
				'header'      => 'header',
				'footer'      => 'footer',
				'single'      => 'single',
				'single-post' => 'single',
				'single-page' => 'single',
				'archive'     => 'archive',
				'popup'       => 'popup',
			);
			$location = sanitize_key( (string) get_post_meta( $post_id, '_elementor_location', true ) );
			if ( '' === $location && isset( $location_map[ $template_type ] ) ) {
				update_post_meta( $post_id, '_elementor_location', $location_map[ $template_type ] );
				++$stats['locations_restored'];
			}
			clean_post_cache( $post_id );
		}

		$stats['cache_rows_removed'] = $this->delete_theme_builder_cache_rows();
		return $stats;
	}

	private function infer_elementor_template_type( int $post_id ): string {
		$terms = wp_get_object_terms( $post_id, 'elementor_library_type', array( 'fields' => 'slugs' ) );
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return '';
		}
		foreach ( $terms as $term ) {
			$type = sanitize_key( (string) $term );
			if ( in_array( $type, array( 'header', 'footer', 'single', 'single-post', 'single-page', 'archive', 'popup', 'kit' ), true ) ) {
				return $type;
			}
		}
		return '';
	}

	/**
	 * @param array<int,int> $map
	 * @param array<string,int> $stats
	 */
	private function remap_condition_value( mixed &$value, array $map, array &$stats ): void {
		if ( is_array( $value ) ) {
			foreach ( $value as &$item ) {
				$this->remap_condition_value( $item, $map, $stats );
			}
			unset( $item );
			return;
		}
		if ( ! is_string( $value ) ) {
			return;
		}

		$value = (string) preg_replace_callback(
			'~(?<=/)(\d+)(?=/|$)~',
			function ( array $matches ) use ( $map, &$stats ): string {
				$old = (int) $matches[1];
				if ( isset( $map[ $old ] ) && $this->post_exists( $map[ $old ] ) && ( $this->aggressive_mapping_remap || ! $this->post_exists( $old ) ) ) {
					++$stats['conditions_updated'];
					return (string) $map[ $old ];
				}
				return $matches[1];
			},
			$value
		);
	}

	/**
	 * @param list<string> $errors
	 * @return array<string,mixed>
	 */
	private function repair_custom_template_post_types( array &$errors ): array {
		$wpdb = $this->wpdb;
		$types     = $this->discover_custom_template_post_types();
		$supported = get_option( ExternalOptionBridge::ELEMENTOR_CPT_SUPPORT, array( 'post', 'page' ) );
		$supported = is_array( $supported ) ? array_values( array_filter( array_map( 'sanitize_key', $supported ) ) ) : array( 'post', 'page' );
		$changed   = false;
		$documents = 0;
		$edit_mode = 0;

		foreach ( $types as $post_type ) {
			if ( ! in_array( $post_type, $supported, true ) ) {
				$supported[] = $post_type;
				$changed     = true;
			}
			if ( post_type_exists( $post_type ) ) {
				add_post_type_support( $post_type, 'elementor' );
				add_post_type_support( $post_type, 'editor' );
			}

			$posts = Identifier::quote( $wpdb->posts );
			$tornado_sql_posts = Identifier::normalize( $posts );
			$meta  = Identifier::quote( $wpdb->postmeta );
			$tornado_sql_meta = Identifier::normalize( $meta );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$ids   = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT p.ID FROM %i p
					 INNER JOIN %i d ON d.post_id = p.ID AND d.meta_key = '_elementor_data'
					 WHERE p.post_type = %s AND p.post_status NOT IN ('trash','auto-draft')",
					$tornado_sql_posts,
					$tornado_sql_meta,
					$post_type
				)
			);
			foreach ( is_array( $ids ) ? $ids : array() as $raw_id ) {
				$post_id = absint( $raw_id );
				++$documents;
				if ( '' === (string) get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
					if ( false === update_post_meta( $post_id, '_elementor_edit_mode', 'builder' ) ) {
						$errors[] = sprintf( 'Elementor Editor برای %1$s با ID %2$d فعال نشد.', $post_type, $post_id );
					} else {
						++$edit_mode;
					}
				}
				clean_post_cache( $post_id );
			}
		}

		if ( $changed ) {
			ExternalOptionBridge::update( ExternalOptionBridge::ELEMENTOR_CPT_SUPPORT, array_values( array_unique( $supported ) ) );
		}
		update_option( 'shcd_tornado_dbm_elementor_template_post_types', $types, false );

		return array(
			'post_types'              => $types,
			'elementor_cpt_support'   => array_values( array_unique( $supported ) ),
			'cpt_setting_updated'     => $changed,
			'documents_detected'      => $documents,
			'edit_mode_rows_restored' => $edit_mode,
		);
	}

	/** @return list<string> */
	private function discover_custom_template_post_types(): array {
		$wpdb = $this->wpdb;
		$types = $this->builders->elementor_post_types();
		$posts = Identifier::quote( $wpdb->posts );
		$tornado_sql_posts = Identifier::normalize( $posts );
		$meta  = Identifier::quote( $wpdb->postmeta );
		$tornado_sql_meta = Identifier::normalize( $meta );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$rows  = $wpdb->get_col(
			 $wpdb->prepare( "SELECT DISTINCT p.post_type FROM %i p
			 INNER JOIN %i pm ON pm.post_id = p.ID AND pm.meta_key = '_elementor_data'
			 WHERE p.post_type <> 'elementor_library'", $tornado_sql_posts, $tornado_sql_meta ) 
		);
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$post_type = sanitize_key( (string) $row );
			if ( 'elementor' === $this->builder_editor_for_post_type( $post_type ) ) {
				$types[] = $post_type;
			}
		}
		$types = apply_filters( 'shcd_tornado_dbm_elementor_custom_template_post_types', $types );
		$types = is_array( $types ) ? $types : array();
		return array_values( array_unique( array_filter( array_map( 'sanitize_key', $types ) ) ) );
	}

	private function builder_editor_for_post_type( string $post_type ): string {
		foreach ( $this->builders->enabled_entries() as $entry ) {
			if ( sanitize_key( (string) ( $entry['post_type'] ?? '' ) ) === $post_type ) {
				return (string) ( $entry['editor'] ?? 'native' );
			}
		}
		return 'native';
	}

	/**
	 * Repairs exact assignment keys registered for custom theme/plugin builders. This
	 * covers builders whose slugs and option names do not contain header/footer words.
	 *
	 * @param array<int,int> $map
	 * @param list<string>    $errors
	 * @return array<string,mixed>
	 */
	private function repair_registered_builder_assignments( array $map, array &$errors ): array {
		$wpdb = $this->wpdb;
		$stats = array(
			'builders_checked'   => 0,
			'options_checked'    => 0,
			'options_updated'    => 0,
			'postmeta_checked'   => 0,
			'postmeta_updated'   => 0,
			'references_updated'=> 0,
			'entries'            => array(),
		);
		if ( empty( $map ) ) {
			return $stats;
		}

		foreach ( $this->builders->enabled_entries() as $entry ) {
			$post_type = sanitize_key( (string) ( $entry['post_type'] ?? '' ) );
			if ( '' === $post_type ) {
				continue;
			}
			++$stats['builders_checked'];
			$entry_stats = array(
				'post_type'          => $post_type,
				'role'               => (string) ( $entry['role'] ?? 'unknown' ),
				'options_updated'    => 0,
				'postmeta_updated'   => 0,
				'references_updated'=> 0,
			);

			foreach ( (array) ( $entry['option_keys'] ?? array() ) as $option_key ) {
				$option_key = trim( sanitize_text_field( (string) $option_key ) );
				if ( '' === $option_key || str_starts_with( $option_key, 'shcd_tornado_dbm_' ) ) {
					continue;
				}
				$table = Identifier::quote( $wpdb->options );
				$tornado_sql_table = Identifier::normalize( $table );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
				$row   = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT option_id, option_value FROM %i WHERE option_name = %s LIMIT 1",
						$tornado_sql_table,
						$option_key
					),
					ARRAY_A
				);
				if ( ! is_array( $row ) ) {
					continue;
				}
				++$stats['options_checked'];
				$changed = $this->repair_registered_assignment_value( (string) $row['option_value'], $entry, $map, $entry_stats );
				if ( null === $changed ) {
					continue;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
				$updated = $wpdb->update(
					$wpdb->options,
					array( 'option_value' => $changed ),
					array( 'option_id' => absint( $row['option_id'] ) ),
					array( '%s' ),
					array( '%d' )
				);
				if ( false === $updated ) {
					$errors[] = sprintf( 'Option ثبت‌شده %1$s برای Builder نوع %2$s ترمیم نشد.', $option_key, $post_type );
				} else {
					$stats['options_updated'] += (int) $updated;
					$entry_stats['options_updated'] += (int) $updated;
					wp_cache_delete( $option_key, 'options' );
				}
			}

			foreach ( (array) ( $entry['meta_keys'] ?? array() ) as $meta_key ) {
				$meta_key = trim( sanitize_text_field( (string) $meta_key ) );
				if ( '' === $meta_key || in_array( $meta_key, array( '_elementor_data', '_elementor_page_settings', '_elementor_conditions' ), true ) ) {
					continue;
				}
				$cursor = 0;
				$table  = Identifier::quote( $wpdb->postmeta );
				$tornado_sql_table = Identifier::normalize( $table );
				while ( true ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
					$rows = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT meta_id, post_id, meta_value FROM %i WHERE meta_id > %d AND meta_key = %s ORDER BY meta_id ASC LIMIT %d",
							$tornado_sql_table,
							$cursor,
							$meta_key,
							self::BATCH_SIZE
						),
						ARRAY_A
					);
					if ( empty( $rows ) ) {
						break;
					}
					foreach ( $rows as $row ) {
						$cursor = absint( $row['meta_id'] ?? 0 );
						++$stats['postmeta_checked'];
						$changed = $this->repair_registered_assignment_value( (string) ( $row['meta_value'] ?? '' ), $entry, $map, $entry_stats );
						if ( null === $changed ) {
							continue;
						}
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
						$updated = $wpdb->update(
							$wpdb->postmeta,
							array( 'meta_value' => $changed ),
							array( 'meta_id' => $cursor ),
							array( '%s' ),
							array( '%d' )
						);
						if ( false === $updated ) {
							$errors[] = sprintf( 'Meta Key ثبت‌شده %1$s برای Builder نوع %2$s و meta_id=%3$d ترمیم نشد.', $meta_key, $post_type, $cursor );
						} else {
							$stats['postmeta_updated'] += (int) $updated;
							$entry_stats['postmeta_updated'] += (int) $updated;
							wp_cache_delete( absint( $row['post_id'] ?? 0 ), 'post_meta' );
						}
					}
				}
			}
			$stats['references_updated'] += (int) $entry_stats['references_updated'];
			$stats['entries'][] = $entry_stats;
		}

		do_action( 'shcd_tornado_dbm_after_registered_builder_reference_repair', $map, $stats );
		return $stats;
	}

	/**
	 * @param array<string,mixed> $entry
	 * @param array<int,int>      $map
	 * @param array<string,mixed> $stats
	 */
	private function repair_registered_assignment_value( string $raw, array $entry, array $map, array &$stats ): ?string {
		$format = 'raw';
		$value  = $raw;
		if ( is_serialized( $raw ) ) {
			$value  = SafeSerialization::maybe_unserialize( $raw );
			$format = 'serialized';
		} else {
			$decoded = json_decode( $raw, true, 512, JSON_BIGINT_AS_STRING );
			if ( JSON_ERROR_NONE === json_last_error() && ( is_array( $decoded ) || is_scalar( $decoded ) ) ) {
				$value  = $decoded;
				$format = 'json';
			}
		}

		$before = maybe_serialize( $value );
		$this->remap_registered_assignment_value( $value, $entry, $map, $stats );
		$after = maybe_serialize( $value );
		if ( $before === $after ) {
			return null;
		}
		if ( 'serialized' === $format ) {
			return maybe_serialize( $value );
		}
		if ( 'json' === $format ) {
			$encoded = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			return is_string( $encoded ) ? $encoded : null;
		}
		return is_scalar( $value ) ? (string) $value : null;
	}

	/**
	 * @param array<string,mixed> $entry
	 * @param array<int,int>      $map
	 * @param array<string,mixed> $stats
	 */
	private function remap_registered_assignment_value( mixed &$value, array $entry, array $map, array &$stats ): void {
		if ( is_array( $value ) ) {
			foreach ( $value as &$item ) {
				$this->remap_registered_assignment_value( $item, $entry, $map, $stats );
			}
			unset( $item );
			return;
		}

		$id = $this->numeric_id( $value );
		if ( $id > 0 && isset( $map[ $id ] ) && $this->registered_builder_target_matches( $map[ $id ], $entry ) ) {
			$value = is_string( $value ) ? (string) $map[ $id ] : $map[ $id ];
			++$stats['references_updated'];
			return;
		}

		if ( ! is_string( $value ) || ! preg_match( '/^\s*\d+(?:\s*[,|]\s*\d+)+\s*$/', $value ) ) {
			return;
		}
		$value = (string) preg_replace_callback(
			'/\d+/',
			function ( array $matches ) use ( $entry, $map, &$stats ): string {
				$old = absint( $matches[0] );
				if ( isset( $map[ $old ] ) && $this->registered_builder_target_matches( $map[ $old ], $entry ) ) {
					++$stats['references_updated'];
					return (string) $map[ $old ];
				}
				return $matches[0];
			},
			$value
		);
	}

	/** @param array<string,mixed> $entry */
	private function registered_builder_target_matches( int $post_id, array $entry ): bool {
		$type = $this->post_type( $post_id );
		if ( false === $type ) {
			return false;
		}
		$post_type = sanitize_key( (string) ( $entry['post_type'] ?? '' ) );
		if ( '' !== $post_type && $type === $post_type ) {
			return true;
		}
		$role = (string) ( $entry['role'] ?? 'unknown' );
		if ( in_array( $role, array( 'header', 'footer', 'template' ), true ) && $role === $this->builders->role_for_post_type( $type ) ) {
			return true;
		}
		return 'elementor_library' === $type && in_array( $role, array( 'header', 'footer' ), true ) && $role === $this->elementor_template_type( $post_id );
	}

	/** @return array<string,mixed> */
	private function purge_registered_builder_caches(): array {
		$wpdb = $this->wpdb;
		$result = array(
			'post_types'         => array(),
			'post_caches_cleaned'=> 0,
			'transients_deleted' => 0,
		);
		$posts = Identifier::quote( $wpdb->posts );
		$tornado_sql_posts = Identifier::normalize( $posts );
		foreach ( $this->builders->enabled_entries() as $entry ) {
			$post_type = sanitize_key( (string) ( $entry['post_type'] ?? '' ) );
			if ( '' === $post_type ) {
				continue;
			}
			$result['post_types'][] = $post_type;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM %i WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft') LIMIT 2000",
					$tornado_sql_posts,
					$post_type
				)
			);
			foreach ( is_array( $ids ) ? $ids : array() as $raw_id ) {
				clean_post_cache( absint( $raw_id ) );
				wp_cache_delete( absint( $raw_id ), 'post_meta' );
				++$result['post_caches_cleaned'];
			}
		}

		$options = Identifier::quote( $wpdb->options );
		$tornado_sql_options = Identifier::normalize( $options );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM %i
				 WHERE (option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s)
				 AND (option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s)",
				$tornado_sql_options,
				'_transient_%',
				'_transient_timeout_%',
				'_site_transient_%',
				'_site_transient_timeout_%',
				'%header%',
				'%footer%',
				'%template%',
				'%builder%'
			)
		);
		if ( false !== $deleted ) {
			$result['transients_deleted'] = (int) $deleted;
		}
		$result['post_types'] = array_values( array_unique( $result['post_types'] ) );
		flush_rewrite_rules( false );
		do_action( 'shcd_tornado_dbm_purge_registered_builder_caches', $result['post_types'] );
		return $result;
	}

	/**
	 * Repairs theme/plugin assignments whose option or meta name clearly denotes a header,
	 * footer, template, layout, kit, logo or Elementor document reference.
	 *
	 * @param array<int,int> $map
	 * @param list<string>    $errors
	 * @return array<string,int>
	 */
	private function repair_semantic_assignments( array $map, array &$errors ): array {
		$stats = array(
			'options_checked'          => 0,
			'options_updated'          => 0,
			'postmeta_checked'         => 0,
			'postmeta_updated'         => 0,
			'references_updated'       => 0,
			'media_objects_checked'    => 0,
			'media_ids_remapped'       => 0,
			'media_recovered_by_url'   => 0,
			'media_urls_synchronized'  => 0,
			'media_recovered'          => 0,
			'media_stale_ids_cleared'  => 0,
		);
		$this->repair_semantic_options( $map, $stats, $errors );
		$this->repair_semantic_postmeta( $map, $stats, $errors );
		return $stats;
	}

	/** @param array<int,int> $map @param array<string,int> $stats @param list<string> $errors */
	private function repair_semantic_options( array $map, array &$stats, array &$errors ): void {
		$wpdb = $this->wpdb;
		$table  = Identifier::normalize( $wpdb->options );
		$cursor = 0;
		$likes  = array( '%header%', '%footer%', '%template%', '%layout%', '%logo%', 'theme_mods_%' );
		$like_sql = implode( ' OR ', array_fill( 0, count( $likes ), 'option_name LIKE %s' ) );

		while ( true ) {
			$args = array_merge( array( $table, $cursor ), $likes, array( self::BATCH_SIZE ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Only the generated LIKE placeholder list is interpolated; table and values are prepared.
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT option_id, option_name, option_value FROM %i WHERE option_id > %d AND ({$like_sql}) ORDER BY option_id ASC LIMIT %d", $args ),
				ARRAY_A
			);
			if ( empty( $rows ) ) {
				break;
			}
			foreach ( $rows as $row ) {
				$cursor = (int) $row['option_id'];
				$name   = (string) $row['option_name'];
				if ( str_starts_with( $name, 'shcd_tornado_dbm_' ) || 'elementor_pro_theme_builder_conditions' === $name ) {
					continue;
				}
				++$stats['options_checked'];
				$changed = $this->repair_stored_value( (string) $row['option_value'], $name, $map, $stats );
				if ( null === $changed ) {
					continue;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
				$result = $wpdb->update(
					$wpdb->options,
					array( 'option_value' => $changed ),
					array( 'option_id' => $cursor ),
					array( '%s' ),
					array( '%d' )
				);
				if ( false === $result ) {
					$errors[] = sprintf( 'Option با نام %s ترمیم نشد.', $name );
				} else {
					$stats['options_updated'] += (int) $result;
					wp_cache_delete( $name, 'options' );
				}
			}
		}
	}

	/** @param array<int,int> $map @param array<string,int> $stats @param list<string> $errors */
	private function repair_semantic_postmeta( array $map, array &$stats, array &$errors ): void {
		$wpdb = $this->wpdb;
		$table  = Identifier::quote( $wpdb->postmeta );
		$tornado_sql_table = Identifier::normalize( $table );
		$cursor = 0;
		$likes  = array( '%header%', '%footer%', '%template%', '%layout%', '%logo%', '%elementor_template%', '%elementor_source_post%' );
		$like_sql = implode( ' OR ', array_fill( 0, count( $likes ), 'meta_key LIKE %s' ) );

		while ( true ) {
			$args = array_merge( array( $table, $cursor ), $likes, array( self::BATCH_SIZE ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Only the generated LIKE placeholder list is interpolated; table and values are prepared.
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT meta_id, post_id, meta_key, meta_value FROM %i WHERE meta_id > %d AND ({$like_sql}) ORDER BY meta_id ASC LIMIT %d", $args ),
				ARRAY_A
			);
			if ( empty( $rows ) ) {
				break;
			}
			foreach ( $rows as $row ) {
				$cursor = (int) $row['meta_id'];
				$key    = (string) $row['meta_key'];
				if ( in_array( $key, array( '_elementor_data', '_elementor_page_settings', '_elementor_conditions' ), true ) ) {
					continue;
				}
				++$stats['postmeta_checked'];
				$changed = $this->repair_stored_value( (string) $row['meta_value'], $key, $map, $stats );
				if ( null === $changed ) {
					continue;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
				$result = $wpdb->update(
					$wpdb->postmeta,
					array( 'meta_value' => $changed ),
					array( 'meta_id' => $cursor ),
					array( '%s' ),
					array( '%d' )
				);
				if ( false === $result ) {
					$errors[] = sprintf( 'Post Meta با کلید %1$s و meta_id=%2$d ترمیم نشد.', $key, $cursor );
				} else {
					$stats['postmeta_updated'] += (int) $result;
					wp_cache_delete( absint( $row['post_id'] ), 'post_meta' );
				}
			}
		}
	}

	/**
	 * @param array<int,int>   $map
	 * @param array<string,int> $stats
	 */
	private function repair_stored_value( string $raw, string $context, array $map, array &$stats ): ?string {
		$format = 'raw';
		$value  = $raw;
		if ( is_serialized( $raw ) ) {
			$value  = SafeSerialization::maybe_unserialize( $raw );
			$format = 'serialized';
		} else {
			$decoded = json_decode( $raw, true, 512, JSON_BIGINT_AS_STRING );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				$value  = $decoded;
				$format = 'json';
			}
		}

		$before = maybe_serialize( $value );
		$this->remap_semantic_value( $value, $context, null, $map, $stats );
		$after = maybe_serialize( $value );
		if ( $before === $after ) {
			return null;
		}

		if ( 'serialized' === $format ) {
			return maybe_serialize( $value );
		}
		if ( 'json' === $format ) {
			$encoded = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			return is_string( $encoded ) ? $encoded : null;
		}
		return is_scalar( $value ) ? (string) $value : null;
	}

	/**
	 * @param array<int,int>    $map
	 * @param array<string,int> $stats
	 */
	private function remap_semantic_value( mixed &$value, string $key, ?string $parent_key, array $map, array &$stats ): void {
		if ( is_array( $value ) ) {
			$media_context = trim( $key . ' ' . (string) $parent_key );
			$this->recover_media_array( $value, $media_context, $map, $stats );
			if ( $this->is_id_list_key( $key ) ) {
				$this->clean_media_id_list( $value, $map, $stats );
			}
			$handled_media_id_key = $this->media_id_key( $value );
			$is_media_object = $this->looks_like_media_object(
				$value,
				$media_context,
				null !== $handled_media_id_key ? $this->numeric_id( $value[ $handled_media_id_key ] ) : 0,
				null !== $this->media_url_key( $value ) ? (string) $value[ $this->media_url_key( $value ) ] : ''
			);
			foreach ( $value as $child_key => &$child_value ) {
				/*
				 * The media resolver has already decided the correct Attachment ID from
				 * URL/path evidence. Running the generic numeric Mapping on the same "id"
				 * could apply the previous Mapping a second time when old and new ranges
				 * overlap.
				 */
				if ( $is_media_object && null !== $handled_media_id_key && (string) $child_key === $handled_media_id_key ) {
					continue;
				}
				$this->remap_semantic_value( $child_value, (string) $child_key, $key, $map, $stats );
			}
			unset( $child_value );
			return;
		}

		if ( is_string( $value ) ) {
			$value = $this->replace_known_elementor_string_ids( $value, $map, $stats );
		}

		$id = $this->numeric_id( $value );
		if ( $id <= 0 ) {
			return;
		}

		$parent_context = in_array( (string) $parent_key, array( '_elementor_data', '_elementor_page_settings' ), true ) ? '' : (string) $parent_key;
		$context        = strtolower( $key . ' ' . $parent_context );
		$key_lower      = strtolower( $key );
		$parent_lower   = strtolower( (string) $parent_key );
		$explicit_media_id_keys = array(
			'attachment_id', 'attachmentid', 'media_id', 'mediaid', 'image_id', 'imageid',
			'custom_logo', 'site_icon', 'site_logo_id', 'site_favicon_id',
		);
		$media_parent_keys = array(
			'image', 'background_image', 'hover_image', 'poster', 'video_image', 'fallback_image',
			'thumbnail', 'logo', 'site_logo', 'site_favicon', 'custom_logo', 'media', 'attachment',
		);
		$is_explicit_media_id = in_array( $key_lower, $explicit_media_id_keys, true )
			|| ( 'id' === $key_lower && in_array( $parent_lower, $media_parent_keys, true ) );
		if ( $is_explicit_media_id && ! $this->attachment_is_usable( $id ) && ( ! isset( $map[ $id ] ) || ! $this->attachment_is_usable( (int) $map[ $id ] ) ) ) {
			$value = is_string( $value ) ? '0' : 0;
			++$stats['media_stale_ids_cleared'];
			++$stats['references_updated'];
			return;
		}
		if ( ! isset( $map[ $id ] ) ) {
			return;
		}
		$known_scalar_keys = array(
			'post_id', 'page_id', 'product_id', 'attachment_id', 'media_id', 'template_id',
			'source_post_id', 'header_id', 'footer_id', 'kit_id', 'document_id', 'popup_id',
			'custom_logo', 'site_icon', 'site_logo_id', 'site_favicon_id', 'selected_template',
		);
		$known_parent_keys = array(
			'image', 'background_image', 'hover_image', 'poster', 'video_image', 'fallback_image',
			'thumbnail', 'logo', 'site_logo', 'site_favicon', 'custom_logo', 'header', 'footer',
			'template', 'layout', 'elementor',
		);
		$is_semantic = in_array( strtolower( $key ), $known_scalar_keys, true )
			|| ( 'id' === strtolower( $key ) && in_array( strtolower( (string) $parent_key ), $known_parent_keys, true ) )
			|| $this->is_semantic_key( $key )
			|| ( ctype_digit( $key ) && $this->is_id_list_key( (string) $parent_key ) );

		if ( ! $is_semantic ) {
			return;
		}

		$new_id = $map[ $id ];
		if ( ! $this->aggressive_mapping_remap && $this->context_allows_target( $context, $id ) ) {
			return;
		}
		if ( ! $this->context_allows_target( $context, $new_id ) ) {
			return;
		}
		$value = is_string( $value ) ? (string) $new_id : $new_id;
		++$stats['references_updated'];
	}

	/**
	 * Repairs Elementor media objects by Mapping first and URL/path evidence second.
	 *
	 * Elementor media controls normally keep both an attachment ID and a URL. After a
	 * gapless Reindex, an old numeric ID can point to a completely different post, so
	 * the Mapping must always win before the current post type is inspected.
	 *
	 * @param array<int|string,mixed> $value
	 * @param array<int,int>          $map
	 * @param array<string,int>       $stats
	 */
	private function recover_media_array( array &$value, string $context, array $map, array &$stats ): void {
		$id_keys = $this->media_id_keys( $value );
		$id_key  = $id_keys[0] ?? null;
		$url_key = $this->media_url_key( $value );
		$id      = 0;
		foreach ( $id_keys as $candidate_key ) {
			$candidate_id = $this->numeric_id( $value[ $candidate_key ] );
			if ( $candidate_id > 0 ) {
				$id     = $candidate_id;
				$id_key = $candidate_key;
				break;
			}
		}
		$url = null !== $url_key && is_string( $value[ $url_key ] ) ? trim( $value[ $url_key ] ) : '';

		if ( ! $this->looks_like_media_object( $value, $context, $id, $url ) ) {
			return;
		}
		++$stats['media_objects_checked'];

		$resolved = 0;
		$source   = '';

		/*
		 * URL/path evidence is the stable identity of an Elementor media control.
		 * Numeric IDs are not stable after a gapless Reindex and may already have
		 * been reused by another valid Attachment. Therefore the previous Mapping
		 * must never override a resolvable URL and must not be applied twice.
		 */
		if ( '' !== $url ) {
			$resolved = $this->resolve_attachment_id_from_url( $url );
			if ( $resolved > 0 ) {
				$source = 'url';
			} elseif ( $id > 0 && $this->attachment_is_usable( $id ) ) {
				$current_url = wp_get_attachment_url( $id );
				if ( is_string( $current_url ) && $this->same_upload_asset( $url, $current_url ) ) {
					$resolved = $id;
					$source   = 'current_id_path';
				}
			}

			if ( $resolved <= 0 && $this->is_probable_wordpress_media_url( $url ) ) {
				/*
				 * The internal file is genuinely unavailable. Keeping a stale ID is
				 * dangerous because that number may now belong to a different image.
				 * Preserve the original URL for a later recovery, but neutralize only
				 * the stale numeric ID.
				 */
				foreach ( $id_keys as $candidate_key ) {
					$candidate_id = $this->numeric_id( $value[ $candidate_key ] );
					if ( $candidate_id <= 0 ) {
						continue;
					}
					$value[ $candidate_key ] = is_string( $value[ $candidate_key ] ) ? '0' : 0;
					++$stats['media_stale_ids_cleared'];
					++$stats['references_updated'];
				}
				++$stats['media_url_only_preserved'];
				return;
			}
		} elseif ( $id > 0 && $this->attachment_is_usable( $id ) ) {
			$resolved = $id;
			$source   = 'current_id';
		} elseif ( $id > 0 && isset( $map[ $id ] ) && $this->attachment_is_usable( (int) $map[ $id ] ) ) {
			$resolved = (int) $map[ $id ];
			$source   = 'mapping_fallback';
		}

		if ( $resolved <= 0 ) {
			return;
		}

		$id_changed = false;
		if ( empty( $id_keys ) ) {
			$value['id'] = $resolved;
			$id_keys[]   = 'id';
			$id_changed  = true;
		} else {
			foreach ( $id_keys as $candidate_key ) {
				$candidate_id = $this->numeric_id( $value[ $candidate_key ] );
				if ( $candidate_id === $resolved ) {
					continue;
				}
				$value[ $candidate_key ] = is_string( $value[ $candidate_key ] ) ? (string) $resolved : $resolved;
				$id_changed = true;
			}
		}

		if ( $id_changed ) {
			++$stats['references_updated'];
			if ( 'mapping_fallback' === $source ) {
				++$stats['media_ids_remapped'];
			} elseif ( in_array( $source, array( 'url', 'current_id_path' ), true ) ) {
				++$stats['media_recovered_by_url'];
				++$stats['media_recovered'];
			}
		}

		$canonical_url = wp_get_attachment_url( $resolved );
		if ( is_string( $canonical_url ) && '' !== $canonical_url ) {
			if ( null === $url_key ) {
				$value['url'] = $canonical_url;
				++$stats['media_urls_synchronized'];
			} elseif ( ! $this->same_upload_asset( $url, $canonical_url ) || $url !== $canonical_url ) {
				$value[ $url_key ] = $canonical_url;
				++$stats['media_urls_synchronized'];
			}
		}
	}

	/** @param array<int|string,mixed> $value */
	private function media_id_key( array $value ): ?string {
		$keys = $this->media_id_keys( $value );
		return $keys[0] ?? null;
	}

	/**
	 * Returns every Attachment-ID alias used by a media control.
	 *
	 * Specific aliases are preferred over the generic `id`, while all existing aliases
	 * are synchronized after URL recovery because add-ons do not consistently read the
	 * same field.
	 *
	 * @param array<int|string,mixed> $value
	 * @return list<string>
	 */
	private function media_id_keys( array $value ): array {
		$result = array();
		foreach ( array( 'attachment_id', 'attachmentId', 'media_id', 'mediaId', 'image_id', 'imageId', 'id' ) as $key ) {
			if ( array_key_exists( $key, $value ) ) {
				$result[] = $key;
			}
		}
		return $result;
	}

	/** @param array<int|string,mixed> $value */
	private function media_url_key( array $value ): ?string {
		foreach ( array( 'url', 'src', 'image_url', 'imageUrl', 'full_url', 'preview_url' ) as $key ) {
			if ( isset( $value[ $key ] ) && is_string( $value[ $key ] ) && '' !== trim( $value[ $key ] ) ) {
				return $key;
			}
		}
		return null;
	}

	/** @param array<int|string,mixed> $value */
	private function looks_like_media_object( array $value, string $context, int $id, string $url ): bool {
		$semantic = strtolower( $context );
		if ( preg_match( '/(?:logo|favicon|icon|image|media|thumbnail|poster|gallery|slide|background)/', $semantic ) ) {
			return $id > 0 || '' !== $url;
		}
		if ( $id > 0 && '' !== $url && $this->is_probable_wordpress_media_url( $url ) ) {
			return true;
		}
		return false;
	}

	private function attachment_is_usable( int $attachment_id ): bool {
		return $attachment_id > 0
			&& 'attachment' === $this->post_type( $attachment_id )
			&& is_string( wp_get_attachment_url( $attachment_id ) )
			&& '' !== (string) wp_get_attachment_url( $attachment_id );
	}

	private function should_synchronize_media_url( string $url, int $attachment_id ): bool {
		if ( '' === $url ) {
			return true;
		}
		$resolved = $this->resolve_attachment_id_from_url( $url );
		return $this->is_probable_wordpress_media_url( $url ) && $resolved !== $attachment_id;
	}

	private function same_upload_asset( string $first_url, string $second_url ): bool {
		$first  = $this->relative_upload_path_from_url( $first_url );
		$second = $this->relative_upload_path_from_url( $second_url );
		if ( '' === $first || '' === $second ) {
			return false;
		}

		$first  = strtolower( rawurldecode( $this->strip_intermediate_image_size( $first ) ) );
		$second = strtolower( rawurldecode( $this->strip_intermediate_image_size( $second ) ) );
		return hash_equals( $first, $second );
	}

	private function is_probable_wordpress_media_url( string $url ): bool {
		$url = html_entity_decode( trim( $url ), ENT_QUOTES, 'UTF-8' );
		if ( '' === $url || str_starts_with( $url, 'data:' ) || str_starts_with( $url, 'blob:' ) ) {
			return false;
		}
		$uploads = wp_upload_dir();
		$baseurl = isset( $uploads['baseurl'] ) ? untrailingslashit( (string) $uploads['baseurl'] ) : '';
		if ( '' !== $baseurl && str_starts_with( $url, $baseurl . '/' ) ) {
			return true;
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		return '' !== $path && str_contains( $path, '/uploads/' );
	}

	private function resolve_attachment_id_from_url( string $url ): int {
		$wpdb = $this->wpdb;
		$url = html_entity_decode( trim( $url ), ENT_QUOTES, 'UTF-8' );
		$url = (string) preg_replace( '/[#?].*$/', '', $url );
		if ( '' === $url ) {
			return 0;
		}
		if ( isset( $this->attachment_url_cache[ $url ] ) ) {
			return $this->attachment_url_cache[ $url ];
		}

		$resolved = function_exists( 'attachment_url_to_postid' ) ? absint( attachment_url_to_postid( $url ) ) : 0;
		if ( $resolved > 0 && $this->attachment_is_usable( $resolved ) ) {
			$this->attachment_url_cache[ $url ] = $resolved;
			return $resolved;
		}

		$posts = Identifier::quote( $wpdb->posts );
		$tornado_sql_posts = Identifier::normalize( $posts );
		$guid_id = absint(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID FROM %i WHERE post_type = 'attachment' AND guid = %s LIMIT 1",
					$tornado_sql_posts,
					$url
				)
			)
		);
		if ( $guid_id > 0 && $this->attachment_is_usable( $guid_id ) ) {
			$this->attachment_url_cache[ $url ] = $guid_id;
			return $guid_id;
		}

		$relative = $this->relative_upload_path_from_url( $url );
		if ( '' !== $relative ) {
			$postmeta = Identifier::quote( $wpdb->postmeta );
			$tornado_sql_postmeta = Identifier::normalize( $postmeta );
			$candidates = array_values( array_unique( array_filter( array(
				$relative,
				$this->strip_intermediate_image_size( $relative ),
			) ) ) );
			foreach ( $candidates as $candidate ) {
				$attachment_id = absint(
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
					$wpdb->get_var(
						$wpdb->prepare(
							"SELECT post_id FROM %i WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
							$tornado_sql_postmeta,
							$candidate
						)
					)
				);
				if ( $attachment_id > 0 && $this->attachment_is_usable( $attachment_id ) ) {
					$this->attachment_url_cache[ $url ] = $attachment_id;
					return $attachment_id;
				}
			}

			$registered = $this->register_existing_upload_as_attachment( $candidates );
			if ( $registered > 0 ) {
				$this->attachment_url_cache[ $url ] = $registered;
				return $registered;
			}
		}

		$this->attachment_url_cache[ $url ] = 0;
		return 0;
	}

	private function relative_upload_path_from_url( string $url ): string {
		$uploads  = wp_upload_dir();
		$base_url = isset( $uploads['baseurl'] ) ? untrailingslashit( (string) $uploads['baseurl'] ) : '';
		if ( '' !== $base_url && str_starts_with( $url, $base_url . '/' ) ) {
			return ltrim( rawurldecode( substr( $url, strlen( $base_url ) ) ), '/' );
		}
		$path = rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$marker = '/uploads/';
		$position = strpos( $path, $marker );
		return false === $position ? '' : ltrim( substr( $path, $position + strlen( $marker ) ), '/' );
	}

	private function strip_intermediate_image_size( string $relative_path ): string {
		return (string) preg_replace( '/-\d+x\d+(?=\.[A-Za-z0-9]{2,6}$)/', '', $relative_path );
	}


	/**
	 * Deletes only stale Attachment rows whose local upload files are all absent.
	 *
	 * This runs before a fresh Mapping is built. Elementor references are not deleted:
	 * their stale numeric ID is neutralized to 0 while the URL is preserved as a plain
	 * URL-only value. Attachments backed by an existing original or generated size are
	 * retained. Remote/non-upload attachments are never removed.
	 *
	 * @return array<string,mixed>
	 */
	private function purge_missing_local_attachment_rows(): array {
		$wpdb = $this->wpdb;
		$result = array(
			'enabled'       => $this->purge_missing_attachment_rows || $this->purge_media_cleaner_trash_rows,
			'checked'       => 0,
			'checked_attachments' => 0,
			'checked_media_cleaner_trash' => 0,
			'deleted'       => 0,
			'deleted_attachments' => 0,
			'deleted_media_cleaner_trash' => 0,
			'kept'          => 0,
			'skipped_remote'=> 0,
			'failed'        => 0,
			'deleted_ids'   => array(),
		);
		if ( ! $this->purge_missing_attachment_rows && ! $this->purge_media_cleaner_trash_rows ) {
			return $result;
		}

		$posts = Identifier::quote( $wpdb->posts );
		$tornado_sql_posts = Identifier::normalize( $posts );
		$meta  = Identifier::quote( $wpdb->postmeta );
		$tornado_sql_meta = Identifier::normalize( $meta );
		$cursor = 0;
		while ( true ) {
			$post_types = array();
			if ( $this->purge_missing_attachment_rows ) {
				$post_types[] = 'attachment';
			}
			if ( $this->purge_media_cleaner_trash_rows ) {
				$post_types[] = 'wmpc-trash';
			}
			if ( 1 === count( $post_types ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT p.ID, p.post_type, p.guid, pm.meta_value AS attached_file
						 FROM %i p
						 LEFT JOIN %i pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
						 WHERE p.post_type = %s AND p.ID > %d
						 ORDER BY p.ID ASC LIMIT %d",
						$tornado_sql_posts,
						$tornado_sql_meta,
						$post_types[0],
						$cursor,
						self::BATCH_SIZE
					),
					ARRAY_A
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT p.ID, p.post_type, p.guid, pm.meta_value AS attached_file
						 FROM %i p
						 LEFT JOIN %i pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
						 WHERE p.post_type IN (%s,%s) AND p.ID > %d
						 ORDER BY p.ID ASC LIMIT %d",
						$tornado_sql_posts,
						$tornado_sql_meta,
						$post_types[0],
						$post_types[1],
						$cursor,
						self::BATCH_SIZE
					),
					ARRAY_A
				);
			}
			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$attachment_id = absint( $row['ID'] ?? 0 );
				$cursor = max( $cursor, $attachment_id );
				if ( $attachment_id <= 0 ) {
					continue;
				}
				++$result['checked'];
				$post_type = sanitize_key( (string) ( $row['post_type'] ?? '' ) );
				if ( 'wmpc-trash' === $post_type ) {
					++$result['checked_media_cleaner_trash'];
				} else {
					++$result['checked_attachments'];
				}
				$relative = ltrim( wp_normalize_path( rawurldecode( (string) ( $row['attached_file'] ?? '' ) ) ), '/' );
				if ( '' === $relative ) {
					$guid = is_string( $row['guid'] ?? null ) ? (string) $row['guid'] : '';
					$relative = $this->is_probable_wordpress_media_url( $guid ) ? $this->relative_upload_path_from_url( $guid ) : '';
				}
				if ( '' === $relative || str_contains( $relative, '../' ) ) {
					++$result['skipped_remote'];
					continue;
				}
				if ( $this->attachment_has_existing_local_file( $attachment_id, $relative ) ) {
					++$result['kept'];
					continue;
				}

				$deleted = 'wmpc-trash' === $post_type
					? wp_delete_post( $attachment_id, true )
					: wp_delete_attachment( $attachment_id, true );
				if ( false === $deleted || null === $deleted ) {
					++$result['failed'];
					continue;
				}
				++$result['deleted'];
				if ( 'wmpc-trash' === $post_type ) {
					++$result['deleted_media_cleaner_trash'];
				} else {
					++$result['deleted_attachments'];
				}
				$this->removed_attachment_ids[] = $attachment_id;
				if ( count( $result['deleted_ids'] ) < self::MEDIA_SAMPLE_LIMIT ) {
					$result['deleted_ids'][] = $attachment_id;
				}
				unset( $this->post_type_cache[ $attachment_id ] );
			}
		}
		return $result;
	}

	private function attachment_has_existing_local_file( int $attachment_id, string $relative ): bool {
		$uploads  = wp_upload_dir();
		$base_dir = isset( $uploads['basedir'] ) ? wp_normalize_path( (string) $uploads['basedir'] ) : '';
		$real_base = '' !== $base_dir ? realpath( $base_dir ) : false;
		if ( false === $real_base ) {
			return true; 
		}
		$real_base = wp_normalize_path( $real_base );
		$candidates = array( $relative, $this->strip_intermediate_image_size( $relative ) );
		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( is_array( $metadata ) ) {
			if ( ! empty( $metadata['file'] ) && is_string( $metadata['file'] ) ) {
				$candidates[] = $metadata['file'];
			}
			$directory = dirname( (string) ( $metadata['file'] ?? $relative ) );
			if ( '.' === $directory ) {
				$directory = '';
			}
			if ( ! empty( $metadata['original_image'] ) && is_string( $metadata['original_image'] ) ) {
				$candidates[] = ltrim( trailingslashit( $directory ) . $metadata['original_image'], '/' );
			}
			foreach ( is_array( $metadata['sizes'] ?? null ) ? $metadata['sizes'] : array() as $size ) {
				if ( is_array( $size ) && ! empty( $size['file'] ) && is_string( $size['file'] ) ) {
					$candidates[] = ltrim( trailingslashit( $directory ) . $size['file'], '/' );
				}
			}
		}
		foreach ( array_unique( array_filter( array_map( 'strval', $candidates ) ) ) as $candidate ) {
			$candidate = ltrim( wp_normalize_path( rawurldecode( $candidate ) ), '/' );
			if ( '' === $candidate || str_contains( $candidate, '../' ) ) {
				continue;
			}
			$file = wp_normalize_path( trailingslashit( $base_dir ) . $candidate );
			$real_file = realpath( $file );
			if ( false !== $real_file ) {
				$real_file = wp_normalize_path( $real_file );
				if ( is_file( $real_file ) && ! is_link( $real_file ) && str_starts_with( $real_file, trailingslashit( $real_base ) ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** @param array<string,mixed> $result @param array<string,mixed> $row_context */
	private function record_url_only_media( array &$result, array $row_context, string $path, string $context, int $id, string $url ): void {
		++$result['url_only_detached_count'];
		$exists = $this->upload_url_file_exists( $url );
		if ( $exists ) {
			++$result['url_only_existing_file_count'];
		} else {
			++$result['url_only_missing_file_count'];
		}
		$result['categories']['url_only_without_attachment'] = (int) ( $result['categories']['url_only_without_attachment'] ?? 0 ) + 1;

		/*
		 * URL-only references are intentionally outside the Post-ID Mapping. They are
		 * informational, not unresolved media errors. Store a compact group per unique
		 * URL instead of one verbose sample for every Elementor JSON path.
		 */
		$normalized_url = strtolower( rawurldecode( (string) preg_replace( '/[#?].*$/', '', trim( $url ) ) ) );
		$signature      = hash( 'sha256', $normalized_url );
		if ( ! isset( $result['_seen_url_only'][ $signature ] ) ) {
			$result['_seen_url_only'][ $signature ] = true;
			++$result['url_only_unique_count'];
		}

		if ( isset( $result['_url_only_example_index'][ $signature ] ) ) {
			$index = (int) $result['_url_only_example_index'][ $signature ];
			$result['url_only_examples'][ $index ]['occurrences'] = (int) ( $result['url_only_examples'][ $index ]['occurrences'] ?? 1 ) + 1;
			return;
		}

		if ( count( $result['url_only_examples'] ) >= self::URL_ONLY_EXAMPLE_LIMIT ) {
			return;
		}

		$result['_url_only_example_index'][ $signature ] = count( $result['url_only_examples'] );
		$result['url_only_examples'][] = array(
			'url'          => $url,
			'file_exists'  => $exists,
			'occurrences'  => 1,
			'first_meta_id'=> absint( $row_context['meta_id'] ?? 0 ),
			'first_post_id'=> absint( $row_context['post_id'] ?? 0 ),
			'first_path'   => $path,
			'context'      => $context,
			'current_id'   => $id,
		);
	}

	private function upload_url_file_exists( string $url ): bool {
		$relative = $this->relative_upload_path_from_url( $url );
		if ( '' === $relative || str_contains( $relative, '../' ) ) {
			return false;
		}
		$uploads = wp_upload_dir();
		$base_dir = isset( $uploads['basedir'] ) ? wp_normalize_path( (string) $uploads['basedir'] ) : '';
		if ( '' === $base_dir ) {
			return false;
		}
		foreach ( array_unique( array_filter( array( $relative, $this->strip_intermediate_image_size( $relative ) ) ) ) as $candidate ) {
			$file = wp_normalize_path( trailingslashit( $base_dir ) . ltrim( rawurldecode( $candidate ), '/' ) );
			if ( is_file( $file ) && ! is_link( $file ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Re-registers an existing file from wp-content/uploads when the physical file is
	 * present but its Attachment row was lost. This is intentionally limited to the
	 * WordPress uploads directory and allowed media MIME types.
	 *
	 * @param list<string> $relative_candidates
	 */
	private function register_existing_upload_as_attachment( array $relative_candidates ): int {
		if ( ! $this->allow_attachment_registration ) {
			return 0;
		}

		$uploads  = wp_upload_dir();
		$base_dir = isset( $uploads['basedir'] ) ? wp_normalize_path( (string) $uploads['basedir'] ) : '';
		$base_url = isset( $uploads['baseurl'] ) ? untrailingslashit( (string) $uploads['baseurl'] ) : '';
		$real_base = '' !== $base_dir ? realpath( $base_dir ) : false;
		if ( false === $real_base || '' === $base_url ) {
			return 0;
		}
		$real_base = wp_normalize_path( $real_base );

		foreach ( $relative_candidates as $relative ) {
			$relative = ltrim( wp_normalize_path( rawurldecode( $relative ) ), '/' );
			if ( '' === $relative || str_contains( $relative, '../' ) ) {
				continue;
			}
			$file = wp_normalize_path( trailingslashit( $base_dir ) . $relative );
			$real_file = realpath( $file );
			if ( false === $real_file ) {
				$directory = dirname( $file );
				$basename  = basename( $file );
				if ( is_dir( $directory ) ) {
					$entries = scandir( $directory );
					foreach ( is_array( $entries ) ? $entries : array() as $entry ) {
						if ( 0 === strcasecmp( $entry, $basename ) ) {
							$real_file = realpath( trailingslashit( $directory ) . $entry );
							break;
						}
					}
				}
			}
			if ( false === $real_file || ! is_file( $real_file ) || is_link( $real_file ) ) {
				continue;
			}
			$real_file = wp_normalize_path( $real_file );
			if ( ! str_starts_with( $real_file, trailingslashit( $real_base ) ) ) {
				continue;
			}

			$allowed_mimes = get_allowed_mime_types();
			$filetype      = wp_check_filetype_and_ext( $real_file, basename( $real_file ), $allowed_mimes );
			$mime          = isset( $filetype['type'] ) ? (string) $filetype['type'] : '';
			if ( '' === $mime ) {
				$fallback = wp_check_filetype( basename( $real_file ), $allowed_mimes );
				$mime     = isset( $fallback['type'] ) ? (string) $fallback['type'] : '';
			}
			if ( '' === $mime || ! in_array( $mime, array_values( $allowed_mimes ), true ) ) {
				continue;
			}

			$title = sanitize_text_field( pathinfo( basename( $real_file ), PATHINFO_FILENAME ) );
			$attachment_id = wp_insert_attachment(
				array(
					'post_mime_type' => $mime,
					'post_title'     => '' !== $title ? $title : 'Recovered media',
					'post_content'   => '',
					'post_status'    => 'inherit',
					'guid'           => trailingslashit( $base_url ) . str_replace( '%2F', '/', rawurlencode( $relative ) ),
				),
				$real_file,
				0,
				true
			);
			if ( is_wp_error( $attachment_id ) || $attachment_id <= 0 ) {
				continue;
			}

			update_attached_file( $attachment_id, $real_file );
			if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}
			if ( function_exists( 'wp_generate_attachment_metadata' ) ) {
				$metadata = wp_generate_attachment_metadata( $attachment_id, $real_file );
				if ( is_array( $metadata ) && ! empty( $metadata ) ) {
					wp_update_attachment_metadata( $attachment_id, $metadata );
				}
			}

			++$this->attachments_registered;
			$this->registered_attachment_ids[] = (int) $attachment_id;
			$this->post_type_cache[ (int) $attachment_id ] = 'attachment';
			return (int) $attachment_id;
		}
		return 0;
	}

	/** @param array<int,int> $map @param array<string,int> $stats */
	private function replace_known_elementor_string_ids( string $value, array $map, array &$stats ): string {
		if ( ! $this->aggressive_mapping_remap ) {
			return $value;
		}

		$callbacks = array(
			'/(?<![A-Za-z0-9_-])\.elementor-(\d+)(?![A-Za-z0-9_-])/' => static fn( array $m ): string => '.elementor-' . ( $map[ (int) $m[1] ] ?? $m[1] ),
			'/\b(elementor-page-|postid-|page-id-)(\d+)\b/'          => static fn( array $m ): string => $m[1] . ( $map[ (int) $m[2] ] ?? $m[2] ),
			'/\bpost-(\d+)\.css\b/'                                => static fn( array $m ): string => 'post-' . ( $map[ (int) $m[1] ] ?? $m[1] ) . '.css',
			'/\b(wp-image-|attachment-|attachment_)(\d+)\b/i'         => static fn( array $m ): string => $m[1] . ( $map[ (int) $m[2] ] ?? $m[2] ),
			'/(\bdata-(?:elementor|attachment|media)-id\s*=\s*["\']?)(\d+)/i' => static fn( array $m ): string => $m[1] . ( $map[ (int) $m[2] ] ?? $m[2] ),
			'/(\[elementor-template\b[^\]]*\bid\s*=\s*["\']?)(\d+)/i' => static fn( array $m ): string => $m[1] . ( $map[ (int) $m[2] ] ?? $m[2] ),
		);
		foreach ( $callbacks as $pattern => $callback ) {
			$before = $value;
			$value  = (string) preg_replace_callback( $pattern, $callback, $value );
			if ( $before !== $value ) {
				++$stats['references_updated'];
			}
		}
		return $value;
	}


	/** @param array<int|string,mixed> $value @param array<int,int> $map @param array<string,int> $stats */
	private function clean_media_id_list( array &$value, array $map, array &$stats ): void {
		$changed = false;
		foreach ( $value as $index => $item ) {
			if ( is_array( $item ) ) {
				continue;
			}
			$id = $this->numeric_id( $item );
			if ( $id <= 0 ) {
				continue;
			}
			if ( $this->attachment_is_usable( $id ) ) {
				continue;
			}
			if ( isset( $map[ $id ] ) && $this->attachment_is_usable( (int) $map[ $id ] ) ) {
				$value[ $index ] = is_string( $item ) ? (string) $map[ $id ] : (int) $map[ $id ];
				++$stats['media_ids_remapped'];
				++$stats['references_updated'];
				continue;
			}
			unset( $value[ $index ] );
			$changed = true;
			++$stats['media_stale_ids_cleared'];
			++$stats['references_updated'];
		}
		if ( $changed && array_is_list( $value ) ) {
			$value = array_values( $value );
		}
	}


	private function is_id_list_key( string $key ): bool {
		$key = strtolower( $key );
		return 1 === preg_match( '/(?:post|page|product|attachment|media|image|gallery|slideshow|slide|template|header|footer|document|popup)_ids$/', $key )
			|| in_array( $key, array( 'gallery', 'images', 'attachments', 'selected_images', 'slideshow_gallery', 'media_gallery' ), true );
	}

	private function is_semantic_key( string $key ): bool {
		$key = strtolower( trim( $key, " _-\t\n\r\0\x0B" ) );
		if ( '' === $key ) {
			return false;
		}
		if ( 1 === preg_match( '/(?:^|[_-])(header|footer|template|layout|kit|logo|favicon)(?:[_-](?:id|ids))?$/', $key ) ) {
			return true;
		}
		return 1 === preg_match( '/(?:^|[_-])(elementor|post|page|product|attachment|media|image|icon)(?:[_-](?:id|ids|template_id|source_post_id))$/', $key );
	}

	private function context_allows_target( string $context, int $new_id ): bool {
		$type = $this->post_type( $new_id );
		if ( false === $type ) {
			return false;
		}
		if ( preg_match( '/(?:logo|favicon|icon|image|media|attachment|thumbnail|poster|gallery|slide|background)/', $context ) ) {
			return 'attachment' === $type;
		}
		$builder_role = $this->builders->role_for_post_type( $type );
		if ( str_contains( $context, 'header' ) ) {
			return 'header' === $builder_role || ( 'elementor_library' === $type && 'header' === $this->elementor_template_type( $new_id ) );
		}
		if ( str_contains( $context, 'footer' ) ) {
			return 'footer' === $builder_role || ( 'elementor_library' === $type && 'footer' === $this->elementor_template_type( $new_id ) );
		}
		if ( str_contains( $context, 'kit' ) ) {
			return 'elementor_library' === $type && 'kit' === $this->elementor_template_type( $new_id );
		}
		if ( preg_match( '/(?:template|layout|elementor)/', $context ) ) {
			return 'elementor_library' === $type || in_array( $builder_role, array( 'header', 'footer', 'template' ), true );
		}
		return true;
	}

	/**
	 * @param array<int,int> $map
	 * @param list<string>    $errors
	 * @return array<string,mixed>
	 */
	private function repair_site_identity( array $map, array &$errors ): array {
		$result = array(
			'active_kit_before'  => absint( get_option( ExternalOptionBridge::ELEMENTOR_ACTIVE_KIT, 0 ) ),
			'active_kit_after'   => 0,
			'custom_logo_before' => absint( get_theme_mod( 'custom_logo', 0 ) ),
			'custom_logo_after'  => 0,
			'site_icon_before'   => absint( get_option( ExternalOptionBridge::WORDPRESS_SITE_ICON, 0 ) ),
			'site_icon_after'    => 0,
			'invalid_logo_removed' => false,
		);

		$active_kit = $this->mapped_valid_id( $result['active_kit_before'], $map, 'kit' );
		if ( $active_kit <= 0 || 'elementor_library' !== $this->post_type( $active_kit ) ) {
			$active_kit = $this->find_latest_elementor_kit();
		}
		if ( $active_kit > 0 && $active_kit !== $result['active_kit_before'] ) {
			ExternalOptionBridge::update( ExternalOptionBridge::ELEMENTOR_ACTIVE_KIT, $active_kit );
		}
		$result['active_kit_after'] = $active_kit;

		$site_icon = $this->mapped_valid_id( $result['site_icon_before'], $map, 'site_icon' );
		if ( $site_icon > 0 && 'attachment' === $this->post_type( $site_icon ) ) {
			if ( $site_icon !== $result['site_icon_before'] ) {
				ExternalOptionBridge::update( ExternalOptionBridge::WORDPRESS_SITE_ICON, $site_icon );
			}
		} elseif ( $result['site_icon_before'] > 0 ) {
			ExternalOptionBridge::update( ExternalOptionBridge::WORDPRESS_SITE_ICON, 0 );
			$site_icon = 0;
		}
		$result['site_icon_after'] = $site_icon;

		$custom_logo = $this->mapped_valid_id( $result['custom_logo_before'], $map, 'custom_logo' );
		if ( $custom_logo <= 0 || 'attachment' !== $this->post_type( $custom_logo ) || ! wp_get_attachment_url( $custom_logo ) ) {
			$custom_logo = $this->logo_from_active_kit( $active_kit );
		}
		if ( $custom_logo > 0 && 'attachment' === $this->post_type( $custom_logo ) && wp_get_attachment_url( $custom_logo ) ) {
			if ( $custom_logo !== $result['custom_logo_before'] ) {
				set_theme_mod( 'custom_logo', $custom_logo );
			}
		} elseif ( $result['custom_logo_before'] > 0 ) {
			remove_theme_mod( 'custom_logo' );
			$result['invalid_logo_removed'] = true;
			$custom_logo = 0;
		}
		$result['custom_logo_after'] = $custom_logo;
		return $result;
	}

	/** @param array<int,int> $map */
	private function mapped_valid_id( int $current, array $map, string $context ): int {
		if ( $current <= 0 ) {
			return 0;
		}
		if ( ! $this->aggressive_mapping_remap && $this->context_allows_target( $context, $current ) ) {
			return $current;
		}
		if ( isset( $map[ $current ] ) && $this->context_allows_target( $context, $map[ $current ] ) ) {
			return $map[ $current ];
		}
		return $this->post_exists( $current ) ? $current : 0;
	}

	private function find_latest_elementor_kit(): int {
		$wpdb = $this->wpdb;
		$posts = Identifier::quote( $wpdb->posts );
		$tornado_sql_posts = Identifier::normalize( $posts );
		$meta  = Identifier::quote( $wpdb->postmeta );
		$tornado_sql_meta = Identifier::normalize( $meta );
		return absint(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$wpdb->get_var(
				 $wpdb->prepare( "SELECT p.ID FROM %i p
				 INNER JOIN %i pm ON pm.post_id = p.ID
				 WHERE p.post_type = 'elementor_library'
				 AND p.post_status NOT IN ('trash','auto-draft')
				 AND pm.meta_key = '_elementor_template_type' AND pm.meta_value = 'kit'
				 ORDER BY p.ID DESC LIMIT 1", $tornado_sql_posts, $tornado_sql_meta ) 
			)
		);
	}

	private function logo_from_active_kit( int $kit_id ): int {
		if ( $kit_id <= 0 ) {
			return 0;
		}
		$settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
		if ( ! is_array( $settings ) ) {
			return 0;
		}
		return $this->find_media_id_in_value( $settings, 'site_logo' );
	}

	private function find_media_id_in_value( mixed $value, string $context ): int {
		if ( ! is_array( $value ) ) {
			return 0;
		}
		foreach ( $value as $key => $item ) {
			$key_string = strtolower( (string) $key );
			if ( str_contains( $key_string, $context ) || ( str_contains( $context, 'logo' ) && str_contains( $key_string, 'logo' ) ) ) {
				if ( is_array( $item ) ) {
					$id = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
					if ( $id > 0 && 'attachment' === $this->post_type( $id ) && wp_get_attachment_url( $id ) ) {
						return $id;
					}
					$url = isset( $item['url'] ) && is_string( $item['url'] ) ? $item['url'] : '';
					if ( '' !== $url && function_exists( 'attachment_url_to_postid' ) ) {
						$resolved = absint( attachment_url_to_postid( $url ) );
						if ( $resolved > 0 ) {
							return $resolved;
						}
					}
				} else {
					$id = absint( $item );
					if ( $id > 0 && 'attachment' === $this->post_type( $id ) ) {
						return $id;
					}
				}
			}
			if ( is_array( $item ) ) {
				$found = $this->find_media_id_in_value( $item, $context );
				if ( $found > 0 ) {
					return $found;
				}
			}
		}
		return 0;
	}

	/**
	 * Verifies that every internal Elementor media object still resolves to the same
	 * WordPress Attachment after Reindex. Only internal uploads or IDs present in the
	 * Mapping are treated as mandatory references; external URLs are left untouched.
	 *
	 * @param array<int,int> $map
	 * @return array<string,mixed>
	 */
	private function verify_elementor_media_references( array $map ): array {
		$wpdb = $this->wpdb;
		$result = array(
			'references_checked'          => 0,
			'valid_references'            => 0,
			'unresolved_count'            => 0,
			'unique_unresolved_count'     => 0,
			'blocking_unresolved_count'   => 0,
			'advisory_unresolved_count'   => 0,
			'url_only_detached_count'      => 0,
			'url_only_existing_file_count' => 0,
			'url_only_missing_file_count'  => 0,
			'url_only_unique_count'        => 0,
			'url_only_examples'            => array(),
			'all_media_resolved'          => true,
			'safe_for_reindex'            => true,
			'categories'                  => array(),
			'samples'                     => array(),
			'unresolved_signatures'       => array(),
			'advisory_signatures'         => array(),
			'blocking_signatures'         => array(),
			'_seen_signatures'            => array(),
			'_seen_url_only'              => array(),
			'_url_only_example_index'     => array(),
		);
		$table  = Identifier::quote( $wpdb->postmeta );
		$tornado_sql_table = Identifier::normalize( $table );
		$cursor = 0;

		while ( true ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_id, post_id, meta_key, meta_value FROM %i
					 WHERE meta_id > %d AND meta_key IN (%s, %s)
					 ORDER BY meta_id ASC LIMIT %d",
					$tornado_sql_table,
					$cursor,
					'_elementor_data',
					'_elementor_page_settings',
					self::BATCH_SIZE
				),
				ARRAY_A
			);
			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$cursor = (int) $row['meta_id'];
				$raw    = (string) $row['meta_value'];
				$value  = json_decode( $raw, true, 512, JSON_BIGINT_AS_STRING );
				if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $value ) ) {
					$value = SafeSerialization::maybe_unserialize( $raw );
				}
				if ( ! is_array( $value ) ) {
					continue;
				}
				$this->inspect_media_references(
					$value,
					(string) $row['meta_key'],
					'$',
					$map,
					$result,
					array(
						'meta_id'  => $cursor,
						'post_id'  => absint( $row['post_id'] ?? 0 ),
						'meta_key' => (string) $row['meta_key'],
					)
				);
			}
		}

		$result['unresolved_signatures'] = array_values( array_unique( array_map( 'strval', $result['unresolved_signatures'] ) ) );
		$result['advisory_signatures']   = array_values( array_unique( array_map( 'strval', $result['advisory_signatures'] ) ) );
		$result['blocking_signatures']   = array_values( array_unique( array_map( 'strval', $result['blocking_signatures'] ) ) );
		sort( $result['unresolved_signatures'], SORT_STRING );
		sort( $result['advisory_signatures'], SORT_STRING );
		sort( $result['blocking_signatures'], SORT_STRING );
		$result['unique_unresolved_count'] = count( $result['unresolved_signatures'] );
		$result['all_media_resolved']      = 0 === (int) $result['unresolved_count'];
		$result['safe_for_reindex']        = 0 === (int) $result['blocking_unresolved_count'];
		$result['unresolved_fingerprint']  = hash( 'sha256', implode( "\n", $result['unresolved_signatures'] ) );
		$result['advisory_fingerprint']    = hash( 'sha256', implode( "\n", $result['advisory_signatures'] ) );
		unset( $result['_seen_signatures'], $result['_seen_url_only'], $result['_url_only_example_index'] );
		return $result;
	}

	/**
	 * @param array<int|string,mixed> $value
	 * @param array<int,int>          $map
	 * @param array<string,mixed>     $result
	 * @param array<string,mixed>     $row_context
	 */
	private function inspect_media_references( array $value, string $context, string $path, array $map, array &$result, array $row_context ): void {
		$id_key  = $this->media_id_key( $value );
		$url_key = $this->media_url_key( $value );
		$id      = null !== $id_key ? $this->numeric_id( $value[ $id_key ] ) : 0;
		$url     = null !== $url_key && is_string( $value[ $url_key ] ) ? trim( $value[ $url_key ] ) : '';
		$is_media_object = $this->looks_like_media_object( $value, $context, $id, $url );

		if ( $is_media_object ) {
			$internal_url = '' !== $url && $this->is_probable_wordpress_media_url( $url );
			$mandatory    = $internal_url
				|| ( $id > 0 && isset( $map[ $id ] ) )
				|| ( $id > 0 && ! $this->attachment_is_usable( $id ) && preg_match( '/(?:logo|favicon|icon|image|media|thumbnail|poster|gallery|slide|background)/', strtolower( $context ) ) );

			if ( $mandatory ) {
				++$result['references_checked'];
				$resolved = $internal_url ? $this->resolve_attachment_id_from_url( $url ) : 0;

				if ( $resolved > 0 ) {
					if ( $id === $resolved && $this->attachment_is_usable( $id ) ) {
						++$result['valid_references'];
					} else {
						$this->record_media_issue(
							$result,
							$row_context,
							$path,
							$context,
							$id,
							$resolved,
							$url,
							'blocking',
							'id_url_mismatch',
							'URL تصویر به Attachment معتبر می‌رسد، اما شناسه ذخیره‌شده با آن هماهنگ نشده است.'
						);
					}
				} elseif ( $internal_url ) {
					$current_url = $id > 0 && $this->attachment_is_usable( $id ) ? wp_get_attachment_url( $id ) : '';
					if ( is_string( $current_url ) && '' !== $current_url && $this->same_upload_asset( $url, $current_url ) ) {
						++$result['valid_references'];
					} else {
						$this->record_url_only_media(
							$result,
							$row_context,
							$path,
							$context,
							$id,
							$url
						);
					}
				} elseif ( $id > 0 && $this->attachment_is_usable( $id ) ) {
					++$result['valid_references'];
				} elseif ( $id > 0 && isset( $map[ $id ] ) && $this->attachment_is_usable( (int) $map[ $id ] ) ) {
					$this->record_media_issue(
						$result,
						$row_context,
						$path,
						$context,
						$id,
						(int) $map[ $id ],
						$url,
						'blocking',
						'mapping_not_applied',
						'شناسه رسانه فاقد URL است و Mapping معتبر آن هنوز روی داده ذخیره‌شده اعمال نشده است.'
					);
				} elseif ( $id > 0 ) {
					$this->record_media_issue(
						$result,
						$row_context,
						$path,
						$context,
						$id,
						0,
						$url,
						'blocking',
						'id_only_unresolved',
						'Reference رسانه فقط شناسه دارد و بدون URL یا مسیر فایل، هویت Attachment آن قابل اثبات نیست.'
					);
				}
			}
		}

		/*
		 * A media object such as {id,url} must be counted once. Previously, when its
		 * parent key was "images" or "gallery", the same id was counted again as a
		 * collection item and the UI displayed inflated numbers such as 123.
		 */
		$is_collection = ! $is_media_object && $this->is_id_list_key( $context );
		foreach ( $value as $child_key => $child_value ) {
			$child_path = $path . '.' . (string) $child_key;
			if ( is_array( $child_value ) ) {
				$this->inspect_media_references( $child_value, (string) $child_key, $child_path, $map, $result, $row_context );
				continue;
			}
			if ( ! $is_collection ) {
				continue;
			}
			$child_id = $this->numeric_id( $child_value );
			if ( $child_id <= 0 ) {
				continue;
			}
			++$result['references_checked'];
			if ( $this->attachment_is_usable( $child_id ) ) {
				++$result['valid_references'];
				continue;
			}
			$expected = isset( $map[ $child_id ] ) && $this->attachment_is_usable( (int) $map[ $child_id ] )
				? (int) $map[ $child_id ]
				: 0;
			$this->record_media_issue(
				$result,
				$row_context,
				$child_path,
				$context,
				$child_id,
				$expected,
				'',
				'blocking',
				$expected > 0 ? 'mapping_not_applied' : 'id_only_unresolved',
				$expected > 0
					? 'شناسه موجود در فهرست رسانه‌ها با Mapping معتبر هماهنگ نشده است.'
					: 'شناسه موجود در فهرست رسانه‌ها فاقد Attachment معتبر و فاقد URL بازیابی است.'
			);
		}
	}

	/**
	 * @param array<string,mixed> $result
	 * @param array<string,mixed> $row_context
	 */
	private function record_media_issue(
		array &$result,
		array $row_context,
		string $path,
		string $context,
		int $current_id,
		int $expected_id,
		string $url,
		string $severity,
		string $reason_code,
		string $reason
	): void {
		++$result['unresolved_count'];
		if ( 'blocking' === $severity ) {
			++$result['blocking_unresolved_count'];
		} else {
			++$result['advisory_unresolved_count'];
		}

		$normalized_url = '' !== $url ? strtolower( rawurldecode( (string) preg_replace( '/[#?].*$/', '', $url ) ) ) : '';
		$identity       = array(
			'meta_id'     => (int) ( $row_context['meta_id'] ?? 0 ),
			'meta_key'    => (string) ( $row_context['meta_key'] ?? '' ),
			'path'        => $path,
			'url'         => $normalized_url,
			'reason_code' => $reason_code,
		);
		if ( '' === $normalized_url ) {
			$identity['current_id'] = $current_id;
		}
		$signature = hash( 'sha256', (string) wp_json_encode( $identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		$result['unresolved_signatures'][] = $signature;
		$result[ 'blocking' === $severity ? 'blocking_signatures' : 'advisory_signatures' ][] = $signature;

		if ( isset( $result['_seen_signatures'][ $signature ] ) ) {
			return;
		}
		$result['_seen_signatures'][ $signature ] = true;
		$result['categories'][ $reason_code ] = (int) ( $result['categories'][ $reason_code ] ?? 0 ) + 1;

		if ( count( $result['samples'] ) < self::MEDIA_SAMPLE_LIMIT ) {
			$result['samples'][] = array_merge(
				$row_context,
				array(
					'path'        => $path,
					'context'     => $context,
					'current_id'  => $current_id,
					'expected_id' => $expected_id,
					'url'         => $url,
					'severity'    => $severity,
					'reason_code' => $reason_code,
					'reason'      => $reason,
				)
			);
		}
	}

	/** @return list<int> */
	private function document_ids(): array {
		$wpdb = $this->wpdb;
		$posts        = Identifier::normalize( $wpdb->posts );
		$postmeta     = Identifier::normalize( $wpdb->postmeta );
		$args         = array( '_elementor_data', '_elementor_edit_mode', '_elementor_page_settings' );
		$placeholders = implode( ',', array_fill( 0, count( $args ), '%s' ) );
		$sql_args     = array_merge( array( $posts, $postmeta ), $args );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Only the generated meta-key placeholder list is interpolated; table identifiers and values are prepared.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID FROM %i p INNER JOIN %i pm ON pm.post_id = p.ID WHERE p.post_status NOT IN ('trash','auto-draft') AND pm.meta_key IN ({$placeholders}) ORDER BY CASE WHEN p.post_type = 'elementor_library' THEN 0 ELSE 1 END, p.ID ASC",
				$sql_args
			)
		);
		$ids = array_values( array_unique( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) ) );

		$active_kit_id = absint( get_option( ExternalOptionBridge::ELEMENTOR_ACTIVE_KIT, 0 ) );
		if ( $active_kit_id > 0 && get_post( $active_kit_id ) ) {
			array_unshift( $ids, $active_kit_id );
			$ids = array_values( array_unique( $ids ) );
		}
		return $ids;
	}

	private function regenerate_document( int $post_id, array &$errors ): bool {
		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return false;
		}

		try {
			wp_cache_delete( $post_id, 'post_meta' );
			if ( class_exists( '\\Elementor\\Core\\Files\\CSS\\Post' ) ) {
				$css_class = '\\Elementor\\Core\\Files\\CSS\\Post';
				$css_file  = null;

				try {
					$reflection = new \ReflectionClass( $css_class );
					$constructor = $reflection->getConstructor();
					if ( null === $constructor || $constructor->isPublic() ) {
						$css_file = $reflection->newInstance( $post_id );
					}
				} catch ( \ReflectionException ) {
					$css_file = null;
				}

				if ( null === $css_file && method_exists( $css_class, 'create' ) ) {
					$create_method = new \ReflectionMethod( $css_class, 'create' );
					if ( $create_method->isPublic() && $create_method->isStatic() ) {
						$css_file = $css_class::create( $post_id );
					}
				}

				if ( is_object( $css_file ) && method_exists( $css_file, 'update' ) ) {
					$update_method = new \ReflectionMethod( $css_file, 'update' );
					if ( $update_method->isPublic() ) {
						$css_file->update();
						return true;
					}
				}
			}
		} catch ( \Throwable $throwable ) {
			$errors[] = sprintf( 'CSS سند Elementor با ID %1$d بازسازی نشد: %2$s', $post_id, sanitize_text_field( $throwable->getMessage() ) );
			return false;
		}

		$errors[] = sprintf( 'برای سند Elementor با ID %d، روش سازگاری برای بازسازی CSS پیدا نشد.', $post_id );
		return false;
	}

	/** @return array<string,mixed> */
	private function verify_css_files( array $document_ids ): array {
		$print_method = sanitize_key( (string) ExternalOptionBridge::get( ExternalOptionBridge::ELEMENTOR_CSS_PRINT_METHOD, 'external' ) );
		if ( 'internal' === $print_method ) {
			return array(
				'print_method' => 'internal',
				'checked'      => 0,
				'missing'      => array(),
				'message'      => 'روش خروجی CSS روی Internal Embedding است؛ وجود فایل فیزیکی الزامی نیست.',
			);
		}

		$uploads = wp_upload_dir();
		$css_dir = trailingslashit( wp_normalize_path( (string) ( $uploads['basedir'] ?? '' ) ) ) . 'elementor/css';
		$missing = array();
		foreach ( $document_ids as $post_id ) {
			$file = trailingslashit( $css_dir ) . 'post-' . absint( $post_id ) . '.css';
			if ( ! is_file( $file ) || filesize( $file ) <= 0 ) {
				$missing[] = absint( $post_id );
			}
		}
		return array(
			'print_method'  => $print_method,
			'checked'       => count( $document_ids ),
			'missing'       => array_slice( $missing, 0, 100 ),
			'missing_count' => count( $missing ),
		);
	}

	/** @return array<string,mixed> */
	private function clear_theme_builder_conditions(): array {
		$result = array(
			'elementor_pro_active' => defined( 'ELEMENTOR_PRO_VERSION' ),
			'cache_rows_removed'    => $this->delete_theme_builder_cache_rows(),
			'cleared'               => false,
			'method'                => 'cache_option_deleted',
		);

		if ( ! defined( 'ELEMENTOR_PRO_VERSION' ) || ! class_exists( '\\ElementorPro\\Modules\\ThemeBuilder\\Module' ) ) {
			return $result;
		}

		try {
			$module_class = '\\ElementorPro\\Modules\\ThemeBuilder\\Module';
			$module       = method_exists( $module_class, 'instance' ) ? $module_class::instance() : null;
			if ( is_object( $module ) && method_exists( $module, 'get_conditions_manager' ) ) {
				$manager = $module->get_conditions_manager();
				foreach ( array( 'clear_cache', 'delete_cache', 'reset_cache' ) as $method ) {
					if ( is_object( $manager ) && method_exists( $manager, $method ) ) {
						$manager->{$method}();
						$result['cleared'] = true;
						$result['method']  = 'php:' . $method;
						return $result;
					}
				}
			}
		} catch ( \Throwable $throwable ) {
			$result['error'] = sanitize_text_field( $throwable->getMessage() );
		}

		do_action( 'shcd_tornado_dbm_elementor_theme_builder_clear_conditions' );
		$result['method'] = 'cache_option_deleted+compatibility_hook';
		return $result;
	}

	private function delete_theme_builder_cache_rows(): int {
		$wpdb = $this->wpdb;
		$deleted = 0;
		if ( ExternalOptionBridge::delete( ExternalOptionBridge::ELEMENTOR_PRO_THEME_BUILDER_CACHE ) ) {
			++$deleted;
		}
		$table = Identifier::quote( $wpdb->options );
		$tornado_sql_table = Identifier::normalize( $table );
		$patterns = array(
			$wpdb->esc_like( '_transient_elementor_pro_theme_builder' ) . '%',
			$wpdb->esc_like( '_transient_timeout_elementor_pro_theme_builder' ) . '%',
			$wpdb->esc_like( '_site_transient_elementor_pro_theme_builder' ) . '%',
			$wpdb->esc_like( '_site_transient_timeout_elementor_pro_theme_builder' ) . '%',
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$result = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s',
				$tornado_sql_table,
				$patterns[0],
				$patterns[1],
				$patterns[2],
				$patterns[3]
			)
		);
		if ( false !== $result ) {
			$deleted += (int) $result;
		}
		wp_cache_delete( 'elementor_pro_theme_builder_conditions', 'options' );
		return $deleted;
	}

	/** @return array<string,mixed> */
	private function verify_custom_template_documents(): array {
		$wpdb = $this->wpdb;
		$result = array(
			'checked'      => 0,
			'loadable'     => 0,
			'not_loadable' => array(),
		);
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return $result;
		}
		$plugin = \Elementor\Plugin::instance();
		if ( ! isset( $plugin->documents ) || ! method_exists( $plugin->documents, 'get' ) ) {
			return $result;
		}

		$types = $this->discover_custom_template_post_types();
		if ( empty( $types ) ) {
			return $result;
		}
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$posts        = Identifier::quote( $wpdb->posts );
		$tornado_sql_posts = Identifier::normalize( $posts );
		$args         = array_merge( $types, array( 200 ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct uncached access is intentional for live database inspection or mutation; caching could make safety checks operate on stale state.
		$ids          = $wpdb->get_col(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Generated SQL fragment is restricted to validated identifiers, fixed clauses, or a placeholder list; all external data values are passed to prepare().
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only validated identifiers, fixed internal clauses, or generated placeholder lists are interpolated; external values are passed to prepare().
				"SELECT ID FROM %i WHERE post_type IN ({$placeholders}) AND post_status NOT IN ('trash','auto-draft') ORDER BY ID ASC LIMIT %d",
				array_merge( array( $tornado_sql_posts ), $args )
			)
		);
		foreach ( is_array( $ids ) ? $ids : array() as $raw_id ) {
			$post_id = absint( $raw_id );
			++$result['checked'];
			try {
				$document = $plugin->documents->get( $post_id, false );
				if ( $document ) {
					++$result['loadable'];
				} else {
					$result['not_loadable'][] = $post_id;
				}
			} catch ( \Throwable ) {
				$result['not_loadable'][] = $post_id;
			}
		}
		$result['not_loadable'] = array_slice( array_values( array_unique( $result['not_loadable'] ) ), 0, 100 );
		return $result;
	}

	private function purge_elementor_object_cache( array $document_ids ): void {
		foreach ( $document_ids as $post_id ) {
			clean_post_cache( absint( $post_id ) );
			wp_cache_delete( absint( $post_id ), 'post_meta' );
		}
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			ExternalOptionBridge::delete_elementor_transient( 'elementor_remote_info_api_data_' . (string) ELEMENTOR_VERSION );
		}
	}

	private function post_exists( int $post_id ): bool {
		return $post_id > 0 && false !== $this->post_type( $post_id );
	}

	private function post_type( int $post_id ): string|false {
		if ( $post_id <= 0 ) {
			return false;
		}
		if ( array_key_exists( $post_id, $this->post_type_cache ) ) {
			return $this->post_type_cache[ $post_id ];
		}
		$type = get_post_type( $post_id );
		$this->post_type_cache[ $post_id ] = is_string( $type ) && '' !== $type ? $type : false;
		return $this->post_type_cache[ $post_id ];
	}

	private function elementor_template_type( int $post_id ): string {
		if ( isset( $this->template_type_cache[ $post_id ] ) ) {
			return $this->template_type_cache[ $post_id ];
		}
		$type = sanitize_key( (string) get_post_meta( $post_id, '_elementor_template_type', true ) );
		if ( '' === $type ) {
			$type = $this->infer_elementor_template_type( $post_id );
		}
		$this->template_type_cache[ $post_id ] = $type;
		return $type;
	}

	private function numeric_id( mixed $value ): int {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) && ctype_digit( $value ) ) {
			return (int) $value;
		}
		return 0;
	}

	private function directory_is_writable( string $directory ): bool {
		if ( '' === $directory ) {
			return false;
		}
		$current = $directory;
		while ( ! is_dir( $current ) ) {
			$parent = dirname( $current );
			if ( $parent === $current ) {
				return false;
			}
			$current = $parent;
		}
		return Filesystem::is_writable( $current );
	}
}
