<?php
/**
 * OAuth storage.
 *
 * @package WPMark
 */

namespace WPMark\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Where OAuth clients, sign-in codes and tokens are kept.
 *
 * Two tables:
 *
 * - {prefix}wpmark_oauth_clients: AI apps that registered themselves
 *   (Claude, ChatGPT…), with the addresses they may send people back to.
 * - {prefix}wpmark_oauth_tokens: one-time sign-in codes, access tokens and
 *   refresh tokens, each tied to one WordPress user and one app.
 *
 * Secrets are never stored: only their SHA-256 hash, like WordPress does
 * with passwords. A leaked database therefore does not leak working tokens.
 * Every code and token carries a "family": all tokens that came from one
 * sign-in. If a used code or an old refresh token is ever presented again,
 * the whole family is revoked, which is how OAuth detects stolen tokens.
 */
final class Store {

	/**
	 * Bump when the table layout changes; install() then upgrades it.
	 */
	private const SCHEMA_VERSION = '1';

	/**
	 * Most apps that may be registered at once, so an open registration
	 * endpoint cannot fill the database.
	 */
	public const MAX_CLIENTS = 500;

	/**
	 * Clients table name.
	 *
	 * @return string
	 */
	public static function clients_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'wpmark_oauth_clients';
	}

	/**
	 * Tokens table name.
	 *
	 * @return string
	 */
	public static function tokens_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'wpmark_oauth_tokens';
	}

	/**
	 * Create or upgrade the tables when needed. Cheap to call on every load.
	 */
	public static function maybe_install(): void {
		if ( get_option( 'wpmark_oauth_schema' ) !== self::SCHEMA_VERSION ) {
			self::install();
		}
	}

	/**
	 * Create or upgrade the tables.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$clients = self::clients_table();
		$tokens  = self::tokens_table();

		// dbDelta needs two spaces after PRIMARY KEY and one field per line.
		dbDelta(
			"CREATE TABLE {$clients} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				client_id varchar(64) NOT NULL,
				client_name varchar(200) NOT NULL DEFAULT '',
				redirect_uris text NOT NULL,
				auth_method varchar(40) NOT NULL DEFAULT 'none',
				secret_hash char(64) DEFAULT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY client_id (client_id)
			) {$charset};
			CREATE TABLE {$tokens} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				token_hash char(64) NOT NULL,
				type varchar(10) NOT NULL,
				client_id varchar(64) NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				family char(32) NOT NULL,
				redirect_uri text,
				code_challenge varchar(128) DEFAULT NULL,
				resource varchar(500) DEFAULT NULL,
				scope varchar(100) NOT NULL DEFAULT '',
				used tinyint(1) NOT NULL DEFAULT 0,
				revoked tinyint(1) NOT NULL DEFAULT 0,
				expires_at datetime NOT NULL,
				created_at datetime NOT NULL,
				last_used_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY token_hash (token_hash),
				KEY client_id (client_id),
				KEY user_id (user_id),
				KEY family (family),
				KEY expires_at (expires_at)
			) {$charset};"
		);

		update_option( 'wpmark_oauth_schema', self::SCHEMA_VERSION, false );
	}

	/**
	 * Remove the tables. Used when WPMark is deleted.
	 */
	public static function uninstall(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Our own tables; fixed names.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::tokens_table() );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::clients_table() );
		// phpcs:enable

		delete_option( 'wpmark_oauth_schema' );
	}

	/**
	 * A new random secret, URL-safe.
	 *
	 * @param string $prefix Readable prefix, so a leaked token is recognisable.
	 * @return string
	 */
	public static function new_secret( string $prefix = '' ): string {
		return $prefix . rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe encoding of random bytes.
	}

	/**
	 * The stored form of a secret.
	 *
	 * @param string $secret Secret.
	 * @return string SHA-256, hex.
	 */
	public static function hash( string $secret ): string {
		return hash( 'sha256', $secret );
	}

	/**
	 * A time as stored in the tables (UTC).
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	public static function time( int $timestamp ): string {
		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Save a newly registered app.
	 *
	 * @param array $client client_id, client_name, redirect_uris (array), auth_method, secret_hash.
	 * @return bool
	 */
	public static function add_client( array $client ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Our own table.
		return (bool) $wpdb->insert(
			self::clients_table(),
			array(
				'client_id'     => $client['client_id'],
				'client_name'   => $client['client_name'],
				'redirect_uris' => wp_json_encode( array_values( $client['redirect_uris'] ) ),
				'auth_method'   => $client['auth_method'],
				'secret_hash'   => $client['secret_hash'],
				'created_at'    => self::time( time() ),
			)
		);
	}

	/**
	 * Find a registered app.
	 *
	 * @param string $client_id Client ID.
	 * @return array|null With redirect_uris decoded.
	 */
	public static function get_client( string $client_id ): ?array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Our own table; fixed name.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::clients_table() . ' WHERE client_id = %s', $client_id ),
			ARRAY_A
		);
		// phpcs:enable

		if ( ! $row ) {
			return null;
		}

		$row['redirect_uris'] = (array) json_decode( (string) $row['redirect_uris'], true );

		return $row;
	}

	/**
	 * How many apps are registered.
	 *
	 * @return int
	 */
	public static function count_clients(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Our own table; fixed name.
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::clients_table() );
	}

	/**
	 * Store a code or token. Returns the secret to hand out; only its hash is kept.
	 *
	 * @param string $type     "code", "access" or "refresh".
	 * @param array  $fields   client_id, user_id, family, scope, and for codes
	 *                         redirect_uri, code_challenge, resource.
	 * @param int    $lifetime Seconds until it expires.
	 * @return string The secret.
	 */
	public static function issue( string $type, array $fields, int $lifetime ): string {
		global $wpdb;

		$prefixes = array(
			'access'  => 'wpmark_at_',
			'refresh' => 'wpmark_rt_',
			'code'    => '',
		);
		$secret   = self::new_secret( $prefixes[ $type ] ?? '' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Our own table.
		$wpdb->insert(
			self::tokens_table(),
			array(
				'token_hash'     => self::hash( $secret ),
				'type'           => $type,
				'client_id'      => $fields['client_id'],
				'user_id'        => (int) $fields['user_id'],
				'family'         => $fields['family'],
				'redirect_uri'   => $fields['redirect_uri'] ?? null,
				'code_challenge' => $fields['code_challenge'] ?? null,
				'resource'       => $fields['resource'] ?? null,
				'scope'          => $fields['scope'] ?? '',
				'expires_at'     => self::time( time() + $lifetime ),
				'created_at'     => self::time( time() ),
			)
		);

		return $secret;
	}

	/**
	 * Look up a code or token by its secret.
	 *
	 * @param string $secret Secret as presented.
	 * @param string $type   Expected type.
	 * @return array|null The row, whether or not it is still valid.
	 */
	public static function find( string $secret, string $type ): ?array {
		global $wpdb;

		if ( '' === $secret ) {
			return null;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Our own table; fixed name.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::tokens_table() . ' WHERE token_hash = %s AND type = %s', self::hash( $secret ), $type ),
			ARRAY_A
		);
		// phpcs:enable

		return $row ? $row : null;
	}

	/**
	 * Whether a row is usable right now: not revoked, not used, not expired.
	 *
	 * @param array $row Token row.
	 * @return bool
	 */
	public static function is_live( array $row ): bool {
		return ! (int) $row['revoked'] && ! (int) $row['used'] && strtotime( $row['expires_at'] . ' UTC' ) > time();
	}

	/**
	 * Mark a code or refresh token as used, exactly once.
	 *
	 * Done with a conditional UPDATE, so two simultaneous requests with the
	 * same code cannot both succeed.
	 *
	 * @param int $id Row ID.
	 * @return bool True for the one caller that marked it.
	 */
	public static function mark_used( int $id ): bool {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Our own table; fixed name.
		$changed = $wpdb->query(
			$wpdb->prepare( 'UPDATE ' . self::tokens_table() . ' SET used = 1, last_used_at = %s WHERE id = %d AND used = 0 AND revoked = 0', self::time( time() ), $id )
		);
		// phpcs:enable

		return 1 === $changed;
	}

	/**
	 * Note that an access token was just used.
	 *
	 * @param int $id Row ID.
	 */
	public static function touch( int $id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Our own table.
		$wpdb->update( self::tokens_table(), array( 'last_used_at' => self::time( time() ) ), array( 'id' => $id ) );
	}

	/**
	 * Revoke every code and token from one sign-in.
	 *
	 * @param string $family Family ID.
	 */
	public static function revoke_family( string $family ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Our own table.
		$wpdb->update( self::tokens_table(), array( 'revoked' => 1 ), array( 'family' => $family ) );
	}

	/**
	 * Revoke every token one person has given one app, or all apps.
	 *
	 * @param int         $user_id   User ID.
	 * @param string|null $client_id App, or null for all.
	 */
	public static function revoke_for_user( int $user_id, ?string $client_id = null ): void {
		global $wpdb;

		$where = array( 'user_id' => $user_id );

		if ( null !== $client_id ) {
			$where['client_id'] = $client_id;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Our own table.
		$wpdb->update( self::tokens_table(), array( 'revoked' => 1 ), $where );
	}

	/**
	 * Revoke every token for everyone: used when OAuth is switched off.
	 */
	public static function revoke_all(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Our own table; fixed name.
		$wpdb->query( 'UPDATE ' . self::tokens_table() . ' SET revoked = 1' );
	}

	/**
	 * Apps with live access, per person: one row per person and app.
	 *
	 * @param int|null $user_id One person, or null for everyone.
	 * @return array<int, array{user_id: int, client_id: string, client_name: string, connected_at: string, last_used_at: ?string}>
	 */
	public static function connections( ?int $user_id = null ): array {
		global $wpdb;

		$tokens  = self::tokens_table();
		$clients = self::clients_table();
		$user    = null === $user_id ? '' : $wpdb->prepare( ' AND t.user_id = %d', $user_id );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Our own tables; fixed names; the user filter is prepared above.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.user_id, t.client_id, COALESCE(c.client_name, '') AS client_name,
					( SELECT MIN(f.created_at) FROM {$tokens} f WHERE f.user_id = t.user_id AND f.client_id = t.client_id AND f.type = 'code' ) AS connected_at,
					( SELECT MAX(a.last_used_at) FROM {$tokens} a WHERE a.user_id = t.user_id AND a.client_id = t.client_id AND a.type = 'access' ) AS last_used_at
				FROM {$tokens} t LEFT JOIN {$clients} c ON c.client_id = t.client_id
				WHERE t.type = 'refresh' AND t.revoked = 0 AND t.used = 0 AND t.expires_at > %s{$user}
				GROUP BY t.user_id, t.client_id, c.client_name
				ORDER BY connected_at DESC",
				self::time( time() )
			),
			ARRAY_A
		);
		// phpcs:enable

		return array_map(
			static fn( array $row ): array => array(
				'user_id'      => (int) $row['user_id'],
				'client_id'    => (string) $row['client_id'],
				'client_name'  => (string) $row['client_name'],
				'connected_at' => (string) $row['connected_at'],
				'last_used_at' => $row['last_used_at'] ? (string) $row['last_used_at'] : null,
			),
			(array) $rows
		);
	}

	/**
	 * Delete what can never be used again: expired codes and tokens, and
	 * registered apps nobody signed in with for a week. Runs daily.
	 */
	public static function cleanup(): void {
		global $wpdb;

		$tokens  = self::tokens_table();
		$clients = self::clients_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Our own tables; fixed names.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$tokens} WHERE expires_at < %s", self::time( time() - DAY_IN_SECONDS ) ) );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE c FROM {$clients} c LEFT JOIN {$tokens} t ON t.client_id = c.client_id WHERE t.id IS NULL AND c.created_at < %s",
				self::time( time() - WEEK_IN_SECONDS )
			)
		);
		// phpcs:enable
	}
}
