<?php
/**
 * Page outline helpers.
 *
 * @package WPMark
 */

namespace WPMark\Builders;

use WPMark\Output\Untrusted_Text;

defined( 'ABSPATH' ) || exit;

/**
 * The shared shape every page builder's outline uses.
 *
 * A page is a list of **sections** (a hero, a feature row, a call to action).
 * Each section holds **slots**: the parts the AI may fill in, such as a
 * heading, a paragraph, a button or an image. Layout, spacing and styling
 * are not slots: they stay exactly as the site designed them, which is how
 * a new page keeps the site's look.
 *
 * Slot kinds and their fields:
 *
 * - heading:     text, level (1-6)
 * - text:        text
 * - button:      text, link
 * - image:       image_id, image_url, alt
 * - background:  image_id, image_url (a section's background picture)
 * - list:        items[] of { text, link }
 * - card:        title, text, link, image_id (icon box, image box, call to action)
 * - testimonial: quote, name, role
 * - faq:         items[] of { question, answer } (accordions, toggles, tabs)
 * - counter:     title, number, suffix
 * - price:       title, subtitle, price, features[], button_text, link
 * - video:       url
 * - form:        form (which form is embedded), plugin
 */
final class Outline {

	/**
	 * Longest text returned for one field.
	 */
	public const MAX_TEXT = 1500;

	/**
	 * Most sections returned for one page.
	 */
	public const MAX_SECTIONS = 40;

	/**
	 * Most slots returned for one section.
	 */
	public const MAX_SLOTS = 60;

	/**
	 * Every slot kind, for documentation and validation.
	 */
	public const KINDS = array( 'heading', 'text', 'button', 'image', 'background', 'list', 'card', 'testimonial', 'faq', 'counter', 'price', 'video', 'form' );

	/**
	 * Clean text from the site for the AI to read.
	 *
	 * @param mixed $text Raw text, possibly HTML.
	 * @return string
	 */
	public static function text( $text ): string {
		return is_scalar( $text ) ? Untrusted_Text::clean( (string) $text, self::MAX_TEXT ) : '';
	}

	/**
	 * A link address from the site, or "" when it is not a plain web link.
	 *
	 * @param mixed $url Raw address.
	 * @return string
	 */
	public static function link( $url ): string {
		if ( ! is_string( $url ) || '' === trim( $url ) ) {
			return '';
		}

		return (string) esc_url_raw( trim( $url ), array( 'http', 'https', 'mailto', 'tel' ) );
	}

	/**
	 * Build a slot, dropping empty fields so the outline stays short.
	 *
	 * @param string $key    Stable key within the page.
	 * @param string $kind   One of KINDS.
	 * @param array  $fields Field values.
	 * @return array
	 */
	public static function slot( string $key, string $kind, array $fields ): array {
		$fields = array_filter(
			$fields,
			static fn( $value ): bool => null !== $value && '' !== $value && array() !== $value && 0 !== $value
		);

		return array_merge(
			array(
				'key'  => $key,
				'kind' => $kind,
			),
			$fields
		);
	}

	/**
	 * A section's label: its own name if the designer gave one, else its
	 * first heading, else a numbered fallback.
	 *
	 * @param string $name     Name given in the builder, or "".
	 * @param array  $slots    The section's slots.
	 * @param int    $position Section number, from 1.
	 * @return string
	 */
	public static function section_label( string $name, array $slots, int $position ): string {
		$name = self::text( $name );

		if ( '' !== $name ) {
			return mb_substr( $name, 0, 80 );
		}

		foreach ( $slots as $slot ) {
			if ( 'heading' === $slot['kind'] && ! empty( $slot['text'] ) ) {
				return mb_substr( $slot['text'], 0, 80 );
			}
		}

		/* translators: %d: Section number on the page. */
		return sprintf( __( 'Section %d', 'wpmark' ), $position );
	}

	/**
	 * Build a section from its slots, keeping the outline within limits.
	 *
	 * @param string   $key      Stable key.
	 * @param string   $name     Name given in the builder, or "".
	 * @param array    $slots    Slots found in it.
	 * @param string[] $other    Element types kept as they are (spacers, dividers…).
	 * @param int      $position Section number, from 1.
	 * @param bool     $locked   True when the section cannot be filled (a global or synced element).
	 * @return array
	 */
	public static function section( string $key, string $name, array $slots, array $other, int $position, bool $locked = false ): array {
		$section = array(
			'key'   => $key,
			'label' => self::section_label( $name, $slots, $position ),
			'slots' => array_slice( $slots, 0, self::MAX_SLOTS ),
		);

		if ( count( $slots ) > self::MAX_SLOTS ) {
			$section['slots_truncated'] = true;
		}

		$other = array_values( array_unique( $other ) );
		if ( $other ) {
			$section['kept_as_is'] = $other;
		}

		if ( $locked ) {
			$section['locked'] = true;
		}

		return $section;
	}
}
