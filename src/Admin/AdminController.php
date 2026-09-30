<?php
/**
 * WordPress administration UI.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Admin;

use Shcd\TornadoDatabaseMaintenance\Core\Capabilities;
use Shcd\TornadoDatabaseMaintenance\Report\ReportExporter;

final class AdminController {
	private const PAGE = 'shcd-tornado';

	public function __construct( private readonly ReportExporter $reports ) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_shcd_tornado_dbm_export_report', array( $this, 'export_report' ) );
	}

	public function menu(): void {
		add_management_page(
			esc_html__( 'Tornado', 'shcd-database-maintenance' ),
			esc_html__( 'Tornado', 'shcd-database-maintenance' ),
			Capabilities::VIEW_DASHBOARD,
			self::PAGE,
			array( $this, 'render' )
		);
	}

	public function assets( string $hook ): void {
		global $wpdb;

		if ( 'tools_page_' . self::PAGE !== $hook ) {
			return;
		}

		$css_file = SHCD_TORNADO_DBM_DIR . 'assets/css/admin.css';
		$js_file  = SHCD_TORNADO_DBM_DIR . 'assets/js/admin.js';
		$css_version = SHCD_TORNADO_DBM_VERSION . '-' . ( is_file( $css_file ) ? (string) filemtime( $css_file ) : '0' );
		$js_version  = SHCD_TORNADO_DBM_VERSION . '-' . ( is_file( $js_file ) ? (string) filemtime( $js_file ) : '0' );

		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style( 'shcd-tornado-dbm-admin', SHCD_TORNADO_DBM_URL . 'assets/css/admin.css', array(), $css_version );
		wp_enqueue_script( 'shcd-tornado-dbm-admin', SHCD_TORNADO_DBM_URL . 'assets/js/admin.js', array( 'wp-api-fetch', 'wp-i18n' ), $js_version, true );
		wp_localize_script(
			'shcd-tornado-dbm-admin',
			'SHCD_TORNADO_DBM_ADMIN',
			array(
				'root'        => esc_url_raw( rest_url( 'shcd-tornado-dbm/v1/' ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'exportBase'  => admin_url( 'admin-post.php?action=shcd_tornado_dbm_export_report' ),
				'exportNonce' => wp_create_nonce( 'shcd_tornado_dbm_export_report' ),
				'tables'      => array(
					'prefix'   => (string) $wpdb->prefix,
					'posts'    => (string) $wpdb->posts,
					'postmeta' => (string) $wpdb->postmeta,
					'options'  => (string) $wpdb->options,
				),
				'i18n'        => array(
					'working' => __( 'لطفاً چند لحظه صبر کنید؛ عملیات در حال انجام است…', 'shcd-database-maintenance' ),
					'failed'  => __( 'اجرای عملیات با خطا متوقف شد.', 'shcd-database-maintenance' ),
				),
			)
		);
	}

	public function render(): void {
		global $wpdb;

		if ( ! current_user_can( Capabilities::VIEW_DASHBOARD ) ) {
			wp_die( esc_html__( 'حساب کاربری شما مجوز دسترسی به این بخش را ندارد.', 'shcd-database-maintenance' ), '', array( 'response' => 403 ) );
		}
		?>
		<div class="wrap shcd-tornado-dbm-app" id="shcd-tornado-dbm-app" dir="rtl">
			<header class="shcd-tornado-dbm-header">
				<div class="shcd-tornado-dbm-brand">
					<img src="<?php echo esc_url( SHCD_TORNADO_DBM_URL . 'assets/img/shcd-logo.png' ); ?>" class="shcd-tornado-dbm-logo" alt="Tornado">
					<div>
						<div class="shcd-tornado-dbm-eyebrow">SHCD DATABASE OPERATIONS</div>
						<h1><?php echo esc_html__( 'Tornado', 'shcd-database-maintenance' ); ?></h1>
						<p><?php echo esc_html( sprintf( 'شناسایی وابستگی‌ها، پاک‌سازی ایمن دیتابیس، بازچینی شناسه‌های %s و مدیریت AUTO_INCREMENT.', $wpdb->posts ) ); ?></p>
					</div>
				</div>
				<div class="shcd-tornado-dbm-header-actions">
					<span class="shcd-tornado-dbm-chip"><span class="dashicons dashicons-shield"></span> Fail-Closed</span>
					<span class="shcd-tornado-dbm-chip">v<?php echo esc_html( SHCD_TORNADO_DBM_VERSION ); ?></span>
					<span class="shcd-tornado-dbm-chip">Build <?php echo esc_html( SHCD_TORNADO_DBM_BUILD ); ?></span>
				</div>
			</header>

			<div id="shcd-tornado-dbm-notice" class="shcd-tornado-dbm-notice" hidden></div>

			<div class="shcd-tornado-dbm-layout">
				<aside class="shcd-tornado-dbm-sidebar">
					<nav class="shcd-tornado-dbm-nav" aria-label="Tornado sections">
						<button class="shcd-tornado-dbm-tab is-active" data-tab="dashboard"><span class="dashicons dashicons-dashboard"></span><span>داشبورد</span></button>
						<button class="shcd-tornado-dbm-tab" data-tab="identifiers"><span class="dashicons dashicons-database"></span><span>شناسه‌ها و AUTO_INCREMENT</span></button>
						<button class="shcd-tornado-dbm-tab" data-tab="backups"><span class="dashicons dashicons-backup"></span><span>مدیریت Backupها</span></button>
						<button class="shcd-tornado-dbm-tab" data-tab="cleanup"><span class="dashicons dashicons-trash"></span><span>پاک‌سازی و تعمیر</span></button>
						<button class="shcd-tornado-dbm-tab" data-tab="tables"><span class="dashicons dashicons-editor-table"></span><span>جدول‌های بدون استفاده</span></button>
						<button class="shcd-tornado-dbm-tab" data-tab="settings"><span class="dashicons dashicons-admin-generic"></span><span>تنظیمات عمومی</span></button>
						<button class="shcd-tornado-dbm-tab" data-tab="jobs"><span class="dashicons dashicons-list-view"></span><span>عملیات، گزارش‌ها و خطاها</span></button>
					</nav>

					<div class="shcd-tornado-dbm-side-status">
						<div class="shcd-tornado-dbm-side-status__title">وضعیت ایمنی</div>
						<div class="shcd-tornado-dbm-side-status__row"><span>Backup</span><strong id="shcd-tornado-dbm-side-backup">—</strong></div>
						<div class="shcd-tornado-dbm-side-status__row"><span>Mapping</span><strong id="shcd-tornado-dbm-side-mapping">—</strong></div>
						<div class="shcd-tornado-dbm-side-status__row"><span>Reindex</span><strong id="shcd-tornado-dbm-side-reindex">آماده بررسی</strong></div>
					</div>
				</aside>

				<main class="shcd-tornado-dbm-main">
					<section class="shcd-tornado-dbm-panel is-active" data-panel="dashboard">
						<div class="shcd-tornado-dbm-page-heading">
							<div><span class="shcd-tornado-dbm-kicker">نمای کلی</span><h2>سلامت و آمادگی دیتابیس</h2><p>وضعیت کلی دیتابیس و آمادگی اجرای عملیات حساس را در یک نگاه بررسی کنید.</p></div>
							<button class="shcd-tornado-dbm-button shcd-tornado-dbm-button--ghost" data-action="refresh-dashboard"><span class="dashicons dashicons-update"></span>به‌روزرسانی</button>
						</div>

						<div class="shcd-tornado-dbm-stats">
							<div class="shcd-tornado-dbm-stat"><span class="dashicons dashicons-wordpress"></span><div><small>WordPress</small><strong id="shcd-tornado-dbm-stat-wordpress">—</strong></div></div>
							<div class="shcd-tornado-dbm-stat"><span class="dashicons dashicons-editor-code"></span><div><small>PHP</small><strong id="shcd-tornado-dbm-stat-php">—</strong></div></div>
							<div class="shcd-tornado-dbm-stat"><span class="dashicons dashicons-database"></span><div><small>Database</small><strong id="shcd-tornado-dbm-stat-database">—</strong></div></div>
							<div class="shcd-tornado-dbm-stat"><span class="dashicons dashicons-admin-post"></span><div><small><?php echo esc_html( $wpdb->posts ); ?></small><strong id="shcd-tornado-dbm-stat-posts">—</strong></div></div>
							<div class="shcd-tornado-dbm-stat"><span class="dashicons dashicons-sort"></span><div><small>Next Post ID</small><strong id="shcd-tornado-dbm-stat-next-id">—</strong></div></div>
							<div class="shcd-tornado-dbm-stat"><span class="dashicons dashicons-yes-alt"></span><div><small>Safety</small><strong id="shcd-tornado-dbm-stat-safety">در حال بررسی</strong></div></div>
						</div>

						<div class="shcd-tornado-dbm-grid shcd-tornado-dbm-grid--2">
							<article class="shcd-tornado-dbm-card">
								<div class="shcd-tornado-dbm-card__head"><div><span class="shcd-tornado-dbm-kicker">مسیر پیشنهادی</span><h3>بازچینی ایمن و کنترل‌شده شناسه‌ها</h3></div><span class="shcd-tornado-dbm-icon shcd-tornado-dbm-icon--purple"><span class="dashicons dashicons-randomize"></span></span></div>
								<div class="shcd-tornado-dbm-mini-steps">
									<span>Discovery</span><i></i><span>Mapping</span><i></i><span>Backup</span><i></i><span>Preflight</span><i></i><span>Execute</span>
								</div>
								<p>ابتدا همه وابستگی‌های Post ID در جداول و داده‌های ساختاری شناسایی می‌شوند. سپس Mapping تأییدشده، به‌صورت یکپارچه روی تمام Referenceهای ثبت‌شده اعمال خواهد شد.</p>
								<button class="shcd-tornado-dbm-button shcd-tornado-dbm-button--primary" data-open-tab="identifiers">مدیریت شناسه‌ها</button>
							</article>

							<article class="shcd-tornado-dbm-card">
								<div class="shcd-tornado-dbm-card__head"><div><span class="shcd-tornado-dbm-kicker">آخرین وضعیت ثبت‌شده</span><h3>وضعیت آمادگی عملیات</h3></div><span class="shcd-tornado-dbm-icon shcd-tornado-dbm-icon--green"><span class="dashicons dashicons-backup"></span></span></div>
								<div class="shcd-tornado-dbm-readiness">
									<div><span>Discovery UUID</span><code id="shcd-tornado-dbm-last-discovery">هنوز ثبت نشده است</code></div>
									<div><span>Mapping UUID</span><code id="shcd-tornado-dbm-last-mapping">هنوز ثبت نشده است</code></div>
									<div><span>Backup UUID</span><code id="shcd-tornado-dbm-last-backup">هنوز ثبت نشده است</code></div>
								</div>
							</article>
						</div>

						<details class="shcd-tornado-dbm-details">
							<summary>نمایش اطلاعات فنی محیط</summary>
							<pre id="shcd-tornado-dbm-system-output">Loading…</pre>
						</details>
					</section>

					<section class="shcd-tornado-dbm-panel" data-panel="identifiers">
						<div class="shcd-tornado-dbm-page-heading">
							<div><span class="shcd-tornado-dbm-kicker">بازچینی بدون شکاف شناسه‌ها و تنظیم شماره بعدی</span><h2>مدیریت ID و AUTO_INCREMENT</h2><p>در Reindex کامل، همه Postها و Attachmentهای کتابخانه رسانه در توالی پیوسته ۱ تا N قرار می‌گیرند. AUTO_INCREMENT فقط شماره رکورد بعدی را تعیین می‌کند و شناسه‌های موجود را تغییر نمی‌دهد.</p></div>
						</div>

						<div class="shcd-tornado-dbm-info-banner">
							<span class="dashicons dashicons-info-outline"></span>
							<div><strong>تفاوت این دو عملیات</strong><p><b>Reindex کامل و بدون شکاف</b> همه ردیف‌های <code><?php echo esc_html( $wpdb->posts ); ?></code>، از جمله <code>attachment</code>های کتابخانه رسانه، را به ترتیب <code>1..N</code> بازچینی می‌کند و تمام Referenceهای وابسته را با همان Mapping تغییر می‌دهد. عملیات در صورت باقی‌ماندن حتی یک شکاف، ردیف خارج از Mapping یا شناسه موقت، پیش از <code>COMMIT</code> متوقف و <code>Rollback</code> می‌شود. <b>AUTO_INCREMENT</b> فقط شماره رکورد بعدی را تنظیم می‌کند.</p></div>
						</div>

						<article class="shcd-tornado-dbm-card shcd-tornado-dbm-card--workflow">
							<div class="shcd-tornado-dbm-card__head"><div><span class="shcd-tornado-dbm-kicker">Wizard خودکار و مرحله‌ای</span><h3>Reindex کامل <?php echo esc_html( $wpdb->posts ); ?> و هماهنگ‌سازی همه Referenceهای شناسایی‌شده</h3></div><span class="shcd-tornado-dbm-risk-badge">توالی نهایی ۱ تا N</span></div>
							<div class="shcd-tornado-dbm-wizard-callout">
								<div><strong>اجرای مرحله‌ای با یک فرمان</strong><p>Wizard ابتدا یک Backup ایمنی تهیه می‌کند و آثار باقی‌مانده از Reindex قبلی، تصاویر و Builderها را بررسی و ترمیم می‌کند. سپس Discovery، Mapping تازه، Dry Run، Backup نهایی و Preflight را به‌ترتیب انجام می‌دهد. پیش از COMMIT نیز پیوستگی شناسه‌ها، نبود شکاف و مقدار صحیح AUTO_INCREMENT کنترل می‌شود.</p></div>
								<button type="button" class="shcd-tornado-dbm-button shcd-tornado-dbm-button--primary shcd-tornado-dbm-button--large" data-action="reindex-wizard"><span class="dashicons dashicons-controls-play"></span>شروع Wizard</button>
							</div>
							<div class="shcd-tornado-dbm-wizard-progress" id="shcd-tornado-dbm-wizard-progress" aria-live="polite">
								<div data-wizard-step="prepare"><b>1</b><span>آماده‌سازی</span><small>تهیه Backup ایمنی و بررسی نسل قبلی</small></div>
								<div data-wizard-step="discover"><b>2</b><span>Discovery</span><small>هنوز اجرا نشده است</small></div>
								<div data-wizard-step="mapping"><b>3</b><span>Mapping</span><small>هنوز اجرا نشده است</small></div>
								<div data-wizard-step="dry-run"><b>4</b><span>Dry Run</span><small>هنوز اجرا نشده است</small></div>
								<div data-wizard-step="backup"><b>5</b><span>Backup نهایی</span><small>هنوز اجرا نشده است</small></div>
								<div data-wizard-step="preflight"><b>6</b><span>Preflight</span><small>هنوز اجرا نشده است</small></div>
							</div>
							<details class="shcd-tornado-dbm-details shcd-tornado-dbm-details--compact">
								<summary>ابزارهای دستی و تنظیمات پیشرفته</summary>
								<div class="shcd-tornado-dbm-workflow">
									<button data-action="reindex-prepare"><b>1</b><span>آماده‌سازی</span><small>Backup ایمنی، تعمیر نسل قبلی و تصاویر</small></button>
									<button data-action="discover"><b>2</b><span>Discovery</span><small>شناسایی جدول‌ها، ستون‌ها و Referenceها</small></button>
									<button data-action="mapping"><b>3</b><span>Mapping</span><small>Old → Temp → New</small></button>
									<button data-action="dry-run"><b>4</b><span>Dry Run</span><small>بررسی نتیجه بدون اعمال تغییر</small></button>
									<button data-action="backup"><b>5</b><span>Backup</span><small>تهیه Backup معتبر و قابل بررسی</small></button>
									<button data-action="reindex-preflight"><b>6</b><span>Preflight</span><small>بررسی Integrity و موارد بازدارنده</small></button>
								</div>
							</details>

							<div class="shcd-tornado-dbm-form-grid shcd-tornado-dbm-form-grid--3">
								<label><span>Mapping UUID</span><input type="text" id="shcd-tornado-dbm-mapping-uuid" autocomplete="off" placeholder="پس از ساخت Mapping، خودکار درج می‌شود"></label>
								<label><span>Backup UUID</span><input type="text" id="shcd-tornado-dbm-backup-uuid" autocomplete="off" placeholder="پس از تهیه Backup، خودکار درج می‌شود"></label>
								<label><span>عبارت تأیید</span><input type="text" id="shcd-tornado-dbm-confirmation" autocomplete="off"></label>
							</div>
							<input type="hidden" id="shcd-tornado-dbm-reindex-mapping">
							<div class="shcd-tornado-dbm-form-grid shcd-tornado-dbm-form-grid--2 shcd-tornado-dbm-arm-box">
								<label><span>Token یک‌بارمصرف Reindex</span><input type="text" id="shcd-tornado-dbm-reindex-authorization" autocomplete="off" readonly placeholder="پس از صدور مجوز، Token به‌صورت خودکار در این کادر قرار می‌گیرد"></label>
								<div class="shcd-tornado-dbm-button-row"><button class="shcd-tornado-dbm-button shcd-tornado-dbm-button--ghost" data-action="reindex-arm"><span class="dashicons dashicons-update"></span>صدور مجوز جدید</button><button class="shcd-tornado-dbm-button shcd-tornado-dbm-button--danger" data-action="reindex-execute"><span class="dashicons dashicons-warning"></span>تأیید و اجرای نهایی Reindex</button></div>
							</div>
							<div id="shcd-tornado-dbm-identifiers-output" class="shcd-tornado-dbm-result shcd-tornado-dbm-result--empty">گزارش هر مرحله از Wizard در این بخش نمایش داده می‌شود.</div>
						</article>

						<article class="shcd-tornado-dbm-card">
							<div class="shcd-tornado-dbm-card__head"><div><span class="shcd-tornado-dbm-kicker">تاریخچه نسل‌های قابل بازیابی</span><h3>نسل‌های Reindex</h3><p>هر اجرای موفق به‌عنوان یک نسل مستقل ثبت می‌شود. نسل بعدی همیشه Mapping تازه می‌سازد و Backupهای لازم برای بازیابی نسل‌های اخیر محفوظ می‌مانند.</p></div><button type="button" class="shcd-tornado-dbm-button shcd-tornado-dbm-button--ghost" data-action="reindex-generations"><span class="dashicons dashicons-backup"></span>نمایش تاریخچه نسل‌ها</button></div>
							<div id="shcd-tornado-dbm-generations-output" class="shcd-tornado-dbm-result shcd-tornado-dbm-result--empty">برای مشاهده شماره نسل، Mapping، Backup، تعداد شکاف‌های اولیه و نتیجه اعتبارسنجی، تاریخچه را باز کنید.</div>
						</article>

						<article class="shcd-tornado-dbm-card">
							<div class="shcd-tornado-dbm-card__head"><div><span class="shcd-tornado-dbm-kicker">تنظیمات مستقل</span><h3>AUTO_INCREMENT Manager</h3></div><span class="shcd-tornado-dbm-icon shcd-tornado-dbm-icon--blue"><span class="dashicons dashicons-sort"></span></span></div>
							<form id="shcd-tornado-dbm-id-settings-form">
								<div class="shcd-tornado-dbm-form-grid shcd-tornado-dbm-form-grid--2">
									<label><span>دامنه بررسی دستی</span><select name="auto_increment_scope" id="shcd-tornado-dbm-auto-increment-scope"><option value="posts_only">فقط <?php echo esc_html( $wpdb->posts ); ?></option><option value="wordpress_core">تمام جدول‌های اصلی وردپرس</option><option value="all_prefixed">تمام جدول‌های دارای پیشوند همین سایت</option></select></label>
									<div class="shcd-tornado-dbm-switch-list">
										<label class="shcd-tornado-dbm-switch"><input type="checkbox" name="allow_destructive_reindex"><span></span><b>اجازه اجرای Reindex کامل و تغییر شناسه‌ها</b></label>
										<label class="shcd-tornado-dbm-switch"><input type="checkbox" name="auto_register_discovered_references"><span></span><b>ثبت خودکار ستون‌هایی که ارتباط آن‌ها با Post ID تأیید شده است</b></label>
										<label class="shcd-tornado-dbm-switch"><input type="checkbox" name="auto_resolve_ambiguous_references"><span></span><b>حل خودکار Referenceهای مبهم با بررسی Post Type و Integrity</b></label>
										<label class="shcd-tornado-dbm-switch"><input type="checkbox" name="elementor_repair_after_reindex"><span></span><b>ترمیم خودکار تصاویر، لوگو، CSS، Kit و Cacheهای Elementor پس از Reindex</b></label>
										<label class="shcd-tornado-dbm-switch"><input type="checkbox" name="purge_missing_attachment_rows_before_reindex"><span></span><b>حذف Attachmentهایی که هیچ فایل معتبری در uploads ندارند، پیش از ساخت Mapping</b></label>
										<label class="shcd-tornado-dbm-switch"><input type="checkbox" name="purge_media_cleaner_trash_before_reindex"><span></span><b>حذف رکوردهای wmpc-trash که فایل محلی آن‌ها وجود ندارد، پیش از ساخت Mapping</b></label>
										<label class="shcd-tornado-dbm-switch"><input type="checkbox" name="restore_missing_attachments_from_uploads"><span></span><b>ثبت دوباره فایل‌های URL-only در Media Library (پیش‌فرض: خاموش)</b></label>
										<label class="shcd-tornado-dbm-switch"><input type="checkbox" name="auto_increment_after_reindex"><span></span><b>پس از Reindex شناسه‌ها</b></label>
										<label class="shcd-tornado-dbm-switch"><input type="checkbox" name="auto_increment_after_cleanup"><span></span><b>پس از پاک‌سازی و Orphan Cleaner</b></label>
										<label class="shcd-tornado-dbm-switch"><input type="checkbox" name="auto_increment_after_cache"><span></span><b>پس از پاک‌سازی Cacheها و Transient</b></label>
									</div>
								</div>
								<div class="shcd-tornado-dbm-button-row"><button type="submit" class="shcd-tornado-dbm-button shcd-tornado-dbm-button--primary">ذخیره تنظیمات شناسه‌ها</button><button type="button" class="shcd-tornado-dbm-button shcd-tornado-dbm-button--ghost" data-action="auto-increment-preview">پیش‌نمایش شمارنده‌ها</button><button type="button" class="shcd-tornado-dbm-button shcd-tornado-dbm-button--success" data-action="auto-increment-execute">تنظیم روی MAX(ID)+1</button></div>
							</form>
							<div id="shcd-tornado-dbm-auto-increment-output" class="shcd-tornado-dbm-result shcd-tornado-dbm-result--empty">پیش‌نمایش جدول‌های دارای AUTO_INCREMENT اینجا نمایش داده می‌شود.</div>
						</article>
					</section>


					<section class="shcd-tornado-dbm-panel" data-panel="backups">
						<div class="shcd-tornado-dbm-page-heading">
							<div><span class="shcd-tornado-dbm-kicker">Backup Center</span><h2>مدیریت Backupها</h2><p>فهرست Backupهای ثبت‌شده، وضعیت فایل، Checksum و حذف کنترل‌شده نسخه‌های غیرضروری.</p></div>
							<div class="shcd-tornado-dbm-button-row"><button class="shcd-tornado-dbm-button shcd-tornado-dbm-button--primary" data-action="backup-manager-create"><span class="dashicons dashicons-plus-alt2"></span>ساخت Backup جدید</button><button class="shcd-tornado-dbm-button shcd-tornado-dbm-button--ghost" data-action="backups-refresh"><span class="dashicons dashicons-update"></span>به‌روزرسانی فهرست</button></div>
						</div>
						<div class="shcd-tornado-dbm-info-banner">
							<span class="dashicons dashicons-info-outline"></span>
							<div><strong>بازتنظیم شناسه داخلی Backup</strong><p>پس از حذف Backup، اگر رکورد حذف‌شده در انتهای جدول باشد، <code>AUTO_INCREMENT</code> روی همان ID حذف‌شده قرار می‌گیرد. شکاف‌های میانی تا زمانی که ID بزرگ‌تر وجود دارد قابل استفاده مجدد نیستند.</p></div>
						</div>
						<article class="shcd-tornado-dbm-card">
							<div class="shcd-tornado-dbm-manager-toolbar">
								<label class="shcd-tornado-dbm-select-all"><input type="checkbox" id="shcd-tornado-dbm-backups-select-all"> انتخاب همه موارد این صفحه</label>
								<div class="shcd-tornado-dbm-button-row"><button class="shcd-tornado-dbm-button shcd-tornado-dbm-button--danger" data-action="backups-delete-selected"><span class="dashicons dashicons-trash"></span>حذف Backupهای انتخاب‌شده</button></div>
							</div>
							<div class="shcd-tornado-dbm-table-wrap"><table class="shcd-tornado-dbm-data-table" id="shcd-tornado-dbm-backups-table"><thead><tr><th></th><th>ID</th><th>UUID</th><th>فایل</th><th>حجم</th><th>جدول‌ها</th><th>Snapshot</th><th>مسیر خصوصی</th><th>تاریخ</th></tr></thead><tbody></tbody></table></div>
							<div id="shcd-tornado-dbm-backups-empty" class="shcd-tornado-dbm-empty-state">فهرست Backupها هنوز بارگذاری نشده است.</div>
							<div id="shcd-tornado-dbm-backup-manager-output" class="shcd-tornado-dbm-result shcd-tornado-dbm-result--empty">نتیجه مدیریت Backupها در این بخش نمایش داده می‌شود.</div>
						</article>
					</section>

					<section class="shcd-tornado-dbm-panel" data-panel="cleanup">
						<div class="shcd-tornado-dbm-page-heading"><div><span class="shcd-tornado-dbm-kicker">Preview First</span><h2>پاک‌سازی و تعمیر</h2><p>ابتدا تعداد رکوردها بررسی می‌شود؛ حذف فقط با Preview معتبر انجام می‌گیرد.</p></div></div>
						<div class="shcd-tornado-dbm-info-banner"><span class="dashicons dashicons-database"></span><div><strong>جدول فعال نوشته‌ها: <code><?php echo esc_html( $wpdb->posts ); ?></code></strong><p>Tornado نام جدول را از <code>$wpdb-&gt;posts</code> می‌خواند و به پیشوند ثابت <code>wp_</code> وابسته نیست. گزینه «تمام Revisionها» همه ردیف‌های <code>post_type=revision</code> را حذف می‌کند؛ گزینه «Revisionهای مازاد» فقط نسخه‌های بیشتر از سقف نگهداری را پاک می‌کند.</p></div></div>
						<article class="shcd-tornado-dbm-card">
							<div class="shcd-tornado-dbm-cleanup-grid" id="shcd-tornado-dbm-cleanup-types">
								<?php
								$cleaners = array(
									'all_revisions' => array( 'تمام Revisionها', 'همه نسخه‌های قبلی نوشته‌ها و برگه‌ها؛ این گزینه Autosave و Pending Revision را نیز دربر می‌گیرد' ),
									'revisions' => array( 'Revisionهای مازاد', 'فقط نسخه‌هایی که از سقف نگهداری تعیین‌شده بیشتر هستند' ),
									'auto_drafts' => array( 'Auto Draft', 'پیش‌نویس‌های خودکار قدیمی' ),
									'trash' => array( 'Trash', 'محتوای زباله‌دان قدیمی' ),
									'media_cleaner_trash_missing' => array( 'Media Cleaner Trash', 'رکوردهای wmpc-trash که فایل محلی آن‌ها دیگر وجود ندارد' ),
									'autosaves' => array( 'Auto Save', 'ذخیره‌های خودکار Revision' ),
									'pending_revisions' => array( 'Pending Revision', 'بازبینی‌های معلق' ),
									'orphan_postmeta' => array( 'Orphan Post Meta', 'متادیتای بدون پست' ),
									'orphan_comments' => array( 'Orphan Comments', 'دیدگاه بدون پست' ),
									'orphan_commentmeta' => array( 'Orphan Comment Meta', 'متادیتای بدون دیدگاه' ),
									'orphan_usermeta' => array( 'Orphan User Meta', 'متادیتای بدون کاربر' ),
									'orphan_termmeta' => array( 'Orphan Term Meta', 'متادیتای بدون Term' ),
									'orphan_term_relationships' => array( 'Orphan Relationships', 'رابطه Taxonomy شکسته' ),
									'orphan_wc_order_itemmeta' => array( 'WooCommerce Item Meta', 'متادیتای آیتم سفارش یتیم' ),
									'orphan_wc_product_lookup' => array( 'Product Lookup', 'Lookup محصول بدون پست' ),
									'orphan_wc_attribute_lookup' => array( 'Attribute Lookup', 'Lookup ویژگی بدون محصول' ),
									'orphan_wc_download_permissions' => array( 'Download Permissions', 'دسترسی دانلود یتیم' ),
									'expired_transients' => array( 'Expired Transients', 'Transientهای منقضی‌شده' ),
								);
								foreach ( $cleaners as $value => $definition ) {
									echo '<label class="shcd-tornado-dbm-cleanup-item"><input type="checkbox" value="' . esc_attr( $value ) . '"><span class="shcd-tornado-dbm-check"></span><span><b>' . esc_html( $definition[0] ) . '</b><small>' . esc_html( $definition[1] ) . '</small></span></label>';
								}
								?>
							</div>
							<div class="shcd-tornado-dbm-button-row"><button class="shcd-tornado-dbm-button shcd-tornado-dbm-button--ghost" data-action="cleanup-select-all">انتخاب همه</button><button class="shcd-tornado-dbm-button shcd-tornado-dbm-button--ghost" data-action="cleanup-clear-all">لغو انتخاب‌ها</button><button class="shcd-tornado-dbm-button shcd-tornado-dbm-button--ghost" data-action="cleanup-preview">پیش‌نمایش موارد قابل پاک‌سازی</button><button class="shcd-tornado-dbm-button shcd-tornado-dbm-button--danger" data-action="cleanup-execute">حذف موارد تأییدشده</button><button class="shcd-tornado-dbm-button shcd-tornado-dbm-button--ghost" data-action="integrity">Integrity Check</button><button class="shcd-tornado-dbm-button shcd-tornado-dbm-button--primary" data-action="cache">پاک‌سازی Cacheها</button></div>
							<div id="shcd-tornado-dbm-maintenance-output" class="shcd-tornado-dbm-result shcd-tornado-dbm-result--empty">گزارش پاک‌سازی، بررسی Integrity و تنظیم AUTO_INCREMENT در این بخش نمایش داده می‌شود.</div>
						</article>
						<article class="shcd-tornado-dbm-card shcd-tornado-dbm-elementor-repair-card">
							<div class="shcd-tornado-dbm-card__head">
								<div><span class="shcd-tornado-dbm-kicker">Builder Recovery Registry</span><h3>ترمیم Elementor و سازنده‌های اختصاصی Header/Footer</h3><p>شناسایی Builderها به چند اسلاگ ثابت محدود نیست. Tornado شناسه تصاویر، لوگوها، پس‌زمینه‌ها، Galleryها و آیکون‌های تصویری Elementor را با Mapping تازه هماهنگ می‌کند. اگر ID تصویر معتبر نباشد، Attachment درست با بررسی URL و مسیر فایل پیدا می‌شود.</p></div>
								<span class="shcd-tornado-dbm-icon shcd-tornado-dbm-icon--purple"><span class="dashicons dashicons-art"></span></span>
							</div>
							<div class="shcd-tornado-dbm-info-banner"><span class="dashicons dashicons-shield"></span><div><strong>پشتیبانی از Builder اختصاصی قالب</strong><p>اگر قالب از Post Type اختصاصی و Option یا Post Meta خودش استفاده کند، همان Referenceها از طریق Registry ترمیم می‌شوند. فقط مواردی که واقعاً با Elementor ویرایش می‌شوند در <code>elementor_cpt_support</code> ثبت خواهند شد.</p></div></div>
							<div class="shcd-tornado-dbm-card__subhead"><div><strong>Registry سازنده‌های قالب</strong><small>نوع کاربرد و ویرایشگر هر Builder را بررسی کنید. چند Option Key یا Meta Key را با ویرگول از هم جدا کنید.</small></div><div class="shcd-tornado-dbm-button-row"><button class="shcd-tornado-dbm-button shcd-tornado-dbm-button--ghost" data-action="builders-scan"><span class="dashicons dashicons-search"></span>کشف خودکار</button><button class="shcd-tornado-dbm-button shcd-tornado-dbm-button--ghost" data-action="builders-add"><span class="dashicons dashicons-plus-alt2"></span>افزودن دستی</button><button class="shcd-tornado-dbm-button shcd-tornado-dbm-button--success" data-action="builders-save"><span class="dashicons dashicons-saved"></span>ذخیره Registry</button></div></div>
							<div class="shcd-tornado-dbm-table-wrap"><table class="shcd-tornado-dbm-data-table shcd-tornado-dbm-builder-table"><thead><tr><th>فعال</th><th>Post Type</th><th>عنوان</th><th>کاربرد</th><th>ویرایشگر</th><th>اطمینان</th><th>Option Keys</th><th>Meta Keys</th><th></th></tr></thead><tbody id="shcd-tornado-dbm-builder-registry-body"></tbody></table></div>
							<div id="shcd-tornado-dbm-builder-registry-empty" class="shcd-tornado-dbm-empty-state">هنوز سازنده‌ای ثبت نشده است. روی «کشف خودکار» کلیک کنید.</div>
							<div class="shcd-tornado-dbm-button-row"><button class="shcd-tornado-dbm-button shcd-tornado-dbm-button--primary" data-action="elementor-repair"><span class="dashicons dashicons-update"></span>تعمیر کامل سازنده‌ها، Elementor و Cacheها</button></div>
							<div id="shcd-tornado-dbm-elementor-repair-output" class="shcd-tornado-dbm-result shcd-tornado-dbm-result--empty">نتیجه ترمیم Registry، تصاویر و لوگوهای Elementor، Site Identity، Theme Builder، CSS و Cacheها در این بخش نمایش داده خواهد شد.</div>
						</article>
					</section>

					<section class="shcd-tornado-dbm-panel" data-panel="tables">
						<div class="shcd-tornado-dbm-page-heading"><div><span class="shcd-tornado-dbm-kicker">Quarantine First</span><h2>جدول‌های بدون استفاده</h2><p>هیچ جدول فعالی مستقیماً حذف نمی‌شود. جدول انتخاب‌شده ابتدا به Quarantine منتقل می‌شود تا امکان بازگردانی آن وجود داشته باشد.</p></div></div>
						<article class="shcd-tornado-dbm-card">
							<div class="shcd-tornado-dbm-button-row"><button class="shcd-tornado-dbm-button shcd-tornado-dbm-button--primary" data-action="unused-tables">اسکن جدول‌ها</button></div>
							<div class="shcd-tornado-dbm-form-grid shcd-tornado-dbm-form-grid--3">
								<label><span>نام جدول</span><input type="text" id="shcd-tornado-dbm-unused-table" autocomplete="off"></label>
								<label><span>Backup UUID</span><input type="text" id="shcd-tornado-dbm-unused-backup" autocomplete="off"></label>
								<label><span>عبارت تأیید</span><input type="text" id="shcd-tornado-dbm-unused-confirmation" autocomplete="off"></label>
							</div>
							<div class="shcd-tornado-dbm-button-row"><button class="shcd-tornado-dbm-button shcd-tornado-dbm-button--ghost" data-action="table-preflight">دریافت عبارت تأیید</button><button class="shcd-tornado-dbm-button shcd-tornado-dbm-button--primary" data-action="table-quarantine">انتقال به Quarantine</button><button class="shcd-tornado-dbm-button shcd-tornado-dbm-button--danger" data-action="table-purge">حذف نهایی</button></div>
							<div class="shcd-tornado-dbm-form-grid shcd-tornado-dbm-form-grid--3">
								<label><span>Quarantine Job UUID</span><input type="text" id="shcd-tornado-dbm-quarantine-job" autocomplete="off"></label>
								<label><span>Restore Confirmation</span><input type="text" id="shcd-tornado-dbm-restore-confirmation" autocomplete="off"></label>
								<label><span>Token یک‌بارمصرف حذف</span><input type="text" id="shcd-tornado-dbm-table-authorization" autocomplete="off" readonly placeholder="پس از صدور مجوز، Token به‌صورت خودکار در این کادر قرار می‌گیرد"></label>
							</div>
							<div class="shcd-tornado-dbm-button-row"><button class="shcd-tornado-dbm-button shcd-tornado-dbm-button--ghost" data-action="table-arm">صدور مجوز جدید حذف</button><button class="shcd-tornado-dbm-button shcd-tornado-dbm-button--success" data-action="table-restore">بازگردانی جدول</button></div>
							<div id="shcd-tornado-dbm-tables-output" class="shcd-tornado-dbm-result shcd-tornado-dbm-result--empty">نتیجه بررسی و عملیات مربوط به جدول‌ها در این بخش نمایش داده می‌شود.</div>
						</article>
					</section>

					<section class="shcd-tornado-dbm-panel" data-panel="settings">
						<div class="shcd-tornado-dbm-page-heading"><div><span class="shcd-tornado-dbm-kicker">پیکربندی عمومی</span><h2>تنظیمات نگهداری</h2><p>تنظیمات مربوط به شناسه‌ها و AUTO_INCREMENT در بخش جداگانه مدیریت می‌شوند.</p></div></div>
						<form class="shcd-tornado-dbm-card" id="shcd-tornado-dbm-settings-form">
							<div class="shcd-tornado-dbm-form-grid shcd-tornado-dbm-form-grid--3">
								<label><span>روش نگهداری تاریخچه ویرایش</span><select name="revision_storage_mode"><option value="wordpress">Revision استاندارد WordPress</option><option value="archive">آرشیو مستقل Tornado؛ بدون Revision عادی در جدول نوشته‌ها</option><option value="off">بدون تاریخچه Revision عادی</option></select><small>حالت آرشیو از ساخته‌شدن Revision عادی در جدول نوشته‌ها جلوگیری می‌کند و نسخه را فقط پس از ذخیره موفق در جدول مستقل نگه می‌دارد. تاریخچه داخلی Elementor با آرشیو Tornado جایگزین نمی‌شود.</small></label>
								<label><span>حداکثر Revision عادی برای هر نوشته</span><input type="number" min="0" max="100" name="revision_limit"><small>مقدار صفر، ساخت Revision عادی WordPress را متوقف می‌کند. این مقدار Autosave را غیرفعال نمی‌کند.</small></label>
								<label><span>حداکثر Snapshot آرشیو برای هر نوشته</span><input type="number" min="0" max="500" name="revision_archive_limit"><small>مقدار صفر یعنی Snapshot جدید در آرشیو نگهداری نشود.</small></label>
								<label><span>مدت نگهداری Snapshotها (روز)</span><input type="number" min="0" max="3650" name="revision_archive_days"><small>مقدار صفر یعنی بدون محدودیت زمانی.</small></label>
								<label><span>نگهداری Autosave پس از ذخیره موفق (روز)</span><input type="number" min="0" max="3650" name="autosave_retention_days"><small>مقدار صفر، Autosave را تنها پس از ذخیره موفق صفحه پاک می‌کند؛ هنگام ویرایش جلوی Autosave گرفته نمی‌شود.</small></label>
								<label><span>نگهداری Pending Revision پس از انتشار (روز)</span><input type="number" min="-1" max="3650" name="pending_revision_retention_days"><small>مقدار منفی یک یعنی بدون پاک‌سازی خودکار. مقدار صفر فقط پس از انتشار موفق و ثبت Snapshot، Pending Revision را پاک می‌کند.</small></label>
								<label><span>مدت نگهداری Auto Draft (روز)</span><input type="number" min="0" max="3650" name="auto_draft_days"></label>
								<label><span>مدت نگهداری Trash (روز)</span><input type="number" min="0" max="3650" name="trash_days"></label>
								<label><span>مدت نگهداری Backup (روز)</span><input type="number" min="1" max="3650" name="backup_retention_days"></label>
								<label><span>مدت نگهداری Job (روز)</span><input type="number" min="1" max="3650" name="job_retention_days"></label>
								<label><span>مدت نگهداری Log (روز)</span><input type="number" min="1" max="3650" name="log_retention_days"></label>
								<label><span>اندازه هر مرحله پاک‌سازی</span><input type="number" min="50" max="2000" name="cleanup_batch_size"></label>
								<label><span>پوسته</span><select name="theme"><option value="auto">خودکار</option><option value="light">روشن</option><option value="dark">تاریک</option></select></label>
							</div>
							<div class="shcd-tornado-dbm-switch-list shcd-tornado-dbm-switch-list--columns">
								<label class="shcd-tornado-dbm-switch"><input type="checkbox" name="revision_archive_elementor"><span></span><b>ذخیره داده‌های Elementor در آرشیو</b></label>
								<label class="shcd-tornado-dbm-switch"><input type="checkbox" name="revision_archive_woocommerce"><span></span><b>ذخیره Metaهای WooCommerce در آرشیو</b></label>
								<label class="shcd-tornado-dbm-switch"><input type="checkbox" name="scheduled_cleanup"><span></span><b>پاک‌سازی زمان‌بندی‌شده</b></label>
								<label class="shcd-tornado-dbm-switch"><input type="checkbox" name="allow_order_reindex"><span></span><b>اجازه Reindex سفارش‌های Legacy</b></label>
								<label class="shcd-tornado-dbm-switch"><input type="checkbox" name="allow_opcache_reset"><span></span><b>اجازه OPcache Reset</b></label>
								<label class="shcd-tornado-dbm-switch"><input type="checkbox" name="remove_data_on_uninstall"><span></span><b>حذف داده‌ها هنگام Uninstall</b></label>
								<label class="shcd-tornado-dbm-switch"><input type="checkbox" name="remove_backups_on_uninstall"><span></span><b>حذف Backupها هنگام Uninstall</b></label>
							</div>
							<div class="shcd-tornado-dbm-button-row"><button class="shcd-tornado-dbm-button shcd-tornado-dbm-button--primary" type="submit">ذخیره تنظیمات عمومی</button></div>
						</form>

						<article class="shcd-tornado-dbm-card">
							<div class="shcd-tornado-dbm-card__head"><div><span class="shcd-tornado-dbm-kicker">Revision Archive</span><h3>آرشیو مستقل نسخه‌های ویرایش</h3><p>Snapshotهای این بخش خارج از جدول نوشته‌ها ذخیره می‌شوند. ذخیره WordPress و Elementor مسدود یا جایگزین نمی‌شود؛ آرشیو تنها پس از پایان موفق ذخیره، نسخه جدید را ثبت می‌کند.</p></div><span class="shcd-tornado-dbm-icon shcd-tornado-dbm-icon--purple"><span class="dashicons dashicons-backup"></span></span></div>
							<div class="shcd-tornado-dbm-info-banner"><span class="dashicons dashicons-shield"></span><div><strong>سازگاری با Elementor و Autosave</strong><p>در حالت آرشیو، Revision عادی در جدول نوشته‌ها ساخته نمی‌شود. Autosave هنگام ویرایش فعال می‌ماند تا ذخیره و بازیابی اضطراری Elementor و ویرایشگر بلوکی مختل نشود. مقدار صفر برای نگهداری Autosave یعنی پاک‌سازی آن پس از ذخیره موفق، نه جلوگیری از ساخته‌شدن آن.</p></div></div>
							<div class="shcd-tornado-dbm-manager-toolbar shcd-tornado-dbm-manager-toolbar--wrap"><label><span>شناسه نوشته برای فیلتر</span><input type="number" id="shcd-tornado-dbm-revision-archive-post-id" min="0" value="0"></label><div class="shcd-tornado-dbm-button-row"><button type="button" class="shcd-tornado-dbm-button shcd-tornado-dbm-button--ghost" data-action="revision-archive-refresh">به‌روزرسانی آرشیو</button><button type="button" class="shcd-tornado-dbm-button shcd-tornado-dbm-button--primary" data-action="revision-archive-migrate-core">انتقال Revisionهای موجود به آرشیو</button><button type="button" class="shcd-tornado-dbm-button shcd-tornado-dbm-button--danger" data-action="revision-archive-delete-selected">حذف انتخاب‌شده‌ها</button></div></div>
							<label class="shcd-tornado-dbm-select-all"><input type="checkbox" id="shcd-tornado-dbm-revision-archive-select-all"> انتخاب همه موارد این صفحه</label>
							<div class="shcd-tornado-dbm-table-wrap"><table class="shcd-tornado-dbm-data-table" id="shcd-tornado-dbm-revision-archive-table"><thead><tr><th></th><th>ID</th><th>نوشته</th><th>منبع</th><th>نسل</th><th>تاریخ</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody></tbody></table></div>
							<div id="shcd-tornado-dbm-revision-archive-empty" class="shcd-tornado-dbm-empty-state">هنوز Snapshotی برای نمایش وجود ندارد.</div>
							<div id="shcd-tornado-dbm-revision-archive-output" class="shcd-tornado-dbm-result shcd-tornado-dbm-result--empty">وضعیت و نتیجه عملیات آرشیو در این بخش نمایش داده می‌شود.</div>
						</article>

						<article class="shcd-tornado-dbm-card">
							<div class="shcd-tornado-dbm-card__head">
								<div><span class="shcd-tornado-dbm-kicker">Internal Footprint</span><h3>مدیریت فضای مصرفی داده‌های داخلی Tornado</h3><p>Discoveryهای تکراری و Mappingهای منقضی فقط تا زمانی نگهداری می‌شوند که برای اجرای جاری یا بازیابی لازم باشند. داده‌های بدون کاربرد عملیاتی ذخیره نخواهند شد.</p></div>
								<span class="shcd-tornado-dbm-icon shcd-tornado-dbm-icon--purple"><span class="dashicons dashicons-database-remove"></span></span>
							</div>
							<div class="shcd-tornado-dbm-info-banner"><span class="dashicons dashicons-info-outline"></span><div><strong>سیاست نگهداری کم‌حجم</strong><p>فقط Discovery قابل استفاده، Candidateهای عملیاتی و Mappingهای لازم برای اجرای جاری یا بازیابی نگهداری می‌شوند. تاریخچه Jobها و خطاها برای Audit باقی خواهد ماند.</p></div></div>
							<div class="shcd-tornado-dbm-button-row"><button type="button" class="shcd-tornado-dbm-button shcd-tornado-dbm-button--ghost" data-action="storage-refresh"><span class="dashicons dashicons-update"></span>محاسبه حجم فعلی</button><button type="button" class="shcd-tornado-dbm-button shcd-tornado-dbm-button--primary" data-action="storage-compact"><span class="dashicons dashicons-performance"></span>فشرده‌سازی داده‌های داخلی</button></div>
							<div id="shcd-tornado-dbm-storage-output" class="shcd-tornado-dbm-result shcd-tornado-dbm-result--empty">میزان مصرف جدول‌های داخلی Tornado در این بخش نمایش داده می‌شود.</div>
						</article>
					</section>

					<section class="shcd-tornado-dbm-panel" data-panel="jobs">
						<div class="shcd-tornado-dbm-page-heading">
							<div><span class="shcd-tornado-dbm-kicker">Audit & Diagnostics</span><h2>عملیات، گزارش‌ها و خطاها</h2><p>Jobها و Logها در یک بخش یکپارچه نمایش داده می‌شوند. Job وضعیت و خروجی هر عملیات را نگه می‌دارد و Log جزئیات فنی، Warningها و Errorها را برای عیب‌یابی ثبت می‌کند.</p></div>
							<div class="shcd-tornado-dbm-button-row"><button class="shcd-tornado-dbm-button shcd-tornado-dbm-button--ghost" data-action="audit-refresh"><span class="dashicons dashicons-update"></span>به‌روزرسانی همه</button></div>
						</div>

						<article class="shcd-tornado-dbm-card">
							<div class="shcd-tornado-dbm-card__head"><div><span class="shcd-tornado-dbm-kicker">Operation Jobs</span><h3>تاریخچه عملیات و خروجی گزارش‌ها</h3></div></div>
							<div class="shcd-tornado-dbm-table-wrap"><table id="shcd-tornado-dbm-jobs-table"><thead><tr><th>UUID</th><th>نوع</th><th>وضعیت</th><th>مرحله</th><th>پیشرفت</th><th>خطا</th><th>گزارش</th></tr></thead><tbody></tbody></table></div>
						</article>

						<article class="shcd-tornado-dbm-card">
							<div class="shcd-tornado-dbm-card__head"><div><span class="shcd-tornado-dbm-kicker">Error & Log Center</span><h3>خطاها و Logهای فنی</h3><p>متن خطا، Job مرتبط و Context فنی قابل مشاهده است. اطلاعات حساس پیش از نمایش پاک‌سازی می‌شوند.</p></div><button class="shcd-tornado-dbm-button shcd-tornado-dbm-button--ghost" data-action="managed-logs-refresh"><span class="dashicons dashicons-update"></span>به‌روزرسانی Logها</button></div>
							<div class="shcd-tornado-dbm-manager-toolbar shcd-tornado-dbm-manager-toolbar--wrap">
								<label><span>سطح Log</span><select id="shcd-tornado-dbm-log-level"><option value="">همه سطح‌ها</option><option value="debug">Debug</option><option value="info">Info</option><option value="warning">Warning</option><option value="error">Error</option><option value="critical">Critical</option></select></label>
								<label><span>Job UUID</span><input type="text" id="shcd-tornado-dbm-log-job-uuid" autocomplete="off"></label>
								<label><span>حذف Logهای قدیمی‌تر از</span><input type="number" id="shcd-tornado-dbm-log-purge-days" min="1" max="3650" value="30"></label>
								<div class="shcd-tornado-dbm-button-row"><button class="shcd-tornado-dbm-button shcd-tornado-dbm-button--danger" data-action="managed-logs-delete-selected">حذف انتخاب‌شده‌ها</button><button class="shcd-tornado-dbm-button shcd-tornado-dbm-button--ghost" data-action="managed-logs-purge">پاک‌سازی دوره‌ای</button></div>
							</div>
							<label class="shcd-tornado-dbm-select-all"><input type="checkbox" id="shcd-tornado-dbm-logs-select-all"> انتخاب همه موارد این صفحه</label>
							<div class="shcd-tornado-dbm-table-wrap"><table class="shcd-tornado-dbm-data-table" id="shcd-tornado-dbm-managed-logs-table"><thead><tr><th></th><th>ID</th><th>سطح</th><th>پیام</th><th>Job UUID</th><th>تاریخ</th><th>جزئیات</th></tr></thead><tbody></tbody></table></div>
							<div id="shcd-tornado-dbm-managed-logs-empty" class="shcd-tornado-dbm-empty-state">هنوز Logی برای نمایش وجود ندارد.</div>
							<div id="shcd-tornado-dbm-log-manager-output" class="shcd-tornado-dbm-result shcd-tornado-dbm-result--empty">نتیجه مدیریت Logها و خطاهای فنی در این بخش نمایش داده می‌شود.</div>
						</article>
					</section>

				</main>
			</div>
		</div>
		<?php
	}

	public function export_report(): void {
		if ( ! current_user_can( Capabilities::VIEW_DASHBOARD ) ) {
			wp_die( esc_html__( 'شما اجازه دسترسی به این بخش را ندارید.', 'shcd-database-maintenance' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'shcd_tornado_dbm_export_report', '_wpnonce' );

		$uuid   = isset( $_GET['uuid'] ) ? sanitize_text_field( wp_unslash( $_GET['uuid'] ) ) : '';
		$format = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : 'json';

		try {
			$file = $this->reports->export( $uuid, $format );
		} catch ( \Throwable $throwable ) {
			wp_die( esc_html__( 'گزارش ساخته یا دریافت نشد. Job UUID و فرمت خروجی را بررسی کنید.', 'shcd-database-maintenance' ), '', array( 'response' => 400 ) );
		}

		nocache_headers();
		header( 'Content-Type: ' . $file['mime'] );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $file['filename'] ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw, generated download payload; escaping would corrupt JSON/CSV.
		echo $file['content']; 
		exit;
	}
}
