<?php
/**
 * Content plan prompt.
 *
 * @package WPMark
 */

namespace WPMark\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Playbook: turn real customer questions into a content plan.
 *
 * Marketing job 3 (find content gaps).
 */
final class Content_Plan_Prompt extends Prompt {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'content-plan';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Content plan from customer questions', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Compare what customers ask in your forms with what the site already covers, and get a prioritised list of new posts and page updates.', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_properties(): array {
		return array(
			'days'  => array(
				'type'        => 'string',
				'description' => __( 'How many days of enquiries to learn from. Default 90.', 'wpmark' ),
			),
			'topic' => array(
				'type'        => 'string',
				'description' => __( 'Optional: focus on one product, service or topic.', 'wpmark' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $input Validated input.
	 */
	protected function instructions( array $input ): string {
		$days  = $this->days( $input, 'days', 90, 365 );
		$topic = trim( sanitize_text_field( (string) ( $input['topic'] ?? '' ) ) );
		$focus = '' !== $topic
			/* translators: %s: topic. */
			? sprintf( __( 'Focus only on questions about: %s.', 'wpmark' ), $topic )
			: __( 'Cover all topics.', 'wpmark' );

		return sprintf(
			/* translators: 1: number of days, 2: focus sentence. */
			__(
				'Build a content plan for this website from real customer questions. %2$s

1. Call wpmark-site-overview to understand the site.
2. Call wpmark-list-leads for the last %1$d days and collect the questions and needs people describe. If the site has no saved leads, say so and use its existing content and categories instead.
3. For each recurring question, call wpmark-search-content to check whether the site already answers it well.
4. Deliver a table of 5 to 10 recommendations, most valuable first, each with: the customer question, whether it is a new post or an update to an existing page (with its link), a working title, the main points to cover, and why it matters (how often it was asked).
5. End with the 3 you would start with this month.',
				'wpmark'
			),
			$days,
			$focus
		);
	}
}
