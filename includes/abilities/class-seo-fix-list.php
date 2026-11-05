<?php
/**
 * SEO fix list tool.
 *
 * @package WPMark
 */

namespace WPMark\Abilities;

use WP_Post;
use WP_Query;
use WPMark\Content\Content_Reader;
use WPMark\Output\Untrusted_Text;
use WPMark\Seo\Seo_Sources;

defined( 'ABSPATH' ) || exit;

/**
 * The pages whose search title or description needs work, with what the AI
 * needs to write better ones: the current values, focus keyword, opening
 * text and headings. The AI writes the suggestions; people paste them in.
 *
 * Marketing job 4 (audit SEO).
 */
final class Seo_Fix_List extends Ability {

	/**
	 * Most published items scanned.
	 */
	private const SCAN_LIMIT = 500;

	/**
	 * Lengths search engines show, in characters.
	 */
	private const TITLE_MAX       = 60;
	private const DESCRIPTION_MIN = 70;
	private const DESCRIPTION_MAX = 160;

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'seo-fix-list';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'SEO fix list', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function summary(): string {
		return __( 'Lists pages whose search title or meta description is missing, too long or too short, so the AI can write better ones for you to paste into your SEO plugin.', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Lists this website\'s published pages and posts whose search appearance needs work, from Rank Math\'s settings: missing_description, description_too_short (under 70 characters), description_too_long (over 160), title_too_long (over 60) and missing_focus_keyword. For each page: id, title, link, the current SEO title and meta description, the focus keyword, the problems, the opening text and the headings, which is what you need to write good replacements. Then give the person a table with, for each page, a suggested SEO title (under 60 characters, the focus keyword near the start) and meta description (120 to 155 characters, specific, with a reason to click), to paste into Rank Math. Pages are listed newest first, pages before posts. If wpmark-stage-page-edit is available, offer to propose the new SEO fields for approval instead. Read-only: it never changes anything.', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return null !== Seo_Sources::active();
	}

	/**
	 * {@inheritDoc}
	 */
	public function unavailable_reason(): string {
		return __( 'The SEO fix list needs an SEO plugin WPMark can read (Rank Math). None is active.', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_properties(): array {
		return array(
			'type'  => array(
				'type'        => 'string',
				'description' => __( 'Content type: "post", "page", another public type, or "any" (default).', 'wpmark' ),
			),
			'limit' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => 50,
				'description' => __( 'Most pages to return (the full count is always given). Default 20.', 'wpmark' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $input Validated input.
	 */
	protected function run( array $input ): array {
		$limit  = $this->int_input( $input, 'limit', 20, 1, 50 );
		$source = Seo_Sources::active();
		$query  = new WP_Query(
			array_merge(
				Content_Reader::query_args( (string) ( $input['type'] ?? 'any' ), 'publish' ),
				array(
					'posts_per_page' => self::SCAN_LIMIT,
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			)
		);

		$pages = array();

		foreach ( $query->posts as $post ) {
			if ( ! Content_Reader::can_read( $post ) ) {
				continue;
			}

			$meta = $source->get_meta( $post->ID );

			// Pages hidden from search engines need no search snippet.
			if ( $meta->noindex ) {
				continue;
			}

			$problems = self::problems( $post, $meta->title, $meta->description, $meta->focus_keywords );

			if ( $problems ) {
				$pages[] = array(
					'post'     => $post,
					'meta'     => $meta,
					'problems' => $problems,
				);
			}
		}

		// Pages first (usually the most important), then posts; newest first within each.
		usort( $pages, static fn( array $a, array $b ): int => ( 'page' === $b['post']->post_type ) <=> ( 'page' === $a['post']->post_type ) );

		$counts = array();
		foreach ( $pages as $page ) {
			foreach ( $page['problems'] as $problem ) {
				$counts[ $problem ] = ( $counts[ $problem ] ?? 0 ) + 1;
			}
		}

		return $this->respond(
			array(
				'seo_plugin'     => $source->get_label(),
				'scanned'        => count( $query->posts ),
				'scan_limited'   => (int) $query->found_posts > self::SCAN_LIMIT,
				'pages_to_fix'   => count( $pages ),
				'problem_counts' => $counts,
				'pages'          => array_map( array( self::class, 'page' ), array_slice( $pages, 0, $limit ) ),
				'how_to_write'   => __( 'SEO title: under 60 characters, focus keyword near the start, the brand at the end only if it fits. Meta description: 120 to 155 characters, says what the page offers and why to click, in the site\'s tone, no keyword stuffing. Base both on the page\'s real content.', 'wpmark' ),
			)
		);
	}

	/**
	 * What is wrong with a page's search snippet.
	 *
	 * @param WP_Post  $post        Page.
	 * @param string   $title       SEO title, or "" for the default.
	 * @param string   $description Meta description.
	 * @param string[] $keywords    Focus keywords.
	 * @return string[]
	 */
	private static function problems( WP_Post $post, string $title, string $description, array $keywords ): array {
		$problems = array();
		$length   = mb_strlen( trim( $description ) );

		if ( 0 === $length ) {
			$problems[] = 'missing_description';
		} elseif ( $length < self::DESCRIPTION_MIN ) {
			$problems[] = 'description_too_short';
		} elseif ( $length > self::DESCRIPTION_MAX ) {
			$problems[] = 'description_too_long';
		}

		// Titles with Rank Math variables (%title% and the like) are left alone: their length depends on settings.
		$shown = '' !== trim( $title ) ? $title : get_the_title( $post );
		if ( ! str_contains( $shown, '%' ) && mb_strlen( wp_strip_all_tags( $shown ) ) > self::TITLE_MAX ) {
			$problems[] = 'title_too_long';
		}

		if ( ! $keywords ) {
			$problems[] = 'missing_focus_keyword';
		}

		return $problems;
	}

	/**
	 * What the AI needs to write a better snippet for one page.
	 *
	 * @param array $page post, meta and problems.
	 * @return array
	 */
	private static function page( array $page ): array {
		$post = $page['post'];
		$meta = $page['meta'];

		return array(
			'id'                  => $post->ID,
			'type'                => $post->post_type,
			'title'               => Untrusted_Text::clean( get_the_title( $post ), 200 ),
			'url'                 => (string) get_permalink( $post ),
			'problems'            => $page['problems'],
			'current_seo_title'   => Untrusted_Text::clean( $meta->title, 300 ),
			'current_description' => Untrusted_Text::clean( $meta->description, 400 ),
			'focus_keywords'      => array_map( static fn( $keyword ): string => Untrusted_Text::clean( (string) $keyword, 100 ), $meta->focus_keywords ),
			'opening_text'        => Content_Reader::text( $post, 400 )['text'],
			'headings'            => array_slice( Content_Reader::headings( $post ), 0, 6 ),
		);
	}
}
