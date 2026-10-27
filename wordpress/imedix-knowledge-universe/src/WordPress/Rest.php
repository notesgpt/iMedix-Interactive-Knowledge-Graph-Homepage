<?php
/**
 * Public, read-only REST endpoints (namespace imedix-ku/v1).
 *
 *  GET /graph          Cached client graph built by the sync job.
 *  GET /news?limit=12  Latest published posts of the `news` CPT.
 *
 * Both are served from cache and accept no user-controlled queries beyond a
 * clamped integer, so they need no authentication or rate limiting of their
 * own; edge caching is enabled through Cache-Control.
 *
 * @package Imedix\KnowledgeUniverse
 */

namespace Imedix\KnowledgeUniverse\WordPress;

final class Rest {

	public const NS = 'imedix-ku/v1';

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route(
			self::NS,
			'/graph',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'graph' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/news',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'news' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'limit' => array(
						'type'              => 'integer',
						'default'           => 12,
						'minimum'           => 1,
						'maximum'           => 20,
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function graph() {
		$data = Sync::graph_data();
		if ( null === $data ) {
			return new \WP_Error( 'imedix_ku_no_graph', 'Graph not built yet.', array( 'status' => 503 ) );
		}
		$response = new \WP_REST_Response( $data, 200 );
		$response->header( 'Cache-Control', 'public, max-age=900, stale-while-revalidate=3600' );
		return $response;
	}

	public static function news( \WP_REST_Request $request ): \WP_REST_Response {
		$news     = News::latest( (int) $request->get_param( 'limit' ) );
		$response = new \WP_REST_Response( $news, 200 );
		$response->header( 'Cache-Control', 'public, max-age=300, stale-while-revalidate=600' );
		return $response;
	}
}
