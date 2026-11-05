<?php
/**
 * Weekly marketing report prompt.
 *
 * @package WPMark
 */

namespace WPMark\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Playbook: one short report for the team on leads, campaigns, content,
 * SEO and AI visibility, with next week's priorities.
 *
 * Marketing jobs 1 to 4, in one place.
 */
final class Weekly_Report_Prompt extends Prompt {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'weekly-report';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Weekly marketing report', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'A short report for the team: leads and campaigns, content published and waiting, SEO issues and AI visibility, with three priorities for next week.', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_properties(): array {
		return array(
			'days' => array(
				'type'        => 'string',
				'description' => __( 'How many days the report covers. Default 7.', 'wpmark' ),
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
			/* translators: 1: number of days, 2: twice the number of days. */
			__(
				'Write this website\'s marketing report for the last %1$d days.

1. Call wpmark-site-overview.
2. If lead tools are available: call wpmark-lead-summary for the last %2$d days, so you can compare this period with the one before it (by week, landing page, campaign and form).
3. Call wpmark-content-inventory sorted "newest" to see what was published in the period, and wpmark-list-drafts (if available) for drafts waiting for review.
4. Call wpmark-find-seo-gaps and wpmark-check-links for the most important problems.
5. Call wpmark-agent-readiness to see whether AI search can read the site.
6. Deliver, in this order and in under one page:
   - Headline numbers: leads this period and the change from the period before; content published; drafts waiting.
   - Leads and campaigns: the landing pages and campaigns that brought the most enquiries, and any that dropped.
   - Content: what was published, and what is waiting for review.
   - SEO and links: the 3 problems most worth fixing, with the page and the fix.
   - AI visibility: one line on whether AI search and assistants can read the site.
   - Next week: three concrete priorities, each with who would do it and why.
   Skip any section whose tools are not available, and say so in one line.',
				'wpmark'
			),
			$days,
			$days * 2
		);
	}
}
