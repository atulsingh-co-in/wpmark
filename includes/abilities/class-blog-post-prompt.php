<?php
/**
 * Blog post writer prompt.
 *
 * @package WPMark
 */

namespace WPMark\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Playbook: research, plan and write an SEO-ready blog post in the site's
 * voice, saved as a draft when drafts are allowed.
 *
 * Marketing jobs 3 (find content gaps) and 5 (draft content).
 */
final class Blog_Post_Prompt extends Prompt {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'blog-post';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Write a blog post', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Plan and write an SEO-ready blog post in your site\'s voice, with internal links, an FAQ and a meta description, and save it as a draft.', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_properties(): array {
		return array(
			'topic'    => array(
				'type'        => 'string',
				'description' => __( 'What the post is about.', 'wpmark' ),
			),
			'keyword'  => array(
				'type'        => 'string',
				'description' => __( 'Optional: the search phrase it should rank for.', 'wpmark' ),
			),
			'audience' => array(
				'type'        => 'string',
				'description' => __( 'Optional: who it is for.', 'wpmark' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $input Validated input.
	 */
	protected function instructions( array $input ): string {
		$topic    = trim( sanitize_text_field( (string) ( $input['topic'] ?? '' ) ) );
		$keyword  = trim( sanitize_text_field( (string) ( $input['keyword'] ?? '' ) ) );
		$audience = trim( sanitize_text_field( (string) ( $input['audience'] ?? '' ) ) );

		$brief = implode(
			' ',
			array_filter(
				array(
					'' !== $topic ? sprintf( /* translators: %s: topic. */ __( 'Topic: %s.', 'wpmark' ), $topic ) : __( 'First ask what the post should be about.', 'wpmark' ),
					'' !== $keyword ? sprintf( /* translators: %s: search phrase. */ __( 'Search phrase to rank for: %s.', 'wpmark' ), $keyword ) : '',
					'' !== $audience ? sprintf( /* translators: %s: audience. */ __( 'Audience: %s.', 'wpmark' ), $audience ) : '',
				)
			)
		);

		return sprintf(
			/* translators: %s: the brief. */
			__(
				'Write a blog post for this website. %s

1. Call wpmark-site-overview to learn what the site is about and who it serves.
2. Call wpmark-search-content for the topic: check the site has no post on it already (if it does, suggest updating that one instead), and note 3 to 5 related pages and posts to link to.
3. Read one or two recent posts with wpmark-get-content to match the site\'s tone, length and formatting.
4. If lead tools are available, call wpmark-list-leads (last 90 days) to find questions real customers ask about this topic, and answer them in the post.
5. Propose: a title (under 60 characters, with the search phrase near the start), the angle, the outline (H2 headings), an FAQ of 3 to 5 questions, and the internal links. Wait for my go-ahead.
6. Write the post: a short introduction that answers the question quickly, scannable sections, concrete examples, the FAQ at the end, and a closing call to action pointing to a relevant page. About 900 to 1,500 words unless I ask otherwise.
7. If wpmark-create-draft is available: save it as a post ("type": "post") with "compose" sections (headings, text, lists, an "faq" item) or block markup in "blocks", plus "excerpt", "seo" (title, meta description under 155 characters, focus keyword) and a "featured_image_id" from wpmark-list-media if a good image exists. Give me the preview link, and suggest categories and tags to set in WordPress. If it is not available, give me the finished post in the chat, ready to paste, with the SEO title and meta description.',
				'wpmark'
			),
			$brief
		);
	}
}
