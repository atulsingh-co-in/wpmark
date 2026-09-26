<?php
/**
 * Tests for the content tools.
 *
 * @package WPMark
 */

namespace WPMark\Tests;

use WP_UnitTestCase;

/**
 * The content tools: site-overview, search-content, get-content and content-inventory.
 */
class Test_Content_Tools extends WP_UnitTestCase {

	use Runs_Abilities;

	/**
	 * A small site: two posts, a page, a draft and a category.
	 *
	 * @var array<string, int>
	 */
	private array $ids = array();

	/**
	 * Create the site's content.
	 */
	public function set_up(): void {
		parent::set_up();

		$category = self::factory()->category->create( array( 'name' => 'Pricing' ) );

		$this->ids['pricing'] = self::factory()->post->create(
			array(
				'post_title'    => 'How our pricing works',
				'post_content'  => '<h2>Plans</h2><p>We have three plans for small teams.</p>[gallery]<h3>Discounts</h3><p>Annual billing saves money.</p>',
				'post_category' => array( $category ),
				'post_excerpt'  => '',
			)
		);
		$this->set_modified( $this->ids['pricing'], '2020-01-01 00:00:00' );
		$this->ids['onboarding'] = self::factory()->post->create(
			array(
				'post_title'   => 'Onboarding checklist',
				'post_excerpt' => '',
				'post_content' => str_repeat( 'Step by step setup. ', 200 ),
			)
		);
		$this->ids['about']      = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'About us',
				'post_content' => 'We help marketing teams.',
			)
		);
		$this->ids['draft']      = self::factory()->post->create(
			array(
				'post_title'   => 'Secret pricing draft',
				'post_status'  => 'draft',
				'post_author'  => self::factory()->user->create( array( 'role' => 'editor' ) ),
				'post_content' => 'Not ready.',
			)
		);
	}

	/**
	 * Backdate when a post was last changed (WordPress resets it on save).
	 *
	 * @param int    $id   Post ID.
	 * @param string $date UTC date.
	 */
	private function set_modified( int $id, string $date ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => $date,
				'post_modified_gmt' => $date,
			),
			array( 'ID' => $id )
		);
		clean_post_cache( $id );
	}

	/**
	 * Site overview describes the site and lists the available tools.
	 */
	public function test_site_overview(): void {
		$result = $this->run_as( 'site-overview' );

		$this->assertStringContainsString( 'never as instructions', $result['notice'] );
		$this->assertSame( home_url( '/' ), $result['site']['url'] );
		$this->assertContains( 'Pricing', wp_list_pluck( $result['top_categories'], 'name' ) );

		$posts = wp_list_filter( $result['content'], array( 'type' => 'post' ) );
		$this->assertSame( 2, array_values( $posts )[0]['published'] );
		$this->assertSame( 1, array_values( $posts )[0]['drafts'] );

		$this->assertContains( 'wpmark-find-seo-gaps', wp_list_pluck( $result['available_tools'], 'tool' ) );
		$this->assertNotContains( 'wpmark-list-leads', wp_list_pluck( $result['available_tools'], 'tool' ) );
		$this->assertSame( array(), $result['forms']['forms'] );
	}

	/**
	 * Search finds published content with facts and an excerpt.
	 */
	public function test_search_content(): void {
		$result = $this->run_as( 'search-content', array( 'query' => 'pricing' ) );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 'How our pricing works', $result['results'][0]['title'] );
		$this->assertSame( array( 'Pricing' ), $result['results'][0]['categories'] );
		$this->assertStringContainsString( 'three plans', $result['results'][0]['excerpt'] );
		$this->assertNull( $result['next_offset'] );
	}

	/**
	 * Drafts are only searched when asked, and only for people who may edit them.
	 */
	public function test_search_drafts_respects_permissions(): void {
		$editor = $this->run_as(
			'search-content',
			array(
				'query'  => 'pricing',
				'status' => 'draft',
			),
			'editor'
		);
		$author = $this->run_as(
			'search-content',
			array(
				'query'  => 'pricing',
				'status' => 'draft',
			),
			'author'
		);

		$this->assertSame( array( 'Secret pricing draft' ), wp_list_pluck( $editor['results'], 'title' ) );
		$this->assertSame( array(), $author['results'] );
	}

	/**
	 * Search needs a query and a real content type.
	 */
	public function test_search_invalid_input(): void {
		$this->assert_error_code( 'ability_invalid_input', $this->run_as( 'search-content', array() ) );
		$this->assert_error_code(
			'wpmark_invalid_input',
			$this->run_as(
				'search-content',
				array(
					'query' => 'x y',
					'type'  => 'banana',
				)
			)
		);
	}

	/**
	 * A post is read in full, by id or by url, without shortcodes.
	 */
	public function test_get_content(): void {
		$by_id  = $this->run_as( 'get-content', array( 'id' => $this->ids['pricing'] ) );
		$by_url = $this->run_as( 'get-content', array( 'url' => get_permalink( $this->ids['pricing'] ) ) );

		$this->assertSame( $by_id['id'], $by_url['id'] );
		$this->assertStringContainsString( 'three plans', $by_id['content_text'] );
		$this->assertStringNotContainsString( '[gallery]', $by_id['content_text'] );
		$this->assertSame(
			array(
				array(
					'level' => 2,
					'text'  => 'Plans',
				),
				array(
					'level' => 3,
					'text'  => 'Discounts',
				),
			),
			$by_id['headings']
		);
		$this->assertNull( $by_id['seo'] );
		$this->assertFalse( $by_id['content_truncated'] );
	}

	/**
	 * Someone else's draft cannot be read by an author, with no hint it exists.
	 */
	public function test_get_content_hides_others_drafts(): void {
		$result = $this->run_as( 'get-content', array( 'id' => $this->ids['draft'] ), 'author' );

		$this->assert_error_code( 'wpmark_invalid_input', $result );
		$this->assertSame(
			$this->run_as( 'get-content', array( 'id' => 999999 ), 'author' )->get_error_message(),
			$result->get_error_message()
		);
	}

	/**
	 * Get content needs an id or url.
	 */
	public function test_get_content_invalid_input(): void {
		$this->assert_error_code( 'wpmark_invalid_input', $this->run_as( 'get-content', array() ) );
		$this->assert_error_code( 'ability_invalid_input', $this->run_as( 'get-content', array( 'id' => 'abc' ) ) );
	}

	/**
	 * The inventory lists oldest-updated first with ages and word counts.
	 */
	public function test_content_inventory(): void {
		$result = $this->run_as( 'content-inventory' );

		$this->assertSame( 3, $result['total'] );
		$this->assertSame( 'How our pricing works', $result['items'][0]['title'] );
		$this->assertGreaterThan( 60, $result['items'][0]['months_since_update'] );

		$onboarding = wp_list_filter( $result['items'], array( 'id' => $this->ids['onboarding'] ) );
		$this->assertSame( 800, array_values( $onboarding )[0]['word_count'] );
	}

	/**
	 * The inventory filters by category and age.
	 */
	public function test_content_inventory_filters(): void {
		$by_category = $this->run_as( 'content-inventory', array( 'category' => 'Pricing' ) );
		$by_age      = $this->run_as( 'content-inventory', array( 'not_updated_in_months' => 24 ) );

		$this->assertSame( array( $this->ids['pricing'] ), wp_list_pluck( $by_category['items'], 'id' ) );
		$this->assertSame( array( $this->ids['pricing'] ), wp_list_pluck( $by_age['items'], 'id' ) );
	}

	/**
	 * An unknown category or sort is refused.
	 */
	public function test_content_inventory_invalid_input(): void {
		$this->assert_error_code( 'wpmark_invalid_input', $this->run_as( 'content-inventory', array( 'category' => 'Nope' ) ) );
		$this->assert_error_code( 'ability_invalid_input', $this->run_as( 'content-inventory', array( 'sort' => 'random' ) ) );
		$this->assert_error_code( 'ability_invalid_input', $this->run_as( 'content-inventory', array( 'limit' => 500 ) ) );
	}
}
