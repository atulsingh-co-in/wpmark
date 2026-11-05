<?php
/**
 * Landing page prompt.
 *
 * @package WPMark
 */

namespace WPMark\Abilities;

use WPMark\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Playbook: plan and draft a landing page in the site's own style.
 *
 * Marketing job 5 (draft content).
 */
final class Landing_Page_Prompt extends Prompt {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'landing-page';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Build a landing page', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Plan a landing page for a product, service, offer or event with the AI, then save it as a draft in your site\'s own style for you to review and publish.', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_drafting(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_properties(): array {
		return array(
			'offer'    => array(
				'type'        => 'string',
				'description' => __( 'What the page is for: the product, service, offer or event.', 'wpmark' ),
			),
			'audience' => array(
				'type'        => 'string',
				'description' => __( 'Optional: who it is for.', 'wpmark' ),
			),
			'goal'     => array(
				'type'        => 'string',
				'description' => __( 'Optional: what visitors should do, e.g. book a demo, register, buy.', 'wpmark' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $input Validated input.
	 */
	protected function instructions( array $input ): string {
		$offer    = trim( sanitize_text_field( (string) ( $input['offer'] ?? '' ) ) );
		$audience = trim( sanitize_text_field( (string) ( $input['audience'] ?? '' ) ) );
		$goal     = trim( sanitize_text_field( (string) ( $input['goal'] ?? '' ) ) );

		$brief = implode(
			' ',
			array_filter(
				array(
					'' !== $offer ? sprintf( /* translators: %s: offer. */ __( 'The page is for: %s.', 'wpmark' ), $offer ) : __( 'First ask what the page is for.', 'wpmark' ),
					'' !== $audience ? sprintf( /* translators: %s: audience. */ __( 'Audience: %s.', 'wpmark' ), $audience ) : '',
					'' !== $goal ? sprintf( /* translators: %s: goal. */ __( 'Goal: %s.', 'wpmark' ), $goal ) : '',
				)
			)
		);

		if ( ! Settings::custom_design_enabled() ) {
			return sprintf(
				/* translators: %s: the brief. */
				__(
					'Help me build a landing page on this website and save it as a draft. %s

1. Call wpmark-site-overview and wpmark-design-guide to learn the site, its page builder, colours, fonts, page templates and forms.
2. Ask me anything you need: the offer, audience, main benefit, proof (numbers, quotes), the call to action, and the date or price if any. Keep it to a few questions.
3. Call wpmark-find-templates and wpmark-page-structure to find a saved template or an existing page with a similar purpose. Propose an outline: which sections, in which order, and what each says. Wait for my go-ahead.
4. Write the copy in the site\'s tone (read a page or two with wpmark-get-content if needed). Use wpmark-list-media for images and an existing form for sign-ups.
5. Call wpmark-create-draft, copying sections from the chosen template with "from" and "fills" (or a whole page with "copy_of") so the design stays the site\'s own. Add an SEO title and meta description.
6. Give me the preview link and a short summary, including any notes. Make the changes I ask for with wpmark-update-draft. Remind me that I publish the page myself in WordPress.',
					'wpmark'
				),
				$brief
			);
		}

		return sprintf(
			/* translators: %s: the brief. */
			__(
				'Help me build a landing page on this website and save it as a draft. %s

1. Call wpmark-site-overview and wpmark-design-guide to learn the site, its page builder, colours, fonts, page templates and forms.
2. Ask me anything you need: the offer, audience, main benefit, proof (numbers, quotes), the call to action, and the date or price if any. Keep it to a few questions.
3. Call wpmark-find-templates and wpmark-page-structure to see whether the site already has a good landing page or template with a similar purpose. Then suggest one of two ways, and say why: (a) copy that page or template and change its text, so it matches the site exactly; or (b) a custom design, written in HTML and CSS, for a more polished page than the site\'s templates allow. Ask whether it should keep the site\'s header and footer ("site") or stand alone ("blank"). Propose an outline: which sections, in which order, and what each says. Wait for my go-ahead.
4. Write the copy in the site\'s tone (read a page or two with wpmark-get-content if needed). Use wpmark-list-media for images and an existing form for sign-ups.
5. Call wpmark-create-draft. For (a): copy sections with "from" and "fills". For (b): send "html", "css" and "layout". Design it like a professional web designer: a strong hero (headline, one-line promise, one main button), benefit cards, social proof, an FAQ in <details>, and a closing call to action; generous white space; one consistent type scale; every colour and font from the css_variables in wpmark-design-guide; the inner width from content_width; responsive @media rules so it works on phones. Add an SEO title and meta description.
6. Read the notes in the result and fix any design notes with wpmark-update-draft before showing me. Then give me the preview link and a short summary. Make the changes I ask for with wpmark-update-draft. Remind me that I publish the page myself in WordPress.',
				'wpmark'
			),
			$brief
		);
	}
}
