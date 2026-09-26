<?php
/**
 * Tests for the fake form source.
 *
 * @package WPMark
 */

namespace WPMark\Tests;

use WPMark\Forms\Form_Source;

/**
 * Runs the shared form source checks against the in-memory fake. This
 * proves the checks themselves work before any real adapter relies on them.
 */
class Test_Fake_Form_Source extends Form_Source_Contract_Test_Case {

	/**
	 * {@inheritDoc}
	 */
	protected function make_source(): Form_Source {
		$data = self::fixture( 'fake' );

		// Shuffle so the test proves the source sorts, rather than relying on insertion order.
		$entries = $data['entries'];
		shuffle( $entries );

		return new Fake_Form_Source( 'fake', true, true, $data['forms'], $entries );
	}
}
