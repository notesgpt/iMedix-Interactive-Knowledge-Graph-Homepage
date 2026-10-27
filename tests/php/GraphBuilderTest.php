<?php
use Imedix\KnowledgeUniverse\Core\ClientPayload;
use Imedix\KnowledgeUniverse\Core\Csv;
use Imedix\KnowledgeUniverse\Core\Discovery;
use Imedix\KnowledgeUniverse\Core\GraphBuilder;

function ku_csv( array $rows ): array {
	$fh = fopen( 'php://temp', 'r+' );
	foreach ( $rows as $r ) {
		fputcsv( $fh, $r, ',', '"', '' );
	}
	rewind( $fh );
	return Csv::parse( stream_get_contents( $fh ) );
}

function ku_small(): array {
	$drugs    = ku_csv(
		array(
			array( '#', 'Drug slug', 'URL', 'Status', 'Batch', 'Note (x)', 'Completed on' ),
			array( '1', 'metformin', 'https://www.imedix.com/drugs/metformin/', 'Completed', 'Diabetes', 'Verified', '2026-10-20' ),
			array( '2', 'glipizide', 'https://www.imedix.com/drugs/glipizide/', 'Pending', 'Diabetes', '', '' ),
			array( '3', 'accupril', 'https://www.imedix.com/drugs/accupril/', 'Retired', '', '301 -> /drugs/quinapril-hcl/ (merged)', '' ),
			array( '4', 'quinapril-hcl', 'https://www.imedix.com/drugs/quinapril-hcl/', 'Completed', '', '', '' ),
			array( '5', 'metformin', 'https://www.imedix.com/drugs/metformin/', 'Pending', '', '', '' ),
			array( '6', 'wrongurl', 'https://www.imedix.com/drugs/other/', 'Pending', '', '', '' ),
			array( '7', 'offsite', 'https://evil.example/drugs/offsite/', 'Pending', '', '', '' ),
			array( '8', 'plavix', 'https://www.imedix.com/drugs/plavix/', 'To create', '', '', '' ),
			array( '9', 'newpage', '', 'Completed', '', '', '' ),
			array( '10', 'redirected', 'https://www.imedix.com/drugs/redirected/', 'Pending', '', '', '' ),
			array( '11', 'mystery', 'https://www.imedix.com/drugs/mystery/', 'On hold', '', '', '' ),
			array( '12', 'lonely', 'https://www.imedix.com/drugs/lonely/', 'Pending', 'Hair Loss', '', '' ),
		)
	);
	$diseases = ku_csv(
		array(
			array( '#', 'Disease slug', 'URL', 'Decision', 'Target (301)', 'Cluster', 'Standard', 'Status', 'Note', 'Updated on' ),
			array( '1', 'diabetes-mellitus', 'https://www.imedix.com/diseases/diabetes-mellitus/', 'KEEP', '', 'Diabetes (hub)', 'legacy', 'Pending rewrite', '', '' ),
			array( '2', 'old-dm', 'https://www.imedix.com/diseases/old-dm/', 'MERGE', '/diseases/diabetes-mellitus/', 'Diabetes', '', '', '', '' ),
			array( '3', 'asthma', 'https://www.imedix.com/diseases/asthma/', 'KEEP', '', 'Asthma (v1)', 'v1.3', 'Completed (v1.3)', '', '2026-10-05' ),
		)
	);
	$redirects = ku_csv(
		array(
			array( 'Old URL', 'Code', 'Target', 'Target check (2026-10-18)', 'Added', 'Note' ),
			array( '/drugs/redirected/', '301', '/drugs/metformin/', 'OK', '', '' ),
			array( '/drugs/glucophage/', '301', '/drugs/metformin/', 'OK', '', '' ),
			array( '/drugs/bad/', '301', 'https://evil.example/x/', '', '', '' ),
		)
	);
	return array( $drugs, $diseases, $redirects );
}

test( 'GraphBuilder eligibility and logging', function () {
	[ $d, $s, $r ] = ku_small();
	$out   = ( new GraphBuilder( array( 'generated_at' => 'T' ) ) )->build( $d, $s, $r );
	$graph = $out['graph'];
	$ids   = array_column( $graph['nodes'], 'id' );

	foreach ( array( 'drug:metformin', 'drug:glipizide', 'drug:quinapril-hcl', 'drug:newpage', 'disease:diabetes-mellitus', 'disease:asthma', 'topic:diabetes' ) as $id ) {
		T::ok( in_array( $id, $ids, true ), "includes $id" );
	}
	foreach ( array( 'drug:accupril', 'drug:plavix', 'drug:wrongurl', 'drug:offsite', 'drug:redirected', 'drug:mystery', 'disease:old-dm', 'topic:hair-loss', 'topic:asthma' ) as $id ) {
		T::ok( ! in_array( $id, $ids, true ), "excludes $id" );
	}

	$reasons = array_column( $out['report']['rejected'], 'reason', 'slug' );
	T::eq( 'status_retired', $reasons['accupril'], 'retired' );
	T::eq( 'status_to_create', $reasons['plavix'], 'to create' );
	T::eq( 'url_slug_mismatch', $reasons['wrongurl'], 'url mismatch' );
	T::eq( 'url_invalid', $reasons['offsite'], 'foreign URL' );
	T::eq( 'redirected', $reasons['redirected'], 'listed in redirect sheet' );
	T::eq( 'status_unknown', $reasons['mystery'], 'unknown status' );
	T::eq( 'decision_not_keep', $reasons['old-dm'], 'disease decision' );
	T::eq( 'duplicate', $reasons['metformin'], 'duplicate slug' );
	T::eq( 'invalid_redirect_target', $reasons['/drugs/bad/'], 'bad redirect target' );

	$nodes = array_column( $graph['nodes'], null, 'id' );
	T::eq( 'pending', $nodes['drug:glipizide']['status']['editorial'], 'Pending kept as editorial state' );
	T::eq( 'unverified', $nodes['drug:metformin']['status']['publication'], 'no resolver → unverified' );
	T::eq( '2026-10-20', $nodes['drug:metformin']['status']['updated'], 'completed date' );
	T::ok( ! empty( $nodes['drug:newpage']['urlDerived'] ), 'empty URL derived from slug' );
	T::eq( 'https://www.imedix.com/drugs/newpage/', $nodes['drug:newpage']['url'], 'derived URL' );
	T::eq( array( 'Accupril' ), $nodes['drug:quinapril-hcl']['aliases'], 'alias from Retired note' );
	T::eq( array( 'Glucophage', 'Redirected' ), $nodes['drug:metformin']['aliases'], 'aliases from redirect sheet' );
	T::eq( 'diabetes', $nodes['drug:metformin']['category'], 'category = first topic' );
	T::eq( 'drug', $nodes['drug:lonely']['category'] ?? 'drug', 'dropped topic resets category' );
	T::eq( 3, $nodes['topic:diabetes']['degree'], 'topic degree' );

	foreach ( $graph['edges'] as $e ) {
		T::eq( 'in_topic', $e['type'], 'only editorial edges without WordPress' );
	}
	T::eq( false, $graph['edgeTypes']['in_topic']['clinical'], 'editorial edges flagged non-clinical' );
} );

test( 'GraphBuilder with WordPress resolver', function () {
	[ $d, $s, $r ] = ku_small();
	$resolver = function ( string $type, string $slug, string $path ) {
		switch ( $slug ) {
			case 'glipizide':
				return null; // no post
			case 'newpage':
				return array( 'status' => 'draft' );
			case 'quinapril-hcl':
				return array( 'status' => 'publish', 'path' => '/drugs/quinapril/' );
			case 'metformin':
				return array( 'status' => 'publish', 'title' => 'Metformin (Glucophage)', 'excerpt' => 'Short summary.', 'links' => array( '/diseases/diabetes-mellitus/', '/drugs/glucophage/', '/drugs/metformin/', '/elsewhere/' ), 'path' => $path );
			default:
				return array( 'status' => 'publish', 'path' => $path );
		}
	};
	$out     = ( new GraphBuilder( array( 'resolver' => $resolver ) ) )->build( $d, $s, $r );
	$nodes   = array_column( $out['graph']['nodes'], null, 'id' );
	$reasons = array_column( $out['report']['rejected'], 'reason', 'slug' );
	T::eq( 'wp_not_found', $reasons['glipizide'], 'missing post' );
	T::eq( 'wp_not_published', $reasons['newpage'], 'draft post' );
	T::eq( 'wp_canonical_mismatch', $reasons['quinapril-hcl'], 'moved post' );
	T::eq( 'Metformin (Glucophage)', $nodes['drug:metformin']['name'], 'WordPress title wins' );
	T::eq( 'Short summary.', $nodes['drug:metformin']['description'], 'excerpt' );
	T::eq( 'published', $nodes['drug:metformin']['status']['publication'], 'published' );
	$links = array_values( array_filter( $out['graph']['edges'], fn( $e ) => 'links_to' === $e['type'] ) );
	T::eq( 1, count( $links ), 'self-links and redirects to self ignored, foreign paths ignored' );
	T::eq( 'disease:diabetes-mellitus', $links[0]['target'], 'content link target' );
} );

test( 'Real tracker snapshot', function () {
	$dir  = __DIR__ . '/../fixtures/sheets/';
	$read = fn( $f ) => Csv::parse( file_get_contents( $dir . $f ) );
	$b    = fn() => ( new GraphBuilder( array( 'generated_at' => 'T' ) ) )->build( $read( 'drugs.csv' ), $read( 'diseases.csv' ), $read( 'redirects.csv' ) );
	$out  = $b();
	$g    = $out['graph'];
	T::eq( 797, $g['counts']['drug'], 'drugs = Completed + Pending' );
	T::eq( 96, $g['counts']['disease'], 'diseases with Decision KEEP' );
	T::eq( 59, count( $out['report']['rejected'] ), 'rejected rows' );
	T::eq( json_encode( $g ), json_encode( $b()['graph'] ), 'deterministic' );

	$redirected = array();
	foreach ( Csv::map_columns( $read( 'redirects.csv' ), GraphBuilder::REDIRECT_COLUMNS, array( 'from' ) ) as $row ) {
		$redirected[ $row['from'] ] = true;
	}
	$ids = array();
	foreach ( $g['nodes'] as $n ) {
		$ids[ $n['id'] ] = true;
		if ( 'topic' === $n['type'] ) {
			T::eq( null, $n['url'], 'topics have no URL' );
			continue;
		}
		$path = parse_url( $n['url'], PHP_URL_PATH );
		T::ok( 0 === strpos( $n['url'], 'https://www.imedix.com/' ), 'https site URL ' . $n['id'] );
		T::ok( ! isset( $redirected[ $path ] ), 'never links a redirected URL ' . $path );
	}
	T::eq( count( $g['nodes'] ), count( $ids ), 'unique ids' );
	foreach ( $g['edges'] as $e ) {
		T::ok( isset( $ids[ $e['source'] ], $ids[ $e['target'] ] ), 'edge endpoints exist' );
	}

	$client = ClientPayload::from_graph( $g );
	T::eq( count( $g['nodes'] ), count( $client['nodes'] ), 'payload keeps nodes' );
	T::ok( strlen( json_encode( $client ) ) < 200000, 'payload < 200 KB' );
	$topics = Discovery::topics( $client );
	T::eq( $g['counts']['topic'], count( $topics ), 'all topics listed' );
	T::ok( count( $topics[0]['members'] ) <= 6, 'bounded links per topic' );
	$sugg = Discovery::suggestions( $client );
	T::eq( 4, count( $sugg ), 'four suggestions' );
	foreach ( $sugg as $sg ) {
		T::ok( 0 === strpos( $sg['path'], '/d' ), 'suggestion has a path' );
	}
} );
