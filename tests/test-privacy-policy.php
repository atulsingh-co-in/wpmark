<?php
/**
 * Tests for WPMark's suggested privacy policy text.
 *
 * @package WPMark
 */

namespace WPMark\Tests;

use WP_Privacy_Policy_Content;
use WP_UnitTestCase;
use WPMark\Privacy\Privacy_Policy;

/**
 * The text offered under Settings → Privacy → Policy Guide.
 */
class Test_Privacy_Policy extends WP_UnitTestCase {

	/**
	 * The text names what is shared and with whom, and points to the full guide.
	 */
	public function test_text(): void {
		$text = Privacy_Policy::text();

		$this->assertStringContainsString( 'name, email address, phone number and message', $text );
		$this->assertStringContainsString( 'Anthropic (Claude), OpenAI (ChatGPT) or Google (Gemini)', $text );
		$this->assertStringContainsString( 'privacy-and-data.md', $text );
		$this->assertStringContainsString( 'not legal advice', $text );
	}

	/**
	 * WordPress lists it in the Policy Guide.
	 */
	public function test_added_to_policy_guide(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';

		set_current_screen( 'dashboard' );

		// WordPress only accepts policy text during admin_init; run just WPMark's hook.
		remove_all_actions( 'admin_init' );
		Privacy_Policy::register();
		do_action( 'admin_init' );

		$plugins = array_column( WP_Privacy_Policy_Content::get_suggested_policy_text(), 'plugin_name' );
		$this->assertContains( 'WPMark', $plugins );
	}
}
