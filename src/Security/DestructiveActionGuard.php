<?php
/**
 * Short-lived, one-time authorization gate for destructive operations.
 *
 * The REST route verifies the current user's capability and WordPress REST
 * nonce before calling this service. Tokens are additionally bound to the
 * current WordPress login session and are consumed exactly once.
 *
 * @package Shcd\TornadoDatabaseMaintenance
 */

declare(strict_types=1);

namespace Shcd\TornadoDatabaseMaintenance\Security;

use InvalidArgumentException;
use RuntimeException;

final class DestructiveActionGuard {
	private const SCOPES = array( 'reindex', 'table_drop', 'backup_restore' );
	private const TTL    = 600;

	/**
	 * Issues a new browser-session-bound authorization token.
	 *
	 * Reissuing a token intentionally invalidates the previously issued token
	 * for the same user, session and operation scope.
	 *
	 * @return array{token:string,authorization_token:string,scope:string,issued_at:int,expires_at:int}
	 */
	public function issue( string $scope ): array {
		$scope = sanitize_key( $scope );
		if ( ! in_array( $scope, self::SCOPES, true ) ) {
			throw new InvalidArgumentException( 'Unsupported destructive-operation scope.' );
		}

		$user_id = get_current_user_id();
		$session = (string) wp_get_session_token();
		if ( $user_id <= 0 || '' === $session ) {
			throw new RuntimeException( 'An authenticated administrator session is required.' );
		}

		$token      = bin2hex( random_bytes( 32 ) );
		$issued_at  = time();
		$expires_at = $issued_at + self::TTL;
		$key        = $this->key( $user_id, $scope );
		$value      = array(
			'hash'       => hash_hmac( 'sha256', $token, wp_salt( 'auth' ) ),
			'issued_at'  => $issued_at,
			'expires_at' => $expires_at,
			'session'    => hash( 'sha256', $session ),
		);

		
		set_transient( $key, $value, self::TTL );

		return array(
			'token'               => $token,
			'authorization_token' => $token,
			'scope'               => $scope,
			'issued_at'           => $issued_at,
			'expires_at'          => $expires_at,
		);
	}

	/**
	 * Issues a one-time token for a local WP-CLI process after its own
	 * interactive confirmation.
	 */
	public function issue_cli( string $scope ): string {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			throw new RuntimeException( 'CLI arming is only available under WP-CLI.' );
		}
		$scope = sanitize_key( $scope );
		if ( ! in_array( $scope, self::SCOPES, true ) ) {
			throw new InvalidArgumentException( 'Unsupported destructive-operation scope.' );
		}
		$token = bin2hex( random_bytes( 32 ) );
		set_transient(
			$this->key( get_current_user_id(), $scope ),
			array(
				'hash'       => hash_hmac( 'sha256', $token, wp_salt( 'auth' ) ),
				'issued_at'  => time(),
				'expires_at' => time() + 120,
				'session'    => 'cli',
			),
			120
		);
		return $token;
	}

	public function consume( string $scope, string $token ): void {
		$scope = sanitize_key( $scope );
		if ( ! in_array( $scope, self::SCOPES, true ) || '' === trim( $token ) ) {
			throw new RuntimeException( 'A valid destructive-operation authorization token is required.' );
		}

		$user_id = get_current_user_id();
		$key     = $this->key( $user_id, $scope );
		$stored  = get_transient( $key );
		delete_transient( $key ); 

		if ( ! is_array( $stored ) || (int) ( $stored['expires_at'] ?? 0 ) < time() ) {
			throw new RuntimeException( 'The destructive-operation authorization has expired.' );
		}

		$session = defined( 'WP_CLI' ) && WP_CLI ? 'cli' : hash( 'sha256', (string) wp_get_session_token() );
		if ( ! hash_equals( (string) ( $stored['session'] ?? '' ), $session ) ) {
			throw new RuntimeException( 'The authorization token belongs to a different session.' );
		}

		$actual = hash_hmac( 'sha256', trim( $token ), wp_salt( 'auth' ) );
		if ( ! hash_equals( (string) ( $stored['hash'] ?? '' ), $actual ) ) {
			throw new RuntimeException( 'The destructive-operation authorization token is invalid.' );
		}
	}

	private function key( int $user_id, string $scope ): string {
		return 'shcd_tornado_dbm_arm_' . max( 0, $user_id ) . '_' . $scope;
	}
}
