<?php
/**
 * Site profile resource.
 *
 * @package WPMark
 */

namespace WPMark\Abilities;

use WPMark\Forms\Form_Sources;
use WPMark\Seo\Seo_Sources;

defined( 'ABSPATH' ) || exit;

/**
 * Background facts about the site that an AI client can attach to a chat.
 *
 * Resources are read-only reference material the person (or the AI app)
 * can pull in, rather than tools the AI calls. Marketing job 1.
 */
final class Site_Profile_Resource extends Ability {

	/**
	 * {@inheritDoc}
	 */
	public function type(): string {
		return self::RESOURCE;
	}

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'site-profile';
	}

	/**
	 * {@inheritDoc}
	 */
	public function resource_uri(): string {
		return 'wpmark://site/profile';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Site profile', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Background facts about this website: name, tagline, address, language, timezone, how much content of each type it has, its main categories, and which form and SEO plugins it uses. Attach it to a conversation so the AI knows which site it is working with. No personal data.', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $input Unused.
	 */
	protected function run( array $input ): array {
		return $this->respond(
			array(
				'site'           => Site_Overview::site_facts(),
				'content'        => Site_Overview::content_counts(),
				'top_categories' => Site_Overview::top_categories(),
				'form_plugins'   => array_values( array_map( static fn( $source ): string => $source->get_label(), Form_Sources::available() ) ),
				'seo_plugin'     => Seo_Sources::active()?->get_label(),
			)
		);
	}
}
