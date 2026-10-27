<?php
/**
 * Settings → Knowledge Universe: configuration, sync status, rejected records.
 *
 * @package Imedix\KnowledgeUniverse
 */

namespace Imedix\KnowledgeUniverse\WordPress;

final class Admin {

	private const PAGE        = 'imedix-ku';
	private const SYNC_ACTION = 'imedix_ku_sync_now';
	private const THROTTLE    = 'imedix_ku_manual_sync';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_init', array( self::class, 'settings' ) );
		add_action( 'admin_post_' . self::SYNC_ACTION, array( self::class, 'sync_now' ) );
	}

	public static function menu(): void {
		add_options_page(
			__( 'iMedix Knowledge Universe', 'imedix-ku' ),
			__( 'Knowledge Universe', 'imedix-ku' ),
			'manage_options',
			self::PAGE,
			array( self::class, 'render' )
		);
	}

	public static function settings(): void {
		register_setting(
			self::PAGE,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	public static function sync_now(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'imedix-ku' ), 403 );
		}
		check_admin_referer( self::SYNC_ACTION );

		if ( get_transient( self::THROTTLE ) ) {
			$result = array(
				'result'  => 'throttled',
				'message' => __( 'Please wait a minute between manual syncs.', 'imedix-ku' ),
			);
		} else {
			set_transient( self::THROTTLE, 1, MINUTE_IN_SECONDS );
			$result = Sync::run( ! empty( $_POST['force'] ), 'manual' ); // phpcs:ignore WordPress.Security.NonceVerification -- verified above.
		}
		set_transient( 'imedix_ku_notice_' . get_current_user_id(), $result, 60 );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s      = Settings::all();
		$state  = Sync::state();
		$report = get_option( Sync::OPTION_REPORT, array() );
		$notice = get_transient( 'imedix_ku_notice_' . get_current_user_id() );
		delete_transient( 'imedix_ku_notice_' . get_current_user_id() );
		$opt = Settings::OPTION;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'iMedix Knowledge Universe', 'imedix-ku' ); ?></h1>

			<?php if ( is_array( $notice ) ) : ?>
				<div class="notice notice-<?php echo 'error' === $notice['result'] ? 'error' : 'info'; ?>"><p><strong><?php echo esc_html( $notice['result'] ); ?>:</strong> <?php echo esc_html( $notice['message'] ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Sync status', 'imedix-ku' ); ?></h2>
			<table class="widefat striped" style="max-width:820px">
				<tbody>
					<tr><th><?php esc_html_e( 'Last attempt', 'imedix-ku' ); ?></th><td><?php echo esc_html( $state['lastAttempt'] ?? '—' ); ?> (<?php echo esc_html( $state['trigger'] ?? '—' ); ?>)</td></tr>
					<tr><th><?php esc_html_e( 'Last result', 'imedix-ku' ); ?></th><td><?php echo esc_html( $state['lastResult'] ?? '—' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Last successful build', 'imedix-ku' ); ?></th><td><?php echo esc_html( $state['lastSuccess'] ?? '—' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Last error', 'imedix-ku' ); ?></th><td><?php echo esc_html( $state['lastError'] ?? '—' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Entities', 'imedix-ku' ); ?></th><td>
						<?php
						if ( ! empty( $state['counts'] ) ) {
							printf(
								'%d drugs · %d diseases · %d topics · %d edges · %d rejected',
								(int) $state['counts']['drug'],
								(int) $state['counts']['disease'],
								(int) $state['counts']['topic'],
								(int) ( $state['edges'] ?? 0 ),
								(int) ( $state['rejected'] ?? 0 )
							);
						} else {
							echo '—';
						}
						?>
					</td></tr>
					<tr><th><?php esc_html_e( 'Static graph file', 'imedix-ku' ); ?></th><td><?php echo ! empty( $state['file'] ) ? '<code>' . esc_html( $state['file'] ) . '</code>' : esc_html__( 'none (REST endpoint is used)', 'imedix-ku' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Next scheduled run', 'imedix-ku' ); ?></th><td><?php $next = wp_next_scheduled( Sync::HOOK ); echo $next ? esc_html( gmdate( 'c', $next ) ) : '—'; ?></td></tr>
				</tbody>
			</table>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SYNC_ACTION ); ?>">
				<?php wp_nonce_field( self::SYNC_ACTION ); ?>
				<label><input type="checkbox" name="force" value="1"> <?php esc_html_e( 'Rebuild even if nothing changed', 'imedix-ku' ); ?></label>
				<?php submit_button( __( 'Sync now', 'imedix-ku' ), 'secondary', 'submit', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Settings', 'imedix-ku' ); ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields( self::PAGE ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="ku-sid"><?php esc_html_e( 'Spreadsheet ID', 'imedix-ku' ); ?></label></th>
						<td><input id="ku-sid" class="regular-text code" name="<?php echo esc_attr( $opt ); ?>[spreadsheet_id]" value="<?php echo esc_attr( $s['spreadsheet_id'] ); ?>"></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Source', 'imedix-ku' ); ?></th>
						<td>
							<label><input type="radio" name="<?php echo esc_attr( $opt ); ?>[source_mode]" value="csv_export" <?php checked( $s['source_mode'], 'csv_export' ); ?>> <?php esc_html_e( 'CSV export of a link-shared sheet (no credentials)', 'imedix-ku' ); ?></label><br>
							<label><input type="radio" name="<?php echo esc_attr( $opt ); ?>[source_mode]" value="sheets_api" <?php checked( $s['source_mode'], 'sheets_api' ); ?>> <?php esc_html_e( 'Google Sheets API (key from IMEDIX_KU_SHEETS_API_KEY in wp-config.php)', 'imedix-ku' ); ?></label>
							<p class="description"><?php echo '' !== Settings::api_key() ? esc_html__( 'API key constant detected.', 'imedix-ku' ) : esc_html__( 'No API key constant defined.', 'imedix-ku' ); ?></p>
						</td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Worksheet gids (CSV mode)', 'imedix-ku' ); ?></th>
						<td>
							<?php foreach ( array( 'gid_drugs' => 'Drugs', 'gid_diseases' => 'Diseases', 'gid_redirects' => 'Redirects' ) as $k => $label ) : ?>
								<label><?php echo esc_html( $label ); ?> <input class="small-text code" style="width:9em" name="<?php echo esc_attr( $opt . '[' . $k . ']' ); ?>" value="<?php echo esc_attr( $s[ $k ] ); ?>"></label>&nbsp;
							<?php endforeach; ?>
						</td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Worksheet titles (API mode)', 'imedix-ku' ); ?></th>
						<td>
							<?php foreach ( array( 'title_drugs', 'title_diseases', 'title_redirects' ) as $k ) : ?>
								<input class="regular-text" style="width:14em" name="<?php echo esc_attr( $opt . '[' . $k . ']' ); ?>" value="<?php echo esc_attr( $s[ $k ] ); ?>">
							<?php endforeach; ?>
						</td></tr>
					<tr><th scope="row"><?php esc_html_e( 'URL bases', 'imedix-ku' ); ?></th>
						<td>
							<label><?php esc_html_e( 'Host used in the sheet', 'imedix-ku' ); ?> <input class="code" name="<?php echo esc_attr( $opt ); ?>[sheet_host]" value="<?php echo esc_attr( $s['sheet_host'] ); ?>"></label>
							<label><?php esc_html_e( 'Drugs', 'imedix-ku' ); ?> <input class="code" name="<?php echo esc_attr( $opt ); ?>[base_drug]" value="<?php echo esc_attr( $s['base_drug'] ); ?>"></label>
							<label><?php esc_html_e( 'Diseases', 'imedix-ku' ); ?> <input class="code" name="<?php echo esc_attr( $opt ); ?>[base_disease]" value="<?php echo esc_attr( $s['base_disease'] ); ?>"></label>
						</td></tr>
					<tr><th scope="row"><?php esc_html_e( 'WordPress validation', 'imedix-ku' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[validate_wp]" value="1" <?php checked( $s['validate_wp'] ); ?>> <?php esc_html_e( 'Only include entities whose WordPress post is published at the canonical URL (recommended)', 'imedix-ku' ); ?></label><br>
							<label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[scan_links]" value="1" <?php checked( $s['scan_links'] ); ?>> <?php esc_html_e( 'Add “linked from article” connections from internal links in published article bodies', 'imedix-ku' ); ?></label>
						</td></tr>
					<tr><th scope="row"><label for="ku-rec"><?php esc_html_e( 'Sync schedule', 'imedix-ku' ); ?></label></th>
						<td><select id="ku-rec" name="<?php echo esc_attr( $opt ); ?>[sync_recurrence]">
							<?php foreach ( array( 'hourly', 'twicedaily', 'daily' ) as $r ) : ?>
								<option value="<?php echo esc_attr( $r ); ?>" <?php selected( $s['sync_recurrence'], $r ); ?>><?php echo esc_html( $r ); ?></option>
							<?php endforeach; ?>
						</select>
						<label> <?php esc_html_e( 'Refuse builds that drop more than', 'imedix-ku' ); ?> <input class="small-text" name="<?php echo esc_attr( $opt ); ?>[max_drop_ratio]" value="<?php echo esc_attr( (string) $s['max_drop_ratio'] ); ?>"> <?php esc_html_e( '(fraction) of entities', 'imedix-ku' ); ?></label></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'News', 'imedix-ku' ); ?></th>
						<td>
							<label><?php esc_html_e( 'Post type', 'imedix-ku' ); ?> <input class="code" name="<?php echo esc_attr( $opt ); ?>[news_post_type]" value="<?php echo esc_attr( $s['news_post_type'] ); ?>"></label>
							<label><?php esc_html_e( 'Items', 'imedix-ku' ); ?> <input type="number" min="4" max="20" class="small-text" name="<?php echo esc_attr( $opt ); ?>[news_count]" value="<?php echo esc_attr( (string) $s['news_count'] ); ?>"></label>
							<label><?php esc_html_e( 'Browser refresh (s)', 'imedix-ku' ); ?> <input type="number" min="120" max="3600" class="small-text" name="<?php echo esc_attr( $opt ); ?>[news_refresh_secs]" value="<?php echo esc_attr( (string) $s['news_refresh_secs'] ); ?>"></label>
							<p class="description"><?php echo post_type_exists( $s['news_post_type'] ) ? esc_html__( 'Post type found.', 'imedix-ku' ) : esc_html__( 'Post type not registered — the ticker stays hidden.', 'imedix-ku' ); ?></p>
						</td></tr>
					<tr><th scope="row"><label for="ku-budget"><?php esc_html_e( 'Graph node budget (desktop)', 'imedix-ku' ); ?></label></th>
						<td><input id="ku-budget" type="number" min="100" max="1500" class="small-text" name="<?php echo esc_attr( $opt ); ?>[graph_budget]" value="<?php echo esc_attr( (string) $s['graph_budget'] ); ?>"> <span class="description"><?php esc_html_e( 'Mobile and low-power devices automatically use a smaller sample.', 'imedix-ku' ); ?></span></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<?php if ( ! empty( $report['rejected'] ) ) : ?>
				<h2><?php esc_html_e( 'Rejected records (last build)', 'imedix-ku' ); ?></h2>
				<p>
					<?php foreach ( (array) $report['rejectedByReason'] as $reason => $n ) : ?>
						<code><?php echo esc_html( $reason ); ?></code>: <?php echo (int) $n; ?>&nbsp;&nbsp;
					<?php endforeach; ?>
				</p>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Sheet', 'imedix-ku' ); ?></th><th><?php esc_html_e( 'Row', 'imedix-ku' ); ?></th><th><?php esc_html_e( 'Slug', 'imedix-ku' ); ?></th><th><?php esc_html_e( 'Reason', 'imedix-ku' ); ?></th><th><?php esc_html_e( 'Detail', 'imedix-ku' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( array_slice( (array) $report['rejected'], 0, 300 ) as $r ) : ?>
						<tr><td><?php echo esc_html( $r['sheet'] ); ?></td><td><?php echo esc_html( $r['row'] ); ?></td><td><code><?php echo esc_html( $r['slug'] ); ?></code></td><td><?php echo esc_html( $r['reason'] ); ?></td><td><?php echo esc_html( $r['detail'] ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<?php if ( ! empty( $report['warnings'] ) ) : ?>
				<h2><?php esc_html_e( 'Warnings', 'imedix-ku' ); ?></h2>
				<ul>
					<?php foreach ( array_slice( (array) $report['warnings'], 0, 200 ) as $w ) : ?>
						<li><code><?php echo esc_html( $w['reason'] ); ?></code> <?php echo esc_html( trim( $w['sheet'] . ' ' . $w['row'] ) ); ?> — <?php echo esc_html( $w['detail'] ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}
}
