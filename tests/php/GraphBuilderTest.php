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
			array( '2', 'glipizide', 'https://www.imedix.com/drugs/glipizide/', 'Completed', 'Diabetes', '', '' ),
			array( '3', 'accupril', 'https://www.imedix.com/drugs/accupril/', 'Retired', '', '301 -> /drugs/quinapril-hcl/ (merged)', '' ),
			array( '4', 'quinapril-hcl', 'https://www.imedix.com/drugs/quinapril-hcl/', 'Completed', '', '', '' ),
			array( '5', 'metformin', 'https://www.imedix.com/drugs/metformin/', 'Completed', '', '', '' ),
			array( '6', 'wrongurl', 'https://www.imedix.com/drugs/other/', 'Completed', '', '', '' ),
			array( '7', 'offsite', 'https://evil.example/drugs/offsite/', 'Completed', '', '', '' ),
			array( '8', 'plavix', 'https://www.imedix.com/drugs/plavix/', 'To create', '', '', '' ),
			array( '9', 'newpage', '', 'Completed', '', '', '' ),
			array( '10', 'redirected', 'https://www.imedix.com/drugs/redirected/', 'Completed', '', '', '' ),
			array( '11', 'mystery', 'https://www.imedix.com/drugs/mystery/', 'On hold', '', '', '' ),
			array( '12', 'lonely', 'https://www.imedix.com/drugs/lonely/', 'Completed', 'Eye Care', '', '' ),
			array( '13', 'legacy', 'https://www.imedix.com/drugs/legacy/', 'Pending', 'Diabetes', '', '' ),
		)
	);
	$diseases = ku_csv(
		array(
			array( '#', 'Disease slug', 'URL', 'Decision', 'Target (301)', 'Cluster', 'Standard', 'Status', 'Note', 'Updated on' ),
			array( '1', 'diabetes-mellitus', 'https://www.imedix.com/diseases/diabetes-mellitus/', 'KEEP', '', 'Diabetes (hub)', 'v1.3', 'Completed (v1.3)', '', '2026-10-01' ),
			array( '4', 'draft-disease', 'https://www.imedix.com/diseases/draft-disease/', 'KEEP', '', 'Diabetes', 'legacy', 'Pending rewrite', '', '' ),
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

	foreach ( array( 'drug:metformin', 'drug:glipizide', 'drug:quinapril-hcl', 'drug:newpage', 'disease:diabetes-mellitus', 'disease:asthma', 'topic:diabetes', 'topic:asthma' ) as $id ) {
		T::ok( in_array( $id, $ids, true ), "includes $id" );
	}
	foreach ( array( 'drug:accupril', 'drug:plavix', 'drug:wrongurl', 'drug:offsite', 'drug:redirected', 'drug:mystery', 'drug:legacy', 'disease:old-dm', 'topic:eye-care' ) as $id ) {
		T::ok( ! in_array( $id, $ids, true ), "excludes $id" );
	}

	$reasons = array_column( $out['report']['rejected'], 'reason', 'slug' );
	T::eq( 'status_retired', $reasons['accupril'], 'retired' );
	T::eq( 'status_to_create', $reasons['plavix'], 'to create' );
	T::eq( 'status_pending', $reasons['legacy'], 'only Completed drugs are included' );
	T::eq( 'url_slug_mismatch', $reasons['wrongurl'], 'url mismatch' );
	T::eq( 'url_invalid', $reasons['offsite'], 'foreign URL' );
	T::eq( 'redirected', $reasons['redirected'], 'listed in redirect sheet' );
	T::eq( 'status_unknown', $reasons['mystery'], 'unknown status' );
	T::eq( 'decision_not_keep', $reasons['old-dm'], 'disease decision' );
	T::eq( 'status_not_completed', $reasons['draft-disease'], 'Pending rewrite diseases are drafts, never shown' );
	T::eq( 'duplicate', $reasons['metformin'], 'duplicate slug' );
	T::eq( 'invalid_redirect_target', $reasons['/drugs/bad/'], 'bad redirect target' );

	$nodes = array_column( $graph['nodes'], null, 'id' );
	T::eq( 'unverified', $nodes['drug:metformin']['status']['publication'], 'no resolver → unverified' );
	T::eq( '2026-10-20', $nodes['drug:metformin']['status']['updated'], 'completed date' );
	T::ok( ! empty( $nodes['drug:newpage']['urlDerived'] ), 'empty URL derived from slug' );
	T::eq( 'https://www.imedix.com/drugs/newpage/', $nodes['drug:newpage']['url'], 'derived URL' );
	T::eq( array( 'Accupril' ), $nodes['drug:quinapril-hcl']['aliases'], 'alias from Retired note' );
	T::eq( array( 'Glucophage', 'Redirected' ), $nodes['drug:metformin']['aliases'], 'aliases from redirect sheet' );
	T::eq( 'diabetes', $nodes['drug:metformin']['category'], 'category = first topic' );
	T::eq( 'drug', $nodes['drug:lonely']['category'], 'unknown grouping → no topic (searchable, not grouped)' );
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
				return array( 'status' => 'publish', 'title' => 'Metformin (Glucophage)', 'excerpt' => 'Short summary.', 'links' => array( '/diseases/diabetes-mellitus/', '/diseases/asthma/', '/drugs/glucophage/', '/drugs/metformin/', '/elsewhere/' ), 'path' => $path );
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
	T::ok( in_array( 'links_without_shared_category', array_column( $out['report']['warnings'], 'reason' ), true ), 'Metformin → Asthma dropped: no shared category' );
} );

test( 'Unregistered products: searchable, never featured', function () {
	$drugs    = ku_csv(
		array(
			array( '#', 'Drug slug', 'URL', 'Status', 'Batch', 'Note', 'Completed on', 'Disease Category', 'Registration' ),
			array( '1', 'viagra', 'https://www.imedix.com/drugs/viagra/', 'Completed', '', '', '2026-10-20', 'Erectile Dysfunction', '' ),
			array( '2', 'kamagra', 'https://www.imedix.com/drugs/kamagra/', 'Completed', '', '', '2026-10-21', 'Erectile Dysfunction', 'Unregistered' ),
		)
	);
	$diseases = ku_csv( array( array( '#', 'Disease slug', 'URL', 'Decision', 'Target (301)', 'Cluster', 'Standard', 'Status', 'Note', 'Updated on' ) ) );
	$out      = ( new GraphBuilder( array( 'generated_at' => 'T' ) ) )->build( $drugs, $diseases, null );
	$client   = ClientPayload::from_graph( $out['graph'] );
	$byId     = array_column( $client['nodes'], null, 'id' );
	T::eq( 0, $byId['drug:kamagra']['r'] ?? null, 'flag reaches the client (search keeps it)' );
	T::ok( ! isset( $byId['drug:viagra']['r'] ), 'registered products carry no flag' );
	$featured = array();
	foreach ( Discovery::areas( $client ) as $area ) {
		$featured = array_merge( $featured, array_column( $area['drugs']['items'], 'name' ) );
	}
	$featured = array_merge( $featured, array_column( Discovery::suggestions( $client ), 'name' ) );
	T::ok( in_array( 'Viagra', $featured, true ), 'registered product featured' );
	T::ok( ! in_array( 'Kamagra', $featured, true ), 'unregistered product never featured' );
} );

test( 'Real tracker snapshot', function () {
	$dir  = __DIR__ . '/../fixtures/sheets/';
	$read = fn( $f ) => Csv::parse( file_get_contents( $dir . $f ) );
	$b    = fn() => ( new GraphBuilder( array( 'generated_at' => 'T' ) ) )->build( $read( 'drugs.csv' ), $read( 'diseases.csv' ), $read( 'redirects.csv' ) );
	$out  = $b();
	$g    = $out['graph'];
	T::eq( 337, $g['counts']['drug'], 'drugs = Completed only' );
	T::eq( 13, $g['counts']['disease'], 'diseases: KEEP and Completed only' );
	T::eq( 602, count( $out['report']['rejected'] ), 'rejected rows (incl. Pending drugs and diseases)' );
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
	T::ok( count( $topics[0]['members'] ) <= 10, 'bounded links per topic' );
	foreach ( $topics as $tp ) {
		T::eq( $tp['total'], $tp['drugs'] + $tp['diseases'], 'composition adds up for ' . $tp['name'] );
		T::eq( $tp['id'], 'topic:' . $tp['key'], 'topic key' );
	}
	$sugg = Discovery::suggestions( $client );
	T::eq( 4, count( $sugg ), 'four suggestions' );
	foreach ( $sugg as $sg ) {
		T::ok( 0 === strpos( $sg['path'], '/d' ), 'suggestion has a path' );
	}
} );

test( 'Discovery::areas: one area per category, plus medicines used for its conditions', function () {
	$n = static fn( $id, $t, $name ) => array( 'id' => $id, 't' => $t, 'n' => $name, 'c' => $t, 'd' => 1, 'p' => '/' . $t . 's/' . substr( $id, strpos( $id, ':' ) + 1 ) . '/', 's' => 'completed' );
	$client = array(
		'nodes' => array(
			$n( 'disease:hypertension', 'disease', 'Hypertension' ),
			$n( 'disease:heart-failure', 'disease', 'Heart Failure' ),
			$n( 'drug:lisinopril', 'drug', 'Lisinopril' ),
			$n( 'drug:atenolol', 'drug', 'Atenolol' ),
			$n( 'drug:unrelated', 'drug', 'Unrelated' ),
			$n( 'disease:asthma', 'disease', 'Asthma' ),
			array( 'id' => 'topic:blood-pressure', 't' => 'topic', 'n' => 'Blood Pressure', 'c' => 'topic', 'd' => 2 ),
			array( 'id' => 'topic:heart-disease', 't' => 'topic', 'n' => 'Heart Disease', 'c' => 'topic', 'd' => 1 ),
			array( 'id' => 'topic:asthma', 't' => 'topic', 'n' => 'Asthma', 'c' => 'topic', 'd' => 1 ),
		),
		'edges' => array(
			array( 'disease:hypertension', 'topic:blood-pressure', 'in_topic' ),
			array( 'drug:lisinopril', 'topic:blood-pressure', 'in_topic' ),
			array( 'disease:heart-failure', 'topic:heart-disease', 'in_topic' ),
			array( 'disease:asthma', 'topic:asthma', 'in_topic' ),
			// Signed KG: atenolol is FDA-approved for hypertension.
			array( 'drug:atenolol', 'disease:hypertension', 'kg' ),
			// Lisinopril (Blood Pressure) is also approved for heart failure: that must not pull Heart Failure into Blood Pressure.
			array( 'drug:lisinopril', 'disease:heart-failure', 'kg' ),
		),
	);
	$areas = Discovery::areas( $client );
	T::eq( 3, count( $areas ), 'every category with a guide is shown (Asthma too)' );
	$bp = $areas[0];
	T::eq( 'Blood Pressure', $bp['name'], 'categories are not merged' );
	T::eq( 3, $bp['total'], 'tracker members plus the linked drug' );
	T::eq( 2, $bp['drugs']['count'], 'Lisinopril (tracker) and Atenolol (FDA-approved for hypertension, signed KG)' );
	T::eq( 1, $bp['diseases']['count'], 'Hypertension only: a medicine never pulls a condition into the area' );
	$names = array_merge( array_column( $bp['drugs']['items'], 'name' ), array_column( $bp['diseases']['items'], 'name' ) );
	T::ok( ! in_array( 'Unrelated', $names, true ), 'nothing joins an area without a tracker grouping or an article link' );
	T::eq( 'blood-pressure', $bp['icon'], 'area icon' );
} );

test( 'Sitemap decides what is published; tracker only labels', function () {
	[ $d, $s, $r ] = ku_small();
	$out = ( new GraphBuilder(
		array(
			'generated_at'    => 'T',
			'published_paths' => array( '/drugs/metformin/', '/drugs/legacy/', '/diseases/draft-disease/' ),
		)
	) )->build( $d, $s, $r );
	$ids     = array_column( $out['graph']['nodes'], 'id' );
	$reasons = array_column( $out['report']['rejected'], 'reason', 'slug' );
	T::ok( in_array( 'drug:legacy', $ids, true ), 'Pending drug listed in the sitemap is shown' );
	T::ok( in_array( 'disease:draft-disease', $ids, true ), 'Pending rewrite disease listed in the sitemap is shown' );
	T::ok( ! in_array( 'disease:asthma', $ids, true ), 'Completed in tracker but missing from the sitemap → hidden' );
	T::eq( 'not_in_sitemap', $reasons['asthma'], 'reason recorded' );
} );

test( 'Active ingredients: shown with the brand, searchable, linked to ingredient pages', function () {
	$drugs    = ku_csv(
		array(
			array( '#', 'Drug slug', 'URL', 'Status', 'Batch', 'Note', 'Completed on', 'Disease Category', 'Registration' ),
			array( '1', 'zoloft', 'https://www.imedix.com/drugs/zoloft/', 'Completed', '', '', '', 'Depression', '' ),
			array( '2', 'augmentin', 'https://www.imedix.com/drugs/augmentin/', 'Completed', '', '', '', 'Antibiotics', '' ),
		)
	);
	$diseases = ku_csv(
		array(
			array( '#', 'Disease slug', 'URL', 'Decision', 'Target (301)', 'Cluster', 'Standard', 'Status', 'Note', 'Updated on' ),
			array( '1', 'premature-ejaculation', 'https://www.imedix.com/diseases/premature-ejaculation/', 'KEEP', '', "ED / Men's Health", '', 'Pending rewrite', '', '' ),
		)
	);
	$resolver = function ( string $type, string $slug, string $path ) {
		$data = array(
			'zoloft'                => array( 'Zoloft', 'Sertraline hydrochloride', '' ),
			'augmentin'             => array( 'Augmentin', 'Amoxicillin + Clavulanate potassium', '' ),
			'premature-ejaculation' => array( 'Premature Ejaculation', '', 'SSRIs such as sertraline may be prescribed off-label. Amoxicillin is used for a secondary infection.' ),
		);
		return array( 'status' => 'publish', 'title' => $data[ $slug ][0], 'path' => $path, 'active' => $data[ $slug ][1], 'clinical' => $data[ $slug ][2] );
	};
	$out    = ( new GraphBuilder(
		array(
			'generated_at'    => 'T',
			'resolver'        => $resolver,
			'published_paths' => array( '/drugs/zoloft/', '/drugs/augmentin/', '/diseases/premature-ejaculation/', '/ingredients/amoxicillin/' ),
		)
	) )->build( $drugs, $diseases, null );
	$nodes  = array_column( $out['graph']['nodes'], null, 'id' );
	$client = array_column( ClientPayload::from_graph( $out['graph'] )['nodes'], null, 'id' );
	T::eq( 'Amoxicillin + Clavulanate potassium', $client['drug:augmentin']['g'], 'combination shown with the brand' );
	T::eq( array( array( 'Amoxicillin', '/ingredients/amoxicillin/' ), array( 'Clavulanate potassium', '' ) ), $client['drug:augmentin']['gl'], 'each ingredient linked to its published page, if any' );
	T::ok( ! isset( $client['drug:zoloft']['gl'] ), 'no ingredient page, no links' );
	T::ok( in_array( 'Sertraline', $nodes['drug:zoloft']['aliases'], true ), 'searching “sertraline” finds Zoloft' );
	T::ok( isset( $nodes['ingredient:amoxicillin'] ), 'ingredient with a published page becomes a node' );
	T::ok( ! isset( $nodes['ingredient:sertraline'] ), 'no page, no node' );
	$edges = array_map( fn( $e ) => $e['type'] . ':' . $e['source'] . '>' . $e['target'], $out['graph']['edges'] );
	T::ok( in_array( 'contains:drug:augmentin>ingredient:amoxicillin', $edges, true ), 'drug contains ingredient' );
	T::ok( ! array_filter( $edges, fn( $e ) => 0 === strpos( $e, 'used_for:' ) ), 'guide prose never creates a clinical relationship' );
} );

test( 'Signed knowledge graph: only registry claims, US FDA labels, ANDA never at brand level', function () {
	$drugs    = ku_csv(
		array(
			array( '#', 'Drug slug', 'URL', 'Status', 'Batch', 'Note', 'Completed on', 'Disease Category', 'Registration' ),
			array( '1', 'maxalt', 'https://www.imedix.com/drugs/maxalt/', 'Completed', '', '', '', 'Pain Relief', '' ),
			array( '2', 'kamagra', 'https://www.imedix.com/drugs/kamagra/', 'Completed', '', '', '', 'Erectile Dysfunction', 'Unregistered' ),
			array( '3', 'combo', 'https://www.imedix.com/drugs/combo/', 'Completed', '', '', '', 'Pain Relief', '' ),
		)
	);
	$diseases = ku_csv(
		array(
			array( '#', 'Disease slug', 'URL', 'Decision', 'Target (301)', 'Cluster', 'Standard', 'Status', 'Note', 'Updated on' ),
			array( '1', 'headache', 'https://www.imedix.com/diseases/headache/', 'KEEP', '', 'Pain Relief', '', 'Completed', '', '' ),
		)
	);
	$nda   = array( 'setid' => 'aaa', 'version' => 5, 'effective' => '2025-01-01', 'application' => 'NDA020864', 'title' => 'Maxalt tablets', 'url' => 'https://dailymed.nlm.nih.gov/dailymed/lookup.cfm?setid=aaa' );
	$anda  = array( 'setid' => 'bbb', 'version' => 2, 'effective' => '2025-02-01', 'application' => 'ANDA078000', 'title' => 'Generic tablets', 'url' => 'https://dailymed.nlm.nih.gov/dailymed/lookup.cfm?setid=bbb' );
	$base  = array( 'page' => '/diseases/headache/', 'condition' => array( 'id' => 7, 'name' => 'migraine', 'role' => 'covered_subtype' ) );
	$claims = array(
		$base + array( 'subject' => array( 'kind' => 'substance', 'id' => 315, 'name' => 'Rizatriptan', 'path' => '/ingredients/rizatriptan/' ), 'predicate' => 'treats', 'qualifier' => 'in adults', 'claim' => 'FDA-approved to treat migraine in adults', 'sources' => array( $nda, $anda ), 'brands' => array( array( 'name' => 'Maxalt', 'path' => '/drugs/maxalt/' ), array( 'name' => 'Kamagra', 'path' => '/drugs/kamagra/' ) ) ),
		$base + array( 'subject' => array( 'kind' => 'product', 'id' => 3, 'name' => 'Combo', 'path' => '/drugs/combo/' ), 'predicate' => 'treats', 'qualifier' => '', 'claim' => 'FDA-approved to treat migraine', 'sources' => array( $anda ), 'brands' => array() ),
		array( 'page' => '/diseases/not-published/', 'condition' => array( 'id' => 8, 'name' => 'x', 'role' => 'primary' ), 'subject' => array( 'kind' => 'substance', 'id' => 1, 'name' => 'Y', 'path' => null ), 'predicate' => 'prevents', 'qualifier' => '', 'claim' => 'FDA-approved to prevent x', 'sources' => array( $nda ), 'brands' => array() ),
	);
	$out   = ( new GraphBuilder( array( 'generated_at' => 'T', 'kg_claims' => $claims ) ) )->build( $drugs, $diseases, null );
	$g     = $out['graph'];
	$nodes = array_column( $g['nodes'], null, 'id' );
	$edges = array();
	foreach ( $g['edges'] as $e ) {
		$edges[ $e['type'] . ':' . $e['source'] . '>' . $e['target'] ] = $e;
	}
	T::eq( 'condition', $nodes['condition:7']['type'] ?? null, 'Condition node' );
	T::ok( isset( $edges['described_on:condition:7>disease:headache'] ), 'condition described on its disease page' );
	$treats = $edges['treats:ingredient:rizatriptan>condition:7'] ?? null;
	T::ok( null !== $treats, 'Substance treats Condition' );
	T::eq( 'in adults', $treats['claims'][0]['qualifier'] ?? null, 'qualifier kept' );
	T::eq( array( 'aaa_v5', 'bbb_v2' ), $treats['claims'][0]['supported_by'] ?? null, 'supported_by: substance level may cite the ANDA reference label' );
	T::ok( isset( $edges['has_brand:ingredient:rizatriptan>drug:maxalt'] ), 'has_brand to a registered brand' );
	T::ok( ! isset( $edges['has_brand:ingredient:rizatriptan>drug:kamagra'] ), 'never to an unregistered product' );
	T::ok( ! isset( $edges['treats:drug:combo>condition:7'] ), 'product claim backed only by an ANDA label is dropped' );
	T::ok( ! isset( $nodes['condition:8'] ), 'claim on an unpublished page is dropped' );
	T::ok( isset( $g['kgSources']->aaa_v5 ), 'sources listed' );
	$pairs = ClientPayload::kg_links( $g['edges'], (array) $g['kgSources'] );
	T::eq(
		array(
			array( 'ingredient:rizatriptan', 'disease:headache', 'FDA-approved to treat migraine in adults', $nda['url'] ),
			array( 'drug:maxalt', 'disease:headache', 'FDA-approved to treat migraine in adults', $nda['url'] ),
		),
		$pairs,
		'client pairs read straight off the signed chain, with claim and label'
	);
	$only_anda = $g['edges'];
	foreach ( $only_anda as &$e ) {
		if ( 'treats' === $e['type'] ) {
			$e['claims'][0]['supported_by'] = array( 'bbb_v2' );
		}
	}
	unset( $e );
	$brand = array_values( array_filter( ClientPayload::kg_links( $only_anda, (array) $g['kgSources'] ), fn( $p ) => 'drug:maxalt' === $p[0] ) );
	T::eq( '', $brand[0][3], 'a brand never shows an ANDA label' );
} );
