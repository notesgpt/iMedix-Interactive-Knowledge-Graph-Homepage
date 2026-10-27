<?php
/**
 * Wires plugin components into WordPress.
 *
 * @package Imedix\KnowledgeUniverse
 */

namespace Imedix\KnowledgeUniverse\WordPress;

final class Plugin {

	public static function boot(): void {
		Sync::register();
		News::register();
		Rest::register();
		Frontend::register();

		if ( is_admin() ) {
			Admin::register();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'imedix-ku', Cli::class );
		}
	}
}
