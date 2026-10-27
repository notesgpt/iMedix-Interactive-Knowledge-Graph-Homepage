#!/usr/bin/env php
<?php
/**
 * Renders the plugin's real PHP template (templates/partials/page.php) outside
 * WordPress, so the Vite dev server and the browser tests exercise exactly the
 * markup WordPress serves. WordPress functions are replaced by minimal stubs.
 *
 * Usage:
 *   php bin/render-preview.php                     → frontend/index.html (Vite dev)
 *   php bin/render-preview.php --mode=dist         → build/preview/index.html (built bundle)
 *   php bin/render-preview.php --news-live         → real latest news from the site's RSS feed
 *                                                   (/news/feed/); hidden if the feed is unreachable
 *   php bin/render-preview.php --news-fixture      → adds clearly-labelled synthetic news (tests only)
 *
 * The preview header/footer are stand-ins: in WordPress the theme's own
 * header and footer are used. Synthetic news is never part of the plugin.
 */

use Imedix\KnowledgeUniverse\Core\ClientPayload;
use Imedix\KnowledgeUniverse\Core\Discovery;

define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../wordpress/imedix-knowledge-universe/src/autoload.php';

$opts  = getopt( '', array( 'mode::', 'news-fixture', 'news-live', 'news-feed:', 'site::' ) );
$mode  = $opts['mode'] ?? 'dev';
$site  = rtrim( $opts['site'] ?? 'https://www.imedix.com', '/' );
$root  = dirname( __DIR__ );
$full  = $root . '/frontend/public/dev/graph.full.json';

/* ---- WordPress stubs (escaping semantics match core closely enough for a preview). ---- */
function __( $t ) { return $t; }
function _n( $s, $p, $n ) { return 1 === (int) $n ? $s : $p; }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES | ENT_HTML5, 'UTF-8' ); }
function esc_attr( $t ) { return esc_html( $t ); }
function esc_html__( $t ) { return esc_html( $t ); }
function esc_html_e( $t ) { echo esc_html( $t ); }
function esc_attr_e( $t ) { echo esc_attr( $t ); }
function esc_url( $u ) { return preg_match( '#^(https?:)?/|^\##', (string) $u ) ? esc_attr( $u ) : ''; }
function wp_kses( $html, $allowed ) { return strip_tags( $html, '<' . implode( '><', array_keys( $allowed ) ) . '>' ); }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
function home_url( $path = '' ) { global $site; return $site . $path; }
function date_i18n( $format, $ts ) { return gmdate( $format, $ts ); }

if ( ! is_file( $full ) ) {
	fwrite( STDERR, "Missing $full — run `npm run fixture` in frontend/.\n" );
	exit( 1 );
}
$graph  = json_decode( (string) file_get_contents( $full ), true );
$client = ClientPayload::from_graph( $graph );

/**
 * Latest items of the live news feed, in the same shape as News::latest().
 * Only links on the site's own host are accepted; nothing is invented.
 */
function ku_live_news( string $site, int $limit, ?string $feed_override = null ): array {
	$host = (string) parse_url( $site, PHP_URL_HOST );
	$ctx  = stream_context_create( array( 'http' => array( 'timeout' => 8, 'user_agent' => 'iMedix-KU-preview/1.0' ) ) );
	foreach ( $feed_override ? array( $feed_override ) : array( $site . '/news/feed/', $site . '/feed/?post_type=news' ) as $feed ) {
		$xml = @file_get_contents( $feed, false, $ctx );
		if ( ! $xml || false === strpos( $xml, '<rss' ) ) {
			continue;
		}
		$rss = @simplexml_load_string( $xml );
		if ( ! $rss || ! isset( $rss->channel->item ) ) {
			continue;
		}
		$items = array();
		foreach ( $rss->channel->item as $item ) {
			$url = trim( (string) $item->link );
			if ( parse_url( $url, PHP_URL_HOST ) !== $host || 'https' !== parse_url( $url, PHP_URL_SCHEME ) ) {
				continue;
			}
			$items[] = array(
				'id'      => crc32( $url ),
				'title'   => trim( html_entity_decode( strip_tags( (string) $item->title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ),
				'url'     => $url,
				'date'    => gmdate( 'c', (int) strtotime( (string) $item->pubDate ) ),
			);
			if ( count( $items ) >= $limit ) {
				break;
			}
		}
		if ( $items ) {
			return $items;
		}
	}
	fwrite( STDERR, "News feed unreachable at $site/news/feed/ — the ticker stays hidden.\n" );
	return array();
}

$news = array();
if ( isset( $opts['news-live'] ) || isset( $opts['news-feed'] ) ) {
	// --news-feed=<file|url> reads a saved copy of the feed (offline checks).
	$news = ku_live_news( $site, 10, $opts['news-feed'] ?? null );
} elseif ( isset( $opts['news-fixture'] ) ) {
	// Synthetic, clearly labelled items for exercising the news layout in tests.
	for ( $i = 1; $i <= 10; $i++ ) {
		$news[] = array(
			'id'      => 9000 + $i,
			'title'   => sprintf( '[Dev fixture %d] Synthetic headline used only to test the ticker', $i ),
			'url'     => $site . '/news/dev-fixture-' . $i . '/',
			'date'    => gmdate( 'c', strtotime( '2026-10-27T09:00:00Z' ) - $i * 86400 ),
		);
	}
}

$vm = array(
	'counts'         => $client['counts'],
	'hasGraph'       => true,
	'areas'          => Discovery::areas( $client ),
	'suggestions'    => Discovery::suggestions( $client ),
	'news'           => $news,
	'newsArchive'    => $news ? $site . '/news/' : null,
	'searchUrl'      => $site . '/',
	'drugArchive'    => null,
	'diseaseArchive' => null,
);

$out_dir = 'dist' === $mode ? $root . '/build/preview' : $root . '/frontend';
$dev_dir = 'dist' === $mode ? $out_dir . '/dev' : $root . '/frontend/public/dev';
@mkdir( $dev_dir, 0777, true );
file_put_contents( $dev_dir . '/graph.json', json_encode( $client, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
file_put_contents( $dev_dir . '/news.json', json_encode( array( 'available' => true, 'items' => $news ), JSON_UNESCAPED_SLASHES ) );

$config = json_encode(
	array(
		'graphUrl'     => 'dev/graph.json',
		'graphRestUrl' => '',
		'graphVersion' => '',
		'newsUrl'      => 'dev/news.json',
		'newsCount'    => 12,
		'newsRefresh'  => 600,
		'searchUrl'    => $site . '/',
		'siteOrigin'   => $site,
		'locale'       => 'en-US',
	),
	JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
);

ob_start();
include $root . '/wordpress/imedix-knowledge-universe/templates/partials/page.php';
$body = ob_get_clean();

if ( 'dist' === $mode ) {
	$manifest = json_decode( (string) file_get_contents( $root . '/wordpress/imedix-knowledge-universe/assets/dist/.vite/manifest.json' ), true );
	$entry    = $manifest['src/main.ts'];
	$base     = '../../wordpress/imedix-knowledge-universe/assets/dist/';
	$assets   = '';
	foreach ( $entry['css'] ?? array() as $css ) {
		$assets .= '<link rel="stylesheet" href="' . $base . $css . '">' . "\n";
	}
	$assets .= '<script type="module" src="' . $base . $entry['file'] . '"></script>';
} else {
	$assets = '<script type="module" src="/src/main.ts"></script>';
}

$html = <<<HTML
<!doctype html>
<html lang="en-US">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>iMedix: Independent Drug &amp; Health Platform</title>
<meta name="description" content="iMedix provides independent, evidence-based information on medicines and health, featuring medically reviewed content, traceable sources, and strict standards.">
<meta name="robots" content="noindex">
<!-- Preview only. In WordPress the SEO plugin owns title/description and the Site Icon owns the favicon; the plugin outputs neither. -->
<link rel="icon" href="$site/wp-content/uploads/2024/08/cropped-iMedix-icon-150x150.png" sizes="150x150">
<style>
  /* Stand-in for the active WordPress theme (not part of the plugin). It deliberately uses
     the system font: the real iMedix font is inherited from the theme in WordPress.
     Override with ?font= or by defining --imedix-* tokens to preview brand values. */
  body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: #fff; color: #112236; }
  .preview-footer { padding: 32px clamp(16px, 4vw, 40px); background: #112236; color: #a3bad2; font-size: 14px; }
</style>
$assets
</head>
<body class="page-template imedix-ku-page">
<main id="primary" class="imedix-ku-main">
$body
</main>
<footer class="preview-footer" role="contentinfo">Theme footer stand-in — WordPress renders the real iMedix footer here.</footer>
</body>
</html>
HTML;

file_put_contents( $out_dir . '/index.html', $html );
fwrite( STDERR, "Wrote $out_dir/index.html\n" );
