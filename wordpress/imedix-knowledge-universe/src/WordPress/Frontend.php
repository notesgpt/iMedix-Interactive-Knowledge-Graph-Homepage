<?php
/**
 * Page template, shortcode, asset loading and server-side rendering.
 *
 * The homepage is delivered as a page template provided by this plugin. The
 * active theme still renders <head>, header and footer, so branding,
 * navigation, canonical URL and SEO-plugin metadata stay exactly as they are.
 *
 * @package Imedix\KnowledgeUniverse
 */

namespace Imedix\KnowledgeUniverse\WordPress;

final class Frontend {

	public const TEMPLATE  = 'imedix-knowledge-universe.php';
	public const SHORTCODE = 'imedix_knowledge_universe';
	private const HANDLE   = 'imedix-ku';

	private static bool $rendered = false;

	public static function register(): void {
		add_filter( 'theme_page_templates', array( self::class, 'add_template' ) );
		add_filter( 'template_include', array( self::class, 'template_include' ), 99 );
		add_filter( 'body_class', array( self::class, 'body_class' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'maybe_enqueue' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'trim_theme_assets' ), 100 );
		add_action( 'wp', array( self::class, 'drop_header_preload' ) );
		add_filter( 'script_loader_tag', array( self::class, 'module_tag' ), 10, 2 );
		add_shortcode( self::SHORTCODE, array( self::class, 'shortcode' ) );
	}

	/**
	 * @param array<string, string> $templates
	 * @return array<string, string>
	 */
	public static function add_template( $templates ): array {
		$templates                   = (array) $templates;
		$templates[ self::TEMPLATE ] = __( 'iMedix Knowledge Universe', 'imedix-ku' );
		return $templates;
	}

	public static function is_ku_page(): bool {
		if ( ! is_singular( 'page' ) ) {
			return false;
		}
		if ( self::TEMPLATE === get_page_template_slug( get_queried_object_id() ) ) {
			return true;
		}
		$post = get_post( get_queried_object_id() );
		return $post instanceof \WP_Post && has_shortcode( (string) $post->post_content, self::SHORTCODE );
	}

	/**
	 * @param string $template Resolved template path.
	 */
	public static function template_include( $template ): string {
		if ( is_singular( 'page' ) && self::TEMPLATE === get_page_template_slug( get_queried_object_id() ) ) {
			return IMEDIX_KU_DIR . 'templates/knowledge-universe.php';
		}
		return (string) $template;
	}

	/**
	 * @param string[] $classes
	 * @return string[]
	 */
	public static function body_class( $classes ): array {
		$classes = (array) $classes;
		if ( self::is_ku_page() ) {
			$classes[] = 'imedix-ku-page';
		}
		return $classes;
	}

	/**
	 * Theme and plugin scripts that the Knowledge Universe page never uses
	 * (carousel, lightbox, masonry, sticky sidebar, tooltips, IE8 shims, code
	 * highlighting). Removed on this template only; jQuery, Bootstrap and the
	 * menu script stay because the theme footer may rely on them.
	 */
	private const UNUSED_SCRIPTS = '#/(themes/himer/js/(html5|modernizr|scrollbar|theia|owl|matchHeight|prettyPhoto|tabs|tipsy|isotope)\.js|plugins/highlighting-code-block/|plugins/contact-form-7/|wp-includes/js/clipboard(\.min)?\.js)#';

	/** Plugin styles with nothing to style on this page (code highlighting, contact forms). Matched by handle or source. */
	private const UNUSED_STYLES = '#^hcb-|^contact-form-7|/plugins/(highlighting-code-block|contact-form-7)/#';

	public static function trim_theme_assets(): void {
		if ( ! is_singular( 'page' ) || self::TEMPLATE !== get_page_template_slug( get_queried_object_id() ) ) {
			return;
		}
		$pattern = (string) apply_filters( 'imedix_ku_unused_scripts', self::UNUSED_SCRIPTS );
		$scripts = wp_scripts();
		foreach ( (array) $scripts->queue as $handle ) {
			$src = (string) ( $scripts->registered[ $handle ]->src ?? '' );
			if ( '' !== $src && preg_match( $pattern, $src ) ) {
				wp_dequeue_script( $handle );
			}
		}
		$styles = wp_styles();
		foreach ( (array) $styles->queue as $handle ) {
			$src = (string) ( $styles->registered[ $handle ]->src ?? '' );
			if ( preg_match( self::UNUSED_STYLES, (string) $handle ) || ( '' !== $src && preg_match( self::UNUSED_STYLES, $src ) ) ) {
				wp_dequeue_style( $handle );
			}
		}
	}

	/** The page has no visible site header, so the header logo must not be preloaded (iMedix child theme). */
	public static function drop_header_preload(): void {
		if ( is_singular( 'page' ) && self::TEMPLATE === get_page_template_slug( get_queried_object_id() ) ) {
			remove_action( 'wp_head', 'imedix_preload_critical_resources', 2 );
		}
	}

	public static function maybe_enqueue(): void {
		if ( ! self::is_ku_page() ) {
			return;
		}
		$manifest = self::manifest();
		$entry    = $manifest['src/main.ts'] ?? null;
		if ( ! is_array( $entry ) || empty( $entry['file'] ) ) {
			return; // Server-rendered page still works without the bundle.
		}
		$base = IMEDIX_KU_URL . 'assets/dist/';
		foreach ( (array) ( $entry['css'] ?? array() ) as $i => $css ) {
			// Hashed filenames make the version string redundant: null avoids "?ver=".
			wp_enqueue_style( self::HANDLE . '-' . $i, $base . $css, array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		}
		wp_enqueue_script( self::HANDLE, $base . $entry['file'], array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	}

	/**
	 * @param string $tag    Script tag.
	 * @param string $handle Handle.
	 */
	public static function module_tag( $tag, $handle ): string {
		if ( self::HANDLE !== $handle ) {
			return (string) $tag;
		}
		// Themes without html5 "script" support get type="text/javascript": replace it.
		$tag = (string) preg_replace( '/\stype=(["\'])text\/javascript\1/', '', (string) $tag );
		return (string) preg_replace( '/<script\b(?![^>]*\btype=)/', '<script type="module"', $tag );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function manifest(): array {
		$file = IMEDIX_KU_DIR . 'assets/dist/.vite/manifest.json';
		if ( ! is_readable( $file ) ) {
			return array();
		}
		$data = json_decode( (string) file_get_contents( $file ), true );
		return is_array( $data ) ? $data : array();
	}

	public static function shortcode(): string {
		ob_start();
		self::render_page();
		return (string) ob_get_clean();
	}

	/**
	 * Print the complete Knowledge Universe markup (once per request).
	 */
	public static function render_page(): void {
		if ( self::$rendered ) {
			return;
		}
		self::$rendered = true;

		$vm     = ViewModel::build();
		$config = self::config_json( $vm );
		include IMEDIX_KU_DIR . 'templates/partials/page.php';
	}

	/**
	 * Client configuration printed as inert JSON.
	 *
	 * @param array<string, mixed> $vm View model.
	 */
	public static function config_json( array $vm ): string {
		$state  = Sync::state();
		$origin = (string) wp_parse_url( home_url(), PHP_URL_SCHEME ) . '://' . (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$config = array(
			// The URL is stored at sync time; a WP-CLI/cron sync has no HTTPS context, so re-apply the request scheme.
			'graphUrl'     => ! empty( $state['file'] ) ? set_url_scheme( $state['file'] ) : rest_url( Rest::NS . '/graph' ),
			'graphRestUrl' => rest_url( Rest::NS . '/graph' ),
			'graphVersion' => (string) ( $state['version'] ?? '' ),
			'newsUrl'      => rest_url( Rest::NS . '/news' ),
			'newsCount'    => (int) Settings::get( 'news_count' ),
			'newsRefresh'  => (int) Settings::get( 'news_refresh_secs' ),
			'searchUrl'    => home_url( '/' ),
			'siteOrigin'   => $origin,
			'locale'       => str_replace( '_', '-', get_locale() ),
		);
		return (string) wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES );
	}
}
