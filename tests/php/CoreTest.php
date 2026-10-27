<?php
use Imedix\KnowledgeUniverse\Core\ContentLinks;
use Imedix\KnowledgeUniverse\Core\Csv;
use Imedix\KnowledgeUniverse\Core\Redirects;
use Imedix\KnowledgeUniverse\Core\Text;
use Imedix\KnowledgeUniverse\Core\Topics;

test( 'Text::humanize_slug', function () {
	$cases = array(
		'erectile-dysfunction-ed'                    => 'Erectile Dysfunction (ED)',
		'chronic-obstructive-pulmonary-disease-copd' => 'Chronic Obstructive Pulmonary Disease (COPD)',
		'gastroesophageal-reflux-disease-gerd'       => 'Gastroesophageal Reflux Disease (GERD)',
		'tuberculosis-tb'                            => 'Tuberculosis (TB)',
		'premenstrual-syndrome-pms'                  => 'Premenstrual Syndrome (PMS)',
		'parkinsons-disease'                         => "Parkinson's Disease",
		'sjogrens-syndrome'                          => "Sjögren's Syndrome",
		'h-pylori-eradication-as-part-of-combination-therapy' => 'H. pylori Eradication as Part of Combination Therapy',
		'diphenhydramine-hcl'                        => 'Diphenhydramine HCl',
		'mucinex-dm'                                 => 'Mucinex DM',
		'metformin'                                  => 'Metformin',
	);
	foreach ( $cases as $slug => $name ) {
		T::eq( $name, Text::humanize_slug( $slug ), $slug );
	}
} );

test( 'Text::to_path validation', function () {
	$h = 'www.imedix.com';
	T::eq( '/drugs/a/', Text::to_path( 'https://www.imedix.com/drugs/a/', $h ), 'canonical' );
	T::eq( '/drugs/a/', Text::to_path( 'https://imedix.com/drugs/a', $h ), 'bare host + missing slash' );
	T::eq( '/drugs/a/', Text::to_path( '/drugs/a/', $h ), 'relative' );
	T::eq( null, Text::to_path( 'https://evil.example/drugs/a/', $h ), 'foreign host' );
	T::eq( null, Text::to_path( 'javascript:alert(1)', $h ), 'javascript scheme' );
	T::eq( null, Text::to_path( '//evil.example/x/', $h ), 'protocol-relative' );
	T::eq( null, Text::to_path( 'https://www.imedix.com/drugs/a/?x=1', $h ), 'query' );
	T::eq( null, Text::to_path( '/drugs/<script>/', $h ), 'markup' );
	T::eq( null, Text::to_path( '', $h ), 'empty' );
	T::ok( Text::is_slug( 'abacavir-lamivudine' ), 'valid slug' );
	T::ok( ! Text::is_slug( 'Bad Slug' ), 'invalid slug' );
} );

test( 'Topics::parse', function () {
	T::eq( array( 'Erectile Dysfunction' ), Topics::parse( 'ED (v1)' ), 'alias + annotation' );
	T::eq( array( 'Erectile Dysfunction', "Men's Health" ), Topics::parse( "ED / Men's Health" ), 'split' );
	T::eq( array( 'Gastrointestinal', 'Antibiotics' ), Topics::parse( 'Gastrointestinal / Antibiotics (rename → H. pylori infection)' ), 'rename note' );
	T::eq( array( "Men's Health", "Women's Health" ), Topics::parse( "Men's/Woman's Health" ), 'slash without spaces; tracker spelling → Women\'s Health' );
	T::eq( array( 'Heart Disease' ), Topics::parse( 'Batch A - Cardiovascular' ), 'batch prefix + alias' );
	T::eq( array(), Topics::parse( 'Other (hub)' ), 'Other is never a topic' );
	T::eq( array(), Topics::parse( 'Unknown grouping' ), 'unknown label → no topic' );
	T::eq( array( 'Antibiotics' ), Topics::parse( 'Other / Antibiotics' ), 'Other dropped next to a real category' );
	T::eq( array( 'Anti Viral' ), Topics::parse( 'HIV / Antiviral' ), 'aliases collapse' );
	T::eq( array( 'Anti Viral' ), Topics::parse( 'Other (Antiviral / Hepatitis C)' ), 'category named in parenthesis replaces Other' );
	T::eq( array( 'Quit Smoking' ), Topics::parse( 'Other (Smoking Cessation)' ), 'smoking cessation' );
	T::eq( array( 'Antibiotics' ), Topics::parse( 'Antibiotics (rename → Lyme disease)' ), 'annotation without a category is dropped' );
	foreach ( Topics::CATEGORIES as $cat ) {
		T::eq( $cat, Topics::category( $cat ), "category $cat resolves to itself" );
	}
	T::eq( array(), Topics::parse( '' ), 'empty' );
} );

test( 'Redirects::resolve', function () {
	$r = new Redirects();
	$r->add( '/a/', 301, '/b/', '1' );
	$r->add( '/b/', 301, '/c/', '2' );
	$r->add( '/gone/', 410, null, '3' );
	$r->add( '/x/', 301, '/y/', '4' );
	$r->add( '/y/', 301, '/x/', '5' );
	$r->add( '/a/', 301, '/z/', '6' ); // duplicate
	T::eq( array( 'status' => 'ok', 'path' => '/c/', 'hops' => 2 ), $r->resolve( '/a/' ), 'chain' );
	T::eq( 'gone', $r->resolve( '/gone/' )['status'], '410' );
	T::eq( 'loop', $r->resolve( '/x/' )['status'], 'loop' );
	T::eq( 'none', $r->resolve( '/c/' )['status'], 'not redirected' );
	T::eq( 1, count( $r->issues() ), 'duplicate logged' );
} );

test( 'Csv', function () {
	$t = Csv::parse( "\xEF\xBB\xBFa,b\n1,\"x, y\"\n\n,\n2,z\n" );
	T::eq( array( 'a', 'b' ), $t['header'], 'BOM stripped' );
	T::eq( 2, count( $t['rows'] ), 'blank rows skipped' );
	T::eq( 'x, y', $t['rows'][0][1], 'quoted comma' );
	$m = Csv::map_columns( $t, array( 'first' => 'A', 'second' => 'b' ), array( 'first' ) );
	T::eq( '2', $m[0]['_row'], 'sheet row number' );
	T::throws( fn() => Csv::map_columns( $t, array( 'q' => 'Q' ), array( 'q' ) ), 'missing column' );
	T::throws( fn() => Csv::parse( '   ' ), 'empty' );
} );

test( 'ContentLinks::extract', function () {
	$html = '<p><a href="https://www.imedix.com/diseases/asthma/#x">a</a> <a href=\'/drugs/b/?utm=1\'>b</a>'
		. '<a href="https://other.example/drugs/c/">c</a><a href="javascript:void(0)">d</a><a href="/diseases/asthma/">dup</a></p>';
	T::eq( array( '/diseases/asthma/', '/drugs/b/' ), ContentLinks::extract( $html, 'www.imedix.com' ), 'internal paths only' );
} );
