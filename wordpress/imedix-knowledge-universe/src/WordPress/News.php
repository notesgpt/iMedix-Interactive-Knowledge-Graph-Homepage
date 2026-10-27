<?php
/**
 * Latest posts from the existing `news` custom post type.
 *
 * Uses WP_Query directly, so it works whether or not the CPT is exposed in
 * the REST API (no change to the CPT registration is required). Results are
 * cached in a transient and invalidated whenever a news post changes status.
 *
 * @package Imedix\KnowledgeUniverse
 */

namespace Imedix\KnowledgeUniverse\WordPress;

final class News {

	private const CACHE_KEY = 'imedix_ku_news_v1';
	private const CACHE_TTL = 600;

	public static function register(): void {
		add_action( 'transition_post_status', array( self::class, 'maybe_flush' ), 10, 3 );
		add_action( 'deleted_post', array( self::class, 'flush' ) );
	}

	/**
	 * @return array{available: bool, items: array<int, array{id:int, title:string, url:string, date:string}>}
	 */
	public static function latest( int $limit ): array {
		$limit     = max( 1, min( 20, $limit ) );
		$post_type = (string) Settings::get( 'news_post_type' );

		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) && isset( $cached['items'] ) && count( $cached['items'] ) >= $limit ) {
			$cached['items'] = array_slice( $cached['items'], 0, $limit );
			return $cached;
		}

		if ( ! post_type_exists( $post_type ) ) {
			return array(
				'available' => false,
				'items'     => array(),
			);
		}

		$query = new \WP_Query(
			array(
				'post_type'              => $post_type,
				'post_status'            => 'publish',
				'has_password'           => false,
				'posts_per_page'         => 20,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'suppress_filters'       => false, // Respect multilingual plugins: current-language posts only.
			)
		);

		$items = array();
		$seen  = array();
		foreach ( $query->posts as $post ) {
			$url   = (string) get_permalink( $post );
			$title = trim( html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			if ( '' === $title || '' === $url || isset( $seen[ $url ] ) ) {
				continue;
			}
			$seen[ $url ] = true;
			$items[]      = array(
				'id'    => (int) $post->ID,
				'title' => $title,
				'url'   => $url,
				'date'  => (string) get_post_time( 'c', true, $post ),
			);
		}

		$result = array(
			'available' => true,
			'items'     => $items,
		);
		set_transient( self::CACHE_KEY, $result, self::CACHE_TTL );

		$result['items'] = array_slice( $items, 0, $limit );
		return $result;
	}

	/**
	 * Archive link of the news CPT when it has one.
	 */
	public static function archive_url(): ?string {
		$link = get_post_type_archive_link( (string) Settings::get( 'news_post_type' ) );
		return $link ? $link : null;
	}

	/**
	 * @param string   $new  New status.
	 * @param string   $old  Old status.
	 * @param \WP_Post $post Post.
	 */
	public static function maybe_flush( $new, $old, $post ): void {
		if ( $post instanceof \WP_Post && Settings::get( 'news_post_type' ) === $post->post_type && ( 'publish' === $new || 'publish' === $old ) ) {
			self::flush();
		}
	}

	public static function flush(): void {
		delete_transient( self::CACHE_KEY );
	}
}
