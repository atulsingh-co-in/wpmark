<?php
/**
 * Forms that can be placed on a page.
 *
 * @package WPMark
 */

namespace WPMark\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the site's existing forms that a new page can embed, and how.
 *
 * WPMark never creates forms: a landing page reuses a form the site already
 * has, so submissions keep going wherever they go today (email, CRM,
 * Flamingo…). Contact Form 7, WPForms and Gravity Forms are embedded by
 * shortcode, which works in the block editor and in Elementor alike.
 * Elementor Pro forms live inside a page and are reused by copying a
 * section that contains one.
 */
final class Form_Embeds {

	/**
	 * Most forms listed per plugin.
	 */
	private const LIMIT = 50;

	/**
	 * Every embeddable form on the site.
	 *
	 * @return array<int, array{plugin: string, form: string, title: string, shortcode: string}>
	 */
	public static function all(): array {
		$forms = array();

		if ( defined( 'WPCF7_VERSION' ) ) {
			foreach ( self::posts( 'wpcf7_contact_form' ) as $post ) {
				$forms[] = self::form( 'contact-form-7', (string) $post->ID, $post->post_title );
			}
		}

		if ( function_exists( 'wpforms' ) ) {
			foreach ( self::posts( 'wpforms' ) as $post ) {
				$forms[] = self::form( 'wpforms', (string) $post->ID, $post->post_title );
			}
		}

		if ( class_exists( 'GFAPI' ) ) {
			foreach ( array_slice( (array) \GFAPI::get_forms(), 0, self::LIMIT ) as $form ) {
				$forms[] = self::form( 'gravity-forms', (string) ( $form['id'] ?? '' ), (string) ( $form['title'] ?? '' ) );
			}
		}

		return $forms;
	}

	/**
	 * Find one embeddable form.
	 *
	 * @param string $plugin Plugin ID, e.g. "wpforms".
	 * @param string $form   Form ID.
	 * @return array|null
	 */
	public static function find( string $plugin, string $form ): ?array {
		foreach ( self::all() as $candidate ) {
			if ( $candidate['plugin'] === $plugin && $candidate['form'] === $form ) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Recognise a form shortcode in some content.
	 *
	 * @param string $content Shortcode text, e.g. '[wpforms id="12"]'.
	 * @return array{plugin: string, form: string}|null
	 */
	public static function from_shortcode( string $content ): ?array {
		$tags = array(
			'contact-form-7' => 'contact-form-7',
			'wpforms'        => 'wpforms',
			'gravityform'    => 'gravity-forms',
		);

		foreach ( $tags as $tag => $plugin ) {
			if ( preg_match( '/\[' . preg_quote( $tag, '/' ) . '\b([^\]]*)\]/', $content, $match ) ) {
				$attributes = shortcode_parse_atts( $match[1] );

				return array(
					'plugin' => $plugin,
					'form'   => is_array( $attributes ) ? (string) ( $attributes['id'] ?? '' ) : '',
				);
			}
		}

		return null;
	}

	/**
	 * One form, with the shortcode that shows it.
	 *
	 * @param string $plugin Plugin ID.
	 * @param string $id     Form ID.
	 * @param string $title  Form name.
	 * @return array
	 */
	private static function form( string $plugin, string $id, string $title ): array {
		$shortcodes = array(
			'contact-form-7' => '[contact-form-7 id="%s"]',
			'wpforms'        => '[wpforms id="%s"]',
			'gravity-forms'  => '[gravityform id="%s" title="false"]',
		);

		return array(
			'plugin'    => $plugin,
			'form'      => $id,
			'title'     => Outline::text( $title ),
			'shortcode' => sprintf( $shortcodes[ $plugin ], absint( $id ) ),
		);
	}

	/**
	 * Published forms of one post type.
	 *
	 * @param string $post_type Post type.
	 * @return \WP_Post[]
	 */
	private static function posts( string $post_type ): array {
		return get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => self::LIMIT,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
	}
}
