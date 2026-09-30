<?php
/**
 * Direct access protection.
 */

if ( ! defined( 'ABSPATH' ) ) {
	http_response_code( 403 );
	exit;
}
