<?php
/**
 * Internal schema installer.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Database;

final class Schema {
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$tables          = new Tables( $wpdb );
		$charset_collate = $wpdb->get_charset_collate();

		$queries = array(
			"CREATE TABLE {$tables->jobs()} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				uuid char(36) NOT NULL,
				type varchar(64) NOT NULL,
				status varchar(32) NOT NULL DEFAULT 'pending',
				phase varchar(64) NOT NULL DEFAULT 'created',
				progress decimal(6,2) unsigned NOT NULL DEFAULT 0,
				payload longtext NULL,
				result longtext NULL,
				error_message text NULL,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uuid (uuid),
				KEY type_status (type,status),
				KEY created_at (created_at)
			) {$charset_collate};",
			"CREATE TABLE {$tables->mappings()} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				job_uuid char(36) NOT NULL,
				old_id bigint(20) unsigned NOT NULL,
				temp_id bigint(20) unsigned NOT NULL,
				new_id bigint(20) unsigned NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY job_old (job_uuid,old_id),
				UNIQUE KEY job_new (job_uuid,new_id),
				UNIQUE KEY job_temp (job_uuid,temp_id),
				KEY old_id (old_id),
				KEY new_id (new_id)
			) {$charset_collate};",
			"CREATE TABLE {$tables->discoveries()} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				job_uuid char(36) NOT NULL,
				table_name varchar(191) NOT NULL,
				column_name varchar(191) NOT NULL DEFAULT '',
				data_type varchar(64) NOT NULL DEFAULT '',
				column_type varchar(191) NOT NULL DEFAULT '',
				key_type varchar(32) NOT NULL DEFAULT '',
				candidate_type varchar(64) NOT NULL DEFAULT '',
				confidence tinyint(3) unsigned NOT NULL DEFAULT 0,
				meta_json longtext NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY job_uuid (job_uuid),
				KEY table_column (table_name,column_name),
				KEY confidence (confidence)
			) {$charset_collate};",
			"CREATE TABLE {$tables->backups()} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				uuid char(36) NOT NULL,
				status varchar(32) NOT NULL DEFAULT 'creating',
				file_path text NOT NULL,
				file_size bigint(20) unsigned NOT NULL DEFAULT 0,
				sha256 char(64) NOT NULL DEFAULT '',
				table_count int(10) unsigned NOT NULL DEFAULT 0,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				verified_at datetime NULL,
				post_id_fingerprint char(64) NOT NULL DEFAULT '',
				schema_fingerprint char(64) NOT NULL DEFAULT '',
				is_consistent tinyint(1) unsigned NOT NULL DEFAULT 0,
				is_private_location tinyint(1) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY uuid (uuid),
				KEY status (status),
				KEY created_at (created_at)
			) {$charset_collate};",
			"CREATE TABLE {$tables->logs()} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				job_uuid char(36) NOT NULL DEFAULT '',
				level varchar(16) NOT NULL DEFAULT 'info',
				message text NOT NULL,
				context_json longtext NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY job_uuid (job_uuid),
				KEY level (level),
				KEY created_at (created_at)
			) {$charset_collate};",
			"CREATE TABLE {$tables->revision_archive()} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				uuid char(36) NOT NULL,
				post_id bigint(20) unsigned NOT NULL,
				parent_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				post_type varchar(64) NOT NULL DEFAULT 'post',
				source varchar(32) NOT NULL DEFAULT 'wordpress',
				generation_uuid varchar(64) NOT NULL DEFAULT 'initial-generation',
				snapshot_hash char(64) NOT NULL,
				post_json longtext NOT NULL,
				meta_json longtext NOT NULL,
				terms_json longtext NOT NULL,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				restored_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uuid (uuid),
				UNIQUE KEY post_hash_generation (post_id,snapshot_hash,generation_uuid(32)),
				KEY post_created (post_id,created_at),
				KEY generation_uuid (generation_uuid(32)),
				KEY created_at (created_at)
			) {$charset_collate};",
		);

		foreach ( $queries as $query ) {
			dbDelta( $query );
		}
	}
}
