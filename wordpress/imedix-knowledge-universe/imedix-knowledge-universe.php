<?php
/**
 * Plugin Name:       iMedix Knowledge Universe
 * Description:       Interactive 3D knowledge-graph homepage (drugs, diseases, central search, live news ticker) as a page template that keeps the active theme's header, footer and SEO output.
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            iMedix
 * License:           GPL-2.0-or-later
 * Text Domain:       imedix-ku
 *
 * @package Imedix\KnowledgeUniverse
 */

defined( 'ABSPATH' ) || exit;

define( 'IMEDIX_KU_VERSION', '1.0.0' );
define( 'IMEDIX_KU_FILE', __FILE__ );
define( 'IMEDIX_KU_DIR', plugin_dir_path( __FILE__ ) );
define( 'IMEDIX_KU_URL', plugin_dir_url( __FILE__ ) );

require IMEDIX_KU_DIR . 'src/autoload.php';

register_activation_hook( __FILE__, array( \Imedix\KnowledgeUniverse\WordPress\Sync::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Imedix\KnowledgeUniverse\WordPress\Sync::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \Imedix\KnowledgeUniverse\WordPress\Plugin::class, 'boot' ) );
