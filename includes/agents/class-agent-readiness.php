<?php
/**
 * Agent readiness checks.
 *
 * @package WPMark
 */

namespace WPMark\Agents;

use WPMark\Admin\Connection_Doctor;
use WPMark\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * How ready the site is for AI search and AI agents, in four layers:
 *
 * 1. Allowed: are AI crawlers and agents let in? Many small businesses
 *    block them without knowing, through a WordPress setting, robots.txt,
 *    or a firewall such as Cloudflare (which, since mid-2025, blocks AI
 *    crawlers by default on new sites).
 * 2. Readable: can they understand the site? Structured data, a sitemap
 *    and an llms.txt summary.
 * 3. Actionable: can an agent in a visitor's browser use the site's forms
 *    and search directly (WebMCP, an early proposal from Google and
 *    Microsoft)?
 * 4. Transactable (checkout by agents) is out of scope: it belongs to shop
 *    platforms.
 *
 * Each check uses the same shape as the health check, with a fix written
 * for a non-developer.
 */
final class Agent_Readiness {

	/**
	 * Browser-like user agent, for comparison with the AI crawlers'.
	 */
	private const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36';

	/**
	 * AI agents tried against the home page in the firewall check: one per
	 * company that matters most for being found.
	 */
	private const PROBES = array(
		'ChatGPT-User'  => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0; +https://openai.com/bot',
		'Claude-User'   => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Claude-User/1.0; +Claude-User@anthropic.com)',
		'PerplexityBot' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)',
	);

	/**
	 * Responses fetched during one report, by URL and user agent, so each
	 * address is asked for only once. Null outside a report.
	 *
	 * @var array<string, array|\WP_Error>|null
	 */
	private static ?array $fetched = null;

	/**
	 * Every check.
	 *
	 * @param bool $live Whether to send test requests from the site to itself.
	 * @return array<int, array{id: string, label: string, status: string, message: string, fix: string}>
	 */
	public static function checks( bool $live = true ): array {
		self::$fetched = array();

		$checks = array(
			self::search_engines(),
			self::crawlers( $live ),
		);

		if ( $live ) {
			$checks[] = self::firewall();
			$checks[] = self::structured_data();
		}

		$checks[] = self::sitemap( $live );
		$checks[] = self::llms_txt( $live );
		$checks[] = self::webmcp();

		self::$fetched = null;

		return $checks;
	}

	/**
	 * Settings → Reading → "Discourage search engines".
	 *
	 * @return array
	 */
	public static function search_engines(): array {
		$public = '0' !== (string) get_option( 'blog_public', '1' );

		return self::result(
			'search_engines',
			__( 'Search engines and AI', 'wpmark' ),
			$public ? Connection_Doctor::GOOD : Connection_Doctor::PROBLEM,
			$public
				? __( 'WordPress lets search engines and AI crawlers in.', 'wpmark' )
				: __( 'WordPress asks every search engine and AI crawler to stay away.', 'wpmark' ),
			$public ? '' : __( 'Unless this is a test site, go to Settings → Reading and untick "Discourage search engines from indexing this site".', 'wpmark' )
		);
	}

	/**
	 * Which AI crawlers robots.txt lets in.
	 *
	 * @param bool $live Whether to read the robots.txt visitors get (it can
	 *                   differ from WordPress's when a CDN rewrites it).
	 * @return array
	 */
	public static function crawlers( bool $live = true ): array {
		$text    = self::robots_text( $live );
		$robots  = new Robots( $text );
		$blocked = array(
			'search'   => array(),
			'training' => array(),
		);

		foreach ( Robots::AGENTS as $agent => $about ) {
			if ( ! $robots->allows( $agent ) ) {
				$blocked[ 'training' === $about[1] ? 'training' : 'search' ][] = $agent;
			}
		}

		$label      = __( 'AI crawlers (robots.txt)', 'wpmark' );
		$cloudflare = str_contains( $text, 'Cloudflare Managed' );
		$where      = $cloudflare
			? __( 'Your robots.txt is managed by Cloudflare: change it in the Cloudflare dashboard under AI Crawl Control (or Security → Bots).', 'wpmark' )
			: __( 'Change robots.txt in your SEO plugin (Rank Math: General Settings → Edit robots.txt), or ask whoever manages your site.', 'wpmark' );

		if ( $blocked['search'] ) {
			return self::result(
				'ai_crawlers',
				$label,
				Connection_Doctor::PROBLEM,
				/* translators: %s: Crawler names. */
				sprintf( __( 'AI search and AI assistants cannot read your site: robots.txt blocks %s. People asking ChatGPT, Claude or Perplexity about what you offer will not see your pages.', 'wpmark' ), implode( ', ', $blocked['search'] ) ),
				__( 'Allow at least the search and user agents (OAI-SearchBot, ChatGPT-User, Claude-SearchBot, Claude-User, PerplexityBot, Perplexity-User).', 'wpmark' ) . ' ' . $where
			);
		}

		if ( $blocked['training'] ) {
			return self::result(
				'ai_crawlers',
				$label,
				Connection_Doctor::GOOD,
				/* translators: %s: Crawler names. */
				sprintf( __( 'AI search and AI assistants can read your site. AI training crawlers are blocked (%s): that is a business choice and does not stop you being found.', 'wpmark' ), implode( ', ', $blocked['training'] ) )
			);
		}

		return self::result( 'ai_crawlers', $label, Connection_Doctor::GOOD, __( 'robots.txt lets every major AI crawler and assistant read your site.', 'wpmark' ) );
	}

	/**
	 * Whether something in front of WordPress turns AI agents away.
	 *
	 * Asks for the home page as a browser and as three AI assistants. A
	 * firewall or CDN that refuses only the assistants is blocking them.
	 * A server cannot fully copy a real crawler (some firewalls also check
	 * where a request comes from), so the result is a strong hint, not proof.
	 *
	 * @return array
	 */
	public static function firewall(): array {
		$label   = __( 'Firewall and CDN', 'wpmark' );
		$browser = self::fetch( home_url( '/' ), self::BROWSER );

		if ( is_wp_error( $browser ) || 200 !== (int) wp_remote_retrieve_response_code( $browser ) ) {
			return self::result( 'ai_firewall', $label, Connection_Doctor::INFO, __( 'Could not test: the site did not answer its own test request.', 'wpmark' ) );
		}

		$cloudflare = '' !== (string) wp_remote_retrieve_header( $browser, 'cf-ray' );
		$refused    = array();

		foreach ( self::PROBES as $agent => $user_agent ) {
			$response = self::fetch( home_url( '/' ), $user_agent );
			$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

			if ( 200 !== $code || ( ! is_wp_error( $response ) && '' !== (string) wp_remote_retrieve_header( $response, 'cf-mitigated' ) ) ) {
				$refused[] = $agent;
			}
		}

		if ( $refused ) {
			return self::result(
				'ai_firewall',
				$label,
				Connection_Doctor::PROBLEM,
				/* translators: %s: AI agent names. */
				sprintf( __( 'Your site answers browsers but turned away requests from %s. Something in front of WordPress seems to block AI assistants.', 'wpmark' ), implode( ', ', $refused ) ),
				$cloudflare
					? __( 'Your site uses Cloudflare, which blocks AI crawlers by default on sites added since mid-2025. In the Cloudflare dashboard, open AI Crawl Control (or Security → Bots) and allow the AI search crawlers and assistants you want.', 'wpmark' )
					: __( 'Check your security plugin, firewall or hosting control panel for a "block AI bots" or bot protection setting, and allow the AI search crawlers and assistants you want.', 'wpmark' )
			);
		}

		return self::result(
			'ai_firewall',
			$label,
			Connection_Doctor::GOOD,
			$cloudflare
				? __( 'AI assistants got your home page in our test. Your site uses Cloudflare: its "Block AI bots" setting can still turn away real crawlers, so check AI Crawl Control in the Cloudflare dashboard once.', 'wpmark' )
				: __( 'AI assistants got your home page in our test.', 'wpmark' )
		);
	}

	/**
	 * Whether the home page describes the business in structured data
	 * (schema.org JSON-LD), which search engines and AI read reliably.
	 *
	 * @return array
	 */
	public static function structured_data(): array {
		$label    = __( 'Structured data', 'wpmark' );
		$response = self::fetch( home_url( '/' ), self::BROWSER );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return self::result( 'structured_data', $label, Connection_Doctor::INFO, __( 'Could not test: the site did not answer its own test request.', 'wpmark' ) );
		}

		$found = (bool) preg_match( '#<script[^>]+type=["\']application/ld\+json["\']#i', (string) wp_remote_retrieve_body( $response ) );

		return self::result(
			'structured_data',
			$label,
			$found ? Connection_Doctor::GOOD : Connection_Doctor::WARNING,
			$found
				? __( 'Your home page describes your business in structured data (schema.org) that AI and search engines read.', 'wpmark' )
				: __( 'Your home page has no structured data (schema.org), so AI has to guess what your business is and does.', 'wpmark' ),
			$found ? '' : __( 'An SEO plugin adds it: in Rank Math, fill in Titles & Meta → Local SEO (your business name, logo and contact details).', 'wpmark' )
		);
	}

	/**
	 * Whether the site has an XML sitemap listed in robots.txt.
	 *
	 * @param bool $live Whether to read the live robots.txt.
	 * @return array
	 */
	public static function sitemap( bool $live = true ): array {
		$listed = (bool) preg_match( '/^\s*sitemap\s*:/im', self::robots_text( $live ) );

		return self::result(
			'sitemap',
			__( 'Sitemap', 'wpmark' ),
			$listed ? Connection_Doctor::GOOD : Connection_Doctor::INFO,
			$listed
				? __( 'robots.txt points crawlers to your sitemap, the list of every page.', 'wpmark' )
				: __( 'robots.txt does not list a sitemap, so crawlers find pages more slowly.', 'wpmark' ),
			$listed ? '' : __( 'Most SEO plugins add it (Rank Math: Sitemap Settings). WordPress also has a built-in sitemap at /wp-sitemap.xml.', 'wpmark' )
		);
	}

	/**
	 * Whether the site publishes an llms.txt summary.
	 *
	 * @param bool $live Whether to check the address itself.
	 * @return array
	 */
	public static function llms_txt( bool $live = true ): array {
		$label = __( 'llms.txt summary', 'wpmark' );

		if ( Llms_Txt::is_served() ) {
			return self::result( 'llms_txt', $label, Connection_Doctor::GOOD, __( 'WPMark publishes a plain summary of your site for AI at /llms.txt.', 'wpmark' ) );
		}

		$other = Llms_Txt::other_provider();
		if ( '' !== $other ) {
			/* translators: %s: Plugin name or "a file". */
			return self::result( 'llms_txt', $label, Connection_Doctor::GOOD, sprintf( __( 'Your site has an llms.txt summary, provided by %s.', 'wpmark' ), $other ) );
		}

		if ( $live ) {
			$response = self::fetch( home_url( '/llms.txt' ), self::BROWSER );
			$type     = is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_header( $response, 'content-type' );

			if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) && str_contains( $type, 'text/plain' ) ) {
				return self::result( 'llms_txt', $label, Connection_Doctor::GOOD, __( 'Your site has an llms.txt summary.', 'wpmark' ) );
			}
		}

		return self::result(
			'llms_txt',
			$label,
			Connection_Doctor::INFO,
			__( 'No llms.txt: an emerging, optional summary file that helps AI tools understand a site quickly.', 'wpmark' ),
			__( 'Switch it on under WPMark → Agent ready. It lists only what is already public.', 'wpmark' )
		);
	}

	/**
	 * Whether forms and search are offered to AI agents in browsers.
	 *
	 * @return array
	 */
	public static function webmcp(): array {
		$on = Settings::webmcp_enabled();

		return self::result(
			'webmcp',
			__( 'Forms and search for AI agents (WebMCP)', 'wpmark' ),
			$on ? Connection_Doctor::GOOD : Connection_Doctor::INFO,
			$on
				? __( 'Your contact forms and site search are described for AI agents in visitors\' browsers (experimental: Chrome and Edge trials). Visitors still press Send themselves.', 'wpmark' )
				: __( 'Off. AI agents in visitors\' browsers have to guess how to use your forms and search.', 'wpmark' ),
			$on ? '' : __( 'Optional and experimental: switch it on under WPMark → Agent ready.', 'wpmark' )
		);
	}

	/**
	 * The robots.txt crawlers get: fetched from the site when possible
	 * (a CDN may rewrite it), else the file or what WordPress generates.
	 *
	 * @param bool $live Whether to fetch it.
	 * @return string
	 */
	public static function robots_text( bool $live = true ): string {
		if ( $live ) {
			$response = self::fetch( home_url( '/robots.txt' ), self::BROWSER );

			if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
				return (string) wp_remote_retrieve_body( $response );
			}
		}

		$file = ABSPATH . 'robots.txt';

		if ( is_readable( $file ) ) {
			return (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file.
		}

		// What WordPress serves itself (see do_robots()).
		$public = '0' !== (string) get_option( 'blog_public', '1' );
		$text   = "User-agent: *\n" . ( $public ? "Disallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n" : "Disallow: /\n" );

		return (string) apply_filters( 'robots_txt', $text, $public ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own hook.
	}

	/**
	 * Fetch a page from the site itself, once per report.
	 *
	 * @param string $url        Address.
	 * @param string $user_agent User agent.
	 * @return array|\WP_Error
	 */
	private static function fetch( string $url, string $user_agent ) {
		$key = $url . '|' . $user_agent;

		if ( null !== self::$fetched && isset( self::$fetched[ $key ] ) ) {
			return self::$fetched[ $key ];
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 8,
				'redirection' => 3,
				'user-agent'  => $user_agent,
			)
		);

		if ( null !== self::$fetched ) {
			self::$fetched[ $key ] = $response;
		}

		return $response;
	}

	/**
	 * A check result.
	 *
	 * @param string $id      Check ID.
	 * @param string $label   Name.
	 * @param string $status  Connection_Doctor status.
	 * @param string $message What was found.
	 * @param string $fix     What to do, or "".
	 * @return array
	 */
	private static function result( string $id, string $label, string $status, string $message, string $fix = '' ): array {
		return array(
			'id'      => $id,
			'label'   => $label,
			'status'  => $status,
			'message' => $message,
			'fix'     => $fix,
		);
	}
}
