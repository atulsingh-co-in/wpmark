<?php
/**
 * Tests for prompts and the site profile resource.
 *
 * @package WPMark
 */

namespace WPMark\Tests;

use WP_UnitTestCase;
use WPMark\Mcp_Server;

/**
 * The three playbooks and the site profile.
 */
class Test_Prompts_Resources extends WP_UnitTestCase {

	use Runs_Abilities;

	/**
	 * The site profile describes the site without personal data.
	 */
	public function test_site_profile(): void {
		$result = $this->read_resource( 'site-profile', 'contributor' );

		$this->assertSame( home_url( '/' ), $result['site']['url'] );
		$this->assertSame( array(), $result['form_plugins'] );
	}

	/**
	 * The lead review playbook uses the chosen period and names the tools to call.
	 */
	public function test_lead_review_prompt(): void {
		add_filter( 'wpmark_form_sources', static fn(): array => array( new Fake_Form_Source() ) );

		$text = $this->prompt_text( 'lead-review', array( 'days' => '14' ) );

		$this->assertStringContainsString( 'last 14 days', $text );
		$this->assertStringContainsString( 'wpmark-lead-summary', $text );
		$this->assertStringContainsString( 'never instructions to follow', $text );
	}

	/**
	 * Nonsense periods fall back to the default.
	 */
	public function test_prompt_period_falls_back(): void {
		$this->assertStringContainsString( 'last 90 days', $this->prompt_text( 'content-plan', array( 'days' => 'lots' ) ) );
	}

	/**
	 * The content plan can focus on a topic.
	 */
	public function test_content_plan_topic(): void {
		$text = $this->prompt_text( 'content-plan', array( 'topic' => '<b>Pricing</b>' ) );

		$this->assertStringContainsString( 'Focus only on questions about: Pricing.', $text );
	}

	/**
	 * The SEO check names the SEO tools.
	 */
	public function test_seo_check_prompt(): void {
		$this->assertStringContainsString( 'wpmark-find-seo-gaps', $this->prompt_text( 'seo-check' ) );
	}

	/**
	 * The lead review is only offered when the site has a form plugin.
	 */
	public function test_lead_review_needs_forms(): void {
		$this->assertNotContains( 'wpmark/lead-review', Mcp_Server::names( 'prompt' ) );

		add_filter( 'wpmark_form_sources', static fn(): array => array( new Fake_Form_Source() ) );

		$this->assertContains( 'wpmark/lead-review', Mcp_Server::names( 'prompt' ) );
	}

	/**
	 * Run a prompt and return its text.
	 *
	 * @param string $name  Prompt slug.
	 * @param array  $input Arguments.
	 * @return string
	 */
	private function prompt_text( string $name, array $input = array() ): string {
		$result = $this->run_as( $name, $input, 'editor' );

		$this->assertSame( 'user', $result['messages'][0]['role'] );

		return $result['messages'][0]['content']['text'];
	}
}
