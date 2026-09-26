<?php
/**
 * SEO health check prompt.
 *
 * @package WPMark
 */

namespace WPMark\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Playbook: what should we fix first for SEO?
 *
 * Marketing jobs 4 (audit SEO) and 6 (refresh old content).
 */
final class Seo_Check_Prompt extends Prompt {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'seo-check';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'SEO health check', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Audit the site\'s SEO basics and get a short, prioritised fix list, including old pages worth refreshing.', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $input Unused: this playbook has no options.
	 */
	protected function instructions( array $input ): string {
		unset( $input );

		return __(
			'Run an SEO health check on this website.

1. Call wpmark-site-overview to understand the site and whether an SEO plugin is active.
2. Call wpmark-find-seo-gaps to find problems across published content.
3. Call wpmark-content-inventory (sorted least_recently_updated) to find important pages that are getting old.
4. Where useful, read a page with wpmark-get-content to make a specific suggestion (for example a draft meta description).
5. Deliver:
   - A 3-sentence summary of the site\'s SEO health.
   - The top 10 fixes, most impact for least effort first. For each: the page (title and link), the problem, and exactly what to do.
   - Checks that were skipped and what would enable them.
   Suggest wording where it helps, but remember WPMark cannot change the site: the team makes the edits in WordPress.',
			'wpmark'
		);
	}
}
