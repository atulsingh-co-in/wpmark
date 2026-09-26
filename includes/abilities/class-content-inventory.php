<?php
/**
 * Content inventory tool.
 *
 * @package WPMark
 */

namespace WPMark\Abilities;

use WP_Query;
use WPMark\Content\Content_Reader;
use WPMark\Output\Untrusted_Text;
use WPMark\Seo\Seo_Sources;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the site's content with the facts needed to plan updates.
 *
 * Marketing jobs 1 (understand the site), 4 (audit SEO) and 6 (refresh
 * old content).
 */
final class Content_Inventory extends Ability {

	private const MAX_LIMIT = 100;

	/**
	 * Sort orders, in plain words, mapped to WP_Query.
	 */
	private const SORTS = array(
		'least_recently_updated' => array( 'modified', 'ASC' ),
		'most_recently_updated'  => array( 'modified', 'DESC' ),
		'newest'                 => array( 'date', 'DESC' ),
		'oldest'                 => array( 'date', 'ASC' ),
		'title'                  => array( 'title', 'ASC' ),
	);

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'content-inventory';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Content inventory', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function summary(): string {
		return __( 'Lists all posts and pages with word counts, age and SEO basics, to plan updates.', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Lists this website\'s content as an inventory, up to 100 items at a time (use offset for more). For each item: id, title, link, type, dates, months since it was last updated, word count, categories, and (when Rank Math is active) whether it has a meta description and its focus keyword. By default lists published posts and pages, least recently updated first, which is ideal for finding content to refresh. Filter by type, category or age (not_updated_in_months), and change the order with sort. Use it for content audits, refresh plans and reports on what exists. For specific SEO problems, wpmark-find-seo-gaps is faster. Read-only.', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_properties(): array {
		return array(
			'type'                  => array(
				'type'        => 'string',
				'description' => __( 'Content type: "post", "page", another public type, or "any" (default).', 'wpmark' ),
			),
			'status'                => array(
				'type'        => 'string',
				'enum'        => Content_Reader::STATUSES,
				'description' => __( 'Which content to list. Default "publish" (live on the site).', 'wpmark' ),
			),
			'category'              => array(
				'type'        => 'string',
				'maxLength'   => 200,
				'description' => __( 'Only items in this category (its name or slug).', 'wpmark' ),
			),
			'not_updated_in_months' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => 240,
				'description' => __( 'Only items not updated for at least this many months.', 'wpmark' ),
			),
			'sort'                  => array(
				'type'        => 'string',
				'enum'        => array_keys( self::SORTS ),
				'description' => __( 'Order of results. Default "least_recently_updated".', 'wpmark' ),
			),
			'limit'                 => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => self::MAX_LIMIT,
				'description' => __( 'How many items to return. Default 50.', 'wpmark' ),
			),
			'offset'                => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'How many items to skip, for the next page. Default 0.', 'wpmark' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $input Validated input.
	 * @throws Invalid_Input When the category is unknown.
	 */
	protected function run( array $input ): array {
		$limit  = $this->int_input( $input, 'limit', 50, 1, self::MAX_LIMIT );
		$offset = $this->int_input( $input, 'offset', 0, 0, 100000 );
		$sort   = self::SORTS[ (string) ( $input['sort'] ?? '' ) ] ?? self::SORTS['least_recently_updated'];
		$args   = Content_Reader::query_args( (string) ( $input['type'] ?? 'any' ), (string) ( $input['status'] ?? 'publish' ) );

		$args = array_merge(
			$args,
			array(
				'posts_per_page' => $limit,
				'offset'         => $offset,
				'orderby'        => $sort[0],
				'order'          => $sort[1],
			)
		);

		if ( ! empty( $input['category'] ) ) {
			$category = get_term_by( 'slug', sanitize_title( (string) $input['category'] ), 'category' );

			if ( ! $category ) {
				$category = get_term_by( 'name', (string) $input['category'], 'category' );
			}

			if ( ! $category ) {
				throw new Invalid_Input( __( 'No category with that name was found. wpmark-site-overview lists the main categories.', 'wpmark' ) );
			}

			$args['cat'] = $category->term_id;
		}

		if ( ! empty( $input['not_updated_in_months'] ) ) {
			$args['date_query'] = array(
				array(
					'column' => 'post_modified_gmt',
					'before' => gmdate( 'Y-m-d H:i:s', time() - (int) $input['not_updated_in_months'] * 30 * DAY_IN_SECONDS ),
				),
			);
		}

		$query  = new WP_Query( $args );
		$source = Seo_Sources::active();
		$items  = array();

		foreach ( $query->posts as $post ) {
			if ( ! Content_Reader::can_read( $post ) ) {
				continue;
			}

			$item = array_merge(
				Content_Reader::summary( $post ),
				array( 'months_since_update' => Content_Reader::months_since_update( $post ) )
			);

			if ( $source ) {
				$meta        = $source->get_meta( $post->ID );
				$item['seo'] = array(
					'has_meta_description' => '' !== $meta->description,
					'focus_keyword'        => $meta->focus_keywords ? Untrusted_Text::clean( $meta->focus_keywords[0], 100 ) : null,
					'hidden_from_search'   => $meta->noindex,
				);
			}

			$items[] = $item;
		}

		$total = (int) $query->found_posts;

		return $this->respond(
			array(
				'total'       => $total,
				'seo_plugin'  => $source?->get_label(),
				'items'       => $items,
				'next_offset' => $offset + $limit < $total ? $offset + $limit : null,
			)
		);
	}
}
