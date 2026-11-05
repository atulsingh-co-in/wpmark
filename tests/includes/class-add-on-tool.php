<?php
/**
 * An add-on's tool, for the tests.
 *
 * @package WPMark
 */

namespace WPMark\Tests;

use WPMark\Abilities\Ability;

/**
 * An add-on's tool, for the tests.
 */
final class Add_On_Tool extends Ability {

	/**
	 * Slug.
	 *
	 * @var string
	 */
	private string $slug;

	/**
	 * A tool with a slug.
	 *
	 * @param string $slug Slug.
	 */
	public function __construct( string $slug = 'add-on-tool' ) {
		$this->slug = $slug;
	}

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return $this->slug;
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Add-on tool';
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'A tool from an add-on.';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $input Input.
	 */
	protected function run( array $input ): array {
		return array( 'from' => 'add-on' );
	}
}
