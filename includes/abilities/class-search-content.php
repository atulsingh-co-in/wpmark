<?php
/**
 * Search content tool.
 *
 * @package WPMark
 */

namespace WPMark\Abilities;

use WP_Query;
use WPMark\Content\Content_Reader;

defined( 'ABSPATH' ) || exit;

/**
 * Finds posts and pages about a topic.
 *
 * Marketing jobs 1 (understand the site) and 3 (find content gaps).
 */
final class Search_Content extends Ability {

	private const MAX_LIMIT = 20;

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'search-content';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Search content', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function summary(): string {
		return __( 'Finds posts and pages about a topic, so the AI can check what the site already says.', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Searches this website\'s posts and pages for words or a phrase, like the search box on the site. Returns matching items with title, link, type, dates, word count, categories and a short excerpt, best matches first, up to 20 at a time (use offset for more). Use it to check whether the site already covers a topic, to find pages to update or link to, or before suggesting new content. Searches published content unless you set status (drafts, pending and scheduled items are only visible to people allowed to edit them). To read a whole page, pass its id to wpmark-get-content. Read-only.', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_properties(): array {
		return array(
			'query'  => array(
				'type'        => 'string',
				'description' => __( 'Words or phrase to search for.', 'wpmark' ),
				'minLength'   => 2,
				'maxLength'   => 200,
			),
			'type'   => array(
				'type'        => 'string',
				'description' => __( 'Content type: "post", "page", another public type, or "any" (default).', 'wpmark' ),
			),
			'status' => array(
				'type'        => 'string',
				'enum'        => Content_Reader::STATUSES,
				'description' => __( 'Which content to search. Default "publish" (live on the site).', 'wpmark' ),
			),
			'limit'  => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => self::MAX_LIMIT,
				'description' => __( 'How many results to return. Default 10.', 'wpmark' ),
			),
			'offset' => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'How many results to skip, for the next page. Default 0.', 'wpmark' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function required_input(): array {
		return array( 'query' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $input Validated input.
	 */
	protected function run( array $input ): array {
		$limit  = $this->int_input( $input, 'limit', 10, 1, self::MAX_LIMIT );
		$offset = $this->int_input( $input, 'offset', 0, 0, 10000 );
		$args   = Content_Reader::query_args( (string) ( $input['type'] ?? 'any' ), (string) ( $input['status'] ?? 'publish' ) );

		$query = new WP_Query(
			array_merge(
				$args,
				array(
					's'              => trim( (string) $input['query'] ),
					'posts_per_page' => $limit,
					'offset'         => $offset,
				)
			)
		);

		$results = array();

		foreach ( $query->posts as $post ) {
			if ( Content_Reader::can_read( $post ) ) {
				$results[] = array_merge( Content_Reader::summary( $post ), array( 'excerpt' => Content_Reader::excerpt( $post ) ) );
			}
		}

		$total = (int) $query->found_posts;

		return $this->respond(
			array(
				'total'       => $total,
				'results'     => $results,
				'next_offset' => $offset + $limit < $total ? $offset + $limit : null,
			)
		);
	}
}
