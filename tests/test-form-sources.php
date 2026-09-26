<?php
/**
 * Tests for the form source registry.
 *
 * @package WPMark
 */

namespace WPMark\Tests;

use WP_UnitTestCase;
use WPMark\Forms\Form_Sources;

/**
 * Form_Sources: collecting, checking and choosing form plugins.
 */
class Test_Form_Sources extends WP_UnitTestCase {

	/**
	 * Elementor, WPForms and Flamingo are built in, but none is active on the test site.
	 */
	public function test_built_in_sources(): void {
		$this->assertSame( array( 'elementor', 'wpforms', 'flamingo' ), array_keys( Form_Sources::all() ) );
		$this->assertSame( array(), Form_Sources::available() );
	}

	/**
	 * Sources added through the filter are listed by ID.
	 */
	public function test_filter_adds_sources_keyed_by_id(): void {
		$this->add_sources( new Fake_Form_Source( 'one' ), new Fake_Form_Source( 'two' ) );

		$this->assertSame( array( 'one', 'two' ), array_keys( Form_Sources::all() ) );
	}

	/**
	 * Only sources whose plugin is active count as available. A site can have several.
	 */
	public function test_available_skips_inactive_sources(): void {
		$this->add_sources(
			new Fake_Form_Source( 'active-one' ),
			new Fake_Form_Source( 'inactive', false ),
			new Fake_Form_Source( 'active-two' )
		);

		$this->assertSame( array( 'active-one', 'active-two' ), array_keys( Form_Sources::available() ) );
	}

	/**
	 * A source can be looked up by ID, but only while it is available.
	 */
	public function test_get_finds_available_source(): void {
		$active = new Fake_Form_Source( 'active' );
		$this->add_sources( $active, new Fake_Form_Source( 'inactive', false ) );

		$this->assertSame( $active, Form_Sources::get( 'active' ) );
		$this->assertNull( Form_Sources::get( 'inactive' ) );
		$this->assertNull( Form_Sources::get( 'missing' ) );
	}

	/**
	 * Something that is not a Form_Source is skipped with a developer notice.
	 */
	public function test_skips_objects_that_are_not_sources(): void {
		$this->setExpectedIncorrectUsage( 'wpmark_form_sources' );
		$this->add_sources( new \stdClass(), 'not-an-object', new Fake_Form_Source( 'good' ) );

		$this->assertSame( array( 'good' ), array_keys( Form_Sources::all() ) );
	}

	/**
	 * A source with a badly formed ID is skipped.
	 */
	public function test_skips_invalid_ids(): void {
		$this->setExpectedIncorrectUsage( 'wpmark_form_sources' );
		$this->add_sources( new Fake_Form_Source( 'Bad ID!' ), new Fake_Form_Source( 'good' ) );

		$this->assertSame( array( 'good' ), array_keys( Form_Sources::all() ) );
	}

	/**
	 * When two sources share an ID, the first one wins.
	 */
	public function test_first_source_wins_on_duplicate_id(): void {
		$first = new Fake_Form_Source( 'same' );

		$this->setExpectedIncorrectUsage( 'wpmark_form_sources' );
		$this->add_sources( $first, new Fake_Form_Source( 'same' ) );

		$this->assertSame( array( 'same' => $first ), Form_Sources::all() );
	}

	/**
	 * A filter that returns something other than an array does not break anything.
	 */
	public function test_non_array_filter_result_gives_no_sources(): void {
		add_filter( 'wpmark_form_sources', '__return_false' );

		$this->assertSame( array(), Form_Sources::all() );
	}

	/**
	 * Replace the built-in sources with these, through the public filter.
	 *
	 * @param mixed ...$sources Anything to put in the list.
	 */
	private function add_sources( ...$sources ): void {
		add_filter( 'wpmark_form_sources', static fn(): array => $sources );
	}
}
