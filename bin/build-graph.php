#!/usr/bin/env php
<?php
/**
 * Standalone graph builder (no WordPress). Uses the same Core classes as the plugin.
 *
 * Usage:
 *   php bin/build-graph.php --drugs=drugs.csv --diseases=diseases.csv [--redirects=redirects.csv] \
 *       [--out=graph.json] [--report=report.json] [--generated-at=2026-10-27T00:00:00Z]
 *
 * Each --drugs/--diseases/--redirects value may be a local CSV path. Live Sheets
 * access belongs to the WordPress plugin (server-side, cached); this CLI is for
 * development fixtures and CI.
 *
 * Output from this CLI has publication status "unverified": only the plugin can
 * confirm live WordPress status.
 */

use Imedix\KnowledgeUniverse\Core\Csv;
use Imedix\KnowledgeUniverse\Core\GraphBuilder;

require __DIR__ . '/../wordpress/imedix-knowledge-universe/src/autoload.php';

$opts = getopt( '', array( 'drugs:', 'diseases:', 'redirects::', 'out::', 'report::', 'generated-at::', 'spreadsheet-id::' ) );
foreach ( array( 'drugs', 'diseases' ) as $req ) {
	if ( empty( $opts[ $req ] ) ) {
		fwrite( STDERR, "Missing --$req\n" );
		exit( 2 );
	}
}

$read = static function ( string $path ): array {
	if ( ! is_file( $path ) || ! is_readable( $path ) ) {
		throw new RuntimeException( "Cannot read $path" );
	}
	return Csv::parse( (string) file_get_contents( $path ) );
};

try {
	$drugs     = $read( $opts['drugs'] );
	$diseases  = $read( $opts['diseases'] );
	$redirects = empty( $opts['redirects'] ) ? null : $read( $opts['redirects'] );
	$hash      = substr( hash( 'sha256', json_encode( array( $drugs, $diseases, $redirects ) ) ), 0, 16 );

	$builder = new GraphBuilder(
		array(
			'spreadsheet_id' => $opts['spreadsheet-id'] ?? '1KLBtihRPuRrwAn9VB0a9lVcyg9z-kjZAqvg4UWO0Ivk',
			'generated_at'   => $opts['generated-at'] ?? gmdate( 'c' ),
			'source_hash'    => $hash,
		)
	);
	$result = $builder->build( $drugs, $diseases, $redirects );
} catch ( Throwable $e ) {
	fwrite( STDERR, 'Build failed: ' . $e->getMessage() . "\n" );
	exit( 1 );
}

$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
$json  = json_encode( $result['graph'], $flags );
if ( ! empty( $opts['out'] ) ) {
	file_put_contents( $opts['out'], $json . "\n" );
} else {
	echo $json, "\n";
}
if ( ! empty( $opts['report'] ) ) {
	file_put_contents( $opts['report'], json_encode( $result['report'], $flags | JSON_PRETTY_PRINT ) . "\n" );
}

$c = $result['graph']['counts'];
fwrite(
	STDERR,
	sprintf(
		"Graph: %d drugs, %d diseases, %d topics, %d edges. Rejected: %d. Warnings: %d.\n",
		$c['drug'],
		$c['disease'],
		$c['topic'],
		count( $result['graph']['edges'] ),
		count( $result['report']['rejected'] ),
		count( $result['report']['warnings'] )
	)
);
