<?php
/**
 * Ability base class.
 *
 * @package WPMark
 */

namespace WPMark\Abilities;

use WP_Error;
use WPMark\Output\Untrusted_Text;
use WPMark\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Everything WPMark offers an AI assistant: tools, resources and prompts.
 *
 * Each one is a WordPress ability (the Abilities API, built into WordPress
 * 6.9), registered as "wpmark/<slug>". The MCP Adapter turns it into what
 * the AI sees; for tools, the name becomes "wpmark-<slug>".
 *
 * A subclass says what it is (slug, label, description, inputs) and what it
 * does (run()). This class handles the parts every ability shares:
 *
 * - Registration with WordPress, marked read-only and safe to repeat.
 * - Permission: the person must be allowed to use WPMark (their role is
 *   switched on) plus whatever extra capability the ability needs.
 * - Friendly errors: Invalid_Input becomes a plain-language message.
 */
abstract class Ability {

	public const TOOL     = 'tool';
	public const RESOURCE = 'resource';
	public const PROMPT   = 'prompt';

	/**
	 * Short ID, e.g. "site-overview".
	 *
	 * @return string
	 */
	abstract public function slug(): string;

	/**
	 * Name shown to people, e.g. "Site overview".
	 *
	 * @return string
	 */
	abstract public function label(): string;

	/**
	 * What the AI reads to decide when to use this. Write it like a brief
	 * to a new colleague: what it returns, when to use it, what it will not do.
	 *
	 * @return string
	 */
	abstract public function description(): string;

	/**
	 * Do the work.
	 *
	 * @param array $input Validated input.
	 * @return array Result.
	 * @throws Invalid_Input When the request cannot be answered as asked.
	 */
	abstract protected function run( array $input ): array;

	/**
	 * Tool, resource or prompt.
	 *
	 * @return string
	 */
	public function type(): string {
		return self::TOOL;
	}

	/**
	 * Full ability name.
	 *
	 * @return string e.g. "wpmark/site-overview".
	 */
	public function name(): string {
		return 'wpmark/' . $this->slug();
	}

	/**
	 * JSON Schema for the input. Properties only; the wrapper is added here.
	 *
	 * @return array<string, array> Property name => schema.
	 */
	public function input_properties(): array {
		return array();
	}

	/**
	 * Which input properties must be given.
	 *
	 * @return string[]
	 */
	public function required_input(): array {
		return array();
	}

	/**
	 * One sentence for the WPMark → Tools screen, written for the site owner.
	 *
	 * @return string
	 */
	public function summary(): string {
		return $this->label();
	}

	/**
	 * Whether the site has what this ability needs (e.g. a form plugin).
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * Why the ability is unavailable, in plain language.
	 *
	 * @return string
	 */
	public function unavailable_reason(): string {
		return '';
	}

	/**
	 * Whether the site owner can switch this off on the Tools screen.
	 *
	 * @return bool
	 */
	public function can_be_switched_off(): bool {
		return self::TOOL === $this->type();
	}

	/**
	 * Whether the site owner has switched this on (tools only; others are always on).
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return ! $this->can_be_switched_off() || Settings::tool_enabled( $this->slug() );
	}

	/**
	 * Whether the MCP server should offer this.
	 *
	 * @return bool
	 */
	public function is_offered(): bool {
		return $this->is_enabled() && $this->is_available();
	}

	/**
	 * Register with WordPress. Runs on wp_abilities_api_init.
	 */
	public function register(): void {
		$schema = array(
			'type'                 => 'object',
			// WordPress applies only a top-level default, so an empty call becomes {}.
			'default'              => array(),
			'additionalProperties' => false,
		);

		if ( $this->input_properties() ) {
			$schema['properties'] = $this->input_properties();
		}

		if ( $this->required_input() ) {
			$schema['required'] = $this->required_input();
		}

		wp_register_ability(
			$this->name(),
			array(
				'label'               => $this->label(),
				'description'         => $this->description(),
				'category'            => 'wpmark',
				'input_schema'        => $schema,
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'meta'                => $this->meta(),
			)
		);
	}

	/**
	 * Permission check. Runs before every call.
	 *
	 * @param mixed $input The call's input (unused by default).
	 * @return true|WP_Error
	 */
	public function check_permission( $input = null ) {
		unset( $input );

		if ( ! $this->is_offered() ) {
			return new WP_Error(
				'wpmark_unavailable',
				$this->is_enabled()
					? $this->unavailable_reason()
					: __( 'This tool has been switched off by the site administrator.', 'wpmark' )
			);
		}

		if ( ! Settings::user_can_connect( wp_get_current_user() ) ) {
			return new WP_Error(
				'wpmark_forbidden',
				__( 'Your WordPress account is not allowed to use WPMark. Ask a site administrator to switch on your role under WPMark → Access & privacy.', 'wpmark' )
			);
		}

		return $this->extra_permission();
	}

	/**
	 * Any further check a particular ability needs.
	 *
	 * @return true|WP_Error
	 */
	protected function extra_permission() {
		return true;
	}

	/**
	 * Run the ability, turning bad input into a friendly error.
	 *
	 * @param mixed $input Validated input.
	 * @return array|WP_Error
	 */
	public function execute( $input = null ) {
		try {
			$result = $this->run( is_array( $input ) ? $input : array() );
		} catch ( Invalid_Input $e ) {
			return new WP_Error( 'wpmark_invalid_input', $e->getMessage() );
		}

		/*
		 * Resources are returned in MCP's own "contents" format. Newer MCP
		 * Adapter versions would convert plain data themselves, but older ones
		 * (0.3.x, shipped by some plugins) pass it through as is, so WPMark
		 * does the conversion and works with both.
		 */
		if ( self::RESOURCE === $this->type() ) {
			return array(
				array(
					'uri'      => $this->resource_uri(),
					'mimeType' => 'application/json',
					'text'     => (string) wp_json_encode( $result ),
				),
			);
		}

		return $result;
	}

	/**
	 * Ability meta: read-only hints for tools, the MCP type for others.
	 *
	 * Not marked public, so the MCP Adapter's default server cannot reach
	 * it. WPMark's own server lists it explicitly.
	 *
	 * @return array
	 */
	protected function meta(): array {
		$meta = array(
			'show_in_rest' => false,
			'mcp'          => array(
				'public' => false,
				'type'   => $this->type(),
			),
		);

		if ( self::RESOURCE === $this->type() ) {
			$meta['mcp']['uri']      = $this->resource_uri();
			$meta['mcp']['mimeType'] = 'application/json';

			/*
			 * Older MCP Adapter versions (0.3.x, which some plugins such as
			 * Elementor ship, and which may be the copy loaded) read these at the
			 * top level. Newer versions read mcp.* first and never fall back, so
			 * setting both is harmless.
			 */
			$meta['uri']      = $meta['mcp']['uri'];
			$meta['mimeType'] = 'application/json';
		}

		if ( self::TOOL === $this->type() ) {
			$meta['annotations'] = array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			);
		}

		return $meta;
	}

	/**
	 * The address a resource is read at, e.g. "wpmark://site/profile".
	 *
	 * @return string
	 */
	public function resource_uri(): string {
		return '';
	}

	/**
	 * Wrap a tool's result with the data-not-instructions notice.
	 *
	 * @param array $data Result.
	 * @return array
	 */
	protected function respond( array $data ): array {
		return array_merge( array( 'notice' => Untrusted_Text::notice() ), $data );
	}

	/**
	 * Read an integer input within bounds.
	 *
	 * @param array  $input    Input.
	 * @param string $key      Property.
	 * @param int    $fallback Value when missing.
	 * @param int    $min      Smallest allowed.
	 * @param int    $max      Largest allowed.
	 * @return int
	 * @throws Invalid_Input When out of range.
	 */
	protected function int_input( array $input, string $key, int $fallback, int $min, int $max ): int {
		$value = isset( $input[ $key ] ) ? (int) $input[ $key ] : $fallback;

		if ( $value < $min || $value > $max ) {
			throw new Invalid_Input(
				sprintf(
					/* translators: 1: input name, 2: smallest value, 3: largest value. */
					__( '"%1$s" must be between %2$d and %3$d.', 'wpmark' ),
					$key,
					$min,
					$max
				)
			);
		}

		return $value;
	}
}
