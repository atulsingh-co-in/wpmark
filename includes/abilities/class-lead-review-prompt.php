<?php
/**
 * Weekly lead review prompt.
 *
 * @package WPMark
 */

namespace WPMark\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Playbook: what did people ask us this week, and who should we call first?
 *
 * Marketing job 2 (review incoming leads).
 */
final class Lead_Review_Prompt extends Prompt {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'lead-review';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Weekly lead review', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Review recent enquiries: the main themes people asked about, which pages and campaigns brought them, and the most promising leads to follow up first.', 'wpmark' );
	}

	/**
	 * Only offered when the site has a form plugin.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return (bool) \WPMark\Forms\Form_Sources::available();
	}

	/**
	 * {@inheritDoc}
	 */
	public function unavailable_reason(): string {
		return __( 'No supported form plugin is active on this site.', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_properties(): array {
		return array(
			'days' => array(
				'type'        => 'string',
				'description' => __( 'How many days back to review. Default 7.', 'wpmark' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $input Validated input.
	 */
	protected function instructions( array $input ): string {
		$days = $this->days( $input, 'days', 7, 90 );

		return sprintf(
			/* translators: %d: number of days. */
			__(
				'Review the enquiries this website received in the last %d days.

1. Call wpmark-lead-summary for the period to see totals by form, landing page, campaign and week.
2. Call wpmark-list-leads for the same period (page through with offset if needed) to read what people asked.
3. Report:
   - A one-paragraph summary with the total and how it compares week to week.
   - The 3 to 5 main themes people asked about, each with a count and a short example in the visitor\'s words.
   - The pages and campaigns that brought the most leads.
   - The 5 most promising leads to follow up first, with one line each on why (budget, urgency, fit). Refer to them by form and date.
   - Questions that came up repeatedly but have no good answer on the site yet (check with wpmark-search-content), as ideas for new content.',
				'wpmark'
			),
			$days
		);
	}
}
