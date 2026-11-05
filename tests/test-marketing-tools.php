<?php
/**
 * Tests for the link checker, the SEO fix list, the new playbooks and the
 * experimental custom design switch.
 *
 * @package WPMark
 */

namespace WPMark\Tests;

use WP_UnitTestCase;
use WPMark\Abilities\Abilities;
use WPMark\Abilities\Blog_Post_Prompt;
use WPMark\Abilities\Weekly_Report_Prompt;
use WPMark\Seo\Rank_Math_Seo_Source;
use WPMark\Settings;

/**
 * Marketing tools added in 0.4.0.
 */
class Test_Marketing_Tools extends WP_UnitTestCase {

	use Runs_Abilities;

	/**
	 * Pretty addresses, so pages have paths.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->set_permalink_structure( '/%postname%/' );
	}

	/**
	 * Clean up.
	 */
	public function tear_down(): void {
		remove_all_filters( 'wpmark_seo_sources' );
		Settings::save_custom_design( false );
		Settings::save_drafts( false );
		parent::tear_down();
	}

	/**
	 * Broken links, links to unpublished pages and orphan pages are found; other sites are ignored.
	 */
	public function test_check_links(): void {
		$pricing = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Pricing plans',
				'post_name'  => 'pricing',
			)
		);
		$draft   = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Coming soon',
				'post_name'   => 'coming-soon',
				'post_status' => 'draft',
			)
		);
		$orphan  = self::factory()->post->create(
			array(
				'post_title' => 'Onboarding checklist',
				'post_name'  => 'onboarding-checklist',
			)
		);
		self::factory()->post->create(
			array(
				'post_title'   => 'Onboarding new customers',
				'post_content' => 'Read about onboarding.',
			)
		);
		$home = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Home',
				'post_content' => '<p><a href="' . get_permalink( $pricing ) . '">Prices</a> <a href="/old-offer/">Old offer</a> <a href="/coming-soon/">Soon</a> <a href="https://example.com/elsewhere">Elsewhere</a> <a href="#top">Top</a> <a href="/wp-content/uploads/file.pdf">PDF</a> <a href="mailto:hi@example.org">Mail</a></p>',
			)
		);
		update_option( 'page_on_front', $home );

		$result = $this->run_as( 'check-links', null, 'editor' );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 3, $result['internal_links_found'] );

		$this->assertSame( 1, $result['broken_links']['count'] );
		$this->assertSame( '/old-offer/', $result['broken_links']['items'][0]['link'] );
		$this->assertSame( 'Old offer', $result['broken_links']['items'][0]['link_text'] );
		$this->assertSame( $home, $result['broken_links']['items'][0]['on_page']['id'] );

		$this->assertSame( 1, $result['links_to_unpublished']['count'] );
		$this->assertStringContainsString( 'draft', $result['links_to_unpublished']['items'][0]['detail'] );

		$orphans = array_column( $result['orphan_pages']['items'], null, 'id' );
		$this->assertArrayHasKey( $orphan, $orphans );
		$this->assertArrayNotHasKey( $pricing, $orphans, 'The home page links to it.' );
		$this->assertArrayNotHasKey( $home, $orphans, 'The front page needs no links.' );
		$this->assertArrayNotHasKey( $draft, $orphans, 'Drafts are not scanned.' );
		$this->assertSame( 'Onboarding new customers', $orphans[ $orphan ]['could_be_linked_from'][0]['title'] );
	}

	/**
	 * Pages with missing or badly sized snippets are listed with what the AI needs to write new ones.
	 */
	public function test_seo_fix_list(): void {
		$this->assert_error_code( 'ability_invalid_permissions', $this->refused_as( 'seo-fix-list', null, 'editor' ), 'Needs Rank Math.' );

		add_filter(
			'wpmark_seo_sources',
			static fn(): array => array(
				new class() extends Rank_Math_Seo_Source {
					/**
					 * Pretend Rank Math is active.
					 *
					 * @return bool
					 */
					public function is_available(): bool {
						return true;
					}
				},
			)
		);

		$missing = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Services',
				'post_content' => '<h2>What we do</h2><p>We build websites for clinics.</p>',
			)
		);
		$long    = self::factory()->post->create( array( 'post_title' => 'A post' ) );
		update_post_meta( $long, 'rank_math_description', str_repeat( 'Too long. ', 20 ) );
		update_post_meta( $long, 'rank_math_focus_keyword', 'websites' );
		$fine = self::factory()->post->create( array( 'post_title' => 'Fine' ) );
		update_post_meta( $fine, 'rank_math_description', 'A description of a good length that says what the page offers and why someone should click.' );
		update_post_meta( $fine, 'rank_math_focus_keyword', 'fine' );
		$hidden = self::factory()->post->create( array( 'post_title' => 'Thank you' ) );
		update_post_meta( $hidden, 'rank_math_robots', array( 'noindex' ) );

		$result = $this->run_as( 'seo-fix-list', null, 'editor' );
		$pages  = array_column( $result['pages'], null, 'id' );

		$this->assertSame( 2, $result['pages_to_fix'] );
		$this->assertSame( $missing, $result['pages'][0]['id'], 'Pages come first.' );
		$this->assertSame( array( 'missing_description', 'missing_focus_keyword' ), $pages[ $missing ]['problems'] );
		$this->assertSame( 'What we do', $pages[ $missing ]['headings'][0]['text'] );
		$this->assertStringContainsString( 'clinics', $pages[ $missing ]['opening_text'] );
		$this->assertSame( array( 'description_too_long' ), $pages[ $long ]['problems'] );
		$this->assertArrayNotHasKey( $fine, $pages );
		$this->assertArrayNotHasKey( $hidden, $pages, 'Pages hidden from search are skipped.' );
		$this->assertSame( 1, $result['problem_counts']['missing_description'] );
	}

	/**
	 * The two new playbooks are offered to everyone and use the right tools.
	 */
	public function test_playbooks(): void {
		$names = array_map( static fn( $ability ): string => $ability->name(), Abilities::offered( 'prompt' ) );
		$this->assertContains( 'wpmark/blog-post', $names );
		$this->assertContains( 'wpmark/weekly-report', $names );

		$blog = $this->run_as(
			'blog-post',
			array(
				'topic'   => 'Choosing a CRM',
				'keyword' => 'best crm for small business',
			),
			'editor'
		);
		$text = $blog['messages'][0]['content']['text'];
		$this->assertStringContainsString( 'Topic: Choosing a CRM.', $text );
		$this->assertStringContainsString( 'wpmark-create-draft', $text );
		$this->assertStringContainsString( 'save drafts that a person reviews', $text );

		$report = $this->run_as( 'weekly-report', array( 'days' => '14' ), 'editor' );
		$text   = $report['messages'][0]['content']['text'];
		$this->assertStringContainsString( 'last 14 days', $text );
		$this->assertStringContainsString( 'last 28 days', $text );
		$this->assertStringContainsString( 'wpmark-check-links', $text );

		$this->assertInstanceOf( Blog_Post_Prompt::class, Abilities::all()[ array_search( 'wpmark/blog-post', array_map( static fn( $ability ): string => $ability->name(), Abilities::all() ), true ) ] );
		$this->assertNotEmpty( ( new Weekly_Report_Prompt() )->label() );
	}

	/**
	 * Custom page design is off until switched on, and needs drafts on.
	 */
	public function test_custom_design_switch(): void {
		Settings::save_drafts( true );

		$this->assertFalse( Settings::custom_design_enabled() );

		$refused = $this->run_as(
			'create-draft',
			array(
				'title' => 'Landing',
				'html'  => '<h1>Hi</h1>',
			),
			'editor'
		);
		$this->assert_error_code( 'wpmark_invalid_input', $refused );
		$this->assertStringContainsString( 'experimental', $refused->get_error_message() );

		$guide = $this->run_as( 'design-guide', null, 'editor' );
		$this->assertArrayNotHasKey( 'css_variables', $guide['builders'][0] );
		$this->assertStringNotContainsString( 'custom design', $guide['how_to_build'] );

		Settings::save_custom_design( true );
		$this->assertTrue( Settings::custom_design_enabled() );

		Settings::save_drafts( false );
		$this->assertFalse( Settings::custom_design_enabled(), 'Needs drafts on too.' );
	}
}
