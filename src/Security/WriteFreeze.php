<?php
/**
 * Best-effort WordPress write freeze during a controlled reindex transaction.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Security;

use WP_Error;
use WP_REST_Request;

final class WriteFreeze {
	public function register(): void {
		add_filter( 'wp_insert_post_data', array( $this, 'block_post_write' ), PHP_INT_MIN, 4 );
		add_filter( 'pre_delete_post', array( $this, 'block_post_delete' ), PHP_INT_MIN, 3 );
		add_filter( 'pre_trash_post', array( $this, 'block_post_delete' ), PHP_INT_MIN, 3 );
		add_filter( 'preprocess_comment', array( $this, 'block_comment_write' ), PHP_INT_MIN );
		add_filter( 'rest_pre_dispatch', array( $this, 'block_rest_write' ), PHP_INT_MIN, 3 );
		add_filter( 'xmlrpc_enabled', array( $this, 'filter_xmlrpc' ), PHP_INT_MIN );
		add_filter( 'pre_insert_term', array( $this, 'block_term_write' ), PHP_INT_MIN, 2 );

		foreach ( array( 'post', 'term', 'user', 'comment' ) as $meta_type ) {
			add_filter( "add_{$meta_type}_metadata", array( $this, 'block_metadata_write' ), PHP_INT_MIN, 5 );
			add_filter( "update_{$meta_type}_metadata", array( $this, 'block_metadata_write' ), PHP_INT_MIN, 5 );
			add_filter( "delete_{$meta_type}_metadata", array( $this, 'block_metadata_write' ), PHP_INT_MIN, 5 );
		}
	}

	/**
	 * @param array<string, mixed> $data Post data.
	 * @return array<string, mixed>
	 */
	public function block_post_write( array $data ): array {
		if ( $this->active() ) {
			$this->terminate();
		}
		return $data;
	}

	public function block_post_delete( mixed $delete ): mixed {
		return $this->active() ? false : $delete;
	}

	/**
	 * @param array<string, mixed> $comment Comment data.
	 * @return array<string, mixed>
	 */
	public function block_comment_write( array $comment ): array {
		if ( $this->active() ) {
			$this->terminate();
		}
		return $comment;
	}

	public function block_rest_write( mixed $result, mixed $server, WP_REST_Request $request ): mixed {
		if ( ! $this->active() || in_array( $request->get_method(), array( 'GET', 'HEAD', 'OPTIONS' ), true ) ) {
			return $result;
		}

		return new WP_Error(
			'shcd_tornado_dbm_database_write_frozen',
			__( 'در زمان اجرای Reindex، ثبت یا تغییر داده‌ها موقتاً متوقف شده است. پس از پایان عملیات دوباره تلاش کنید.', 'shcd-database-maintenance' ),
			array( 'status' => 503 )
		);
	}

	public function filter_xmlrpc( bool $enabled ): bool {
		return $this->active() ? false : $enabled;
	}

	public function block_term_write( string $term ): string|WP_Error {
		if ( ! $this->active() ) {
			return $term;
		}

		return new WP_Error(
			'shcd_tornado_dbm_database_write_frozen',
			__( 'در زمان اجرای Reindex، ثبت یا تغییر داده‌ها موقتاً متوقف شده است. پس از پایان عملیات دوباره تلاش کنید.', 'shcd-database-maintenance' )
		);
	}

	public function block_metadata_write( mixed $check ): mixed {
		return $this->active() ? false : $check;
	}

	private function active(): bool {
		return false !== get_transient( 'shcd_tornado_dbm_active_reindex_lock' );
	}

	private function terminate(): never {
		wp_die(
			esc_html__( 'در زمان اجرای Reindex، ثبت یا تغییر داده‌ها موقتاً متوقف شده است. پس از پایان عملیات دوباره تلاش کنید.', 'shcd-database-maintenance' ),
			esc_html__( 'سایت برای انجام عملیات نگهداری موقتاً در دسترس نیست', 'shcd-database-maintenance' ),
			array( 'response' => 503 )
		);
	}
}
