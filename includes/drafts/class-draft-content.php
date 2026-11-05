<?php
/**
 * Content an AI app sends for a draft.
 *
 * @package WPMark
 */

namespace WPMark\Drafts;

defined( 'ABSPATH' ) || exit;

/**
 * Makes everything an AI app writes safe before it reaches a draft.
 *
 * The AI may have read a planted instruction (in a form entry, say), so
 * what it writes is treated as untrusted too:
 *
 * - Text keeps only simple formatting: bold, italics, links, line breaks.
 *   No scripts, styles, iframes or event handlers, whatever the person's
 *   role (even administrators, who WordPress would otherwise trust).
 * - Links must be web, email or phone links.
 * - Images must already be in the media library: WPMark never uploads or
 *   downloads files. An image the person cannot see is refused.
 * - Videos must be YouTube or Vimeo links.
 *
 * Problems are not fatal: the part is left out and a note says why, so
 * the AI can fix it and the person sees what happened.
 */
final class Draft_Content {

	/**
	 * Longest text accepted for one field.
	 */
	public const MAX_TEXT = 5000;

	/**
	 * Notes about what was changed or left out.
	 *
	 * @var string[]
	 */
	private array $notes = array();

	/**
	 * HTML allowed inside text.
	 *
	 * @return array
	 */
	public static function inline_tags(): array {
		return array(
			'a'      => array(
				'href'   => true,
				'title'  => true,
				'target' => true,
				'rel'    => true,
			),
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
			'i'      => array(),
			'br'     => array(),
			'span'   => array(),
		);
	}

	/**
	 * Text with simple formatting, for paragraphs, headings and answers.
	 *
	 * @param mixed $value Text from the AI.
	 * @return string Safe HTML.
	 */
	public function rich( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$text = mb_substr( trim( (string) $value ), 0, self::MAX_TEXT );
		$text = wp_kses( $text, self::inline_tags(), array( 'http', 'https', 'mailto', 'tel' ) );

		return trim( $text );
	}

	/**
	 * Plain text, for button labels, names and titles.
	 *
	 * @param mixed $value Text from the AI.
	 * @param int   $max   Longest length.
	 * @return string
	 */
	public function plain( $value, int $max = 300 ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return mb_substr( sanitize_text_field( (string) $value ), 0, $max );
	}

	/**
	 * A link address: web, email or phone.
	 *
	 * @param mixed $value Address from the AI.
	 * @return string The address, or "" when it is not allowed.
	 */
	public function link( $value ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return '';
		}

		$value = trim( $value );

		// Links within the site may be written as a path.
		if ( str_starts_with( $value, '/' ) && ! str_starts_with( $value, '//' ) ) {
			$value = home_url( $value );
		}

		$url = esc_url_raw( $value, array( 'http', 'https', 'mailto', 'tel' ) );

		if ( '' === $url ) {
			/* translators: %s: The link that was refused. */
			$this->note( sprintf( __( 'Left out the link "%s": only web, email and phone links are allowed.', 'wpmark' ), mb_substr( $value, 0, 100 ) ) );
		}

		return $url;
	}

	/**
	 * An image from the media library.
	 *
	 * @param mixed  $id   Attachment ID from the AI.
	 * @param string $size Image size to link to.
	 * @return array{id: int, url: string, alt: string}|null Null when not usable.
	 */
	public function image( $id, string $size = 'large' ): ?array {
		$id = is_numeric( $id ) ? (int) $id : 0;

		if ( $id <= 0 ) {
			return null;
		}

		$post = get_post( $id );

		if ( ! $post || 'attachment' !== $post->post_type || ! wp_attachment_is_image( $id ) || ! current_user_can( 'read_post', $id ) ) {
			/* translators: %d: Image ID. */
			$this->note( sprintf( __( 'Left out image %d: it is not an image in the media library that you can use. Find images with wpmark-list-media.', 'wpmark' ), $id ) );
			return null;
		}

		$url = wp_get_attachment_image_url( $id, $size );

		return array(
			'id'  => $id,
			'url' => $url ? $url : (string) wp_get_attachment_url( $id ),
			'alt' => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
		);
	}

	/**
	 * A YouTube or Vimeo address.
	 *
	 * @param mixed $value Address from the AI.
	 * @return string The address, or "" when it is not allowed.
	 */
	public function video( $value ): string {
		$url  = is_string( $value ) ? esc_url_raw( trim( $value ), array( 'https', 'http' ) ) : '';
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		if ( '' !== $url && preg_match( '/(^|\.)(youtube\.com|youtu\.be|vimeo\.com)$/', $host ) ) {
			return $url;
		}

		$this->note( __( 'Left out a video: only YouTube and Vimeo links can be embedded.', 'wpmark' ) );

		return '';
	}

	/**
	 * Record a note for the AI and the person.
	 *
	 * @param string $note Plain-language note.
	 */
	public function note( string $note ): void {
		$this->notes[] = $note;
	}

	/**
	 * Everything noted so far, without repeats.
	 *
	 * @return string[]
	 */
	public function notes(): array {
		return array_values( array_unique( $this->notes ) );
	}
}
