<?php
/**
 * What happened the last time an AI app called WPMark.
 *
 * @package WPMark
 */

namespace WPMark\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Remembers the outcome of recent sign-in checks on the MCP address, so the
 * health check can say why an app that signed in still cannot connect.
 *
 * Without this, a site owner only sees "Last used: Not yet" and the AI app
 * shows a vague error. The usual causes look the same from outside:
 * - the web server or a proxy removes the Authorization header, so the
 *   app's access token never reaches WordPress;
 * - the app sends a token WPMark doesn't know or that has expired;
 * - the person's role is no longer allowed to use WPMark.
 *
 * Only the outcome, the time and the app's name are kept: no tokens, no IP
 * addresses, no request content. One small option, not autoloaded.
 */
final class Connection_Log {

	/**
	 * Option name.
	 */
	public const OPTION = 'wpmark_connection_log';

	public const OK          = 'ok';
	public const NO_TOKEN    = 'no_token';
	public const UNKNOWN     = 'unknown_token';
	public const EXPIRED     = 'expired_token';
	public const NOT_ALLOWED = 'not_allowed';

	/**
	 * Successful requests are recorded at most this often, to avoid a
	 * database write on every call.
	 */
	private const OK_INTERVAL = 10 * MINUTE_IN_SECONDS;

	/**
	 * Record an outcome.
	 *
	 * @param string $outcome One of the constants above.
	 * @param string $app     The app's name, when known.
	 */
	public static function record( string $outcome, string $app = '' ): void {
		$log = self::all();
		$now = time();

		if ( self::OK === $outcome && isset( $log[ self::OK ] ) && $now - $log[ self::OK ]['time'] < self::OK_INTERVAL ) {
			return;
		}

		$log[ $outcome ] = array(
			'time' => $now,
			'app'  => sanitize_text_field( $app ),
		);

		update_option( self::OPTION, $log, false );
	}

	/**
	 * The latest time each outcome happened.
	 *
	 * @return array<string, array{time: int, app: string}>
	 */
	public static function all(): array {
		$log = get_option( self::OPTION, array() );

		return is_array( $log ) ? $log : array();
	}

	/**
	 * The most recent outcome of any kind.
	 *
	 * @return array{outcome: string, time: int, app: string}|null
	 */
	public static function latest(): ?array {
		$latest = null;

		foreach ( self::all() as $outcome => $entry ) {
			if ( null === $latest || $entry['time'] > $latest['time'] ) {
				$latest = array( 'outcome' => (string) $outcome ) + $entry;
			}
		}

		return $latest;
	}

	/**
	 * Forget everything. Used on uninstall and when sign-in is switched off.
	 */
	public static function clear(): void {
		delete_option( self::OPTION );
	}
}
