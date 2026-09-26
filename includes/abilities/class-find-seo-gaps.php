<?php
/**
 * Find SEO gaps tool.
 *
 * @package WPMark
 */

namespace WPMark\Abilities;

use WP_Post;
use WPMark\Content\Content_Reader;
use WPMark\Output\Untrusted_Text;
use WPMark\Seo\Seo_Source;
use WPMark\Seo\Seo_Sources;

defined( 'ABSPATH' ) || exit;

/**
 * Finds common, fixable SEO problems across the site.
 *
 * Marketing jobs 4 (audit SEO) and 6 (refresh old content).
 */
final class Find_Seo_Gaps extends Ability {

	/**
	 * How many published items one check scans, newest first.
	 */
	private const SCAN_LIMIT = 500;

	/**
	 * Checks that need an SEO plugin.
	 */
	private const SEO_PLUGIN_CHECKS = array( 'missing_meta_description', 'missing_focus_keyword', 'hidden_from_search', 'no_internal_links' );

	/**
	 * Every check.
	 */
	private const CHECKS = array( 'missing_meta_description', 'missing_focus_keyword', 'hidden_from_search', 'no_internal_links', 'thin_content', 'stale_content', 'duplicate_titles' );

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'find-seo-gaps';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Find SEO gaps', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function summary(): string {
		return __( 'Finds fixable SEO problems: missing meta descriptions, thin or stale pages, duplicate titles and more.', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Audits this website\'s published posts and pages for common, fixable SEO problems and groups them by kind, each with a one-line reason why it matters and the affected pages (id, title, link and a detail). Checks: missing_meta_description, missing_focus_keyword, hidden_from_search (set to noindex; may be intentional), no_internal_links (nothing on the site links to the page, where Rank Math has scanned it), thin_content (fewer words than thin_under_words, default 300), stale_content (not updated for stale_after_months, default 18) and duplicate_titles. The first four need Rank Math; without it they are listed under skipped_checks with the reason. Scans the 500 most recently published items and says if there are more. Use it for SEO audits and to decide what to fix first. It only reports: it never changes anything. Read-only.', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_properties(): array {
		return array(
			'type'               => array(
				'type'        => 'string',
				'description' => __( 'Content type: "post", "page", another public type, or "any" (default).', 'wpmark' ),
			),
			'checks'             => array(
				'type'        => 'array',
				'items'       => array(
					'type' => 'string',
					'enum' => self::CHECKS,
				),
				'description' => __( 'Which checks to run. Default: all.', 'wpmark' ),
			),
			'thin_under_words'   => array(
				'type'        => 'integer',
				'minimum'     => 50,
				'maximum'     => 5000,
				'description' => __( 'Pages with fewer words than this count as thin. Default 300.', 'wpmark' ),
			),
			'stale_after_months' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => 120,
				'description' => __( 'Pages not updated for this many months count as stale. Default 18.', 'wpmark' ),
			),
			'limit_per_check'    => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => 50,
				'description' => __( 'Most pages to list for each problem (the full count is always given). Default 20.', 'wpmark' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $input Validated input.
	 */
	protected function run( array $input ): array {
		$thin   = $this->int_input( $input, 'thin_under_words', 300, 50, 5000 );
		$stale  = $this->int_input( $input, 'stale_after_months', 18, 1, 120 );
		$per    = $this->int_input( $input, 'limit_per_check', 20, 1, 50 );
		$checks = ! empty( $input['checks'] ) ? array_values( array_intersect( self::CHECKS, (array) $input['checks'] ) ) : self::CHECKS;
		$source = Seo_Sources::active();

		$query = new \WP_Query(
			array_merge(
				Content_Reader::query_args( (string) ( $input['type'] ?? 'any' ), 'publish' ),
				array(
					'posts_per_page' => self::SCAN_LIMIT,
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			)
		);

		// Only pages this person could read in WordPress.
		$posts   = array_values( array_filter( $query->posts, array( Content_Reader::class, 'can_read' ) ) );
		$skipped = array();
		$found   = array();

		foreach ( $checks as $check ) {
			if ( ! $source && in_array( $check, self::SEO_PLUGIN_CHECKS, true ) ) {
				$skipped[] = array(
					'check'  => $check,
					'reason' => __( 'Needs an SEO plugin (Rank Math). None is active.', 'wpmark' ),
				);
				continue;
			}

			$found[ $check ] = $this->find( $check, $posts, $source, $thin, $stale );
		}

		$issues = array();

		foreach ( $found as $check => $items ) {
			if ( ! $items ) {
				continue;
			}

			$issues[] = array(
				'check'          => $check,
				'why_it_matters' => $this->why( $check ),
				'count'          => count( $items ),
				'pages'          => array_slice( $items, 0, $per ),
			);
		}

		return $this->respond(
			array(
				'seo_plugin'      => $source?->get_label(),
				'scanned'         => count( $posts ),
				'total_published' => (int) $query->found_posts,
				'scan_limited'    => (int) $query->found_posts > self::SCAN_LIMIT,
				'checks_run'      => array_keys( $found ),
				'skipped_checks'  => $skipped,
				'issues'          => $issues,
			)
		);
	}

	/**
	 * Run one check.
	 *
	 * @param string          $check  Check slug.
	 * @param WP_Post[]       $posts  Posts to check.
	 * @param Seo_Source|null $source Active SEO plugin.
	 * @param int             $thin   Thin-content word threshold.
	 * @param int             $stale  Stale-content month threshold.
	 * @return array<int, array> Affected pages.
	 */
	private function find( string $check, array $posts, ?Seo_Source $source, int $thin, int $stale ): array {
		$items = array();

		if ( 'duplicate_titles' === $check ) {
			return $this->duplicate_titles( $posts, $source );
		}

		$links = 'no_internal_links' === $check && $source
			? $source->incoming_links( wp_list_pluck( $posts, 'ID' ) )
			: array();

		foreach ( $posts as $post ) {
			$detail = null;

			switch ( $check ) {
				case 'missing_meta_description':
					$detail = '' === $source->get_meta( $post->ID )->description ? __( 'No meta description', 'wpmark' ) : null;
					break;
				case 'missing_focus_keyword':
					$detail = ! $source->get_meta( $post->ID )->focus_keywords ? __( 'No focus keyword', 'wpmark' ) : null;
					break;
				case 'hidden_from_search':
					$detail = $source->get_meta( $post->ID )->noindex ? __( 'Set to noindex', 'wpmark' ) : null;
					break;
				case 'no_internal_links':
					$detail = isset( $links[ $post->ID ] ) && 0 === $links[ $post->ID ] ? __( 'No internal links point here', 'wpmark' ) : null;
					break;
				case 'thin_content':
					$words = Content_Reader::word_count( $post );
					/* translators: %d: number of words. */
					$detail = $words < $thin ? sprintf( _n( '%d word', '%d words', $words, 'wpmark' ), $words ) : null;
					break;
				case 'stale_content':
					$months = Content_Reader::months_since_update( $post );
					/* translators: %d: number of months. */
					$detail = $months >= $stale ? sprintf( _n( 'Not updated for %d month', 'Not updated for %d months', $months, 'wpmark' ), $months ) : null;
					break;
			}

			if ( null !== $detail ) {
				$items[] = $this->page( $post, $detail );
			}
		}

		return $items;
	}

	/**
	 * Pages whose SEO title (or post title, without an SEO plugin) is used more than once.
	 *
	 * @param WP_Post[]       $posts  Posts.
	 * @param Seo_Source|null $source Active SEO plugin.
	 * @return array<int, array>
	 */
	private function duplicate_titles( array $posts, ?Seo_Source $source ): array {
		$groups = array();

		foreach ( $posts as $post ) {
			$title = $source ? $source->get_meta( $post->ID )->title : '';
			$title = '' !== $title ? $title : get_the_title( $post );
			$key   = mb_strtolower( trim( $title ) );

			if ( '' !== $key ) {
				$groups[ $key ][] = $post;
			}
		}

		$items = array();

		foreach ( $groups as $group ) {
			if ( count( $group ) < 2 ) {
				continue;
			}

			foreach ( $group as $post ) {
				/* translators: %d: number of pages sharing the title. */
				$items[] = $this->page( $post, sprintf( __( 'Same title as %d other page(s)', 'wpmark' ), count( $group ) - 1 ) );
			}
		}

		return $items;
	}

	/**
	 * How one affected page is reported.
	 *
	 * @param WP_Post $post   Post.
	 * @param string  $detail What is wrong.
	 * @return array
	 */
	private function page( WP_Post $post, string $detail ): array {
		return array(
			'id'     => $post->ID,
			'title'  => Untrusted_Text::clean( get_the_title( $post ), 300 ),
			'url'    => (string) get_permalink( $post ),
			'detail' => $detail,
		);
	}

	/**
	 * Why each problem matters, in one line.
	 *
	 * @param string $check Check slug.
	 * @return string
	 */
	private function why( string $check ): string {
		$reasons = array(
			'missing_meta_description' => __( 'Search engines show the meta description under the page title. Without one they pick any text from the page, which usually gets fewer clicks.', 'wpmark' ),
			'missing_focus_keyword'    => __( 'Without a focus keyword, Rank Math cannot check whether the page is optimised for the search it should rank for.', 'wpmark' ),
			'hidden_from_search'       => __( 'These pages are set to noindex, so search engines will not show them. Fine for thank-you or private pages, a problem for pages meant to attract visitors.', 'wpmark' ),
			'no_internal_links'        => __( 'No other page on the site links here, so visitors and search engines struggle to find it.', 'wpmark' ),
			'thin_content'             => __( 'Very short pages rarely rank well or answer a visitor\'s question fully.', 'wpmark' ),
			'stale_content'            => __( 'Old information loses rankings and trust. Refreshing facts, dates and examples is often a quick win.', 'wpmark' ),
			'duplicate_titles'         => __( 'Pages with the same title compete with each other in search results and confuse visitors.', 'wpmark' ),
		);

		return $reasons[ $check ] ?? '';
	}
}
