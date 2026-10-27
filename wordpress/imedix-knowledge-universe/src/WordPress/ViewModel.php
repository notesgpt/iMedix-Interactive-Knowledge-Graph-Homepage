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

	public static function path_url( string $path ): string {
		return home_url( $path );
	}
}
