<?php
/**
 * Site overview tool.
 *
 * @package WPMark
 */

namespace WPMark\Abilities;

use WPMark\Content\Content_Reader;
use WPMark\Forms\Entry_Query;
use WPMark\Forms\Form_Sources;
use WPMark\Output\Untrusted_Text;
use WPMark\Privacy\Lead_Access;
use WPMark\Seo\Seo_Sources;
use WPMark\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The first thing an AI should call: what this site is and what it has.
 *
 * Marketing job 1: understand the site.
 */
final class Site_Overview extends Ability {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'site-overview';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Site overview', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function summary(): string {
		return __( 'What the site is about, how much content it has, which form and SEO plugins were found, and recent lead counts per form.', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Start here. Returns a snapshot of this WordPress website: its name, tagline, language and timezone; how many posts, pages and other content items are published and when content was last updated; the main categories; which form plugins and SEO plugin were found; each form with how many leads it received in the last 30 days (counts only, and only if you may see leads); and which WPMark tools are available on this site. Use it at the start of a conversation to understand the site before answering questions about content, SEO or leads. It contains no personal data. Read-only.', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $input Unused.
	 */
	protected function run( array $input ): array {
		return $this->respond(
			array(
				'site'            => self::site_facts(),
				'content'         => self::content_counts(),
				'top_categories'  => self::top_categories(),
				'seo_plugin'      => Seo_Sources::active()?->get_label(),
				'forms'           => $this->forms(),
				'available_tools' => $this->available_tools(),
			)
		);
	}

	/**
	 * Name, tagline, address, language and timezone.
	 *
	 * @return array
	 */
	public static function site_facts(): array {
		return array(
			'name'     => Untrusted_Text::clean( get_bloginfo( 'name' ), 200 ),
			'tagline'  => Untrusted_Text::clean( get_bloginfo( 'description' ), 300 ),
			'url'      => home_url( '/' ),
			'language' => get_bloginfo( 'language' ),
			'timezone' => wp_timezone_string(),
		);
	}

	/**
	 * Published, draft and scheduled counts per content type.
	 *
	 * @return array<int, array>
	 */
	public static function content_counts(): array {
		$counts = array();

		foreach ( Content_Reader::types() as $slug => $label ) {
			$count = wp_count_posts( $slug );

			if ( ! (int) $count->publish && ! (int) $count->draft && ! (int) $count->future ) {
				continue;
			}

			$latest = get_posts(
				array(
					'post_type'      => $slug,
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'orderby'        => 'modified',
					'order'          => 'DESC',
					'no_found_rows'  => true,
				)
			);

			$counts[] = array(
				'type'         => $slug,
				'label'        => $label,
				'published'    => (int) $count->publish,
				'drafts'       => (int) $count->draft,
				'scheduled'    => (int) $count->future,
				'last_updated' => $latest ? Content_Reader::iso_date( $latest[0]->post_modified_gmt ) : null,
			);
		}

		return $counts;
	}

	/**
	 * The ten categories with the most posts.
	 *
	 * @return array<int, array{name: string, posts: int}>
	 */
	public static function top_categories(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'category',
				'orderby'    => 'count',
				'order'      => 'DESC',
				'number'     => 10,
				'hide_empty' => true,
			)
		);

		if ( ! is_array( $terms ) ) {
			return array();
		}

		return array_map(
			static fn( $term ): array => array(
				'name'  => Untrusted_Text::clean( $term->name, 100 ),
				'posts' => (int) $term->count,
			),
			$terms
		);
	}

	/**
	 * Form plugins, their forms, and 30-day lead counts when allowed.
	 *
	 * @return array
	 */
	private function forms(): array {
		$may_count = Lead_Access::None !== Settings::lead_access_for( wp_get_current_user() );
		$since     = new \DateTimeImmutable( '-30 days', new \DateTimeZone( 'UTC' ) );
		$sources   = array();
		$forms     = array();

		foreach ( Form_Sources::available() as $source ) {
			$sources[] = array(
				'source'      => $source->get_id(),
				'name'        => $source->get_label(),
				'saves_leads' => $source->supports_entries(),
			);

			foreach ( $source->list_forms() as $form ) {
				$forms[] = array(
					'source'             => $source->get_id(),
					'form_id'            => $form->id,
					'title'              => Untrusted_Text::clean( $form->title, 200 ),
					'leads_last_30_days' => $may_count && $source->supports_entries()
						? $source->count_entries( new Entry_Query( form_id: $form->id, since: $since ) )
						: null,
				);
			}
		}

		return array(
			'plugins'             => $sources,
			'forms'               => $forms,
			'lead_counts_visible' => $may_count,
		);
	}

	/**
	 * Which WPMark tools this site offers, so the AI knows what it can ask.
	 *
	 * @return array<int, array{tool: string, purpose: string}>
	 */
	private function available_tools(): array {
		$tools = array();

		foreach ( Abilities::offered( self::TOOL ) as $ability ) {
			$tools[] = array(
				'tool'    => str_replace( '/', '-', $ability->name() ),
				'purpose' => $ability->summary(),
			);
		}

		return $tools;
	}
}
