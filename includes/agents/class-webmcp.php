<?php
/**
 * WebMCP: forms and search for AI agents in the browser.
 *
 * @package WPMark
 */

namespace WPMark\Agents;

use WP_HTML_Tag_Processor;
use WPMark\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Describes the site's contact forms and search box for AI agents that
 * run in a visitor's browser, using WebMCP's declarative form attributes
 * (an early proposal by Google and Microsoft, in trial in Chrome and Edge):
 *
 * - toolname and tooldescription on the form say what it does;
 * - toolparamdescription on each field says what to type in it.
 *
 * An agent can then fill in a form correctly instead of guessing at the
 * page. WPMark never adds "toolautosubmit": the agent fills the form and
 * the visitor reviews it and presses Send, as before.
 *
 * Only plain HTML attributes are added: no script, no tracking, nothing
 * sent anywhere. Browsers and agents that do not know WebMCP ignore them.
 * Off by default, because the standard may still change.
 */
final class WebMCP {

	/**
	 * Fields never described: hidden, buttons, and spam traps.
	 */
	private const SKIP_TYPES = array( 'hidden', 'submit', 'button', 'reset', 'image', 'file', 'password' );

	/**
	 * Tool names used on this page, to keep each one unique.
	 *
	 * @var array<string, true>
	 */
	private static array $names = array();

	/**
	 * Add the front-end filters, when switched on.
	 */
	public static function register(): void {
		if ( ! Settings::webmcp_enabled() || is_admin() ) {
			return;
		}

		add_filter( 'get_search_form', array( self::class, 'search_form' ), 20 );
		add_filter( 'render_block_core/search', array( self::class, 'search_form' ), 20 );
		add_filter( 'do_shortcode_tag', array( self::class, 'shortcode_form' ), 20, 3 );
		add_filter( 'render_block', array( self::class, 'block_form' ), 20, 2 );
		add_filter( 'elementor/widget/render_content', array( self::class, 'elementor_form' ), 20, 2 );
	}

	/**
	 * The site search box.
	 *
	 * @param mixed $html Search form HTML.
	 * @return mixed
	 */
	public static function search_form( $html ) {
		if ( ! is_string( $html ) ) {
			return $html;
		}

		return self::describe(
			$html,
			'search-site',
			sprintf(
				/* translators: %s: Site name. */
				__( 'Search the pages and posts of %s. Opens the search results page.', 'wpmark' ),
				self::site()
			),
			array( 's' => __( 'Words to search for.', 'wpmark' ) )
		);
	}

	/**
	 * Contact Form 7, WPForms and Gravity Forms forms shown by shortcode.
	 *
	 * @param mixed  $html Shortcode output.
	 * @param string $tag  Shortcode name.
	 * @param mixed  $attr Shortcode attributes.
	 * @return mixed
	 */
	public static function shortcode_form( $html, $tag, $attr ) {
		$plugins = array(
			'contact-form-7' => 'Contact Form 7',
			'wpforms'        => 'WPForms',
			'gravityform'    => 'Gravity Forms',
		);

		if ( ! is_string( $html ) || ! isset( $plugins[ $tag ] ) ) {
			return $html;
		}

		$attr  = is_array( $attr ) ? $attr : array();
		$title = (string) ( $attr['title'] ?? '' );

		if ( ( '' === $title || 'false' === $title ) && is_numeric( $attr['id'] ?? '' ) ) {
			$title = get_the_title( (int) $attr['id'] );
		}

		return self::form( $html, $title );
	}

	/**
	 * Forms placed with the WPForms or Contact Form 7 blocks.
	 *
	 * @param mixed $html  Block output.
	 * @param mixed $block Block.
	 * @return mixed
	 */
	public static function block_form( $html, $block ) {
		$name = is_array( $block ) ? (string) ( $block['blockName'] ?? '' ) : '';

		if ( ! is_string( $html ) || ! in_array( $name, array( 'wpforms/form-selector', 'contact-form-7/contact-form-selector' ), true ) ) {
			return $html;
		}

		$id = (int) ( $block['attrs']['formId'] ?? $block['attrs']['id'] ?? 0 );

		return self::form( $html, $id ? get_the_title( $id ) : '' );
	}

	/**
	 * Elementor Pro forms.
	 *
	 * @param mixed $html   Widget output.
	 * @param mixed $widget Widget.
	 * @return mixed
	 */
	public static function elementor_form( $html, $widget ) {
		if ( ! is_string( $html ) || ! is_object( $widget ) || ! method_exists( $widget, 'get_name' ) || 'form' !== $widget->get_name() ) {
			return $html;
		}

		$title = method_exists( $widget, 'get_settings_for_display' ) ? (string) $widget->get_settings_for_display( 'form_name' ) : '';

		return self::form( $html, $title );
	}

	/**
	 * Describe a contact form.
	 *
	 * @param string $html  Form HTML.
	 * @param string $title The form's name, or "".
	 * @return string
	 */
	private static function form( string $html, string $title ): string {
		$title = trim( wp_strip_all_tags( $title ) );

		$description = '' !== $title
			/* translators: 1: Site name, 2: Form name. */
			? sprintf( __( 'Send a message to %1$s with its "%2$s" form. Fill in the fields; the visitor reviews the message and sends it.', 'wpmark' ), self::site(), $title )
			/* translators: %s: Site name. */
			: sprintf( __( 'Send a message to %s. Fill in the fields; the visitor reviews the message and sends it.', 'wpmark' ), self::site() );

		return self::describe( $html, self::name( '' !== $title ? $title : 'contact' ), $description, array() );
	}

	/**
	 * Add the WebMCP attributes to the first form in some HTML and its fields.
	 *
	 * @param string                $html         HTML containing a form.
	 * @param string                $name         Tool name.
	 * @param string                $description  What the form does.
	 * @param array<string, string> $descriptions Field name => description, for known fields.
	 * @return string
	 */
	private static function describe( string $html, string $name, string $description, array $descriptions ): string {
		$tags = new WP_HTML_Tag_Processor( $html );

		if ( ! $tags->next_tag( 'form' ) || null !== $tags->get_attribute( 'toolname' ) ) {
			return $html;
		}

		$tags->set_attribute( 'toolname', $name );
		$tags->set_attribute( 'tooldescription', $description );

		while ( $tags->next_tag() ) {
			$tag = $tags->get_tag();

			if ( 'FORM' === $tag ) {
				break;
			}

			if ( ! in_array( $tag, array( 'INPUT', 'TEXTAREA', 'SELECT' ), true ) ) {
				continue;
			}

			$field = (string) $tags->get_attribute( 'name' );
			$type  = strtolower( (string) $tags->get_attribute( 'type' ) );

			if ( '' === $field || in_array( $type, self::SKIP_TYPES, true ) || self::is_trap( $tags, $field ) || null !== $tags->get_attribute( 'toolparamdescription' ) ) {
				continue;
			}

			$tags->set_attribute( 'toolparamdescription', $descriptions[ $field ] ?? self::field_description( $tags, $field ) );
		}

		return $tags->get_updated_html();
	}

	/**
	 * What to type in a field: its accessible label, placeholder or name.
	 *
	 * @param WP_HTML_Tag_Processor $tags  At the field.
	 * @param string                $field Field name.
	 * @return string
	 */
	private static function field_description( WP_HTML_Tag_Processor $tags, string $field ): string {
		foreach ( array( 'aria-label', 'placeholder', 'title' ) as $attribute ) {
			$value = $tags->get_attribute( $attribute );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				$text = trim( wp_strip_all_tags( $value ) );
				break;
			}
		}

		if ( empty( $text ) ) {
			// Turn a field name such as your-email into words: your email.
			$text = trim( (string) preg_replace( '/[\[\]_\-]+/', ' ', (string) preg_replace( '/^(wpforms\[fields\]|form_fields)/', '', $field ) ) );
		}

		$required = null !== $tags->get_attribute( 'required' ) || 'true' === $tags->get_attribute( 'aria-required' );

		return ucfirst( $text ) . ( $required ? ' ' . __( '(required)', 'wpmark' ) : '' );
	}

	/**
	 * Whether a field is a spam trap (a honeypot that people never see).
	 *
	 * @param WP_HTML_Tag_Processor $tags  At the field.
	 * @param string                $field Field name.
	 * @return bool
	 */
	private static function is_trap( WP_HTML_Tag_Processor $tags, string $field ): bool {
		$class = (string) $tags->get_attribute( 'class' );

		return str_contains( $field, '_wpcf7' ) || str_contains( $field, 'wpforms[hp]' ) || str_contains( $class, 'wpforms-field-hp' ) || 'true' === $tags->get_attribute( 'aria-hidden' ) || '-1' === (string) $tags->get_attribute( 'tabindex' );
	}

	/**
	 * A tool name, unique on this page: lower-case words joined by hyphens.
	 *
	 * @param string $title Form name.
	 * @return string
	 */
	private static function name( string $title ): string {
		$base = 'send-message-' . trim( substr( sanitize_title( $title ), 0, 40 ), '-' );
		$name = $base;

		for ( $i = 2; isset( self::$names[ $name ] ); $i++ ) {
			$name = $base . '-' . $i;
		}

		self::$names[ $name ] = true;

		return $name;
	}

	/**
	 * The site's name.
	 *
	 * @return string
	 */
	private static function site(): string {
		return wp_strip_all_tags( get_bloginfo( 'name' ) );
	}

	/**
	 * Forget the names used. For tests.
	 */
	public static function reset(): void {
		self::$names = array();
	}
}
