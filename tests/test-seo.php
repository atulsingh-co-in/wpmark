<?php
/**
 * Tests for the Rank Math adapter and the SEO gaps tool.
 *
 * @package WPMark
 */

namespace WPMark\Tests;

use WP_UnitTestCase;
use WPMark\Seo\Rank_Math_Seo_Source;

/**
 * Rank Math meta reading, link counts and find-seo-gaps.
 */
class Test_Seo extends WP_UnitTestCase {

	use Plugin_Tables;
	use Runs_Abilities;

	/**
	 * Create Rank Math's link count table (as in its installer).
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		self::create_tables(
			array(
				'CREATE TABLE IF NOT EXISTS {prefix}rank_math_internal_meta (
					object_id bigint(20) unsigned NOT NULL,
					internal_link_count int(10) unsigned NULL default 0,
					external_link_count int(10) unsigned NULL default 0,
					incoming_link_count int(10) unsigned NULL default 0,
					PRIMARY KEY (object_id)
				)',
			)
		);
	}

	/**
	 * Remove it again.
	 */
	public static function tear_down_after_class(): void {
		self::drop_tables( array( 'rank_math_internal_meta' ) );

		parent::tear_down_after_class();
	}

	/**
	 * Rank Math settings are read from post meta, in both robots formats.
	 */
	public function test_reads_rank_math_meta(): void {
		$post = self::factory()->post->create();
		update_post_meta( $post, 'rank_math_title', ' Custom title ' );
		update_post_meta( $post, 'rank_math_description', 'A description' );
		update_post_meta( $post, 'rank_math_focus_keyword', 'pricing, plans ,' );
		update_post_meta( $post, 'rank_math_robots', array( 'noindex', 'nofollow' ) );

		$meta = ( new Rank_Math_Seo_Source() )->get_meta( $post );

		$this->assertSame( 'Custom title', $meta->title );
		$this->assertSame( 'A description', $meta->description );
		$this->assertSame( array( 'pricing', 'plans' ), $meta->focus_keywords );
		$this->assertTrue( $meta->noindex );

		update_post_meta( $post, 'rank_math_robots', 'index,follow' );
		$this->assertFalse( ( new Rank_Math_Seo_Source() )->get_meta( $post )->noindex );
	}

	/**
	 * Link counts are only reported for posts Rank Math has scanned.
	 */
	public function test_incoming_links_only_for_scanned_posts(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
		$wpdb->insert(
			$wpdb->prefix . 'rank_math_internal_meta',
			array(
				'object_id'           => 10,
				'incoming_link_count' => 0,
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
		$wpdb->insert(
			$wpdb->prefix . 'rank_math_internal_meta',
			array(
				'object_id'           => 11,
				'incoming_link_count' => 4,
			)
		);

		$this->assertSame(
			array(
				10 => 0,
				11 => 4,
			),
			( new Rank_Math_Seo_Source() )->incoming_links( array( 10, 11, 12 ) )
		);
	}

	/**
	 * Without an SEO plugin, the plugin-based checks are skipped with a reason.
	 */
	public function test_gaps_without_seo_plugin(): void {
		self::factory()->post->create( array( 'post_content' => 'Too short.' ) );

		$result = $this->run_as( 'find-seo-gaps' );

		$this->assertNull( $result['seo_plugin'] );
		$this->assertSame( array( 'missing_meta_description', 'missing_focus_keyword', 'hidden_from_search', 'no_internal_links' ), wp_list_pluck( $result['skipped_checks'], 'check' ) );
		$this->assertContains( 'thin_content', wp_list_pluck( $result['issues'], 'check' ) );
	}

	/**
	 * With Rank Math, every check runs and finds the right pages.
	 */
	public function test_gaps_with_rank_math(): void {
		global $wpdb;

		$this->use_rank_math();

		$good = self::factory()->post->create(
			array(
				'post_title'   => 'Complete guide',
				'post_content' => str_repeat( 'word ', 400 ),
			)
		);
		update_post_meta( $good, 'rank_math_description', 'Good' );
		update_post_meta( $good, 'rank_math_focus_keyword', 'guide' );

		$bad = self::factory()->post->create(
			array(
				'post_title'   => 'Duplicate',
				'post_content' => 'Short.',
			)
		);
		update_post_meta( $bad, 'rank_math_robots', array( 'noindex' ) );
		self::factory()->post->create(
			array(
				'post_title'   => 'Duplicate',
				'post_content' => str_repeat( 'word ', 400 ),
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
		$wpdb->insert(
			$wpdb->prefix . 'rank_math_internal_meta',
			array(
				'object_id'           => $bad,
				'incoming_link_count' => 0,
			)
		);

		$result = $this->run_as( 'find-seo-gaps', array( 'limit_per_check' => 5 ) );
		$issues = array();

		foreach ( $result['issues'] as $issue ) {
			$issues[ $issue['check'] ] = wp_list_pluck( $issue['pages'], 'id' );
			$this->assertNotSame( '', $issue['why_it_matters'] );
		}

		$this->assertSame( 'Rank Math', $result['seo_plugin'] );
		$this->assertSame( array(), $result['skipped_checks'] );
		$this->assertNotContains( $good, $issues['missing_meta_description'] );
		$this->assertContains( $bad, $issues['missing_meta_description'] );
		$this->assertSame( array( $bad ), $issues['hidden_from_search'] );
		$this->assertSame( array( $bad ), $issues['no_internal_links'] );
		$this->assertSame( array( $bad ), $issues['thin_content'] );
		$this->assertCount( 2, $issues['duplicate_titles'] );
	}

	/**
	 * Only the requested checks run, and bad thresholds are refused.
	 */
	public function test_gaps_input(): void {
		$result = $this->run_as( 'find-seo-gaps', array( 'checks' => array( 'stale_content' ) ) );

		$this->assertSame( array( 'stale_content' ), $result['checks_run'] );
		$this->assert_error_code( 'ability_invalid_input', $this->run_as( 'find-seo-gaps', array( 'thin_under_words' => 5 ) ) );
		$this->assert_error_code( 'ability_invalid_input', $this->run_as( 'find-seo-gaps', array( 'checks' => array( 'everything' ) ) ) );
	}

	/**
	 * Get content and the inventory show Rank Math settings.
	 */
	public function test_content_tools_show_seo(): void {
		$this->use_rank_math();

		$post = self::factory()->post->create();
		update_post_meta( $post, 'rank_math_description', 'Plans for small teams' );
		update_post_meta( $post, 'rank_math_focus_keyword', 'pricing' );

		$page      = $this->run_as( 'get-content', array( 'id' => $post ) );
		$inventory = $this->run_as( 'content-inventory' );

		$this->assertSame( 'Plans for small teams', $page['seo']['meta_description'] );
		$this->assertTrue( $page['seo']['uses_default_title'] );
		$this->assertSame( 'pricing', $inventory['items'][0]['seo']['focus_keyword'] );
	}

	/**
	 * Make Rank Math count as active.
	 */
	private function use_rank_math(): void {
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
	}
}
