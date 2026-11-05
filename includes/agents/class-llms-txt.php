<?php
/**
 * The llms.txt summary.
 *
 * @package WPMark
 */

namespace WPMark\Agents;

use WP;
use WP_Post;
use WPMark\Content\Content_Reader;
use WPMark\Output\Untrusted_Text;
use WPMark\Seo\Seo_Sources;
use WPMark\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Publishes /llms.txt: a plain Markdown summary of the site for AI tools,
 * following the llms.txt proposal (llmstxt.org): the site's name, a short
 * description, then lists of its main pages and recent posts with links.
 *
 * Off by default. It lists only what is already public: published pages
 * and posts that are not password-protected or hidden from search engines.
 * Nothing about leads, drafts or people.
 *
 * If a file or another plugin already provides llms.txt, WPMark steps aside.
 */
final class Llms_Txt {

	/**
	 * Cached text.
	 */
	private const CACHE = 'wpmark_llms_txt';

	/**
	 * Most pages and posts listed.
	 */
	private const MAX_PAGES = 50;
	private const MAX_POSTS = 20;

	/**
	 * Add the hooks.
	 */
	public static function register(): void {
		add_action( 'parse_request', array( self::class, 'maybe_serve' ), 0 );

		foreach ( array( 'save_post', 'deleted_post', 'update_option_blogname', 'update_option_blogdescription' ) as $hook ) {
			add_action( $hook, array( self::class, 'clear' ) );
		}
	}

	/**
	 * Whether WPMark serves llms.txt right now.
	 *
	 * @return bool
	 */
	public static function is_served(): bool {
		return Settings::llms_txt_enabled() && '' === self::other_provider();
	}

	/**
	 * Who else provides llms.txt, if anyone: a file in the site's folder,
	 * or an SEO plugin with its own llms.txt feature switched on.
	 *
	 * @return string Name of the provider, or "".
	 */
	public static function other_provider(): string {
		if ( file_exists( ABSPATH . 'llms.txt' ) ) {
			return __( 'a file on your server', 'wpmark' );
		}

		if ( class_exists( '\RankMath\Helper' ) && method_exists( '\RankMath\Helper', 'is_module_active' ) && \RankMath\Helper::is_module_active( 'llms-txt' ) ) {
			return 'Rank Math';
		}

		$yoast = get_option( 'wpseo' );
		if ( is_array( $yoast ) && ! empty( $yoast['enable_llms_txt'] ) ) {
			return 'Yoast SEO';
		}

		return '';
	}

	/**
	 * Answer requests for /llms.txt.
	 *
	 * @param WP $wp The request.
	 */
	public static function maybe_serve( WP $wp ): void {
		if ( ! self::is_served() || ! self::is_request( $wp ) ) {
			return;
		}

		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		header( 'Cache-Control: public, max-age=3600' );

		echo self::text(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text, built from cleaned values.
		exit;
	}

	/**
	 * Whether this request is for /llms.txt at the site's root.
	 *
	 * @param WP $wp The request.
	 * @return bool
	 */
	private static function is_request( WP $wp ): bool {
		if ( 'llms.txt' === $wp->request ) {
			return true;
		}

		$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '', PHP_URL_PATH );
		$home = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );

		return $home . '/llms.txt' === $path;
	}

	/**
	 * The llms.txt text, cached until content changes.
	 *
	 * @return string
	 */
	public static function text(): string {
		$cached = get_transient( self::CACHE );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$text = self::build();
		set_transient( self::CACHE, $text, 12 * HOUR_IN_SECONDS );

		return $text;
	}

	/**
	 * Forget the cached text.
	 */
	public static function clear(): void {
		delete_transient( self::CACHE );
	}

	/**
	 * Build the text.
	 *
	 * @return string
	 */
	public static function build(): string {
		$name    = self::line( get_bloginfo( 'name' ) );
		$tagline = self::line( get_bloginfo( 'description' ) );
		$lines   = array( '# ' . ( '' !== $name ? $name : home_url() ), '' );

		if ( '' !== $tagline ) {
			$lines[] = '> ' . $tagline;
			$lines[] = '';
		}

		$front = (int) get_option( 'page_on_front' );
		$pages = self::posts(
			array(
				'post_type'      => 'page',
				'posts_per_page' => self::MAX_PAGES,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
			)
		);

		$home  = array( '- [' . __( 'Home page', 'wpmark' ) . '](' . home_url( '/' ) . ')' );
		$lines = array_merge( $lines, array( '## ' . __( 'Pages', 'wpmark' ), '' ), $home, self::entries( $pages, $front ), array( '' ) );

		$posts = self::posts(
			array(
				'post_type'      => 'post',
				'posts_per_page' => self::MAX_POSTS,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		if ( $posts ) {
			$lines = array_merge( $lines, array( '## ' . __( 'Latest posts', 'wpmark' ), '' ), self::entries( $posts, 0 ), array( '' ) );
		}

		$lines[] = '## ' . __( 'Optional', 'wpmark' );
		$lines[] = '';
		$lines[] = '- [' . __( 'Sitemap', 'wpmark' ) . '](' . home_url( '/wp-sitemap.xml' ) . ')';

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * List entries: "- [Title](url): short description".
	 *
	 * @param WP_Post[] $posts Posts.
	 * @param int       $skip  A post to leave out (the front page), or 0.
	 * @return string[]
	 */
	private static function entries( array $posts, int $skip ): array {
		$entries = array();

		foreach ( $posts as $post ) {
			if ( $post->ID === $skip ) {
				continue;
			}

			$title = self::line( get_the_title( $post ) );
			$about = self::line( Content_Reader::excerpt( $post, 25 ) );

			if ( '' === $title ) {
				continue;
			}

			$entries[] = '- [' . str_replace( array( '[', ']' ), '', $title ) . '](' . get_permalink( $post ) . ')' . ( '' !== $about ? ': ' . $about : '' );
		}

		return $entries;
	}

	/**
	 * Published, public posts, without those hidden from search engines.
	 *
	 * @param array $args Query arguments.
	 * @return WP_Post[]
	 */
	private static function posts( array $args ): array {
		$posts  = get_posts(
			array_merge(
				array(
					'post_status'  => 'publish',
					'has_password' => false,
				),
				$args
			)
		);
		$source = Seo_Sources::active();

		return array_values( array_filter( $posts, static fn( WP_Post $post ): bool => ! $source || ! $source->get_meta( $post->ID )->noindex ) );
	}

	/**
	 * Text on one line, without Markdown that could break the list.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function line( string $text ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', Untrusted_Text::clean( $text, 300 ) ) );
	}
}
