<?php
/**
 * Plugin Name: KU integration test environment (test sites only)
 *
 * - Registers drugs / diseases / news post types the way a typical iMedix-like
 *   install would (the plugin itself never assumes these names).
 * - Serves the Google Sheets CSV export from the repository's tracker snapshot,
 *   because the test sandbox cannot reach docs.google.com. Scenarios:
 *     option ku_test_sheet = ok | html (sheet no longer shared) | truncated
 */

add_action(
	'init',
	static function () {
		register_post_type( 'drugs', array( 'public' => true, 'label' => 'Drugs', 'rewrite' => array( 'slug' => 'drugs' ), 'has_archive' => true ) );
		register_post_type( 'diseases', array( 'public' => true, 'label' => 'Diseases', 'rewrite' => array( 'slug' => 'diseases' ), 'has_archive' => false ) );
		register_post_type( 'news', array( 'public' => true, 'label' => 'News', 'rewrite' => array( 'slug' => 'news' ), 'has_archive' => true, 'show_in_rest' => false ) );
	}
);

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( false === strpos( $url, 'docs.google.com/spreadsheets/' ) ) {
			return $pre;
		}
		$GLOBALS['ku_test_requests'][] = $url;
		$scenario = get_option( 'ku_test_sheet', 'ok' );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		$files = array( '975154414' => 'drugs.csv', '77683610' => 'diseases.csv', '383967448' => 'redirects.csv' );
		$file  = getenv( 'KU_FIXTURES' ) . '/' . ( $files[ $q['gid'] ?? '' ] ?? 'missing.csv' );
		if ( 'html' === $scenario ) {
			return array( 'headers' => array( 'content-type' => 'text/html; charset=utf-8' ), 'body' => '<html>Sign in</html>', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
		}
		if ( ! is_readable( $file ) ) {
			return array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 404, 'message' => 'Not Found' ), 'cookies' => array(), 'filename' => null );
		}
		$body = file_get_contents( $file );
		if ( 'truncated' === $scenario && 'drugs.csv' === basename( $file ) ) {
			$body = implode( "\n", array_slice( explode( "\n", $body ), 0, 6 ) );
		}
		return array( 'headers' => array( 'content-type' => 'text/csv' ), 'body' => $body, 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	},
	10,
	3
);
