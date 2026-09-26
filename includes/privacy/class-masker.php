<?php
/**
 * Contact detail masking.
 *
 * @package WPMark
 */

namespace WPMark\Privacy;

defined( 'ABSPATH' ) || exit;

/**
 * Hides most of a name, email or phone number while keeping it recognisable.
 *
 * Examples:
 *
 *     rahul.sharma@acme.com  ->  r***@acme.com   (the company domain stays: useful for B2B)
 *     +91 98765 43210        ->  *********210
 *     Rahul Sharma           ->  R*** S***
 *
 * It also finds emails and phone numbers that visitors typed into free text
 * ("call me on 98765 43210") so they do not slip past the settings.
 */
final class Masker {

	/**
	 * Emails anywhere in a piece of text.
	 */
	private const EMAIL_PATTERN = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i';

	/**
	 * Phone-like runs: an optional +, then digits with spaces, dots, dashes
	 * or brackets between them. Checked for at least 8 digits afterwards.
	 */
	private const PHONE_PATTERN = '/(?<![\w@])\+?\(?\d[\d\s().\-]{6,}\d(?![\w@])/';

	/**
	 * Mask a single email address.
	 *
	 * @param string $email Email address.
	 * @return string e.g. "r***@acme.com".
	 */
	public static function email( string $email ): string {
		$email = trim( $email );
		$at    = strrpos( $email, '@' );

		if ( false === $at || 0 === $at ) {
			return self::stars( $email );
		}

		return mb_substr( $email, 0, 1 ) . '***' . substr( $email, $at );
	}

	/**
	 * Mask a phone number, keeping only its last three digits.
	 *
	 * @param string $phone Phone number in any format.
	 * @return string e.g. "*********210".
	 */
	public static function phone( string $phone ): string {
		$digits = preg_replace( '/\D/', '', $phone );
		$count  = strlen( (string) $digits );

		if ( $count < 6 ) {
			return str_repeat( '*', max( $count, 3 ) );
		}

		return str_repeat( '*', $count - 3 ) . substr( (string) $digits, -3 );
	}

	/**
	 * Mask a name, keeping the first letter of each word.
	 *
	 * @param string $name Name.
	 * @return string e.g. "R*** S***".
	 */
	public static function name( string $name ): string {
		$words = preg_split( '/\s+/u', trim( $name ), -1, PREG_SPLIT_NO_EMPTY );

		if ( ! $words ) {
			return '';
		}

		return implode(
			' ',
			array_map(
				static fn( string $word ): string => mb_substr( $word, 0, 1 ) . '***',
				$words
			)
		);
	}

	/**
	 * Protect contact details that appear inside free text.
	 *
	 * @param string      $text  Text a visitor wrote.
	 * @param Lead_Access $level What the reader may see.
	 * @return string The text with emails and phone numbers masked (Masked),
	 *                removed (Hidden or None) or left alone (Full).
	 */
	public static function protect_free_text( string $text, Lead_Access $level ): string {
		if ( Lead_Access::Full === $level ) {
			return $text;
		}

		$masked = Lead_Access::Masked === $level;

		$text = (string) preg_replace_callback(
			self::EMAIL_PATTERN,
			static fn( array $m ): string => $masked ? self::email( $m[0] ) : __( '[email removed]', 'wpmark' ),
			$text
		);

		return (string) preg_replace_callback(
			self::PHONE_PATTERN,
			static function ( array $m ) use ( $masked ): string {
				if ( ! self::looks_like_phone( $m[0] ) ) {
					return $m[0];
				}
				return $masked ? self::phone( $m[0] ) : __( '[phone removed]', 'wpmark' );
			},
			$text
		);
	}

	/**
	 * Whether a phone-shaped run really is a phone number, not a date or amount.
	 *
	 * @param string $candidate Matched text.
	 * @return bool
	 */
	private static function looks_like_phone( string $candidate ): bool {
		$digits = strlen( (string) preg_replace( '/\D/', '', $candidate ) );

		if ( $digits < 8 || $digits > 15 ) {
			return false;
		}

		// Dates such as 2026-01-31 or 31.01.2026 are not phone numbers.
		return ! preg_match( '/^\s*(\d{4}[-.\/]\d{1,2}[-.\/]\d{1,2}|\d{1,2}[-.\/]\d{1,2}[-.\/]\d{4})\s*$/', $candidate );
	}

	/**
	 * Replace every character with a star.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function stars( string $value ): string {
		return str_repeat( '*', max( 3, mb_strlen( $value ) ) );
	}
}
