(function () {
    'use strict';

    const app = document.getElementById('shcd-tornado-dbm-app');
    if (!app || !window.SHCD_TORNADO_DBM_ADMIN) {
        return;
    }

    const config = window.SHCD_TORNADO_DBM_ADMIN;
    const tables = config.tables || {};
    const postsTable = tables.posts || 'posts';
    const optionsTable = tables.options || 'options';
    const notice = document.getElementById('shcd-tornado-dbm-notice');
    const outputs = {
        identifiers: document.getElementById('shcd-tornado-dbm-identifiers-output'),
        generations: document.getElementById('shcd-tornado-dbm-generations-output'),
        autoIncrement: document.getElementById('shcd-tornado-dbm-auto-increment-output'),
        maintenance: document.getElementById('shcd-tornado-dbm-maintenance-output'),
        elementorRepair: document.getElementById('shcd-tornado-dbm-elementor-repair-output'),
        tables: document.getElementById('shcd-tornado-dbm-tables-output'),
        backupManager: document.getElementById('shcd-tornado-dbm-backup-manager-output'),
        logManager: document.getElementById('shcd-tornado-dbm-log-manager-output'),
        storage: document.getElementById('shcd-tornado-dbm-storage-output'),
        revisionArchive: document.getElementById('shcd-tornado-dbm-revision-archive-output')
    };

    let cleanupPreviewToken = '';
    let autoIncrementPreviewToken = '';
    let currentSettings = {};
    const authorization = { reindex: '', table_drop: '' };

    const request = async (path, options = {}) => {
        const response = await fetch(`${config.root}${path}`, {
            method: options.method || 'GET',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': config.nonce
            },
            body: options.body ? JSON.stringify(options.body) : undefined
        });
        let data = {};
        try {
            data = await response.json();
        } catch (error) {
            data = {};
        }
        if (!response.ok) {
            const message = translateServerMessage(data.message || config.i18n.failed || 'Operation failed.');
            const technical = data?.data?.technical_message ? String(data.data.technical_message) : '';
            throw new Error(technical ? `${message}
جزئیات فنی: ${technical}` : message);
        }
        return data;
    };

    const translateServerMessage = (message) => {
        const text = String(message || '').trim();
        const exact = {
            'Operation failed.': 'اجرای عملیات با خطا متوقف شد.',
            'Access denied.': 'شما مجوز انجام این عملیات را ندارید.',
            'Reauthentication failed.': 'صدور مجوز یک‌بارمصرف انجام نشد.',
            'A valid UUID is required.': 'شناسه UUID واردشده معتبر نیست.'
        };
        if (exact[text]) {
            return exact[text];
        }
        if (/Reindex preflight is blocked:/i.test(text)) {
            return text
                .replace(/Reindex preflight is blocked:\s*/i, 'بررسی پیش از اجرای Reindex متوقف شد: ')
                .replace(/Destructive reindexing is disabled\.[^.]*/i, 'اجازه اجرای Reindex فعال نیست. گزینه مربوط را در بخش «شناسه‌ها و AUTO_INCREMENT» روشن کنید و تنظیمات را ذخیره نمایید')
                .replace(/(\d+) database columns contain mapped post IDs but remain semantically ambiguous\./i, 'در $1 ستون دیتابیس، مقدارهایی از Mapping پیدا شده است؛ بااین‌حال نوع ارتباط این ستون‌ها هنوز به‌طور قطعی مشخص نیست.')
                .replace(/The embedded-data scan was incomplete;[^.]*/i, 'بررسی داده‌های Embedded کامل نشد. جزئیات گزارش را بررسی کنید')
                .replace(/\s+/g, ' ')
                .trim();
        }
        return text || 'خطایی پیش‌بینی‌نشده رخ داد و عملیات متوقف شد.';
    };

    const showNotice = (message, type = 'success') => {
        notice.hidden = false;
        notice.className = `shcd-tornado-dbm-notice is-${type}`;
        notice.textContent = message;
        window.clearTimeout(showNotice.timer);
        showNotice.timer = window.setTimeout(() => {
            notice.hidden = true;
        }, 6500);
    };

    const scalarLabel = (key) => ({
        uuid: 'Job UUID',
        generation_no: 'شماره نسل',
        parent_generation_uuid: 'نسل والد',
        parent_mapping_uuid: 'Mapping نسل قبل',
        backup_uuid: 'Backup نسل',
        repeated_reindex: 'اجرای تکرارشونده',
        source_gap_count: 'شکاف‌های مبدأ نسل',
        target_gap_count: 'شکاف‌های نهایی نسل',
        verified: 'نسل تأییدشده',
        total_posts: 'تعداد پست‌ها',
        changed_ids: 'شناسه‌های قابل تغییر',
        unchanged_ids: 'شناسه‌های بدون تغییر',
        old_min_id: 'کمترین ID فعلی',
        old_max_id: 'بیشترین ID فعلی',
        old_gap_count: 'شکاف‌های فعلی',
        new_min_id: 'کمترین ID جدید',
        new_max_id: 'بیشترین ID جدید',
        new_gap_count: 'شکاف‌های نهایی',
        gap_count: 'تعداد شکاف‌ها',
        gapless_reindex: 'Reindex پیوسته',
        gapless_mapping: 'اعتبار Mapping بدون شکاف',
        gapless_guarantee: 'تضمین توالی بدون شکاف',
        gapless_validation: 'اعتبارسنجی توالی پیوسته',
        valid_mapping: 'Mapping معتبر و پیوسته',
        guaranteed_at_commit: 'تأیید پیوستگی هنگام COMMIT',
        current_gap_count: 'شکاف‌های پیش از Reindex',
        final_gap_count: 'شکاف‌های پس از Reindex',
        final_min_id: 'اولین ID نهایی',
        final_max_id: 'آخرین ID نهایی',
        next_auto_increment: 'AUTO_INCREMENT نهایی',
        auto_increment: 'AUTO_INCREMENT ثبت‌شده',
        expected_auto_increment: 'AUTO_INCREMENT مورد انتظار',
        auto_increment_valid: 'اعتبار AUTO_INCREMENT',
        attachment_count: 'تعداد Attachmentها',
        attachments_included: 'کتابخانه رسانه در Mapping',
        mapping_rows: 'ردیف‌های Mapping',
        unmapped_posts: 'پست‌های خارج از Mapping',
        stale_mapping_rows: 'ردیف‌های منقضی Mapping',
        unmapped_final_rows: 'ردیف‌های نهایی خارج از Mapping',
        missing_final_posts: 'شناسه‌های نهایی پیدا نشده',
        temporary_rows: 'شناسه‌های موقت باقی‌مانده',
        total_deleted: 'مجموع حذف‌شده',
        deleted_count: 'تعداد حذف‌شده',
        next_id: 'شناسه بعدی',
        previous_auto_increment: 'AUTO_INCREMENT قبلی',
        reused_deleted_tail_id: 'بازاستفاده از ID انتهایی',
        limit_reached: 'رسیدن به سقف پردازش',
        table_count: 'تعداد جدول‌ها',
        change_count: 'نیازمند اصلاح',
        allowed: 'اجازه اجرا',
        total_issues: 'کل یافته‌های Integrity',
        reindex_blocking_issues: 'روابط مرتبط با Reindex',
        advisory_issues: 'یافته‌های غیرمسدودکننده',
        scanned_rows: 'ردیف‌های بررسی‌شده',
        candidate_rows: 'مسیرهای ساختاری شناسایی‌شده',
        involved_table_count: 'جدول‌های درگیر',
        scalar_reference_count: 'Referenceهای عددی',
        option_reference_count: 'Optionهای وابسته',
        destructive_reindex_enabled: 'وضعیت Reindex مخرب',
        auto_register_discovered_references: 'ثبت خودکار Referenceها',
        auto_resolve_ambiguous_references: 'حل خودکار Referenceهای مبهم',
        hit_count: 'Referenceهای Embedded شناسایی‌شده',
        complete: 'کامل بودن اسکن',
        references_checked: 'Referenceهای رسانه بررسی‌شده',
        valid_references: 'Referenceهای رسانه‌ای سالم',
        unresolved_count: 'مجموع رخدادهای حل‌نشده',
        unique_unresolved_count: 'Referenceهای یکتای حل‌نشده',
        blocking_unresolved_count: 'موارد بازدارنده',
        advisory_unresolved_count: 'فایل‌های مفقود از قبل',
        safe_for_reindex: 'ایمن برای Reindex',
        all_media_resolved: 'تمام رسانه‌ها بازیابی شده‌اند',
        media_stale_ids_cleared: 'شناسه‌های stale خنثی‌شده',
        media_url_only_preserved: 'رسانه‌های URL-only حفظ‌شده',
        url_only_detached_count: 'Referenceهای دارای URL بدون Attachment',
        url_only_existing_file_count: 'URL-only با فایل موجود',
        url_only_missing_file_count: 'URL-only با فایل مفقود',
        url_only_unique_count: 'URLهای یکتای خارج از Mapping',
        deleted: 'مجموع ردیف‌های رسانه‌ای حذف‌شده',
        deleted_attachments: 'Attachmentهای فاقد فایل حذف‌شده',
        deleted_media_cleaner_trash: 'رکوردهای wmpc-trash حذف‌شده',
        checked: 'مجموع ردیف‌های رسانه‌ای بررسی‌شده',
        checked_attachments: 'Attachmentهای بررسی‌شده',
        checked_media_cleaner_trash: 'رکوردهای wmpc-trash بررسی‌شده',
        all_revisions: 'تمام Revisionها',
        revisions: 'Revisionهای مازاد',
        auto_drafts: 'Auto Draftهای قدیمی',
        trash: 'محتوای قدیمی زباله‌دان',
        autosaves: 'Autosaveهای Revision',
        pending_revisions: 'Pending Revisionها',
        media_cleaner_trash_missing: 'رکوردهای wmpc-trash فاقد فایل',
        remaining: 'موارد باقی‌مانده',
        tables: 'جدول‌های درگیر',
        posts_table: 'جدول نوشته‌ها',
        postmeta_table: 'جدول متادیتای نوشته‌ها',
        database_prefix: 'پیشوند دیتابیس',
        plugin_build: 'Build افزونه',
        mode: 'روش نگهداری تاریخچه',
        core_revision_limit: 'سقف Revision عادی',
        archive_limit: 'سقف Snapshot برای هر نوشته',
        archive_retention_days: 'مدت نگهداری آرشیو',
        autosave_retention_days: 'مدت نگهداری Autosave',
        pending_retention_days: 'مدت نگهداری Pending Revision',
        snapshot_count: 'تعداد Snapshotها',
        normal_revision_count: 'Revision عادی در جدول نوشته‌ها',
        autosave_count: 'Autosaveهای موجود',
        pending_revision_count: 'Pending Revisionهای موجود',
        current_generation: 'نسل فعلی Reindex',
        elementor_detected: 'شناسایی Elementor',
        elementor_save_is_intercepted: 'مسدودسازی ذخیره Elementor',
        autosave_creation_is_blocked: 'مسدودسازی ساخت Autosave'
    }[key] || key.replaceAll('_', ' '));

    const valueText = (value) => {
        if (typeof value === 'boolean') {
            return value ? 'بله' : 'خیر';
        }
        if (value === null || value === undefined || value === '') {
            return '—';
        }
        return String(value);
    };


    const formatBytes = (bytes) => {
        const value = Number(bytes || 0);
        if (value < 1024) return `${value} B`;
        if (value < 1024 * 1024) return `${(value / 1024).toFixed(1)} KB`;
        if (value < 1024 * 1024 * 1024) return `${(value / (1024 * 1024)).toFixed(1)} MB`;
        return `${(value / (1024 * 1024 * 1024)).toFixed(2)} GB`;
    };

    const builderRoleLabel = (role) => ({ header: 'Header / سربرگ', footer: 'Footer / پاورقی', template: 'Template / قالب', unknown: 'نامشخص' }[role] || role);

    const createBuilderSelect = (values, current, className) => {
        const select = document.createElement('select');
        select.className = className;
        values.forEach(([value, label]) => {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = label;
            option.selected = value === current;
            select.appendChild(option);
        });
        return select;
    };

    const appendBuilderRow = (entry = {}) => {
        const tbody = document.getElementById('shcd-tornado-dbm-builder-registry-body');
        const empty = document.getElementById('shcd-tornado-dbm-builder-registry-empty');
        if (!tbody) return;
        const row = document.createElement('tr');
        row.className = 'shcd-tornado-dbm-builder-row';

        const enabledCell = document.createElement('td');
        const enabled = document.createElement('input');
        enabled.type = 'checkbox';
        enabled.className = 'shcd-tornado-dbm-builder-enabled';
        enabled.checked = entry.enabled !== false;
        enabledCell.appendChild(enabled);
        row.appendChild(enabledCell);

        const typeCell = document.createElement('td');
        const postType = document.createElement('input');
        postType.type = 'text';
        postType.className = 'shcd-tornado-dbm-builder-post-type';
        postType.value = String(entry.post_type || '');
        postType.placeholder = 'my_header_builder';
        postType.dir = 'ltr';
        typeCell.appendChild(postType);
        row.appendChild(typeCell);

        const labelCell = document.createElement('td');
        const label = document.createElement('input');
        label.type = 'text';
        label.className = 'shcd-tornado-dbm-builder-label';
        label.value = String(entry.label || '');
        label.placeholder = 'سازنده سربرگ قالب';
        labelCell.appendChild(label);
        row.appendChild(labelCell);

        const roleCell = document.createElement('td');
        roleCell.appendChild(createBuilderSelect([
            ['header', 'Header / سربرگ'],
            ['footer', 'Footer / پاورقی'],
            ['template', 'Template / قالب'],
            ['unknown', 'نامشخص']
        ], String(entry.role || 'unknown'), 'shcd-tornado-dbm-builder-role'));
        row.appendChild(roleCell);

        const editorCell = document.createElement('td');
        editorCell.appendChild(createBuilderSelect([
            ['elementor', 'Elementor'],
            ['native', 'ویرایشگر اختصاصی قالب'],
            ['unknown', 'نامشخص']
        ], String(entry.editor || 'native'), 'shcd-tornado-dbm-builder-editor'));
        row.appendChild(editorCell);

        const confidenceCell = document.createElement('td');
        const confidence = document.createElement('span');
        const confidenceValue = Number(entry.confidence || 0);
        confidence.className = `shcd-tornado-dbm-status is-${confidenceValue >= 70 ? 'success' : (confidenceValue >= 40 ? 'warning' : 'danger')}`;
        confidence.textContent = `${confidenceValue}%`;
        confidence.title = `${entry.source || 'manual'} | ${entry.post_count || 0} رکورد | ${entry.elementor_documents || 0} سند Elementor`;
        confidenceCell.appendChild(confidence);
        row.appendChild(confidenceCell);

        const optionCell = document.createElement('td');
        const optionKeys = document.createElement('input');
        optionKeys.type = 'text';
        optionKeys.className = 'shcd-tornado-dbm-builder-option-keys';
        optionKeys.value = Array.isArray(entry.option_keys) ? entry.option_keys.join(', ') : String(entry.option_keys || '');
        optionKeys.placeholder = 'theme_header_id, active_layout';
        optionKeys.dir = 'ltr';
        optionCell.appendChild(optionKeys);
        row.appendChild(optionCell);

        const metaCell = document.createElement('td');
        const metaKeys = document.createElement('input');
        metaKeys.type = 'text';
        metaKeys.className = 'shcd-tornado-dbm-builder-meta-keys';
        metaKeys.value = Array.isArray(entry.meta_keys) ? entry.meta_keys.join(', ') : String(entry.meta_keys || '');
        metaKeys.placeholder = '_selected_header, layout_ref';
        metaKeys.dir = 'ltr';
        metaCell.appendChild(metaKeys);
        row.appendChild(metaCell);

        const actionCell = document.createElement('td');
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'shcd-tornado-dbm-icon-button shcd-tornado-dbm-builder-remove';
        remove.title = 'حذف از Registry';
        remove.innerHTML = '<span class="dashicons dashicons-trash"></span>';
        actionCell.appendChild(remove);
        row.appendChild(actionCell);

        tbody.appendChild(row);
        if (empty) empty.hidden = true;
    };

    const renderBuilders = (data) => {
        const tbody = document.getElementById('shcd-tornado-dbm-builder-registry-body');
        const empty = document.getElementById('shcd-tornado-dbm-builder-registry-empty');
        if (!tbody) return;
        tbody.textContent = '';
        const items = Array.isArray(data?.items) ? data.items : [];
        items.forEach((entry) => appendBuilderRow(entry));
        if (empty) empty.hidden = items.length > 0;
    };

    const splitBuilderKeys = (value) => String(value || '').split(/[\s,\n\r]+/).map((item) => item.trim()).filter(Boolean);

    const collectBuilders = () => Array.from(document.querySelectorAll('.shcd-tornado-dbm-builder-row')).map((row) => ({
        enabled: Boolean(row.querySelector('.shcd-tornado-dbm-builder-enabled')?.checked),
        post_type: String(row.querySelector('.shcd-tornado-dbm-builder-post-type')?.value || '').trim(),
        label: String(row.querySelector('.shcd-tornado-dbm-builder-label')?.value || '').trim(),
        role: String(row.querySelector('.shcd-tornado-dbm-builder-role')?.value || 'unknown'),
        editor: String(row.querySelector('.shcd-tornado-dbm-builder-editor')?.value || 'native'),
        confidence: Number(String(row.querySelector('.shcd-tornado-dbm-status')?.textContent || '0').replace('%', '')) || 0,
        option_keys: splitBuilderKeys(row.querySelector('.shcd-tornado-dbm-builder-option-keys')?.value),
        meta_keys: splitBuilderKeys(row.querySelector('.shcd-tornado-dbm-builder-meta-keys')?.value),
        source: 'manual',
        manual: true
    })).filter((entry) => entry.post_type);

    const renderBackups = (data) => {
        const tbody = document.querySelector('#shcd-tornado-dbm-backups-table tbody');
        const empty = document.getElementById('shcd-tornado-dbm-backups-empty');
        if (!tbody || !empty) return;
        tbody.textContent = '';
        const items = Array.isArray(data?.items) ? data.items : [];
        empty.hidden = items.length > 0;
        items.forEach((item) => {
            const row = document.createElement('tr');
            const select = document.createElement('td');
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.className = 'shcd-tornado-dbm-backup-select';
            checkbox.value = String(item.uuid || '');
            checkbox.disabled = Boolean(item.protected_by_generation);
            if (checkbox.disabled) {
                checkbox.title = 'این Backup به یکی از نسل‌های اخیر Reindex متصل است و برای بازیابی محافظت می‌شود.';
            }
            select.appendChild(checkbox);
            row.appendChild(select);

            const values = [
                item.id,
                item.uuid,
                item.file_name || '—',
                formatBytes(item.file_size),
                item.table_count,
                item.is_consistent ? 'سازگار' : 'Best Effort',
                item.is_private_location ? 'بله' : 'خیر',
                item.created_at || '—'
            ];
            values.forEach((value, index) => {
                const cell = document.createElement('td');
                if (index === 1 || index === 2) {
                    const code = document.createElement('code');
                    code.textContent = String(value ?? '');
                    cell.appendChild(code);
                } else if (index === 5 || index === 6) {
                    const badge = document.createElement('span');
                    const ok = String(value) === 'سازگار' || String(value) === 'بله';
                    badge.className = `shcd-tornado-dbm-status ${ok ? 'is-success' : 'is-warning'}`;
                    badge.textContent = String(value);
                    cell.appendChild(badge);
                } else {
                    cell.textContent = String(value ?? '');
                }
                row.appendChild(cell);
            });
            tbody.appendChild(row);
        });
    };

    const renderManagedLogs = (data) => {
        const tbody = document.querySelector('#shcd-tornado-dbm-managed-logs-table tbody');
        const empty = document.getElementById('shcd-tornado-dbm-managed-logs-empty');
        if (!tbody || !empty) return;
        tbody.textContent = '';
        const items = Array.isArray(data?.items) ? data.items : [];
        empty.hidden = items.length > 0;
        items.forEach((item) => {
            const row = document.createElement('tr');
            const select = document.createElement('td');
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.className = 'shcd-tornado-dbm-log-select';
            checkbox.value = String(item.id || '');
            select.appendChild(checkbox);
            row.appendChild(select);

            const idCell = document.createElement('td');
            idCell.textContent = String(item.id || '');
            row.appendChild(idCell);

            const levelCell = document.createElement('td');
            const level = document.createElement('span');
            level.className = `shcd-tornado-dbm-status is-${['error', 'critical'].includes(item.level) ? 'danger' : (item.level === 'warning' ? 'warning' : 'success')}`;
            level.textContent = String(item.level || 'info').toUpperCase();
            levelCell.appendChild(level);
            row.appendChild(levelCell);

            const messageCell = document.createElement('td');
            messageCell.className = 'shcd-tornado-dbm-log-message';
            messageCell.textContent = String(item.message || '');
            row.appendChild(messageCell);

            const jobCell = document.createElement('td');
            const jobCode = document.createElement('code');
            jobCode.textContent = String(item.job_uuid || '—');
            jobCell.appendChild(jobCode);
            row.appendChild(jobCell);

            const dateCell = document.createElement('td');
            dateCell.textContent = String(item.created_at || '—');
            row.appendChild(dateCell);

            const detailCell = document.createElement('td');
            const context = item.context && typeof item.context === 'object' ? item.context : {};
            if (Object.keys(context).length > 0) {
                const details = document.createElement('details');
                const summary = document.createElement('summary');
                summary.textContent = 'نمایش';
                const pre = document.createElement('pre');
                pre.className = 'shcd-tornado-dbm-log-context';
                pre.textContent = JSON.stringify(context, null, 2);
                details.append(summary, pre);
                detailCell.appendChild(details);
            } else {
                detailCell.textContent = '—';
            }
            row.appendChild(detailCell);
            tbody.appendChild(row);
        });
    };

    const selectedValues = (selector) => Array.from(document.querySelectorAll(`${selector}:checked`))
        .map((input) => input.value)
        .filter(Boolean);

    const renderRevisionArchive = (data) => {
        const tbody = document.querySelector('#shcd-tornado-dbm-revision-archive-table tbody');
        const empty = document.getElementById('shcd-tornado-dbm-revision-archive-empty');
        if (!tbody || !empty) {
            return;
        }
        tbody.textContent = '';
        const items = Array.isArray(data?.items) ? data.items : [];
        empty.hidden = items.length > 0;
        items.forEach((item) => {
            const row = document.createElement('tr');
            const selectCell = document.createElement('td');
            const select = document.createElement('input');
            select.type = 'checkbox';
            select.className = 'shcd-tornado-dbm-revision-archive-select';
            select.value = String(item.id || '');
            selectCell.appendChild(select);
            row.appendChild(selectCell);

            const idCell = document.createElement('td');
            idCell.textContent = String(item.id || '');
            row.appendChild(idCell);

            const postCell = document.createElement('td');
            const title = document.createElement('strong');
            title.textContent = item.post_title || `Post #${item.post_id || 0}`;
            const postId = document.createElement('small');
            postId.textContent = `ID: ${item.post_id || 0} — ${item.post_type || 'post'}`;
            postCell.append(title, document.createElement('br'), postId);
            row.appendChild(postCell);

            const sourceCell = document.createElement('td');
            const sourceLabels = {
                elementor: 'Elementor',
                wordpress: 'WordPress',
                'core-migration': 'انتقال از Revision وردپرس'
            };
            sourceCell.textContent = sourceLabels[String(item.source || '')] || String(item.source || 'WordPress');
            row.appendChild(sourceCell);

            const generationCell = document.createElement('td');
            const generation = document.createElement('code');
            generation.textContent = String(item.generation_uuid || 'initial-generation');
            generationCell.appendChild(generation);
            row.appendChild(generationCell);

            const dateCell = document.createElement('td');
            dateCell.textContent = String(item.created_at || '—');
            row.appendChild(dateCell);

            const statusCell = document.createElement('td');
            const status = document.createElement('span');
            status.className = `shcd-tornado-dbm-status ${item.restorable ? 'is-success' : 'is-warning'}`;
            status.textContent = item.restorable ? 'قابل بازیابی' : 'فقط خواندنی';
            statusCell.appendChild(status);
            row.appendChild(statusCell);

            const actionCell = document.createElement('td');
            const restore = document.createElement('button');
            restore.type = 'button';
            restore.className = 'shcd-tornado-dbm-button shcd-tornado-dbm-button--ghost shcd-tornado-dbm-button--small';
            restore.dataset.action = 'revision-archive-restore';
            restore.dataset.snapshotId = String(item.id || '');
            restore.textContent = 'بازیابی';
            restore.disabled = !item.restorable;
            actionCell.appendChild(restore);
            row.appendChild(actionCell);
            tbody.appendChild(row);
        });
    };

    const loadRevisionArchive = async () => {
        const postId = Math.max(0, Number(document.getElementById('shcd-tornado-dbm-revision-archive-post-id')?.value || 0));
        const query = new URLSearchParams({ limit: '200', post_id: String(postId) });
        const data = await request(`revisions/archive?${query.toString()}`);
        renderRevisionArchive(data);
        return data;
    };

    const loadBackups = async () => {
        const data = await request('backups?limit=200');
        renderBackups(data);
        return data;
    };

    const loadManagedLogs = async () => {
        const level = document.getElementById('shcd-tornado-dbm-log-level')?.value || '';
        const jobUuid = document.getElementById('shcd-tornado-dbm-log-job-uuid')?.value.trim() || '';
        const query = new URLSearchParams({ limit: '200' });
        if (level) query.set('level', level);
        if (jobUuid) query.set('job_uuid', jobUuid);
        const data = await request(`logs/manage?${query.toString()}`);
        renderManagedLogs(data);
        return data;
    };

    const addSummary = (container, data) => {
        const scalarEntries = Object.entries(data).filter(([, value]) => ['string', 'number', 'boolean'].includes(typeof value));
        if (!scalarEntries.length) {
            return;
        }
        const grid = document.createElement('div');
        grid.className = 'shcd-tornado-dbm-result-stats';
        scalarEntries.slice(0, 10).forEach(([key, value]) => {
            const item = document.createElement('div');
            const label = document.createElement('span');
            const strong = document.createElement('strong');
            label.textContent = scalarLabel(key);
            strong.textContent = valueText(value);
            item.append(label, strong);
            grid.appendChild(item);
        });
        container.appendChild(grid);
    };

    const addMessages = (container, title, values, type) => {
        if (!Array.isArray(values) || !values.length) {
            return;
        }
        const box = document.createElement('div');
        box.className = `shcd-tornado-dbm-message-list is-${type}`;
        const heading = document.createElement('strong');
        heading.textContent = title;
        const list = document.createElement('ul');
        values.forEach((value) => {
            const item = document.createElement('li');
            item.textContent = typeof value === 'string' ? value : JSON.stringify(value);
            list.appendChild(item);
        });
        box.append(heading, list);
        container.appendChild(box);
    };

    const addAutoIncrementTable = (container, tables) => {
        if (!Array.isArray(tables) || !tables.length || !tables[0].table || !Object.prototype.hasOwnProperty.call(tables[0], 'proposed_next_id')) {
            return false;
        }
        const wrap = document.createElement('div');
        wrap.className = 'shcd-tornado-dbm-table-scroll';
        const table = document.createElement('table');
        table.className = 'shcd-tornado-dbm-data-table';
        const head = document.createElement('thead');
        head.innerHTML = '<tr><th>جدول</th><th>ستون</th><th>AUTO_INCREMENT فعلی</th><th>MAX(ID)</th><th>مقدار پیشنهادی</th><th>وضعیت</th></tr>';
        const body = document.createElement('tbody');
        tables.forEach((row) => {
            const tr = document.createElement('tr');
            [row.table, row.column, row.current_auto_increment, row.max_existing_id, row.proposed_next_id].forEach((value) => {
                const td = document.createElement('td');
                td.textContent = valueText(value);
                tr.appendChild(td);
            });
            const status = document.createElement('td');
            const badge = document.createElement('span');
            badge.className = `shcd-tornado-dbm-status ${row.needs_change ? 'is-warning' : 'is-success'}`;
            badge.textContent = row.needs_change ? 'نیازمند اصلاح' : 'هماهنگ';
            status.appendChild(badge);
            tr.appendChild(status);
            body.appendChild(tr);
        });
        table.append(head, body);
        wrap.appendChild(table);
        container.appendChild(wrap);
        return true;
    };

    const addMappingPreview = (container, rows) => {
        if (!Array.isArray(rows) || !rows.length || !Object.prototype.hasOwnProperty.call(rows[0], 'old_id')) {
            return false;
        }
        const wrap = document.createElement('div');
        wrap.className = 'shcd-tornado-dbm-mapping-preview';
        rows.slice(0, 12).forEach((row) => {
            const item = document.createElement('div');
            item.innerHTML = `<code>${Number(row.old_id)}</code><span>→</span><code>${Number(row.new_id)}</code>`;
            wrap.appendChild(item);
        });
        container.appendChild(wrap);
        return true;
    };

    const addMediaVerification = (container, data) => {
        const media = data?.media_verification
            || data?.elementor?.media_verification
            || data?.cache?.elementor?.media_verification
            || null;
        if (!media || typeof media !== 'object') {
            return;
        }

        const box = document.createElement('div');
        box.className = 'shcd-tornado-dbm-coverage';
        const title = document.createElement('h4');
        title.textContent = 'وضعیت Referenceهای رسانه و تصاویر Elementor';
        box.appendChild(title);
        addSummary(box, {
            references_checked: media.references_checked || 0,
            valid_references: media.valid_references || 0,
            unique_unresolved_count: media.unique_unresolved_count || 0,
            blocking_unresolved_count: media.blocking_unresolved_count || 0,
            advisory_unresolved_count: media.advisory_unresolved_count || 0,
            url_only_detached_count: media.url_only_detached_count || 0,
            safe_for_reindex: Boolean(media.safe_for_reindex)
        });

        if (media.categories && typeof media.categories === 'object' && Object.keys(media.categories).length) {
            const categories = document.createElement('div');
            categories.className = 'shcd-tornado-dbm-table-chips';
            const labels = {
                missing_internal_file: 'فایل محلی پیدا نشد',
                id_only_unresolved: 'شناسه رسانه بدون URL',
                mapping_not_applied: 'Mapping روی Reference اعمال نشده است',
                id_url_mismatch: 'ID و URL به یک رسانه اشاره نمی‌کنند',
                url_only_without_attachment: 'فقط URL؛ خارج از محدوده Mapping'
            };
            Object.entries(media.categories).forEach(([key, count]) => {
                const chip = document.createElement('code');
                chip.textContent = `${labels[key] || key}: ${count}`;
                categories.appendChild(chip);
            });
            box.appendChild(categories);
        }

        if ((media.url_only_detached_count || 0) > 0) {
            const note = document.createElement('div');
            note.className = 'shcd-tornado-dbm-inline-note is-info';
            note.textContent = `${media.url_only_detached_count || 0} کاربرد Elementor فقط با URL ثبت شده است (${media.url_only_unique_count || 0} URL یکتا). این موارد رکورد Attachment در ${postsTable} ندارند، خارج از Mapping باقی می‌مانند و مانع Reindex نیستند.`;
            box.appendChild(note);

            if (Array.isArray(media.url_only_examples) && media.url_only_examples.length) {
                const details = document.createElement('details');
                details.className = 'shcd-tornado-dbm-technical';
                const summary = document.createElement('summary');
                summary.textContent = 'نمایش چند URL نمونه';
                details.appendChild(summary);
                const list = document.createElement('div');
                list.className = 'shcd-tornado-dbm-message-list is-info';
                media.url_only_examples.slice(0, 8).forEach((row) => {
                    const item = document.createElement('div');
                    item.className = 'shcd-tornado-dbm-message';
                    const status = row.file_exists ? 'فایل محلی موجود' : 'فایل محلی موجود نیست';
                    item.textContent = `${row.url || '—'} — ${status} — ${row.occurrences || 1} بار استفاده`;
                    list.appendChild(item);
                });
                details.appendChild(list);
                box.appendChild(details);
            }
        }

        if (Array.isArray(media.samples) && media.samples.length) {
            const relevantSamples = media.samples.filter((row) => row.severity !== 'info' && row.category !== 'url_only_without_attachment');
            if (relevantSamples.length) {
                const messages = relevantSamples.slice(0, 30).map((row) => {
                    const severity = row.severity === 'blocking' ? 'مسدودکننده' : 'خط مبنای قبلی';
                    const location = `meta_id=${row.meta_id || '—'}، مسیر ${row.path || '—'}`;
                    const ids = `ID فعلی ${row.current_id || 0}${row.expected_id ? ` ← ID صحیح ${row.expected_id}` : ''}`;
                    const url = row.url ? `، URL: ${row.url}` : '';
                    return `${severity} — ${location} — ${ids}${url} — ${row.reason || ''}`;
                });
                addMessages(box, 'مواردی که باید بررسی شوند', messages, (media.blocking_unresolved_count || 0) > 0 ? 'danger' : 'warning');
            }
        }

        container.appendChild(box);
    };

    const addReferencePolicyManager = (container, data) => {
        if (!data || typeof data !== 'object') {
            return;
        }

        const rows = [];
        const sources = [
            ...(Array.isArray(data.auto_registered_references) ? data.auto_registered_references : []),
            ...(Array.isArray(data.managed_reference_candidates) ? data.managed_reference_candidates : []),
            ...(Array.isArray(data.unknown_candidates) ? data.unknown_candidates : [])
        ];
        const seen = new Set();
        sources.forEach((row) => {
            const table = String(row.table || row.table_name || '').trim();
            const column = String(row.column || row.column_name || '').trim();
            const key = `${table}.${column}`;
            if (!table || !column || seen.has(key)) {
                return;
            }
            seen.add(key);
            rows.push({ ...row, table, column, key });
        });

        if (!rows.length) {
            return;
        }

        const storedPolicies = data.reference_policies && typeof data.reference_policies === 'object'
            ? data.reference_policies
            : (currentSettings.reference_policies && typeof currentSettings.reference_policies === 'object' ? currentSettings.reference_policies : {});
        const box = document.createElement('div');
        box.className = 'shcd-tornado-dbm-reference-policy';
        const title = document.createElement('h4');
        title.textContent = 'مدیریت Referenceهای اختصاصی';
        const description = document.createElement('p');
        description.textContent = 'برای جدول‌های اختصاصی مشخص کنید شناسه‌ها با Mapping هماهنگ شوند، ارتباط‌های درگیر صفر شوند، ردیف‌های درگیر حذف شوند یا فقط در صورت بی‌اثر بودن نادیده گرفته شوند.';
        box.append(title, description);

        rows.slice(0, 100).forEach((row) => {
            const item = document.createElement('div');
            item.className = 'shcd-tornado-dbm-reference-policy__row';
            const info = document.createElement('div');
            const name = document.createElement('strong');
            name.textContent = row.key;
            const details = document.createElement('small');
            details.textContent = `${row.matching_rows || 0} ردیف منطبق، ${row.gap_collision_rows || 0} برخورد با شناسه هدف${row.reason ? ` — ${row.reason}` : ''}`;
            info.append(name, details);

            const select = document.createElement('select');
            select.dataset.referencePolicy = row.key;
            [
                ['update', 'هماهنگ‌سازی شناسه‌ها با Mapping'],
                ['detach', 'صفرکردن ارتباط‌های درگیر و ادامه'],
                ['delete_rows', 'حذف ردیف‌های درگیر و ادامه'],
                ['ignore', 'نادیده‌گرفتن فقط در صورت بی‌اثر بودن']
            ].forEach(([value, label]) => {
                const option = document.createElement('option');
                option.value = value;
                option.textContent = label;
                select.appendChild(option);
            });
            select.value = String(storedPolicies[row.key] || row.policy || 'update');
            item.append(info, select);
            box.appendChild(item);
        });

        const note = document.createElement('div');
        note.className = 'shcd-tornado-dbm-message shcd-tornado-dbm-message--warning';
        note.textContent = 'نادیده‌گرفتن یک Reference دارای مقدار منطبق ایمن نیست و Preflight آن را نمی‌پذیرد. برای جدول‌های آماری و Cache، گزینه صفرکردن یا حذف ردیف‌های درگیر مناسب‌تر است.';
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'shcd-tornado-dbm-button shcd-tornado-dbm-button--primary';
        button.dataset.action = 'save-reference-policies';
        button.textContent = 'ذخیره سیاست و بررسی دوباره Preflight';
        const status = document.createElement('div');
        status.className = 'shcd-tornado-dbm-reference-policy__status';
        status.dataset.referencePolicyStatus = 'true';
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        status.textContent = 'پس از ذخیره، نتیجه Preflight و علت‌های باقی‌مانده در همین بخش نمایش داده می‌شود.';
        box.append(note, button, status);
        container.appendChild(box);
    };

    const renderResult = (container, data, title = 'نتیجه عملیات') => {
        if (!container) {
            return;
        }
        container.className = 'shcd-tornado-dbm-result';
        container.textContent = '';

        const head = document.createElement('div');
        head.className = 'shcd-tornado-dbm-result__head';
        const heading = document.createElement('strong');
        heading.textContent = title;
        const timestamp = document.createElement('span');
        timestamp.textContent = new Intl.DateTimeFormat('fa-IR', { hour: '2-digit', minute: '2-digit' }).format(new Date());
        head.append(heading, timestamp);
        container.appendChild(head);

        if (Array.isArray(data)) {
            const summary = { count: data.length };
            addSummary(container, summary);
        } else if (data && typeof data === 'object') {
            addSummary(container, data);
            addMessages(container, 'موارد بازدارنده', data.blockers, 'danger');
            addMessages(container, 'هشدارها', data.warnings, 'warning');

            const repairDetails = (data.elementor && typeof data.elementor === 'object')
                ? data.elementor
                : data;
            addMessages(container, 'خطاهای ساختاری Elementor و Builderها', repairDetails.critical_errors, 'danger');
            addMessages(container, 'هشدارهای مربوط به CSS و Cache در Elementor', repairDetails.advisory_errors, 'warning');

            if (Array.isArray(data.auto_registered_references) && data.auto_registered_references.length) {
                addMessages(
                    container,
                    'Referenceهایی که خودکار تأیید شدند',
                    data.auto_registered_references.map((row) => `${row.table}.${row.column} — ${row.matching_rows || 0} ردیف منطبق`),
                    'success'
                );
            }
            if (Array.isArray(data.unknown_candidates) && data.unknown_candidates.length) {
                addMessages(
                    container,
                    'Referenceهایی که نیاز به بررسی دارند',
                    data.unknown_candidates.map((row) => `${row.table || row.table_name}.${row.column || row.column_name} — ${row.reason || 'ابهام معنایی'}`),
                    'danger'
                );
            }
            addReferencePolicyManager(container, data);
            if (data.baseline_integrity && typeof data.baseline_integrity === 'object') {
                const integrityBox = document.createElement('div');
                integrityBox.className = 'shcd-tornado-dbm-coverage';
                const integrityTitle = document.createElement('h4');
                integrityTitle.textContent = 'وضعیت پایه Integrity';
                integrityBox.appendChild(integrityTitle);
                addSummary(integrityBox, {
                    total_issues: data.baseline_integrity.total_issues || 0,
                    reindex_blocking_issues: data.baseline_integrity.reindex_blocking_issues || 0,
                    advisory_issues: data.baseline_integrity.advisory_issues || 0
                });
                container.appendChild(integrityBox);
            }

            if (Array.isArray(data.tables)) {
                addAutoIncrementTable(container, data.tables);
            }
            if (Array.isArray(data.rebuild_preview)) {
                addMappingPreview(container, data.rebuild_preview);
            }
            if (data.reference_coverage && typeof data.reference_coverage === 'object') {
                const coverage = document.createElement('div');
                coverage.className = 'shcd-tornado-dbm-coverage';
                const coverageTitle = document.createElement('h4');
                coverageTitle.textContent = 'دامنه Referenceهای پوشش‌داده‌شده';
                coverage.appendChild(coverageTitle);
                addSummary(coverage, data.reference_coverage);
                if (Array.isArray(data.reference_coverage.tables)) {
                    const chips = document.createElement('div');
                    chips.className = 'shcd-tornado-dbm-table-chips';
                    data.reference_coverage.tables.slice(0, 30).forEach((name) => {
                        const chip = document.createElement('code');
                        chip.textContent = name;
                        chips.appendChild(chip);
                    });
                    coverage.appendChild(chips);
                }
                container.appendChild(coverage);
            }
            addMediaVerification(container, data);
            if (data.auto_increment && typeof data.auto_increment === 'object') {
                const autoBox = document.createElement('div');
                autoBox.className = 'shcd-tornado-dbm-coverage';
                const titleNode = document.createElement('h4');
                titleNode.textContent = 'تنظیم خودکار AUTO_INCREMENT';
                autoBox.appendChild(titleNode);
                addSummary(autoBox, {
                    changed: Array.isArray(data.auto_increment.changed) ? data.auto_increment.changed.length : 0,
                    unchanged: Array.isArray(data.auto_increment.unchanged) ? data.auto_increment.unchanged.length : 0,
                    failed: Array.isArray(data.auto_increment.failed) ? data.auto_increment.failed.length : 0
                });
                container.appendChild(autoBox);
            }
        }

        const details = document.createElement('details');
        details.className = 'shcd-tornado-dbm-result__raw';
        const summary = document.createElement('summary');
        summary.textContent = 'نمایش جزئیات فنی';
        const pre = document.createElement('pre');
        pre.textContent = JSON.stringify(data, null, 2);
        details.append(summary, pre);
        container.appendChild(details);
    };

    const selectedCleanupTypes = () => Array.from(document.querySelectorAll('#shcd-tornado-dbm-cleanup-types input:checked')).map((field) => field.value);

    const setKnownUuids = (data) => {
        if (!data || typeof data !== 'object') {
            return;
        }
        if (data.uuid && (Object.prototype.hasOwnProperty.call(data, 'changed_ids') || data.mapping_sha256)) {
            document.getElementById('shcd-tornado-dbm-mapping-uuid').value = data.uuid;
            document.getElementById('shcd-tornado-dbm-reindex-mapping').value = data.uuid;
        }
        if (data.uuid && (data.sha256 || data.file_size || data.table_count)) {
            document.getElementById('shcd-tornado-dbm-backup-uuid').value = data.uuid;
            document.getElementById('shcd-tornado-dbm-unused-backup').value = data.uuid;
        }
    };

    const authorizationFieldIds = {
        reindex: 'shcd-tornado-dbm-reindex-authorization',
        table_drop: 'shcd-tornado-dbm-table-authorization'
    };

    const storeAuthorization = (scope, token) => {
        const normalized = String(token || '').trim();
        authorization[scope] = normalized;
        const field = document.getElementById(authorizationFieldIds[scope] || '');
        if (field) {
            field.value = normalized;
        }
        return normalized;
    };

    const clearAuthorization = (scope) => {
        storeAuthorization(scope, '');
    };

    const arm = async (scope) => {
        const data = await request('security/arm', { method: 'POST', body: { scope } });
        const token = storeAuthorization(scope, data.authorization_token || data.token || '');
        if (!token) {
            throw new Error('Token مجوز از سرور دریافت نشد. صفحه را تازه‌سازی کنید و دوباره مجوز بگیرید.');
        }
        showNotice('مجوز یک‌بارمصرف صادر شد و Token در کادر مربوط قرار گرفت.', 'success');
        return { ...data, authorization_token: token };
    };

    const authorizationSummary = (data) => ({
        status: 'صادر شد',
        scope: data.scope || '—',
        issued_at: data.issued_at || '—',
        expires_at: data.expires_at || '—'
    });

    const isAuthorizationError = (error) => /مجوز|Token|توکن|نشست|اعتبار مجوز/i.test(String(error?.message || ''));

    const openTab = (name) => {
        document.querySelectorAll('.shcd-tornado-dbm-tab').forEach((item) => item.classList.toggle('is-active', item.dataset.tab === name));
        document.querySelectorAll('.shcd-tornado-dbm-panel').forEach((panel) => panel.classList.toggle('is-active', panel.dataset.panel === name));
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    const renderSystem = (system) => {
        document.getElementById('shcd-tornado-dbm-stat-wordpress').textContent = system.wordpress_version || '—';
        document.getElementById('shcd-tornado-dbm-stat-php').textContent = system.php_version || '—';
        document.getElementById('shcd-tornado-dbm-stat-database').textContent = system.database_version || '—';
        document.getElementById('shcd-tornado-dbm-stat-posts').textContent = new Intl.NumberFormat('fa-IR').format(Number(system.post_count || 0));
        document.getElementById('shcd-tornado-dbm-stat-next-id').textContent = new Intl.NumberFormat('fa-IR').format(Number(system.post_auto_increment || 0));

        const safety = system.multisite
            ? 'نیازمند بررسی تنظیمات شبکه'
            : (system.active_reindex
                ? 'Reindex آماده اجرا است'
                : (!system.destructive_reindex_enabled
                    ? 'Reindex غیرفعال است'
                    : (system.last_backup_reindex_eligible ? 'آماده اجرای Preflight' : 'Backup باید بررسی شود')));
        document.getElementById('shcd-tornado-dbm-stat-safety').textContent = safety;
        document.getElementById('shcd-tornado-dbm-side-reindex').textContent = safety;

        const discovery = system.last_discovery_uuid || '';
        const mapping = system.last_mapping_uuid || '';
        const backup = system.last_backup_uuid || '';
        document.getElementById('shcd-tornado-dbm-last-discovery').textContent = discovery || 'هنوز ثبت نشده است';
        document.getElementById('shcd-tornado-dbm-last-mapping').textContent = mapping || 'هنوز ثبت نشده است';
        document.getElementById('shcd-tornado-dbm-last-backup').textContent = backup || 'هنوز ثبت نشده است';
        document.getElementById('shcd-tornado-dbm-side-mapping').textContent = mapping ? 'آماده' : 'هنوز ثبت نشده است';
        document.getElementById('shcd-tornado-dbm-side-backup').textContent = backup ? 'موجود' : 'هنوز ثبت نشده است';
        document.getElementById('shcd-tornado-dbm-system-output').textContent = JSON.stringify(system, null, 2);

        if (mapping) {
            document.getElementById('shcd-tornado-dbm-mapping-uuid').value = mapping;
            document.getElementById('shcd-tornado-dbm-reindex-mapping').value = mapping;
        }
        if (backup) {
            document.getElementById('shcd-tornado-dbm-backup-uuid').value = backup;
            document.getElementById('shcd-tornado-dbm-unused-backup').value = backup;
        }
    };

    const refreshDashboard = async () => {
        const system = await request('system');
        renderSystem(system);
        return system;
    };

    const loadStorageFootprint = async () => {
        const data = await request('storage/footprint');
        renderResult(outputs.storage, data, 'فضای فعلی داده‌های داخلی Tornado');
        return data;
    };

    const setWizardStep = (step, status, message) => {
        const node = document.querySelector(`[data-wizard-step="${step}"]`);
        if (!node) {
            return;
        }
        node.classList.remove('is-running', 'is-complete', 'is-failed');
        if (status) {
            node.classList.add(`is-${status}`);
        }
        const description = node.querySelector('small');
        if (description && message) {
            description.textContent = message;
        }
    };

    const resetWizard = () => {
        ['prepare', 'discover', 'mapping', 'dry-run', 'backup', 'preflight'].forEach((step) => {
            setWizardStep(step, '', 'هنوز اجرا نشده است');
        });
    };

    const ensureAutomaticWizardSettings = async () => {
        const form = document.getElementById('shcd-tornado-dbm-id-settings-form');
        if (!form) {
            throw new Error('فرم تنظیمات شناسه‌ها در صفحه در دسترس نیست. صفحه را تازه‌سازی کنید.');
        }
        const destructive = form.elements.namedItem('allow_destructive_reindex');
        if (destructive && !destructive.checked) {
            const accepted = window.confirm('برای اجرای Wizard باید اجازه تغییر شناسه‌ها فعال باشد. این گزینه اکنون فعال و ذخیره شود؟');
            if (!accepted) {
                throw new Error('Wizard متوقف شد؛ اجازه تغییر شناسه‌ها فعال نشده است.');
            }
            destructive.checked = true;
        }
        const autoRegister = form.elements.namedItem('auto_register_discovered_references');
        if (autoRegister) {
            autoRegister.checked = true;
        }
        const autoResolve = form.elements.namedItem('auto_resolve_ambiguous_references');
        if (autoResolve) {
            autoResolve.checked = true;
        }
        await saveSettingsForm(form, 'تنظیمات لازم برای اجرای Wizard ذخیره شد.');
    };

    const runAutomaticReindexWizard = async () => {
        resetWizard();
        await ensureAutomaticWizardSettings();

        let activeStep = 'prepare';
        try {
            setWizardStep('prepare', 'running', 'در حال تهیه Backup ایمنی و بررسی آثار نسل قبلی، Builderها و تصاویر…');
            const preparation = await request('reindex/prepare', { method: 'POST' });
            renderResult(outputs.identifiers, preparation, 'مرحله ۱: بررسی و آماده‌سازی نسل قبلی');
            if (!preparation.allowed) {
                setWizardStep('prepare', 'failed', 'چند Reference هنوز نیازمند بررسی است');
                const blockers = Array.isArray(preparation.blockers) ? preparation.blockers.join(' ') : 'مرحله آماده‌سازی اجازه ادامه عملیات را نداد.';
                throw new Error(blockers);
            }
            setWizardStep('prepare', 'complete', 'Backup ایمنی تهیه شد و بررسی نسل قبلی پایان یافت');

            activeStep = 'discover';
            setWizardStep('discover', 'running', 'در حال شناسایی ساختار دیتابیس و Referenceها…');
            const discovery = await request('discover', { method: 'POST' });
            setWizardStep('discover', 'complete', 'Discovery با موفقیت پایان یافت');
            renderResult(outputs.identifiers, discovery, 'مرحله ۲: Discovery پایان یافت');

            activeStep = 'mapping';
            setWizardStep('mapping', 'running', 'در حال ساخت Mapping جدید و قابل اعتبارسنجی…');
            const mapping = await request('mapping', { method: 'POST' });
            setKnownUuids(mapping);
            setWizardStep('mapping', 'complete', 'Mapping با موفقیت ساخته شد');
            renderResult(outputs.identifiers, mapping, 'مرحله ۳: Mapping با موفقیت ساخته شد');

            activeStep = 'dry-run';
            setWizardStep('dry-run', 'running', 'در حال بررسی نتیجه تغییرات بدون نوشتن در دیتابیس…');
            const dryRun = await request('dry-run', {
                method: 'POST',
                body: { mapping_uuid: mapping.uuid }
            });
            setWizardStep('dry-run', 'complete', 'Dry Run بدون اعمال تغییر پایان یافت');
            renderResult(outputs.identifiers, dryRun, 'مرحله ۴: Dry Run پایان یافت');

            activeStep = 'backup';
            setWizardStep('backup', 'running', 'در حال تهیه و بررسی Backup نهایی…');
            const backup = await request('backup', { method: 'POST' });
            setKnownUuids(backup);
            setWizardStep('backup', 'complete', 'Backup نهایی تهیه و تأیید شد');
            renderResult(outputs.identifiers, backup, 'مرحله ۵: Backup تأیید شد');

            activeStep = 'preflight';
            setWizardStep('preflight', 'running', 'در حال بررسی Integrity و اعتبار Referenceها…');
            const preflight = await request('reindex/preflight', {
                method: 'POST',
                body: { mapping_uuid: mapping.uuid, backup_uuid: backup.uuid }
            });
            if (preflight.confirmation_phrase) {
                const confirmation = document.getElementById('shcd-tornado-dbm-confirmation');
                confirmation.value = preflight.confirmation_phrase;
                confirmation.placeholder = preflight.confirmation_phrase;
            }
            renderResult(outputs.identifiers, preflight, preflight.allowed ? 'آماده‌سازی Wizard پایان یافت' : 'Wizard در Preflight متوقف شد');
            if (!preflight.allowed) {
                setWizardStep('preflight', 'failed', 'مواردی پیدا شد که باید پیش از ادامه برطرف شوند');
                const blockers = Array.isArray(preflight.blockers) ? preflight.blockers.join(' ') : 'Preflight اجازه اجرای Reindex را نداد.';
                throw new Error(blockers);
            }
            setWizardStep('preflight', 'complete', 'Preflight با موفقیت پایان یافت؛ آماده اجرای نهایی');
            showNotice('مراحل آماده‌سازی با موفقیت پایان یافت. اکنون مجوز جدید صادر کنید و اجرای نهایی Reindex را تأیید کنید.', 'success');
            await refreshDashboard();
            return preflight;
        } catch (error) {
            setWizardStep(activeStep, 'failed', 'اجرای این مرحله با خطا متوقف شد');
            throw error;
        }
    };

    const actions = {
        'save-reference-policies': async () => {
            const selects = outputs.identifiers ? outputs.identifiers.querySelectorAll('[data-reference-policy]') : [];
            const status = outputs.identifiers ? outputs.identifiers.querySelector('[data-reference-policy-status]') : null;
            if (!selects.length) {
                throw new Error('Reference قابل تنظیمی در گزارش فعلی وجود ندارد. Preflight را دوباره اجرا کنید.');
            }
            const policies = {};
            selects.forEach((select) => {
                const key = String(select.dataset.referencePolicy || '').trim();
                const value = String(select.value || 'update');
                if (key) {
                    policies[key] = value;
                }
            });
            const mappingUuid = document.getElementById('shcd-tornado-dbm-mapping-uuid')?.value.trim() || '';
            const backupUuid = document.getElementById('shcd-tornado-dbm-backup-uuid')?.value.trim() || '';
            if (!mappingUuid || !backupUuid) {
                throw new Error('برای بررسی دوباره Preflight، Mapping UUID و Backup UUID باید مشخص باشند.');
            }
            if (status) {
                status.className = 'shcd-tornado-dbm-reference-policy__status is-loading';
                status.textContent = 'سیاست انتخاب‌شده در حال ذخیره است؛ سپس Preflight دوباره بررسی می‌شود…';
            }
            try {
                const response = await request('reindex/reference-policies/preflight', {
                    method: 'POST',
                    body: {
                        policies,
                        mapping_uuid: mappingUuid,
                        backup_uuid: backupUuid
                    }
                });
                currentSettings = response.settings && typeof response.settings === 'object' ? response.settings : currentSettings;
                const preflight = response.preflight && typeof response.preflight === 'object' ? response.preflight : {};
                if (preflight.confirmation_phrase) {
                    const confirmation = document.getElementById('shcd-tornado-dbm-confirmation');
                    if (confirmation) {
                        confirmation.value = preflight.confirmation_phrase;
                        confirmation.placeholder = preflight.confirmation_phrase;
                    }
                }
                renderResult(outputs.identifiers, preflight, preflight.allowed ? 'سیاست ذخیره شد و Preflight تأیید شد' : 'سیاست ذخیره شد؛ Preflight هنوز متوقف است');
                setWizardStep('preflight', preflight.allowed ? 'complete' : 'failed', preflight.allowed ? 'Preflight با سیاست تازه تأیید شد' : 'موارد بازدارنده دیگری هنوز باقی مانده‌اند');
                const message = String(response.message || (preflight.allowed ? 'Preflight اجازه ادامه عملیات را صادر کرد.' : 'Preflight هنوز موارد بازدارنده دارد.'));
                showNotice(message, preflight.allowed ? 'success' : 'warning');
                outputs.identifiers?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                return preflight;
            } catch (error) {
                if (status) {
                    status.className = 'shcd-tornado-dbm-reference-policy__status is-error';
                    status.textContent = `ذخیره سیاست یا اجرای Preflight انجام نشد: ${error.message || config.i18n.failed}`;
                }
                throw error;
            }
        },
        'refresh-dashboard': refreshDashboard,
        'revision-archive-refresh': async () => {
            const [status, list] = await Promise.all([request('revisions/archive/status'), loadRevisionArchive()]);
            renderResult(outputs.revisionArchive, { ...status, shown_items: Array.isArray(list.items) ? list.items.length : 0 }, 'وضعیت آرشیو نسخه‌ها');
            showNotice('فهرست Snapshotهای آرشیو به‌روزرسانی شد.', 'success');
        },
        'revision-archive-migrate-core': async () => {
            if (String(currentSettings.revision_storage_mode || 'wordpress') !== 'archive') {
                throw new Error('ابتدا روش نگهداری تاریخچه را روی «آرشیو مستقل Tornado» قرار دهید و تنظیمات را ذخیره کنید.');
            }
            if (!window.confirm('Revisionهای عادی موجود ابتدا در آرشیو مستقل ذخیره و سپس از جدول نوشته‌ها حذف می‌شوند. Autosave و Pending Revision دست‌نخورده می‌مانند. ادامه می‌دهید؟')) {
                return;
            }
            let remaining = 1;
            let batches = 0;
            let migrated = 0;
            let deleted = 0;
            const failed = [];
            let last = {};
            while (remaining > 0 && batches < 20) {
                last = await request('revisions/archive/migrate-core', {
                    method: 'POST',
                    body: { confirmation: 'MIGRATE-REVISIONS', limit: 500 }
                });
                batches += 1;
                migrated += Number(last.migrated || 0);
                deleted += Number(last.deleted || 0);
                remaining = Number(last.remaining || 0);
                if (Array.isArray(last.failed)) {
                    failed.push(...last.failed);
                }
                if (Number(last.migrated || 0) === 0 && Number(last.deleted || 0) === 0) {
                    break;
                }
            }
            const data = { ...last, batches, migrated, deleted, remaining, failed };
            renderResult(outputs.revisionArchive, data, 'انتقال Revisionهای موجود پایان یافت');
            if (remaining > 0) {
                showNotice(`تعداد ${remaining} Revision عادی هنوز باقی مانده است. موارد ناموفق را بررسی و عملیات را دوباره اجرا کنید.`, 'warning');
            } else if (deleted > 0) {
                showNotice('Revisionهای عادی به آرشیو منتقل و از جدول نوشته‌ها حذف شدند. برای بستن شکاف‌های ایجادشده، Reindex را در زمان نگهداری اجرا کنید.', 'success');
            } else {
                showNotice('Revision عادی قابل انتقالی در جدول نوشته‌ها پیدا نشد.', 'success');
            }
            await loadRevisionArchive();
        },
        'revision-archive-delete-selected': async () => {
            const ids = selectedValues('.shcd-tornado-dbm-revision-archive-select').map((value) => Number(value)).filter(Number.isInteger);
            if (!ids.length) {
                throw new Error('حداقل یک Snapshot را برای حذف انتخاب کنید.');
            }
            if (!window.confirm(`تعداد ${ids.length} Snapshot از آرشیو حذف می‌شود. ادامه می‌دهید؟`)) {
                return;
            }
            const data = await request('revisions/archive/delete', { method: 'POST', body: { ids } });
            renderResult(outputs.revisionArchive, data, 'Snapshotهای انتخاب‌شده حذف شدند');
            const selectAll = document.getElementById('shcd-tornado-dbm-revision-archive-select-all');
            if (selectAll) selectAll.checked = false;
            await loadRevisionArchive();
        },
        'revision-archive-restore': async (button) => {
            const snapshotId = Number(button?.dataset?.snapshotId || 0);
            if (!snapshotId) {
                throw new Error('شناسه Snapshot برای بازیابی در دسترس نیست.');
            }
            if (!window.confirm('محتوا و Metaهای ثبت‌شده در این Snapshot روی نوشته فعلی بازیابی شوند؟')) {
                return;
            }
            const data = await request('revisions/archive/restore', { method: 'POST', body: { snapshot_id: snapshotId } });
            renderResult(outputs.revisionArchive, data, 'Snapshot با موفقیت بازیابی شد');
            showNotice('Snapshot بازیابی شد و Cacheهای مشتق‌شده پاک شدند.', 'success');
            await loadRevisionArchive();
        },
        'storage-refresh': loadStorageFootprint,
        'storage-compact': async () => {
            if (!window.confirm('Discoveryهای تکراری و Mappingهای منقضی حذف شوند؟ آخرین Snapshotهای لازم برای Wizard و بازیابی Reindex حفظ خواهند شد.')) {
                return;
            }
            const data = await request('storage/compact', { method: 'POST' });
            renderResult(outputs.storage, data, 'فشرده‌سازی داده‌های داخلی پایان یافت');
            showNotice('داده‌های داخلی تکراری حذف شدند و AUTO_INCREMENT جدول‌های مربوط تنظیم شد.', 'success');
        },
        'reindex-prepare': async () => {
            const data = await request('reindex/prepare', { method: 'POST' });
            renderResult(outputs.identifiers, data, 'آماده‌سازی برای Reindex دوباره');
            showNotice(data.allowed ? 'آماده‌سازی پایان یافت. اکنون Discovery و Mapping تازه ایجاد کنید.' : 'آماده‌سازی کامل نشد؛ موارد گزارش‌شده را بررسی کنید.', data.allowed ? 'success' : 'warning');
        },
        'reindex-wizard': runAutomaticReindexWizard,
        'reindex-generations': async () => {
            const data = await request('reindex/generations');
            renderResult(outputs.generations, { generations: data }, 'تاریخچه اجرای Reindex');
        },
        discover: async () => {
            const data = await request('discover', { method: 'POST' });
            renderResult(outputs.identifiers, data, 'Discovery پایان یافت');
            showNotice('Discovery دیتابیس با موفقیت پایان یافت.', 'success');
        },
        mapping: async () => {
            const data = await request('mapping', { method: 'POST' });
            setKnownUuids(data);
            renderResult(outputs.identifiers, data, 'Mapping شناسه‌ها ساخته شد');
            showNotice('Mapping جدید ساخته و ثبت شد.', 'success');
        },
        'dry-run': async () => {
            const data = await request('dry-run', {
                method: 'POST',
                body: { mapping_uuid: document.getElementById('shcd-tornado-dbm-mapping-uuid').value.trim() }
            });
            renderResult(outputs.identifiers, data, 'گزارش Dry Run');
        },
        backup: async () => {
            const data = await request('backup', { method: 'POST' });
            setKnownUuids(data);
            renderResult(outputs.identifiers, data, 'Backup تأییدشده ساخته شد');
            showNotice('Backup با موفقیت تهیه و اعتبارسنجی شد.', 'success');
        },
        integrity: async () => {
            const data = await request('integrity', {
                method: 'POST',
                body: { mapping_uuid: document.getElementById('shcd-tornado-dbm-mapping-uuid').value.trim() }
            });
            renderResult(outputs.maintenance, data, 'Integrity Check');
        },
        'cleanup-select-all': async () => {
            document.querySelectorAll('#shcd-tornado-dbm-cleanup-types input[type="checkbox"]').forEach((field) => {
                field.checked = true;
            });
            showNotice('همه گزینه‌های پاک‌سازی انتخاب شدند. انتخاب «تمام Revisionها» باعث می‌شود گزینه‌های جزئی Revision در زمان اجرا به‌صورت خودکار کنار گذاشته شوند.', 'success');
        },
        'cleanup-clear-all': async () => {
            document.querySelectorAll('#shcd-tornado-dbm-cleanup-types input[type="checkbox"]').forEach((field) => {
                field.checked = false;
            });
            cleanupPreviewToken = '';
            showNotice('انتخاب‌های پاک‌سازی لغو شدند.', 'success');
        },
        'cleanup-preview': async () => {
            const types = selectedCleanupTypes();
            if (!types.length) {
                throw new Error('دست‌کم یک مورد را برای پاک‌سازی انتخاب کنید.');
            }
            const data = await request('cleanup/preview', { method: 'POST', body: { types } });
            cleanupPreviewToken = data.preview_token || '';
            const visible = { ...data };
            delete visible.preview_token;
            renderResult(outputs.maintenance, visible, 'پیش‌نمایش موارد قابل پاک‌سازی');
        },
        'cleanup-execute': async () => {
            if (!cleanupPreviewToken) {
                throw new Error('پیش از حذف، ابتدا پیش‌نمایش موارد قابل پاک‌سازی را اجرا کنید.');
            }
            if (!window.confirm('موارد تأییدشده حذف شوند و سپس AUTO_INCREMENT جدول‌های درگیر تنظیم شود؟')) {
                return;
            }
            const data = await request('cleanup/execute', {
                method: 'POST',
                body: { types: selectedCleanupTypes(), preview_token: cleanupPreviewToken }
            });
            cleanupPreviewToken = '';
            renderResult(outputs.maintenance, data, 'پاک‌سازی پایان یافت');
            const remainingTotal = Object.values(data.remaining || {}).reduce((sum, value) => sum + Number(value || 0), 0);
            showNotice(remainingTotal > 0 ? `پاک‌سازی انجام شد، اما ${remainingTotal} مورد همچنان باقی مانده است. جزئیات گزارش را بررسی کنید.` : 'پاک‌سازی با موفقیت پایان یافت و شمارنده جدول‌های درگیر نیز تنظیم شد.', remainingTotal > 0 ? 'warning' : 'success');
            await refreshDashboard();
        },
        cache: async () => {
            const data = await request('cache/purge', { method: 'POST' });
            renderResult(outputs.maintenance, data, 'پاک‌سازی Cacheها');
            showNotice('Cache پاک شد و AUTO_INCREMENT جدول‌های مرتبط نیز بررسی شد.', 'success');
        },
        'builders-scan': async () => {
            const data = await request('builders/scan', { method: 'POST' });
            renderBuilders(data);
            showNotice(`${data.count || 0} سازنده Header/Footer/Template بررسی شد. Role و Editor را کنترل و Registry را ذخیره کنید.`, 'success');
        },
        'builders-add': async () => {
            appendBuilderRow({ enabled: true, role: 'unknown', editor: 'native', confidence: 0, source: 'manual' });
        },
        'builders-save': async () => {
            const entries = collectBuilders();
            const data = await request('builders/save', { method: 'POST', body: { entries } });
            renderBuilders(data);
            showNotice('Registry سازنده‌های قالب ذخیره شد.', 'success');
        },
        'elementor-repair': async () => {
            const accepted = window.confirm('Referenceهای باقی‌مانده در Elementor و سازنده‌های اختصاصی قالب، Assignmentهای Header/Footer، Site Identity و Cacheها تعمیر شوند؟');
            if (!accepted) {
                return;
            }
            const mappingField = document.getElementById('shcd-tornado-dbm-mapping-uuid');
            const mappingUuid = mappingField ? mappingField.value.trim() : '';
            const data = await request('elementor/repair', {
                method: 'POST',
                body: mappingUuid ? { mapping_uuid: mappingUuid } : {}
            });
            renderResult(outputs.elementorRepair, data, 'تعمیر سازنده‌های قالب و Elementor کامل شد');
            if (data && data.success === false) {
                showNotice('ترمیم پایان یافت، اما چند مورد هنوز نیاز به بررسی دارد. جزئیات در گزارش همین بخش آمده است.', 'warning');
            } else {
                showNotice('Builderهای ثبت‌شده، Header/Footer، Site Logo و Cacheهای Elementor و قالب ترمیم شدند. در پایان، Cache مرورگر و CDN را نیز پاک کنید.', 'success');
            }
        },
        'auto-increment-preview': async () => {
            const scope = document.getElementById('shcd-tornado-dbm-auto-increment-scope').value;
            const data = await request('auto-increment/preview', { method: 'POST', body: { scope } });
            autoIncrementPreviewToken = data.preview_token || '';
            const visible = { ...data };
            delete visible.preview_token;
            renderResult(outputs.autoIncrement, visible, 'پیش‌نمایش تنظیم AUTO_INCREMENT');
        },
        'auto-increment-execute': async () => {
            if (!autoIncrementPreviewToken) {
                throw new Error('ابتدا پیش‌نمایش تنظیم AUTO_INCREMENT را اجرا کنید.');
            }
            if (!window.confirm('شمارنده جدول‌های نمایش‌داده‌شده روی MAX(ID)+1 تنظیم شود؟ این کار IDهای موجود را تغییر نمی‌دهد.')) {
                return;
            }
            const scope = document.getElementById('shcd-tornado-dbm-auto-increment-scope').value;
            const data = await request('auto-increment/execute', {
                method: 'POST',
                body: { scope, preview_token: autoIncrementPreviewToken }
            });
            autoIncrementPreviewToken = '';
            renderResult(outputs.autoIncrement, data, 'AUTO_INCREMENT با موفقیت تنظیم شد');
            showNotice('شمارنده‌های انتخاب‌شده تنظیم شدند.', 'success');
            await refreshDashboard();
        },
        'unused-tables': async () => renderResult(outputs.tables, await request('tables/unused'), 'بررسی جدول‌های بدون استفاده'),
        'table-arm': async () => { const data = await arm('table_drop'); renderResult(outputs.tables, authorizationSummary(data), 'مجوز حذف نهایی صادر شد'); },
        'table-preflight': async () => {
            const data = await request('tables/preflight', {
                method: 'POST',
                body: {
                    table_name: document.getElementById('shcd-tornado-dbm-unused-table').value.trim(),
                    backup_uuid: document.getElementById('shcd-tornado-dbm-unused-backup').value.trim()
                }
            });
            document.getElementById('shcd-tornado-dbm-unused-confirmation').placeholder = data.quarantine_confirmation_phrase || data.purge_confirmation_phrase || '';
            renderResult(outputs.tables, data, 'بررسی ایمنی جدول');
        },
        'table-quarantine': async () => {
            if (!window.confirm('جدول انتخاب‌شده به Quarantine منتقل شود؟')) {
                return;
            }
            const data = await request('tables/quarantine', {
                method: 'POST',
                body: {
                    table_name: document.getElementById('shcd-tornado-dbm-unused-table').value.trim(),
                    backup_uuid: document.getElementById('shcd-tornado-dbm-unused-backup').value.trim(),
                    confirmation: document.getElementById('shcd-tornado-dbm-unused-confirmation').value.trim()
                }
            });
            if (data.uuid) {
                document.getElementById('shcd-tornado-dbm-quarantine-job').value = data.uuid;
            }
            if (data.restore_confirmation_phrase) {
                document.getElementById('shcd-tornado-dbm-restore-confirmation').placeholder = data.restore_confirmation_phrase;
            }
            renderResult(outputs.tables, data, 'جدول با موفقیت به Quarantine منتقل شد');
        },
        'table-restore': async () => renderResult(outputs.tables, await request('tables/restore', {
            method: 'POST',
            body: {
                job_uuid: document.getElementById('shcd-tornado-dbm-quarantine-job').value.trim(),
                confirmation: document.getElementById('shcd-tornado-dbm-restore-confirmation').value.trim()
            }
        }), 'جدول با موفقیت بازگردانده شد'),
        'table-purge': async () => {
            if (!window.confirm('حذف نهایی جدول Quarantine برگشت‌پذیر نیست. ادامه می‌دهید؟')) {
                return;
            }

            let token = authorization.table_drop || document.getElementById('shcd-tornado-dbm-table-authorization')?.value.trim() || '';
            if (!token) {
                const issued = await arm('table_drop');
                token = issued.authorization_token;
            }

            const purge = (authorizationToken) => request('tables/purge', {
                method: 'POST',
                body: {
                    table_name: document.getElementById('shcd-tornado-dbm-unused-table').value.trim(),
                    backup_uuid: document.getElementById('shcd-tornado-dbm-unused-backup').value.trim(),
                    confirmation: document.getElementById('shcd-tornado-dbm-unused-confirmation').value.trim(),
                    authorization_token: authorizationToken
                }
            });

            let data;
            try {
                data = await purge(token);
            } catch (error) {
                if (!isAuthorizationError(error)) {
                    throw error;
                }
                const reissued = await arm('table_drop');
                data = await purge(reissued.authorization_token);
            }

            clearAuthorization('table_drop');
            renderResult(outputs.tables, data, 'جدول برای همیشه حذف شد');
        },
        'reindex-arm': async () => { const data = await arm('reindex'); renderResult(outputs.identifiers, authorizationSummary(data), 'مجوز اجرای Reindex صادر شد'); },
        'reindex-preflight': async () => {
            const idSettingsForm = document.getElementById('shcd-tornado-dbm-id-settings-form');
            if (idSettingsForm) {
                await saveSettingsForm(idSettingsForm, 'تنظیمات Reindex و AUTO_INCREMENT ذخیره شد.');
            }
            const mappingUuid = document.getElementById('shcd-tornado-dbm-mapping-uuid').value.trim();
            const backupUuid = document.getElementById('shcd-tornado-dbm-backup-uuid').value.trim();
            document.getElementById('shcd-tornado-dbm-reindex-mapping').value = mappingUuid;
            const data = await request('reindex/preflight', {
                method: 'POST',
                body: { mapping_uuid: mappingUuid, backup_uuid: backupUuid }
            });
            if (data.confirmation_phrase) {
                document.getElementById('shcd-tornado-dbm-confirmation').placeholder = data.confirmation_phrase;
            }
            renderResult(outputs.identifiers, data, data.allowed ? 'Preflight با موفقیت پایان یافت' : 'Preflight اجازه اجرا نداد');
        },
        'reindex-execute': async () => {
            if (!window.confirm(`این عملیات IDهای موجود ${postsTable} و تمام Referenceهای تأییدشده را تغییر می‌دهد. Backup معتبر ساخته شده است؛ آیا اجرای نهایی انجام شود؟`)) {
                return;
            }

            let token = authorization.reindex || document.getElementById('shcd-tornado-dbm-reindex-authorization')?.value.trim() || '';
            if (!token) {
                const issued = await arm('reindex');
                token = issued.authorization_token;
            }

            const execute = (authorizationToken) => request('reindex/execute', {
                method: 'POST',
                body: {
                    mapping_uuid: document.getElementById('shcd-tornado-dbm-mapping-uuid').value.trim(),
                    backup_uuid: document.getElementById('shcd-tornado-dbm-backup-uuid').value.trim(),
                    confirmation: document.getElementById('shcd-tornado-dbm-confirmation').value.trim(),
                    authorization_token: authorizationToken
                }
            });

            let data;
            try {
                data = await execute(token);
            } catch (error) {
                if (!isAuthorizationError(error)) {
                    throw error;
                }
                const reissued = await arm('reindex');
                data = await execute(reissued.authorization_token);
            }

            clearAuthorization('reindex');
            renderResult(outputs.identifiers, data, 'Reindex با موفقیت پایان یافت');
            showNotice('شناسه‌ها بازچینی شدند و Referenceهای ثبت‌شده نیز با Mapping جدید هماهنگ شدند.', 'success');
            await refreshDashboard();
        },
        'backups-refresh': async () => {
            await loadBackups();
            showNotice('فهرست Backupها تازه‌سازی شد.', 'success');
        },
        'backup-manager-create': async () => {
            const data = await request('backup', { method: 'POST' });
            setKnownUuids(data);
            renderResult(outputs.backupManager, data, 'Backup جدید با موفقیت تهیه شد');
            await loadBackups();
            await refreshDashboard();
        },
        'backups-delete-selected': async () => {
            const uuids = selectedValues('.shcd-tornado-dbm-backup-select');
            if (!uuids.length) {
                throw new Error('حداقل یک Backup را برای حذف انتخاب کنید.');
            }
            if (!window.confirm(`تعداد ${uuids.length} Backup حذف می‌شود. فایل SQL و رکورد دیتابیس هر دو حذف خواهند شد. ادامه می‌دهید؟`)) {
                return;
            }
            const data = await request('backups/delete', {
                method: 'POST',
                body: { uuids, confirmation: 'DELETE-BACKUPS' }
            });
            renderResult(outputs.backupManager, data, 'Backupهای انتخاب‌شده با موفقیت حذف شدند');
            document.getElementById('shcd-tornado-dbm-backups-select-all').checked = false;
            await loadBackups();
            await refreshDashboard();
        },
        'managed-logs-refresh': async () => {
            await loadManagedLogs();
            showNotice('فهرست Logها تازه‌سازی شد.', 'success');
        },
        'managed-logs-delete-selected': async () => {
            const ids = selectedValues('.shcd-tornado-dbm-log-select').map((value) => Number(value)).filter(Number.isInteger);
            if (!ids.length) {
                throw new Error('حداقل یک Log را برای حذف انتخاب کنید.');
            }
            if (!window.confirm(`تعداد ${ids.length} Log حذف می‌شود. ادامه می‌دهید؟`)) {
                return;
            }
            const data = await request('logs/delete', {
                method: 'POST',
                body: { ids, confirmation: 'DELETE-LOGS' }
            });
            renderResult(outputs.logManager, data, 'Logهای انتخاب‌شده با موفقیت حذف شدند');
            document.getElementById('shcd-tornado-dbm-logs-select-all').checked = false;
            await loadManagedLogs();
        },
        'managed-logs-purge': async () => {
            const days = Math.max(1, Number(document.getElementById('shcd-tornado-dbm-log-purge-days')?.value || 30));
            if (!window.confirm(`تمام Logهای قدیمی‌تر از ${days} روز حذف می‌شوند. ادامه می‌دهید؟`)) {
                return;
            }
            const data = await request('logs/purge', {
                method: 'POST',
                body: { days, confirmation: 'PURGE-LOGS' }
            });
            renderResult(outputs.logManager, data, 'پاک‌سازی دوره‌ای Logها پایان یافت');
            await loadManagedLogs();
        },
        jobs: async () => renderJobs(await request('jobs?limit=50')),
        'audit-refresh': async () => {
            const [jobs, logs] = await Promise.all([
                request('jobs?limit=50'),
                loadManagedLogs()
            ]);
            renderJobs(jobs);
            renderBuilders(builders);
            renderManagedLogs(logs);
            showNotice('فهرست عملیات، گزارش‌ها و Logها تازه‌سازی شد.');
        }
    };

    const renderJobs = (jobs) => {
        const tbody = document.querySelector('#shcd-tornado-dbm-jobs-table tbody');
        tbody.textContent = '';
        jobs.forEach((job) => {
            const row = document.createElement('tr');
            const values = [job.uuid, job.type, job.status, job.phase, `${job.progress}%`];
            values.forEach((value, index) => {
                const cell = document.createElement('td');
                if (index === 2) {
                    const status = document.createElement('span');
                    status.className = `shcd-tornado-dbm-status is-${job.status === 'completed' ? 'success' : (job.status === 'failed' ? 'danger' : 'warning')}`;
                    status.textContent = String(value || '');
                    cell.appendChild(status);
                } else {
                    cell.textContent = String(value ?? '');
                }
                row.appendChild(cell);
            });
            const errorCell = document.createElement('td');
            if (job.error_message) {
                const details = document.createElement('details');
                const summary = document.createElement('summary');
                summary.textContent = 'مشاهده جزئیات خطا';
                const message = document.createElement('div');
                message.className = 'shcd-tornado-dbm-job-error';
                message.textContent = translateServerMessage(String(job.error_message));
                details.append(summary, message);
                errorCell.appendChild(details);
            } else {
                errorCell.textContent = '—';
            }
            row.appendChild(errorCell);

            const reportCell = document.createElement('td');
            ['json', 'csv', 'pdf'].forEach((format) => {
                const link = document.createElement('a');
                link.href = `${config.exportBase}&uuid=${encodeURIComponent(job.uuid)}&format=${format}&_wpnonce=${encodeURIComponent(config.exportNonce)}`;
                link.textContent = format.toUpperCase();
                link.className = 'shcd-tornado-dbm-report-link';
                reportCell.appendChild(link);
            });
            row.appendChild(reportCell);
            tbody.appendChild(row);
        });
    };

    const fillForm = (form, settings) => {
        if (!form) {
            return;
        }
        Object.entries(settings).forEach(([key, value]) => {
            const field = form.elements.namedItem(key);
            if (!field) {
                return;
            }
            if (field.type === 'checkbox') {
                field.checked = Boolean(value);
            } else {
                field.value = String(value);
            }
        });
    };

    const collectForm = (form) => {
        const result = {};
        new FormData(form).forEach((value, key) => {
            result[key] = value;
        });
        form.querySelectorAll('input[type="checkbox"]').forEach((field) => {
            result[field.name] = field.checked;
        });
        return result;
    };

    const saveSettingsForm = async (form, successMessage) => {
        const body = { ...currentSettings, ...collectForm(form) };
        const saved = await request('settings', { method: 'POST', body });
        if (Object.prototype.hasOwnProperty.call(body, 'allow_destructive_reindex')
            && Boolean(saved.allow_destructive_reindex) !== Boolean(body.allow_destructive_reindex)) {
            throw new Error(`اجازه اجرای Reindex در دیتابیس ذخیره نشد. دسترسی کاربر، Object Cache و سلامت جدول ${optionsTable} را بررسی کنید.`);
        }
        currentSettings = saved;
        fillForm(document.getElementById('shcd-tornado-dbm-settings-form'), currentSettings);
        fillForm(document.getElementById('shcd-tornado-dbm-id-settings-form'), currentSettings);
        document.documentElement.setAttribute('data-shcd-tornado-dbm-theme', currentSettings.theme || 'auto');
        if (successMessage) {
            showNotice(successMessage, 'success');
        }
        return currentSettings;
    };

    document.getElementById('shcd-tornado-dbm-settings-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        try {
            await saveSettingsForm(event.currentTarget, 'تنظیمات عمومی با موفقیت ذخیره شد.');
        } catch (error) {
            showNotice(error.message, 'error');
        }
    });

    document.getElementById('shcd-tornado-dbm-id-settings-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        try {
            await saveSettingsForm(event.currentTarget, 'تنظیمات شناسه‌ها و AUTO_INCREMENT با موفقیت ذخیره شد.');
        } catch (error) {
            showNotice(error.message, 'error');
        }
    });

    app.addEventListener('click', async (event) => {
        const tab = event.target.closest('[data-tab]');
        if (tab) {
            openTab(tab.dataset.tab);
            return;
        }
        const openButton = event.target.closest('[data-open-tab]');
        if (openButton) {
            openTab(openButton.dataset.openTab);
            return;
        }
        if (event.target.id === 'shcd-tornado-dbm-backups-select-all') {
            document.querySelectorAll('.shcd-tornado-dbm-backup-select').forEach((input) => {
                input.checked = event.target.checked;
            });
            return;
        }
        if (event.target.id === 'shcd-tornado-dbm-logs-select-all') {
            document.querySelectorAll('.shcd-tornado-dbm-log-select').forEach((input) => {
                input.checked = event.target.checked;
            });
            return;
        }
        if (event.target.id === 'shcd-tornado-dbm-revision-archive-select-all') {
            document.querySelectorAll('.shcd-tornado-dbm-revision-archive-select').forEach((input) => {
                input.checked = event.target.checked;
            });
            return;
        }
        const builderRemove = event.target.closest('.shcd-tornado-dbm-builder-remove');
        if (builderRemove) {
            builderRemove.closest('.shcd-tornado-dbm-builder-row')?.remove();
            const empty = document.getElementById('shcd-tornado-dbm-builder-registry-empty');
            if (empty) empty.hidden = document.querySelectorAll('.shcd-tornado-dbm-builder-row').length > 0;
            return;
        }
        const button = event.target.closest('[data-action]');
        if (!button || !actions[button.dataset.action]) {
            return;
        }
        button.disabled = true;
        button.classList.add('is-loading');
        try {
            await actions[button.dataset.action](button, event);
        } catch (error) {
            showNotice(error.message || config.i18n.failed, 'error');
        } finally {
            button.disabled = false;
            button.classList.remove('is-loading');
        }
    });

    Promise.all([request('system'), request('settings'), request('jobs?limit=20'), request('builders'), request('storage/footprint')])
        .then(([system, settings, jobs, builders, storage]) => {
            currentSettings = settings;
            renderSystem(system);
            fillForm(document.getElementById('shcd-tornado-dbm-settings-form'), settings);
            fillForm(document.getElementById('shcd-tornado-dbm-id-settings-form'), settings);
            document.documentElement.setAttribute('data-shcd-tornado-dbm-theme', settings.theme || 'auto');
            renderJobs(jobs);
            renderResult(outputs.storage, storage, 'فضای فعلی داده‌های داخلی Tornado');
            loadBackups().catch((error) => showNotice(error.message, 'error'));
            loadManagedLogs().catch((error) => showNotice(error.message, 'error'));
            loadRevisionArchive().catch((error) => showNotice(error.message, 'error'));
            request('revisions/archive/status').then((status) => renderResult(outputs.revisionArchive, status, 'وضعیت آرشیو نسخه‌ها')).catch((error) => showNotice(error.message, 'error'));
        })
        .catch((error) => showNotice(error.message, 'error'));
}());
