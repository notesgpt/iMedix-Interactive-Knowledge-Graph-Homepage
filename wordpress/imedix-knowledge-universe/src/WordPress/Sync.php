<?php
/**
 * Scheduled Google Sheets → WordPress synchronisation.
 *
 * Visitors never trigger a Sheets request: the graph is rebuilt by WP-Cron
 * (or WP-CLI / the admin "Sync now" button), validated, and stored. When a
 * run fails, the last good graph keeps being served.
 *
 * @package Imedix\KnowledgeUniverse
 */

namespace Imedix\KnowledgeUniverse\WordPress;

use Imedix\KnowledgeUniverse\Core\ClientPayload;
use Imedix\KnowledgeUniverse\Core\GraphBuilder;

final class Sync {

	public const HOOK          = 'imedix_ku_sync';
	public const OPTION_GRAPH  = 'imedix_ku_graph';
	public const OPTION_STATE  = 'imedix_ku_state';
	public const OPTION_REPORT = 'imedix_ku_report';
	private const LOCK         = 'imedix_ku_sync_lock';
	private const UPLOAD_DIR   = 'imedix-ku';

	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'cron' ) );
		add_action( 'update_option_' . Settings::OPTION, array( self::class, 'reschedule' ) );
		add_action( 'transition_post_status', array( self::class, 'mark_dirty_on_transition' ), 10, 3 );
		add_action( 'post_updated', array( self::class, 'mark_dirty_on_update' ), 10, 1 );
	}

	public static function activate(): void {
		self::reschedule();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public static function reschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
		wp_schedule_event( time() + 60, (string) Settings::get( 'sync_recurrence' ), self::HOOK );
	}

	public static function cron(): void {
		self::run( false, 'cron' );
	}

	/**
	 * @return array{result: string, message: string}
	 */
	public static function run( bool $force, string $trigger ): array {
		if ( get_transient( self::LOCK ) ) {
			return array(
				'result'  => 'locked',
				'message' => 'Another sync is running.',
			);
		}
		set_transient( self::LOCK, time(), 5 * MINUTE_IN_SECONDS );

		$state                = self::state();
		$state['lastAttempt'] = gmdate( 'c' );
		$state['trigger']     = $trigger;

		try {
			$tables = SheetSource::fetch_all();
			$hash   = substr( hash( 'sha256', (string) wp_json_encode( $tables ) ), 0, 16 );

			if ( ! $force && $hash === ( $state['hash'] ?? '' ) && empty( $state['dirty'] ) && self::graph() ) {
				$state['lastResult'] = 'unchanged';
				$state['lastError']  = null;
				self::save_state( $state );
				return array(
					'result'  => 'unchanged',
					'message' => 'Sheet and WordPress content unchanged since the last build.',
				);
			}

			$s        = Settings::all();
			$resolver = $s['validate_wp'] ? new WpResolver( (bool) $s['scan_links'], (string) $s['sheet_host'] ) : null;
			$builder  = new GraphBuilder(
				array(
					'site_url'       => untrailingslashit( home_url() ),
					'sheet_host'     => $s['sheet_host'],
					'base_paths'     => array(
						'drug'    => $s['base_drug'],
						'disease' => $s['base_disease'],
					),
					'resolver'       => $resolver,
					'spreadsheet_id' => $s['spreadsheet_id'],
					'source_hash'    => $hash,
				)
			);
			$result   = $builder->build( $tables['drugs'], $tables['diseases'], $tables['redirects'] );
			$graph    = $result['graph'];

			self::guard_against_collapse( $graph, $state, (float) $s['max_drop_ratio'] );

			$client = ClientPayload::from_graph( $graph );
			$json   = (string) wp_json_encode( $client, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

			update_option( self::OPTION_GRAPH, $json, false );
			update_option( self::OPTION_REPORT, $result['report'], false );

			$state['hash']        = $hash;
			$state['version']     = substr( hash( 'sha256', $json ), 0, 12 );
			$state['counts']      = $graph['counts'];
			$state['edges']       = count( $graph['edges'] );
			$state['rejected']    = count( $result['report']['rejected'] );
			$state['lastSuccess'] = gmdate( 'c' );
			$state['lastResult']  = 'built';
			$state['lastError']   = null;
			$state['dirty']       = false;
			$state['file']        = self::write_file( $json, $state['version'] );
			self::save_state( $state );

			/** Fires after a successful graph build. */
			do_action( 'imedix_ku_graph_built', $state );

			return array(
				'result'  => 'built',
				'message' => sprintf( '%d drugs, %d diseases, %d topics; %d records rejected.', $graph['counts']['drug'], $graph['counts']['disease'], $graph['counts']['topic'], $state['rejected'] ),
			);
		} catch ( \Throwable $e ) {
			$state['lastResult'] = 'error';
			$state['lastError']  = sanitize_text_field( $e->getMessage() );
			self::save_state( $state );
			return array(
				'result'  => 'error',
				'message' => $state['lastError'],
			);
		} finally {
			delete_transient( self::LOCK );
		}
	}

	/**
	 * Refuse to publish a graph that lost most of its entities (broken sheet,
	 * renamed columns, WordPress lookup failure…). The previous graph stays live.
	 *
	 * @param array<string, mixed> $graph
	 * @param array<string, mixed> $state
	 */
	private static function guard_against_collapse( array $graph, array $state, float $max_drop ): void {
		$now = $graph['counts']['drug'] + $graph['counts']['disease'];
		if ( $now < 10 ) {
			throw new \RuntimeException( 'Refusing to publish: only ' . $now . ' eligible entities.' );
		}
		if ( ! empty( $state['counts'] ) ) {
			$before = (int) $state['counts']['drug'] + (int) $state['counts']['disease'];
			if ( $before > 0 && $now < $before * ( 1 - $max_drop ) ) {
				throw new \RuntimeException( sprintf( 'Refusing to publish: entity count fell from %d to %d.', $before, $now ) );
			}
		}
	}

	/**
	 * Write a versioned static copy to uploads so it can be served (and cached
	 * as immutable) by the web server/CDN. Returns the public URL or null.
	 */
	private static function write_file( string $json, string $version ): ?string {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			return null;
		}
		$dir = trailingslashit( $uploads['basedir'] ) . self::UPLOAD_DIR;
		if ( ! wp_mkdir_p( $dir ) ) {
			return null;
		}
		$name = 'graph-' . $version . '.json';
		if ( false === file_put_contents( $dir . '/' . $name, $json, LOCK_EX ) ) {
			return null;
		}
		foreach ( (array) glob( $dir . '/graph-*.json' ) as $old ) {
			if ( is_string( $old ) && basename( $old ) !== $name ) {
				wp_delete_file( $old );
			}
		}
		return set_url_scheme( trailingslashit( $uploads['baseurl'] ) . self::UPLOAD_DIR . '/' . $name );
	}

	public static function delete_files(): void {
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . self::UPLOAD_DIR;
		foreach ( (array) glob( $dir . '/graph-*.json' ) as $file ) {
			if ( is_string( $file ) ) {
				wp_delete_file( $file );
			}
		}
		if ( is_dir( $dir ) ) {
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	/**
	 * Stored client graph JSON, or null before the first successful sync.
	 */
	public static function graph(): ?string {
		$json = get_option( self::OPTION_GRAPH, null );
		return is_string( $json ) && '' !== $json ? $json : null;
	}

	/**
	 * Decoded client graph (memoised per request).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function graph_data(): ?array {
		static $cache = false;
		if ( false === $cache ) {
			$json  = self::graph();
			$data  = null === $json ? null : json_decode( $json, true );
			$cache = is_array( $data ) ? $data : null;
		}
		return $cache;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function state(): array {
		$state = get_option( self::OPTION_STATE, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private static function save_state( array $state ): void {
		update_option( self::OPTION_STATE, $state, false );
	}

	/**
	 * Publication changes on drug/disease posts invalidate the incremental
	 * "unchanged" shortcut so the next scheduled run rebuilds.
	 *
	 * @param string   $new  New status.
	 * @param string   $old  Old status.
	 * @param \WP_Post $post Post.
	 */
	public static function mark_dirty_on_transition( $new, $old, $post ): void {
		if ( $new !== $old ) {
			self::mark_dirty( $post );
		}
	}

	/**
	 * @param int $post_id Post ID.
	 */
	public static function mark_dirty_on_update( $post_id ): void {
		self::mark_dirty( get_post( $post_id ) );
	}

	/**
	 * @param mixed $post Post.
	 */
	private static function mark_dirty( $post ): void {
		if ( ! $post instanceof \WP_Post || wp_is_post_revision( $post ) ) {
			return;
		}
		// Drafts have no pretty permalink, so match the post type's rewrite base too.
		$path    = (string) wp_parse_url( (string) get_permalink( $post ), PHP_URL_PATH );
		$type    = get_post_type_object( $post->post_type );
		$rewrite = $type && is_array( $type->rewrite ) ? '/' . trim( (string) ( $type->rewrite['slug'] ?? '' ), '/' ) . '/' : '';
		foreach ( array( Settings::get( 'base_drug' ), Settings::get( 'base_disease' ) ) as $base ) {
			if ( 0 === strpos( $path, (string) $base ) || $rewrite === $base || '/' . $post->post_type . '/' === $base ) {
				$state = self::state();
				if ( empty( $state['dirty'] ) ) {
					$state['dirty'] = true;
					self::save_state( $state );
				}
				return;
			}
		}
	}
}
