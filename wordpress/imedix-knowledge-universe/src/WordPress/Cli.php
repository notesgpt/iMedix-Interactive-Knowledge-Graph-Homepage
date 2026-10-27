<?php
/**
 * WP-CLI commands.
 *
 *   wp imedix-ku sync [--force]
 *   wp imedix-ku status
 *   wp imedix-ku rejected [--format=table|csv|json]
 *
 * @package Imedix\KnowledgeUniverse
 */

namespace Imedix\KnowledgeUniverse\WordPress;

final class Cli {

	/**
	 * Rebuild the graph from Google Sheets and WordPress.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Rebuild even if the sheet and content are unchanged.
	 *
	 * @param string[]              $args       Positional args.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function sync( $args, $assoc_args ): void {
		$result = Sync::run( isset( $assoc_args['force'] ), 'cli' );
		if ( 'error' === $result['result'] || 'locked' === $result['result'] ) {
			\WP_CLI::error( $result['message'] );
		}
		\WP_CLI::success( $result['result'] . ': ' . $result['message'] );
	}

	/**
	 * Show the last sync state.
	 */
	public function status(): void {
		\WP_CLI::line( (string) wp_json_encode( Sync::state(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * List records rejected by the last build.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, csv or json.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param string[]              $args       Positional args.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function rejected( $args, $assoc_args ): void {
		$report = get_option( Sync::OPTION_REPORT, array() );
		$rows   = is_array( $report ) && ! empty( $report['rejected'] ) ? $report['rejected'] : array();
		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'sheet', 'row', 'slug', 'reason', 'detail' ) );
	}
}
