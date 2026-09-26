<?php
/**
 * WPMark's MCP server.
 *
 * @package WPMark
 */

namespace WPMark;

use WP\MCP\Core\McpAdapter;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;
use WP\MCP\Transport\HttpTransport;
use WPMark\Abilities\Abilities;
use WPMark\Abilities\Ability;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the address AI assistants connect to: /wp-json/wpmark/mcp
 *
 * The MCP Adapter plugin does the protocol work (we never re-implement it).
 * This class only tells the adapter which WPMark tools to offer and who may
 * connect.
 *
 * Why a separate server instead of the adapter's default one: the default
 * server hides every tool behind three generic "discover / describe /
 * execute ability" tools, so the AI never reads our tool descriptions
 * directly. Here each WPMark tool is listed by name with its own
 * description, which is what lets a model pick the right one.
 *
 * WPMark abilities are not marked public, so they are not reachable through
 * the default server either. This server is the only way in.
 */
final class Mcp_Server {

	/**
	 * Unique ID of the server inside the MCP Adapter.
	 */
	public const ID = 'wpmark';

	/**
	 * REST namespace and route. Together: /wp-json/wpmark/mcp
	 */
	public const REST_NAMESPACE = 'wpmark';
	public const REST_ROUTE     = 'mcp';

	/**
	 * The full address AI apps connect to. Uses ?rest_route= on sites with
	 * plain permalinks, so it always works.
	 *
	 * @return string
	 */
	public static function url(): string {
		return rest_url( self::REST_NAMESPACE . '/' . self::REST_ROUTE );
	}

	/**
	 * Create the server. Runs on the mcp_adapter_init action.
	 *
	 * @param McpAdapter $adapter The MCP Adapter instance.
	 */
	public static function register( McpAdapter $adapter ): void {
		$result = $adapter->create_server(
			self::ID,
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			'WPMark',
			__( 'Marketing tools for this WordPress site: content, SEO and leads. Read-only.', 'wpmark' ),
			WPMARK_VERSION,
			array( HttpTransport::class ),
			ErrorLogMcpErrorHandler::class,
			NullMcpObservabilityHandler::class,
			self::tool_names(),
			self::names( Ability::RESOURCE ),
			self::names( Ability::PROMPT ),
			array( self::class, 'can_connect' )
		);

		if ( is_wp_error( $result ) ) {
			wp_trigger_error(
				__METHOD__,
				sprintf(
					/* translators: %s: error message from the MCP Adapter. */
					__( 'WPMark could not create its MCP server: %s', 'wpmark' ),
					$result->get_error_message()
				)
			);
		}
	}

	/**
	 * The abilities this server offers as tools: switched on, and supported
	 * by this site (the lead tools need a form plugin, for example).
	 *
	 * @return string[] Ability names.
	 */
	public static function tool_names(): array {
		return self::names( Ability::TOOL );
	}

	/**
	 * Names of the offered abilities of one type.
	 *
	 * @param string $type Ability::TOOL, RESOURCE or PROMPT.
	 * @return string[]
	 */
	public static function names( string $type ): array {
		return array_map( static fn( Ability $ability ): string => $ability->name(), Abilities::offered( $type ) );
	}

	/**
	 * Who may connect at all.
	 *
	 * This is the front door only: someone who can write posts and whose
	 * role is switched on under WPMark → Access & privacy. Each tool still
	 * checks its own, often stricter, permission before it runs. Logged-out
	 * visitors and subscribers are turned away.
	 *
	 * @return bool Whether the current user may use the MCP endpoint.
	 */
	public static function can_connect(): bool {
		return Settings::user_can_connect( wp_get_current_user() );
	}
}
