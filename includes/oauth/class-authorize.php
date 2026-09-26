<?php
/**
 * OAuth authorization endpoint and consent screen.
 *
 * @package WPMark
 */

namespace WPMark\OAuth;

use WP_REST_Request;
use WP_User;
use WPMark\Privacy\Lead_Access;
use WPMark\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The page people see when an AI app asks to connect: log in, then Allow.
 *
 * Safety rules, in order:
 * 1. The app and its return address are checked against its registration
 *    before anything else. If they do not match, nobody is sent anywhere:
 *    the page shows an error instead (an attacker cannot use this page to
 *    bounce people to a site of their choosing).
 * 2. PKCE with S256 is required, so a stolen code is useless on its own.
 * 3. The person must be logged in to WordPress and their role switched on
 *    for WPMark.
 * 4. The consent screen names the app, shows where the person will be sent
 *    back to, and says exactly what the app can and cannot see.
 * 5. Allow is a POST with a WordPress nonce, and every value is checked
 *    again on POST; nothing from the form is trusted. The page cannot be
 *    shown inside a frame on another site.
 */
final class Authorize {

	/**
	 * Register the route.
	 */
	public static function register_routes(): void {
		register_rest_route(
			OAuth::NS,
			'/authorize',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( self::class, 'handle' ),
				// Anyone may reach the sign-in page; it asks them to log in and checks their role.
				'permission_callback' => array( Discovery::class, 'public_document' ),
			)
		);
	}

	/**
	 * Show the consent screen (GET) or act on the person's choice (POST).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function handle( WP_REST_Request $request ): void {
		$result = self::process( $request, self::logged_in_user() );

		if ( 'page' === $result['type'] ) {
			self::page( $result['title'], $result['body'] );
		}

		if ( 'login' === $result['type'] ) {
			wp_safe_redirect( $result['url'] );
			exit;
		}

		// The return address is an external site the app registered, so wp_safe_redirect() would refuse it.
		wp_redirect( $result['url'] ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Checked against the app's registered return addresses.
		exit;
	}

	/**
	 * Decide what to do with a sign-in request, without sending anything.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param WP_User|null    $user    The logged-in person, if any.
	 * @return array{type: string, url?: string, title?: string, body?: string}
	 *               "page" (show title and body), "login" (go to the login
	 *               page) or "redirect" (back to the app).
	 */
	public static function process( WP_REST_Request $request, ?WP_User $user ): array {
		$params = self::check_request( $request );

		// The app or its return address is wrong: show an error, never redirect.
		if ( isset( $params['fatal'] ) ) {
			return self::page_result( __( 'This sign-in link is not valid', 'wpmark' ), '<p>' . esc_html( $params['fatal'] ) . '</p>' );
		}

		// Anything else wrong is reported back to the app, as OAuth expects.
		if ( isset( $params['error'] ) ) {
			return self::redirect_result(
				$params,
				array(
					'error'             => $params['error'],
					'error_description' => $params['error_description'],
				)
			);
		}

		if ( ! $user ) {
			return array(
				'type' => 'login',
				'url'  => wp_login_url( self::current_url( $request ) ),
			);
		}

		wp_set_current_user( $user->ID );

		if ( ! Settings::user_can_connect( $user ) ) {
			return self::page_result(
				__( 'Your account cannot connect AI apps', 'wpmark' ),
				'<p>' . esc_html__( 'Your WordPress role is not allowed to use WPMark. Ask a site administrator to switch it on under WPMark → Access & privacy.', 'wpmark' ) . '</p>'
				. '<p><a class="button" href="' . esc_url( self::redirect_url( $params, array( 'error' => 'access_denied' ) ) ) . '">' . esc_html__( 'Go back to the app', 'wpmark' ) . '</a></p>'
			);
		}

		if ( 'POST' === $request->get_method() ) {
			return self::decide( $request, $params, $user );
		}

		return self::consent( $params, $user );
	}

	/**
	 * Validate the request. Returns the checked values, or an error.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array Checked parameters; "fatal" (show error) or "error" (redirect) on failure.
	 */
	public static function check_request( WP_REST_Request $request ): array {
		$client = Store::get_client( (string) $request->get_param( 'client_id' ) );

		if ( ! $client ) {
			return array( 'fatal' => __( 'The app that sent you here is not registered with this site. Try connecting again from the app.', 'wpmark' ) );
		}

		$redirect = (string) $request->get_param( 'redirect_uri' );

		if ( '' === $redirect && 1 === count( $client['redirect_uris'] ) ) {
			$redirect = (string) $client['redirect_uris'][0];
		}

		// Exact match against the registration: no prefixes, no wildcards.
		if ( ! in_array( $redirect, $client['redirect_uris'], true ) ) {
			return array( 'fatal' => __( 'The app asked to send you back to an address it did not register. For your safety, the sign-in was stopped.', 'wpmark' ) );
		}

		$params = array(
			'client_id'      => (string) $client['client_id'],
			'client_name'    => (string) $client['client_name'],
			'redirect_uri'   => $redirect,
			'state'          => (string) $request->get_param( 'state' ),
			'code_challenge' => (string) $request->get_param( 'code_challenge' ),
			'resource'       => (string) $request->get_param( 'resource' ),
		);

		if ( 'code' !== $request->get_param( 'response_type' ) ) {
			return array_merge( $params, self::oauth_error( 'unsupported_response_type', 'Only response_type=code is supported.' ) );
		}

		if ( 'S256' !== $request->get_param( 'code_challenge_method' ) || ! preg_match( '/^[A-Za-z0-9\-._~]{43,128}$/', $params['code_challenge'] ) ) {
			return array_merge( $params, self::oauth_error( 'invalid_request', 'PKCE with code_challenge_method=S256 is required.' ) );
		}

		if ( '' !== $params['resource'] && untrailingslashit( $params['resource'] ) !== untrailingslashit( OAuth::resource() ) ) {
			return array_merge( $params, self::oauth_error( 'invalid_target', 'The resource must be this site\'s WPMark MCP address.' ) );
		}

		return $params;
	}

	/**
	 * Act on Allow or Cancel.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param array           $params  Checked parameters.
	 * @param WP_User         $user    The person.
	 * @return array Result, as from process().
	 */
	private static function decide( WP_REST_Request $request, array $params, WP_User $user ): array {
		if ( ! wp_verify_nonce( (string) $request->get_param( '_wpmark_nonce' ), self::nonce_action( $params ) ) ) {
			return self::page_result( __( 'This page expired', 'wpmark' ), '<p>' . esc_html__( 'Please go back to the app and start connecting again.', 'wpmark' ) . '</p>' );
		}

		if ( 'allow' !== $request->get_param( 'decision' ) ) {
			return self::redirect_result( $params, array( 'error' => 'access_denied' ) );
		}

		$code = Store::issue(
			'code',
			array(
				'client_id'      => $params['client_id'],
				'user_id'        => $user->ID,
				'family'         => wp_generate_password( 32, false ),
				'redirect_uri'   => $params['redirect_uri'],
				'code_challenge' => $params['code_challenge'],
				'resource'       => '' !== $params['resource'] ? $params['resource'] : null,
				'scope'          => OAuth::SCOPE,
			),
			OAuth::CODE_LIFETIME
		);

		return self::redirect_result( $params, array( 'code' => $code ) );
	}

	/**
	 * The consent screen.
	 *
	 * @param array   $params Checked parameters.
	 * @param WP_User $user   The person.
	 * @return array Result, as from process().
	 */
	private static function consent( array $params, WP_User $user ): array {
		$host  = (string) wp_parse_url( $params['redirect_uri'], PHP_URL_HOST );
		$leads = Settings::lead_access_for( $user );
		$site  = wp_strip_all_tags( get_bloginfo( 'name' ) );

		$leads_line = match ( $leads ) {
			Lead_Access::None   => __( 'It cannot see form leads.', 'wpmark' ),
			Lead_Access::Hidden => __( 'See form leads, without names, emails or phone numbers.', 'wpmark' ),
			Lead_Access::Masked => __( 'See form leads, with contact details partly hidden.', 'wpmark' ),
			Lead_Access::Full   => __( 'See form leads, including full contact details.', 'wpmark' ),
		};

		$hidden = '';
		foreach ( array( 'client_id', 'redirect_uri', 'state', 'code_challenge', 'resource' ) as $key ) {
			$hidden .= '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $params[ $key ] ) . '">';
		}
		$hidden .= '<input type="hidden" name="response_type" value="code"><input type="hidden" name="code_challenge_method" value="S256">';
		$hidden .= '<input type="hidden" name="_wpmark_nonce" value="' . esc_attr( wp_create_nonce( self::nonce_action( $params ) ) ) . '">';

		$body = '<p class="wpmark-app">' . sprintf(
			/* translators: 1: app name, 2: site name. */
			esc_html__( '%1$s wants to connect to %2$s.', 'wpmark' ),
			'<strong>' . esc_html( $params['client_name'] ) . '</strong>',
			'<strong>' . esc_html( $site ) . '</strong>'
		) . '</p>';
		$body .= '<p>' . sprintf(
			/* translators: %s: user display name. */
			esc_html__( 'You are signed in as %s. The app will be able to:', 'wpmark' ),
			'<strong>' . esc_html( $user->display_name ) . '</strong>'
		) . '</p><ul>';
		$body .= '<li>' . esc_html__( 'Read your published pages and posts, and their SEO settings.', 'wpmark' ) . '</li>';
		$body .= '<li>' . esc_html( $leads_line ) . '</li>';
		$body .= '</ul><p class="wpmark-never">' . esc_html__( 'It cannot publish, change or delete anything.', 'wpmark' ) . '</p>';
		$body .= '<p class="wpmark-where">' . sprintf(
			/* translators: %s: web address the person will return to. */
			esc_html__( 'After you choose, you will return to %s.', 'wpmark' ),
			'<strong>' . esc_html( $host ) . '</strong>'
		) . '</p>';
		$body .= '<form method="post" action="' . esc_url( OAuth::url( 'authorize' ) ) . '">' . $hidden;
		$body .= '<button type="submit" name="decision" value="allow" class="button button-primary">' . esc_html__( 'Allow', 'wpmark' ) . '</button> ';
		$body .= '<button type="submit" name="decision" value="deny" class="button">' . esc_html__( 'Cancel', 'wpmark' ) . '</button></form>';
		$body .= '<p class="wpmark-small">' . esc_html__( 'You can disconnect it any time under WPMark → Connect.', 'wpmark' ) . ' <a href="' . esc_url( wp_logout_url( self::current_url_from_params( $params ) ) ) . '">' . esc_html__( 'Not you? Log out', 'wpmark' ) . '</a></p>';

		return self::page_result(
			/* translators: %s: app name. */
			sprintf( __( 'Connect %s?', 'wpmark' ), $params['client_name'] ),
			$body
		);
	}

	/**
	 * The logged-in WordPress user, from the login cookie.
	 *
	 * The REST API ignores cookies unless a REST nonce is sent, so the
	 * cookie is checked here directly.
	 *
	 * @return WP_User|null
	 */
	private static function logged_in_user(): ?WP_User {
		$user_id = wp_validate_auth_cookie( '', 'logged_in' );

		return $user_id ? get_userdata( $user_id ) : null;
	}

	/**
	 * Nonce action, tied to the app and the PKCE challenge of this sign-in.
	 *
	 * @param array $params Checked parameters.
	 * @return string
	 */
	private static function nonce_action( array $params ): string {
		return 'wpmark_oauth_' . $params['client_id'] . '_' . $params['code_challenge'];
	}

	/**
	 * A result that sends the person back to the app.
	 *
	 * @param array $params Checked parameters.
	 * @param array $result code, or error (and error_description).
	 * @return array
	 */
	private static function redirect_result( array $params, array $result ): array {
		return array(
			'type' => 'redirect',
			'url'  => self::redirect_url( $params, $result ),
		);
	}

	/**
	 * A result that shows a page.
	 *
	 * @param string $title Heading.
	 * @param string $body  HTML, already escaped.
	 * @return array
	 */
	private static function page_result( string $title, string $body ): array {
		return array(
			'type'  => 'page',
			'title' => $title,
			'body'  => $body,
		);
	}

	/**
	 * The app's return address with the result added (plus state and iss).
	 *
	 * @param array $params Checked parameters.
	 * @param array $result Values to add.
	 * @return string
	 */
	private static function redirect_url( array $params, array $result ): string {
		if ( '' !== $params['state'] ) {
			$result['state'] = $params['state'];
		}

		$result['iss'] = OAuth::issuer();

		return add_query_arg( rawurlencode_deep( $result ), $params['redirect_uri'] );
	}

	/**
	 * An OAuth error to send back to the app. Descriptions are for developers.
	 *
	 * @param string $code        Error code.
	 * @param string $description Description.
	 * @return array
	 */
	private static function oauth_error( string $code, string $description ): array {
		return array(
			'error'             => $code,
			'error_description' => $description,
		);
	}

	/**
	 * This page's address with its query, for returning after login.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string
	 */
	private static function current_url( WP_REST_Request $request ): string {
		return add_query_arg( rawurlencode_deep( $request->get_query_params() ), OAuth::url( 'authorize' ) );
	}

	/**
	 * This page's address rebuilt from checked values, for "Not you? Log out".
	 *
	 * @param array $params Checked parameters.
	 * @return string
	 */
	private static function current_url_from_params( array $params ): string {
		$query = array(
			'response_type'         => 'code',
			'client_id'             => $params['client_id'],
			'redirect_uri'          => $params['redirect_uri'],
			'state'                 => $params['state'],
			'code_challenge'        => $params['code_challenge'],
			'code_challenge_method' => 'S256',
			'resource'              => $params['resource'],
		);

		return add_query_arg( rawurlencode_deep( array_filter( $query ) ), OAuth::url( 'authorize' ) );
	}

	/**
	 * Output a small standalone page and stop.
	 *
	 * @param string $title Heading.
	 * @param string $body  HTML, already escaped.
	 */
	private static function page( string $title, string $body ): void {
		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Frame-Options: DENY' );
		header( "Content-Security-Policy: frame-ancestors 'none'" );
		header( 'Referrer-Policy: no-referrer' );

		$icon = get_site_icon_url( 96 );

		echo '<!DOCTYPE html><html ' . get_language_attributes() . '><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_language_attributes() is escaped by core.
		echo '<title>' . esc_html( $title ) . '</title>';
		echo '<style>body{margin:0;background:#f0f0f1;font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#1d2327}'
			. '.card{max-width:440px;margin:8vh auto;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:28px 32px;box-shadow:0 1px 3px rgba(0,0,0,.04)}'
			. 'h1{font-size:21px;margin:12px 0 16px}img.icon{width:48px;height:48px;border-radius:8px}ul{padding-left:20px}'
			. '.wpmark-never{color:#50575e}.wpmark-where{background:#f6f7f7;padding:8px 12px;border-radius:4px}'
			. '.button{display:inline-block;font-size:15px;padding:8px 18px;border-radius:4px;border:1px solid #2271b1;background:#fff;color:#2271b1;cursor:pointer;text-decoration:none}'
			. '.button-primary{background:#2271b1;color:#fff}.wpmark-small{font-size:13px;color:#646970;margin-top:20px}</style></head><body><div class="card">';
		if ( $icon ) {
			echo '<img class="icon" src="' . esc_url( $icon ) . '" alt="">';
		}
		echo '<h1>' . esc_html( $title ) . '</h1>';
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts above.
		echo '</div></body></html>';
		exit;
	}
}
