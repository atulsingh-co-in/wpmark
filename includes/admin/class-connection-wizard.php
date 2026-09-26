<?php
/**
 * Connection wizard.
 *
 * @package WPMark
 */

namespace WPMark\Admin;

use WP_Application_Passwords;
use WP_Error;
use WP_User;
use WPMark\Mcp_Server;
use WPMark\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Creates a connection key and the exact setup text for each AI app.
 *
 * The key is a WordPress Application Password for the person's own
 * account, so the AI can only ever do what that person may do, and they
 * can revoke it any time from their profile. It is shown once and never
 * stored in readable form (WordPress keeps only a hash).
 */
final class Connection_Wizard {

	/**
	 * AI apps with ready-made setup, keyed by ID.
	 *
	 * @return array<string, string> ID => name.
	 */
	public static function apps(): array {
		return array(
			'claude-desktop' => 'Claude Desktop',
			'claude-code'    => 'Claude Code',
			'cursor'         => 'Cursor / Windsurf',
			'vscode'         => 'VS Code (GitHub Copilot)',
			'gemini-cli'     => 'Gemini CLI',
			'inspector'      => 'MCP Inspector (testing)',
		);
	}

	/**
	 * Create a connection key for a person.
	 *
	 * @param WP_User $user Person, normally the current user.
	 * @param string  $app  App ID from apps(), used in the key's name.
	 * @return array{username: string, password: string, url: string}|WP_Error
	 */
	public static function create_key( WP_User $user, string $app ) {
		if ( ! Settings::user_can_connect( $user ) ) {
			return new WP_Error( 'wpmark_forbidden', __( 'Your role is not allowed to use WPMark. Ask an administrator to switch it on under WPMark → Access & privacy.', 'wpmark' ) );
		}

		if ( ! wp_is_application_passwords_available_for_user( $user ) ) {
			return new WP_Error( 'wpmark_no_app_passwords', __( 'Connection keys (Application Passwords) are switched off on this site. See the Health check for how to fix it.', 'wpmark' ) );
		}

		$apps = self::apps();
		$name = sprintf(
			/* translators: 1: AI app name, 2: date. */
			__( 'WPMark – %1$s – %2$s', 'wpmark' ),
			$apps[ $app ] ?? __( 'AI assistant', 'wpmark' ),
			wp_date( 'Y-m-d H:i' )
		);

		$created = WP_Application_Passwords::create_new_application_password( $user->ID, array( 'name' => $name ) );

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		return array(
			'username' => $user->user_login,
			'password' => $created[0],
			'url'      => Mcp_Server::url(),
		);
	}

	/**
	 * The HTTP Basic login header value for a key.
	 *
	 * @param array{username: string, password: string} $key Key.
	 * @return string e.g. "Basic dXNlcjpwYXNz".
	 */
	public static function auth_header( array $key ): string {
		return 'Basic ' . base64_encode( $key['username'] . ':' . str_replace( ' ', '', $key['password'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic authentication is defined as base64.
	}

	/**
	 * Setup instructions and text to paste, for one app.
	 *
	 * @param string $app App ID.
	 * @param array  $key Key from create_key().
	 * @return array{steps: string, code: string}
	 */
	public static function setup( string $app, array $key ): array {
		$url    = $key['url'];
		$header = self::auth_header( $key );
		$json   = static fn( array $data ): string => (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		switch ( $app ) {
			case 'claude-desktop':
				return array(
					'steps' => __( 'Needs Node.js (free, from nodejs.org) installed once. In Claude Desktop open Settings → Developer → Edit Config, paste this into claude_desktop_config.json (merge with anything already under "mcpServers"), save, and restart Claude Desktop. WPMark then appears under the tools icon.', 'wpmark' ),
					'code'  => $json(
						array(
							'mcpServers' => array(
								'wpmark' => array(
									'command' => 'npx',
									'args'    => array( '-y', 'mcp-remote@latest', $url, '--header', 'Authorization:${WPMARK_AUTH}' ),
									'env'     => array( 'WPMARK_AUTH' => $header ),
								),
							),
						)
					),
				);

			case 'claude-code':
				return array(
					'steps' => __( 'Run this once in a terminal. WPMark is then available in every Claude Code session.', 'wpmark' ),
					'code'  => sprintf( 'claude mcp add --transport http wpmark %s --header "Authorization: %s"', $url, $header ),
				);

			case 'cursor':
				return array(
					'steps' => __( 'In Cursor: Settings → MCP → Add new server, or add this to ~/.cursor/mcp.json. Windsurf uses the same format in ~/.codeium/windsurf/mcp_config.json (use "serverUrl" instead of "url").', 'wpmark' ),
					'code'  => $json(
						array(
							'mcpServers' => array(
								'wpmark' => array(
									'url'     => $url,
									'headers' => array( 'Authorization' => $header ),
								),
							),
						)
					),
				);

			case 'vscode':
				return array(
					'steps' => __( 'Add this to .vscode/mcp.json in your project (or run "MCP: Open User Configuration" for all projects), then start the server from the file.', 'wpmark' ),
					'code'  => $json(
						array(
							'servers' => array(
								'wpmark' => array(
									'type'    => 'http',
									'url'     => $url,
									'headers' => array( 'Authorization' => $header ),
								),
							),
						)
					),
				);

			case 'gemini-cli':
				return array(
					'steps' => __( 'Add this to ~/.gemini/settings.json, then restart Gemini CLI.', 'wpmark' ),
					'code'  => $json(
						array(
							'mcpServers' => array(
								'wpmark' => array(
									'httpUrl' => $url,
									'headers' => array( 'Authorization' => $header ),
								),
							),
						)
					),
				);

			default:
				return array(
					'steps' => __( 'Run "npx @modelcontextprotocol/inspector" in a terminal. In the page that opens choose Transport: Streamable HTTP, paste the address, open Authentication and add a header named Authorization with the value below, then Connect. You can list and try every WPMark tool.', 'wpmark' ),
					'code'  => $url . "\n" . $header,
				);
		}
	}
}
