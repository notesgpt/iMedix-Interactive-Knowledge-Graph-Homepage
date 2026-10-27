<?php
/**
 * Removes plugin options, cache and generated files. Never touches posts.
 *
 * @package Imedix\KnowledgeUniverse
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require __DIR__ . '/src/autoload.php';

foreach ( array( 'imedix_ku_settings', 'imedix_ku_graph', 'imedix_ku_state', 'imedix_ku_report' ) as $imedix_ku_option ) {
	delete_option( $imedix_ku_option );
}
delete_transient( 'imedix_ku_news_v1' );
delete_transient( 'imedix_ku_sync_lock' );
wp_clear_scheduled_hook( 'imedix_ku_sync' );
\Imedix\KnowledgeUniverse\WordPress\Sync::delete_files();
