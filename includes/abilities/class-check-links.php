<?php
/**
 * Internal link checker tool.
 *
 * @package WPMark
 */

namespace WPMark\Abilities;

use WP_Post;
use WP_Query;
use WPMark\Builders\Elementor;
use WPMark\Content\Content_Reader;
use WPMark\Output\Untrusted_Text;

defined( 'ABSPATH' ) || exit;

/**
 * Checks the links between the site's own pages: links that lead nowhere,
 * links to pages that are not published, and pages nothing links to, with
 * suggestions for pages that could link to them.
 *
 * Marketing job 4 (audit SEO). Works without an SEO plugin, and reads only
 * the site's own content: it sends no requests.
 */
final class Check_Links extends Ability {

	/**
	 * Most published items scanned.
	 */
	private const SCAN_LIMIT = 300;

	/**
	 * Addresses that are not pages: files, feeds and WordPress's own screens.
	 */
	private const SKIP_PATHS = '#^/(wp-content|wp-admin|wp-includes|wp-json|wp-login\.php|feed|comments/feed|xmlrpc\.php)(/|$)#';

	/**
	 * Archive addresses WordPress makes itself: authors, dates, search, pages of lists.
	 */
	private const ARCHIVE_PATHS = '#^/(author/[^/]+|\d{4}(/\d{2}(/\d{2})?)?|search/.*|page/\d+)/?$#';

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'check-links';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Check internal links', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function summary(): string {
		return __( 'Finds links between your pages that lead nowhere or to unpublished pages, and pages nothing links to, with pages that could link to them.', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Checks the links between this website\'s own pages and posts (in the block editor and Elementor). Returns: broken_links (links to addresses with no page, post or archive on the site: the source page, the link and its text), links_to_unpublished (links to drafts, private or trashed pages), and orphan_pages (published pages and posts that no other scanned page links to), each orphan with related pages that could link to it. Also the number of internal links found. Scans the 300 most recently published items and says if there are more; links to other websites are not checked. Use it for SEO audits, after reorganising pages, and to plan internal links. It only reports: it never changes anything. Read-only.', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_properties(): array {
		return array(
			'type'            => array(
				'type'        => 'string',
				'description' => __( 'Content type: "post", "page", another public type, or "any" (default).', 'wpmark' ),
			),
			'limit_per_check' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => 50,
				'description' => __( 'Most items to list for each problem (the full count is always given). Default 20.', 'wpmark' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $input Validated input.
	 */
	protected function run( array $input ): array {
		$per   = $this->int_input( $input, 'limit_per_check', 20, 1, 50 );
		$query = new WP_Query(
			array_merge(
				Content_Reader::query_args( (string) ( $input['type'] ?? 'any' ), 'publish' ),
				array(
					'posts_per_page' => self::SCAN_LIMIT,
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			)
		);

		$posts       = array_values( array_filter( $query->posts, array( Content_Reader::class, 'can_read' ) ) );
		$broken      = array();
		$unpublished = array();
		$inbound     = array();
		$total       = 0;
		$cache       = array();

		foreach ( $posts as $post ) {
			foreach ( self::links( $post ) as $link ) {
				$path = self::internal_path( $link['url'] );

				if ( null === $path ) {
					continue;
				}

				++$total;
				$target = $cache[ $path ] ??= self::resolve( $path );

				if ( $target instanceof WP_Post ) {
					if ( $target->ID === $post->ID ) {
						continue;
					}

					if ( 'publish' === $target->post_status ) {
						$inbound[ $target->ID ][ $post->ID ] = true;
					} else {
						$unpublished[] = self::issue( $post, $link, sprintf( /* translators: %s: Post status. */ __( 'Links to a page that is %s', 'wpmark' ), self::status_label( $target ) ) );
					}
				} elseif ( false === $target ) {
					$broken[] = self::issue( $post, $link, __( 'No page, post or archive at this address', 'wpmark' ) );
				}
			}
		}

		$front   = (int) get_option( 'page_on_front' );
		$orphans = array();

		foreach ( $posts as $post ) {
			if ( $post->ID !== $front && empty( $inbound[ $post->ID ] ) ) {
				$orphans[] = $post;
			}
		}

		return $this->respond(
			array(
				'scanned'              => count( $posts ),
				'total_published'      => (int) $query->found_posts,
				'scan_limited'         => (int) $query->found_posts > self::SCAN_LIMIT,
				'internal_links_found' => $total,
				'broken_links'         => array(
					'count' => count( $broken ),
					'items' => array_slice( $broken, 0, $per ),
				),
				'links_to_unpublished' => array(
					'count' => count( $unpublished ),
					'items' => array_slice( $unpublished, 0, $per ),
				),
				'orphan_pages'         => array(
					'count' => count( $orphans ),
					'items' => array_map( array( self::class, 'orphan' ), array_slice( $orphans, 0, $per ) ),
				),
				'next_step'            => __( 'Fix broken links first: change them to the right page or remove them. Then add links to orphan pages from the related pages suggested. The team makes these edits in WordPress.', 'wpmark' ),
			)
		);
	}

	/**
	 * The links on a page: in its content, and in its Elementor layout.
	 *
	 * @param WP_Post $post Page.
	 * @return array<int, array{url: string, text: string}>
	 */
	private static function links( WP_Post $post ): array {
		$links = array();

		if ( preg_match_all( '#<a\s[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>#is', $post->post_content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$links[] = array(
					'url'  => html_entity_decode( $match[2] ),
					'text' => Untrusted_Text::clean( wp_strip_all_tags( $match[3] ), 100 ),
				);
			}
		}

		$elementor = (string) get_post_meta( $post->ID, Elementor::DATA_META, true );

		if ( '' !== $elementor && preg_match_all( '#"url"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"#', $elementor, $matches ) ) {
			foreach ( $matches[1] as $url ) {
				$url = (string) json_decode( '"' . $url . '"' );

				if ( '' !== $url ) {
					$links[] = array(
						'url'  => $url,
						'text' => '',
					);
				}
			}
		}

		return $links;
	}

	/**
	 * The path of a link to this site, or null for other sites, files,
	 * anchors and WordPress's own addresses.
	 *
	 * @param string $url Link.
	 * @return string|null
	 */
	private static function internal_path( string $url ): ?string {
		$url = trim( $url );

		if ( '' === $url || str_starts_with( $url, '#' ) || preg_match( '#^(mailto|tel|javascript|data):#i', $url ) ) {
			return null;
		}

		$home  = wp_parse_url( home_url( '/' ) );
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || ( isset( $parts['host'] ) && strtolower( $parts['host'] ) !== strtolower( (string) ( $home['host'] ?? '' ) ) ) ) {
			return null;
		}

		if ( ! isset( $parts['host'] ) && ! str_starts_with( $url, '/' ) ) {
			return null;
		}

		$path = '/' . ltrim( (string) ( $parts['path'] ?? '/' ), '/' );
		$base = rtrim( (string) ( $home['path'] ?? '' ), '/' );

		if ( '' !== $base && str_starts_with( $path, $base ) ) {
			$path = '/' . ltrim( substr( $path, strlen( $base ) ), '/' );
		}

		// Links with ?p=123 and similar are resolved as they are.
		if ( '/' === $path && ! empty( $parts['query'] ) ) {
			return '/?' . $parts['query'];
		}

		if ( preg_match( self::SKIP_PATHS, $path ) || preg_match( '#\.[a-z0-9]{2,5}$#i', $path ) ) {
			return null;
		}

		return $path;
	}

	/**
	 * What a path on the site leads to.
	 *
	 * @param string $path Path, e.g. "/pricing/".
	 * @return WP_Post|bool The page or post; true for the home page or an archive; false when nothing is there.
	 */
	private static function resolve( string $path ) {
		if ( '/' === $path || preg_match( self::ARCHIVE_PATHS, $path ) ) {
			return true;
		}

		$id = url_to_postid( home_url( $path ) );

		if ( $id ) {
			$post = get_post( $id );
			return $post ? $post : false;
		}

		// A page that is not published is not found by its address, but still exists.
		$page = get_page_by_path( trim( $path, '/' ), OBJECT, array( 'page', 'post' ) );
		if ( $page instanceof WP_Post ) {
			return $page;
		}

		return self::is_archive( $path );
	}

	/**
	 * Whether a path is a category, tag or post type archive.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private static function is_archive( string $path ): bool {
		$segments = array_values( array_filter( explode( '/', trim( $path, '/' ) ) ) );

		if ( ! $segments ) {
			return true;
		}

		foreach ( get_post_types( array( 'has_archive' => true ), 'objects' ) as $type ) {
			$slug = is_string( $type->has_archive ) ? $type->has_archive : ( $type->rewrite['slug'] ?? $type->name );
			if ( trim( (string) $slug, '/' ) === $segments[0] ) {
				return true;
			}
		}

		$last = (string) end( $segments );

		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			$base = trim( (string) ( $taxonomy->rewrite['slug'] ?? $taxonomy->name ), '/' );

			if ( str_starts_with( trim( $path, '/' ), $base . '/' ) && get_term_by( 'slug', $last, $taxonomy->name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * One problem link.
	 *
	 * @param WP_Post $post   Page the link is on.
	 * @param array   $link   url and text.
	 * @param string  $detail What is wrong.
	 * @return array
	 */
	private static function issue( WP_Post $post, array $link, string $detail ): array {
		return array(
			'on_page'   => array(
				'id'    => $post->ID,
				'title' => Untrusted_Text::clean( get_the_title( $post ), 200 ),
				'url'   => (string) get_permalink( $post ),
			),
			'link'      => esc_url_raw( $link['url'] ),
			'link_text' => $link['text'],
			'detail'    => $detail,
		);
	}

	/**
	 * A page nothing links to, with pages that could.
	 *
	 * @param WP_Post $post Page.
	 * @return array
	 */
	private static function orphan( WP_Post $post ): array {
		$keyword = self::keywords( get_the_title( $post ) );
		$related = mb_strlen( $keyword ) < 4 ? array() : get_posts(
			array(
				's'              => $keyword,
				'post_type'      => array( 'page', 'post' ),
				'post_status'    => 'publish',
				'posts_per_page' => 4,
				'post__not_in'   => array( $post->ID ),
				'has_password'   => false,
			)
		);

		return array(
			'id'                   => $post->ID,
			'title'                => Untrusted_Text::clean( get_the_title( $post ), 200 ),
			'url'                  => (string) get_permalink( $post ),
			'type'                 => $post->post_type,
			'could_be_linked_from' => array_map(
				static fn( WP_Post $page ): array => array(
					'id'    => $page->ID,
					'title' => Untrusted_Text::clean( get_the_title( $page ), 200 ),
					'url'   => (string) get_permalink( $page ),
				),
				array_slice( $related, 0, 3 )
			),
		);
	}

	/**
	 * The most telling word of a title (its longest), for finding related
	 * pages. WordPress's search needs every word to match, so one is best.
	 *
	 * @param string $title Title.
	 * @return string
	 */
	private static function keywords( string $title ): string {
		$words = (array) preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( wp_strip_all_tags( $title ) ) );

		usort( $words, static fn( $a, $b ): int => mb_strlen( (string) $b ) <=> mb_strlen( (string) $a ) );

		return (string) ( $words[0] ?? '' );
	}

	/**
	 * A post status in words.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private static function status_label( WP_Post $post ): string {
		$status = get_post_status_object( $post->post_status );

		return strtolower( $status ? (string) $status->label : $post->post_status );
	}
}
