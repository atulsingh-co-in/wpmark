<?php
/**
 * Suggested privacy policy text.
 *
 * @package WPMark
 */

namespace WPMark\Privacy;

use WPMark\Links;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a suggested paragraph to Settings → Privacy → Policy Guide.
 *
 * Site owners are responsible for telling visitors how their data is used.
 * When the team connects an AI assistant, what visitors wrote in forms can
 * be sent to that AI company. This text helps the owner say so. WordPress
 * shows it as a suggestion; nothing is added to the policy automatically.
 */
final class Privacy_Policy {

	/**
	 * Add the hook.
	 */
	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'add_suggested_text' ) );
	}

	/**
	 * Give WordPress the suggested text.
	 */
	public static function add_suggested_text(): void {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( 'WPMark', self::text() );
		}
	}

	/**
	 * The suggested text, as HTML.
	 *
	 * @return string
	 */
	public static function text(): string {
		$paragraphs = array(
			'<p class="privacy-policy-tutorial">' . esc_html__( 'WPMark lets your team ask an AI assistant about this site. Use the text below as a starting point: name the AI companies your team actually uses, and check it against the privacy laws that apply to you. This is not legal advice.', 'wpmark' ) . '</p>',
			'<p class="privacy-policy-tutorial">' . sprintf(
				/* translators: %s: link to WPMark's privacy and data guide. */
				esc_html__( 'More detail, including notes for GDPR, India\'s DPDP Act, US state laws and the UAE and GCC: %s', 'wpmark' ),
				'<a href="' . esc_url( Links::doc( 'privacy-and-data.md' ) ) . '">' . esc_html__( 'WPMark privacy and data guide', 'wpmark' ) . '</a>'
			) . '</p>',
			'<p><strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'wpmark' ) . ' </strong>' . esc_html__( 'Our team uses AI assistants to review enquiries and improve our website. When they do, the content of this website and the information you send us through our forms (such as your name, email address, phone number and message) may be shared with the AI provider we use, for example Anthropic (Claude), OpenAI (ChatGPT) or Google (Gemini), only to answer our team\'s questions. We use business accounts with these providers where available and do not allow them to use your information to train their AI models. Only authorised members of our team can do this, and contact details can be hidden from them.', 'wpmark' ) . '</p>',
		);

		return implode( '', $paragraphs );
	}
}
