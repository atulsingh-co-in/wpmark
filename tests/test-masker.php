<?php
/**
 * Tests for contact detail masking.
 *
 * @package WPMark
 */

namespace WPMark\Tests;

use WP_UnitTestCase;
use WPMark\Privacy\Lead_Access;
use WPMark\Privacy\Masker;

/**
 * Masker and Lead_Access.
 */
class Test_Masker extends WP_UnitTestCase {

	/**
	 * Emails keep their first letter and domain.
	 */
	public function test_masks_email(): void {
		$this->assertSame( 'r***@acme.com', Masker::email( 'rahul.sharma@acme.com' ) );
		$this->assertSame( '***', Masker::email( 'no' ) );
		$this->assertSame( '*********', Masker::email( '@acme.com' ) );
	}

	/**
	 * Phone numbers keep only their last three digits.
	 */
	public function test_masks_phone(): void {
		$this->assertSame( '*********210', Masker::phone( '+91 98765 43210' ) );
		$this->assertSame( '***', Masker::phone( '123' ) );
	}

	/**
	 * Names keep the first letter of each word.
	 */
	public function test_masks_name(): void {
		$this->assertSame( 'R*** S***', Masker::name( '  Rahul   Sharma ' ) );
		$this->assertSame( '', Masker::name( '' ) );
	}

	/**
	 * Contact details typed into a message are masked, removed or kept.
	 */
	public function test_protects_free_text(): void {
		$text = 'Mail me at rahul@acme.com or call +91 98765 43210 about the 2026-01-31 launch, budget 50000.';

		$this->assertSame(
			'Mail me at r***@acme.com or call *********210 about the 2026-01-31 launch, budget 50000.',
			Masker::protect_free_text( $text, Lead_Access::Masked )
		);
		$this->assertSame(
			'Mail me at [email removed] or call [phone removed] about the 2026-01-31 launch, budget 50000.',
			Masker::protect_free_text( $text, Lead_Access::Hidden )
		);
		$this->assertSame( $text, Masker::protect_free_text( $text, Lead_Access::Full ) );
	}

	/**
	 * Levels compare from no access up to full access.
	 */
	public function test_most_generous_level(): void {
		$this->assertSame( Lead_Access::Masked, Lead_Access::most_generous( Lead_Access::Hidden, Lead_Access::Masked ) );
		$this->assertSame( Lead_Access::Full, Lead_Access::most_generous( Lead_Access::Full, Lead_Access::None ) );
		$this->assertSame( Lead_Access::None, Lead_Access::most_generous( Lead_Access::None, Lead_Access::None ) );
	}
}
