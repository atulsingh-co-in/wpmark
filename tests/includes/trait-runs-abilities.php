<?php
/**
 * Helper for running abilities in tests.
 *
 * @package WPMark
 */

namespace WPMark\Tests;

use WP_Error;

/**
 * Runs an ability the way the MCP Adapter does: WordPress validates the
 * input against the schema, checks permission, then executes.
 */
trait Runs_Abilities {

	/**
	 * Run an ability as a user with the given role.
	 *
	 * @param string $name  Ability name without "wpmark/".
	 * @param mixed  $input Input.
	 * @param string $role  Role, or "" for a logged-out visitor.
	 * @return array|WP_Error
	 */
	protected function run_as( string $name, $input = null, string $role = 'administrator' ) {
		wp_set_current_user( '' === $role ? 0 : self::factory()->user->create( array( 'role' => $role ) ) );

		$ability = wp_get_ability( 'wpmark/' . $name );
		$this->assertNotNull( $ability, "Ability wpmark/{$name} is registered." );

		return $ability->execute( $input );
	}

	/**
	 * Read a resource and decode its JSON contents.
	 *
	 * @param string $name Resource slug.
	 * @param string $role Role.
	 * @return array
	 */
	protected function read_resource( string $name, string $role = 'administrator' ): array {
		$contents = $this->run_as( $name, null, $role );

		$this->assertSame( 'wpmark://', substr( $contents[0]['uri'], 0, 9 ) );
		$this->assertSame( 'application/json', $contents[0]['mimeType'] );

		return (array) json_decode( $contents[0]['text'], true );
	}

	/**
	 * Run an ability that should be refused permission.
	 *
	 * WordPress logs a developer notice when an ability is run without
	 * permission (the MCP Adapter checks permission first, so users never
	 * trigger it); the test expects that notice.
	 *
	 * @param string $name  Ability name without "wpmark/".
	 * @param mixed  $input Input.
	 * @param string $role  Role, or "" for a logged-out visitor.
	 * @return array|WP_Error
	 */
	protected function refused_as( string $name, $input = null, string $role = 'administrator' ) {
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		return $this->run_as( $name, $input, $role );
	}

	/**
	 * Assert a result is an error with the given code.
	 *
	 * @param string $code   Expected code.
	 * @param mixed  $result Result.
	 */
	protected function assert_error_code( string $code, $result ): void {
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code(), (string) $result->get_error_message() );
	}
}
