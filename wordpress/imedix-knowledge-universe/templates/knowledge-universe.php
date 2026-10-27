<?php
/**
 * Page template "iMedix Knowledge Universe".
 *
 * Uses the active theme's header and footer so navigation, branding, the
 * canonical URL and all SEO-plugin output in <head> stay unchanged.
 * Classic themes: get_header()/get_footer(). Block themes: the theme's
 * "header"/"footer" template parts inside a standard document shell.
 *
 * @package Imedix\KnowledgeUniverse
 */

defined( 'ABSPATH' ) || exit;

$imedix_ku_block_theme = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();

if ( $imedix_ku_block_theme ) :
	?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
	<?php wp_body_open(); ?>
	<div class="wp-site-blocks">
		<header class="wp-block-template-part"><?php block_template_part( 'header' ); ?></header>
	<?php
else :
	get_header();
endif;
?>
<main id="primary" class="imedix-ku-main">
	<?php \Imedix\KnowledgeUniverse\WordPress\Frontend::render_page(); ?>
</main>
<?php
if ( $imedix_ku_block_theme ) :
	?>
	<footer class="wp-block-template-part"><?php block_template_part( 'footer' ); ?></footer>
	</div>
	<?php wp_footer(); ?>
</body>
</html>
	<?php
else :
	// Themes that print their visible footer from a template part in each template
	// (iMedix "himer" child theme: includes/parts/new-footer.php) get it here too.
	// The homepage deliberately has no visible site header.
	$imedix_ku_footer = locate_template( (string) apply_filters( 'imedix_ku_footer_part', 'includes/parts/new-footer.php' ) );
	if ( $imedix_ku_footer ) {
		// The part first closes the wrappers its header part opened; without that
		// header they would be orphan closing tags, so only the footer is kept.
		ob_start();
		include $imedix_ku_footer;
		echo preg_replace( '#^(?:\s|</(?:div|section|main)>|<!--.*?-->)+(?=<footer)#s', '', (string) ob_get_clean() ); // phpcs:ignore WordPress.Security.EscapeOutput -- theme markup.
	}
	get_footer();
endif;
