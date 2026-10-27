<?php
/**
 * Seeds the throw-away test database (run with `wp eval-file`).
 * Entity posts mirror the tracker snapshot; news posts are synthetic and
 * clearly labelled — they exist only in this test database.
 */
use Imedix\KnowledgeUniverse\Core\Csv;

$dir = getenv( 'KU_FIXTURES' );
$drafts = array( 'abilify', 'proctitis', 'zoloft' );
$count  = 0;
foreach ( array( 'drugs' => 'drugs.csv', 'diseases' => 'diseases.csv' ) as $type => $file ) {
	$t = Csv::parse( file_get_contents( "$dir/$file" ) );
	foreach ( $t['rows'] as $row ) {
		$slug   = $row[1];
		$status = strtolower( $row[3] );
		if ( 'drugs' === $type && ! in_array( $status, array( 'completed', 'pending' ), true ) ) {
			continue;
		}
		$content = '<p>Test article body.</p>';
		if ( 'metformin' === $slug ) {
			$content .= '<p>See <a href="https://www.imedix.com/diseases/diabetes-mellitus/">diabetes</a> and <a href="/diseases/metabolic-syndrome/">metabolic syndrome</a>.</p>';
		}
		wp_insert_post(
			array(
				'post_type'    => $type,
				'post_name'    => $slug,
				'post_title'   => 'WP title: ' . ucwords( str_replace( '-', ' ', $slug ) ),
				'post_status'  => in_array( $slug, $drafts, true ) ? 'draft' : 'publish',
				'post_content' => $content,
				'post_excerpt' => 'metformin' === $slug ? 'Excerpt <b>with markup</b> & entities.' : '',
			)
		);
		++$count;
	}
}
for ( $i = 1; $i <= 15; $i++ ) {
	wp_insert_post(
		array(
			'post_type'   => 'news',
			'post_title'  => "Integration test news item $i: A&B <em>trial</em> <script>alert(1)</script>",
			'post_status' => 15 === $i ? 'draft' : 'publish',
			'post_date'   => gmdate( 'Y-m-d H:i:s', strtotime( '2026-10-01 10:00:00' ) + $i * 3600 ),
		)
	);
}
echo "seeded $count entity posts\n";
