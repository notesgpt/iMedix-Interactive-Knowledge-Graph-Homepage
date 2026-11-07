<?php
/**
 * Data for the server-rendered templates.
 *
 * @package Imedix\KnowledgeUniverse
 */

namespace Imedix\KnowledgeUniverse\WordPress;

use Imedix\KnowledgeUniverse\Core\Discovery;

final class ViewModel {

	/**
	 * @return array<string, mixed>
	 */
	public static function build(): array {
		$graph  = Sync::graph_data();
		$counts = $graph['counts'] ?? array(
			'drug'    => 0,
			'disease' => 0,
			'topic'   => 0,
		);
		$news   = News::latest( (int) Settings::get( 'news_count' ) );

		$vm = array(
			'counts'      => $counts,
			'hasGraph'    => null !== $graph,
			'areas'       => $graph ? Discovery::areas( $graph ) : array(),
			'suggestions' => $graph ? Discovery::suggestions( $graph ) : array(),
			'news'        => $news['items'],
			'newsArchive' => News::archive_url(),
			'searchUrl'   => home_url( '/' ),
			'drugArchive' => self::archive_for_base( (string) Settings::get( 'base_drug' ) ),
			'diseaseArchive' => self::archive_for_base( (string) Settings::get( 'base_disease' ) ),
			'trust'       => array(
				'standard' => $graph ? Discovery::standard( $graph, current_time( 'Y-m-d' ) ) : null,
				// Shown only when an editor has confirmed it and named the page that proves it.
				'review'   => Settings::get( 'claim_medical_review' ) && '' !== (string) Settings::get( 'review_url' ) ? (string) Settings::get( 'review_url' ) : null,
			),
		);

		/**
		 * Filter the homepage view model (e.g. to change copy or limits).
		 *
		 * @param array<string, mixed> $vm
		 */
		return (array) apply_filters( 'imedix_ku_view_model', $vm );
	}

	/**
	 * Archive URL of the post type whose rewrite slug matches the base path,
	 * when that post type has an archive. Discovered at runtime, never assumed.
	 */
	private static function archive_for_base( string $base ): ?string {
		$slug = trim( $base, '/' );
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			$rewrite = is_array( $type->rewrite ) ? ( $type->rewrite['slug'] ?? '' ) : '';
			if ( $type->has_archive && ( $rewrite === $slug || $type->name === $slug ) ) {
				$link = get_post_type_archive_link( $type->name );
				return $link ? $link : null;
			}
		}
		return null;
	}

	/**
	 * Published posts of the post type behind a base path ("Published (353)" in
	 * the admin list), for reconciling the homepage counts. Null if unknown.
	 */
	public static function published_for_base( string $base ): ?int {
		$slug = trim( $base, '/' );
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			$rewrite = is_array( $type->rewrite ) ? ( $type->rewrite['slug'] ?? '' ) : '';
			if ( $rewrite === $slug || $type->name === $slug ) {
				return (int) ( wp_count_posts( $type->name )->publish ?? 0 );
			}
		}
		return null;
	}

	public static function path_url( string $path ): string {
		return home_url( $path );
	}
}
