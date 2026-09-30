<?php
/**
 * Controlled writes to WordPress-core and third-party options owned outside Tornado.
 *
 * These option names are integration contracts; they must not be renamed with the
 * Tornado prefix because Elementor/WordPress would no longer read them. Keeping
 * the allowlist here prevents arbitrary option names from reaching update/delete.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Repair;

use InvalidArgumentException;

final class ExternalOptionBridge {
	public const ELEMENTOR_CPT_SUPPORT              = 'elementor_cpt_support';
	public const ELEMENTOR_ACTIVE_KIT               = 'elementor_active_kit';
	public const ELEMENTOR_CSS_PRINT_METHOD         = 'elementor_css_print_method';
	public const ELEMENTOR_PRO_THEME_BUILDER_CACHE  = 'elementor_pro_theme_builder_conditions';
	public const WORDPRESS_SITE_ICON                = 'site_icon';

	private const READABLE_OPTIONS = array(
		self::ELEMENTOR_CSS_PRINT_METHOD,
	);

	private const WRITABLE_OPTIONS = array(
		self::ELEMENTOR_CPT_SUPPORT,
		self::ELEMENTOR_ACTIVE_KIT,
		self::WORDPRESS_SITE_ICON,
	);

	private const DELETABLE_OPTIONS = array(
		self::ELEMENTOR_PRO_THEME_BUILDER_CACHE,
	);

	public static function get( string $option_name, mixed $default = false ): mixed {
		if ( ! in_array( $option_name, self::READABLE_OPTIONS, true ) ) {
			throw new InvalidArgumentException( 'Unsupported external option read.' );
		}

		return get_option( $option_name, $default );
	}

	public static function update( string $option_name, mixed $value ): bool {
		if ( ! in_array( $option_name, self::WRITABLE_OPTIONS, true ) ) {
			throw new InvalidArgumentException( 'Unsupported external option write.' );
		}

		return update_option( $option_name, $value, false );
	}

	public static function delete( string $option_name ): bool {
		if ( ! in_array( $option_name, self::DELETABLE_OPTIONS, true ) ) {
			throw new InvalidArgumentException( 'Unsupported external option deletion.' );
		}

		return delete_option( $option_name );
	}
	public static function delete_elementor_transient( string $transient_name ): bool {
		if ( ! str_starts_with( $transient_name, 'elementor_remote_info_api_data_' ) ) {
			throw new InvalidArgumentException( 'Unsupported external transient deletion.' );
		}

		return delete_transient( $transient_name );
	}

}
