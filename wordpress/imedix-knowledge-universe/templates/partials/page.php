<?php
/**
 * Knowledge Universe markup. Everything essential is server-rendered:
 * heading, working search form, latest news links and crawlable discovery
 * links. JavaScript only enhances it (floating guide labels, autocomplete,
 * ticker motion).
 *
 * Expects:
 *   $vm     array  View model (ViewModel::build()).
 *   $config string Client configuration JSON (already escaped for <script>).
 *
 * @package Imedix\KnowledgeUniverse
 */

defined( 'ABSPATH' ) || exit;

$ku_counts = $vm['counts'];
$ku_types  = array(
	'drug'    => __( 'Drug', 'imedix-ku' ),
	'disease' => __( 'Disease', 'imedix-ku' ),
);
?>
<div class="imedix-ku" id="imedix-ku" data-ku-root>

	<section class="ku-hero" aria-labelledby="ku-title">
		<div class="ku-hero__stage" data-ku-stage aria-hidden="true"></div>
		<div class="ku-hero__veil" aria-hidden="true"></div>

		<div class="ku-hero__inner">
			<p class="ku-pillars">
				<span><?php esc_html_e( 'Independent', 'imedix-ku' ); ?></span>
				<span><?php esc_html_e( 'Evidence-based', 'imedix-ku' ); ?></span>
				<span><?php esc_html_e( 'Transparent', 'imedix-ku' ); ?></span>
			</p>
			<h1 id="ku-title" class="ku-title">
				<?php
				/* translators: Homepage heading (the official site title). The brand name is wrapped in <span>. */
				echo wp_kses( __( '<span>iMedix:</span> Independent Drug &amp; Health Platform', 'imedix-ku' ), array( 'span' => array() ) );
				?>
			</h1>
			<p class="ku-lede"><?php esc_html_e( 'Evidence-based information on medicines and health, with traceable sources and strict standards.', 'imedix-ku' ); ?></p>

			<form class="ku-search" role="search" method="get" action="<?php echo esc_url( $vm['searchUrl'] ); ?>" data-ku-search>
				<label class="ku-search__label ku-visually-hidden" for="ku-q"><?php esc_html_e( 'Search drugs and diseases', 'imedix-ku' ); ?></label>
				<div class="ku-search__field">
					<svg class="ku-icon ku-search__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
					<input id="ku-q" class="ku-search__input" name="s" type="search" inputmode="search" enterkeyhint="search"
						autocomplete="off" autocapitalize="off" spellcheck="false" maxlength="100"
						placeholder="<?php esc_attr_e( 'e.g. metformin or asthma', 'imedix-ku' ); ?>"
						aria-describedby="ku-q-hint">
					<button class="ku-search__submit" type="submit"><?php esc_html_e( 'Search', 'imedix-ku' ); ?></button>
				</div>
				<fieldset class="ku-filter" data-ku-filter hidden>
					<legend class="ku-visually-hidden"><?php esc_html_e( 'Show suggestions for', 'imedix-ku' ); ?></legend>
					<label><input type="radio" name="ku_type" value="all" checked> <span><?php esc_html_e( 'All', 'imedix-ku' ); ?></span></label>
					<label><input type="radio" name="ku_type" value="drug"> <span><?php esc_html_e( 'Drugs', 'imedix-ku' ); ?></span></label>
					<label><input type="radio" name="ku_type" value="disease"> <span><?php esc_html_e( 'Diseases', 'imedix-ku' ); ?></span></label>
				</fieldset>
				<p id="ku-q-hint" class="ku-visually-hidden"><?php esc_html_e( 'Suggestions appear as you type. Use the up and down arrow keys to choose one and Enter to open it.', 'imedix-ku' ); ?></p>
				<div class="ku-search__panel" data-ku-panel hidden>
					<ul class="ku-search__list" id="ku-q-list" role="listbox" aria-label="<?php esc_attr_e( 'Search suggestions', 'imedix-ku' ); ?>"></ul>
				</div>
				<p class="ku-visually-hidden" role="status" aria-live="polite" data-ku-status></p>
			</form>

			<?php if ( $vm['suggestions'] ) : ?>
				<p class="ku-try">
					<span class="ku-try__label"><?php esc_html_e( 'Recently updated:', 'imedix-ku' ); ?></span>
					<?php foreach ( $vm['suggestions'] as $ku_s ) : ?>
						<a class="ku-chip" href="<?php echo esc_url( home_url( $ku_s['path'] ) ); ?>" data-ku-node="<?php echo esc_attr( $ku_s['type'] . ':' . trim( basename( $ku_s['path'] ), '/' ) ); ?>">
							<span class="ku-glyph ku-glyph--<?php echo esc_attr( $ku_s['type'] ); ?>" aria-hidden="true"></span>
							<?php echo esc_html( $ku_s['name'] ); ?>
							<span class="ku-visually-hidden">(<?php echo esc_html( $ku_types[ $ku_s['type'] ] ); ?>)</span>
						</a>
					<?php endforeach; ?>
				</p>
			<?php endif; ?>
		</div>

		<div class="ku-hud" data-ku-hud>
			<ul class="ku-legend" aria-label="<?php esc_attr_e( 'Guides on iMedix', 'imedix-ku' ); ?>">
				<li class="ku-legend__item ku-legend__item--drug"><span class="ku-glyph ku-glyph--drug" aria-hidden="true"></span><?php esc_html_e( 'Drugs', 'imedix-ku' ); ?> <strong><?php echo esc_html( number_format_i18n( (int) $ku_counts['drug'] ) ); ?></strong></li>
				<li class="ku-legend__item ku-legend__item--disease"><span class="ku-glyph ku-glyph--disease" aria-hidden="true"></span><?php esc_html_e( 'Diseases', 'imedix-ku' ); ?> <strong><?php echo esc_html( number_format_i18n( (int) $ku_counts['disease'] ) ); ?></strong></li>
				<?php if ( ! empty( $ku_counts['condition'] ) ) : ?>
					<li class="ku-legend__item ku-legend__item--note"><span class="ku-legend__line" aria-hidden="true"></span><?php esc_html_e( 'FDA-approved uses · from FDA labels, not a complete list', 'imedix-ku' ); ?></li>
				<?php endif; ?>
			</ul>
		</div>
	</section>

	<section class="ku-news" aria-labelledby="ku-news-title" data-ku-news<?php echo $vm['news'] ? '' : ' hidden'; ?>>
		<div class="ku-news__bar">
			<p class="ku-news__label" id="ku-news-title">
				<svg class="ku-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="2"/><path d="M8.5 8.5a5 5 0 0 0 0 7M15.5 8.5a5 5 0 0 1 0 7M5.6 5.6a9 9 0 0 0 0 12.8M18.4 5.6a9 9 0 0 1 0 12.8"/></svg>
				<span><?php esc_html_e( 'Latest news', 'imedix-ku' ); ?></span>
			</p>
			<div class="ku-news__viewport" data-ku-ticker>
				<ul class="ku-news__track" data-ku-ticker-track>
					<?php foreach ( $vm['news'] as $ku_n ) : ?>
						<li class="ku-news__item" data-ku-news-id="<?php echo esc_attr( (string) $ku_n['id'] ); ?>">
							<a href="<?php echo esc_url( $ku_n['url'] ); ?>">
								<span><?php echo esc_html( $ku_n['title'] ); ?></span>
								<time datetime="<?php echo esc_attr( $ku_n['date'] ); ?>"><?php echo esc_html( date_i18n( 'M j, Y', (int) strtotime( $ku_n['date'] ) ) ); ?></time>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<button type="button" class="ku-news__btn ku-news__toggle" data-ku-ticker-toggle aria-pressed="false" hidden>
				<svg class="ku-icon ku-icon--pause" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 6v12M15 6v12"/></svg>
				<svg class="ku-icon ku-icon--play" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M8 5.5v13l10-6.5z"/></svg>
				<span class="ku-visually-hidden"><?php esc_html_e( 'Pause news ticker', 'imedix-ku' ); ?></span>
			</button>
			<?php if ( $vm['newsArchive'] ) : ?>
				<a class="ku-news__btn ku-news__all" href="<?php echo esc_url( $vm['newsArchive'] ); ?>">
					<svg class="ku-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m9 6 6 6-6 6"/></svg>
					<span class="ku-visually-hidden"><?php esc_html_e( 'All news', 'imedix-ku' ); ?></span>
				</a>
			<?php endif; ?>
		</div>
	</section>

	<section class="ku-discover" aria-labelledby="ku-discover-title">
		<div class="ku-wrap">
			<div class="ku-discover__head">
				<h2 class="ku-discover__title" id="ku-discover-title"><?php esc_html_e( 'Browse the library', 'imedix-ku' ); ?></h2>
				<p class="ku-discover__intro"><?php esc_html_e( 'Every drug and disease guide on iMedix, by type of guide and by health area.', 'imedix-ku' ); ?></p>
			</div>

			<?php
			$ku_cats = array(
				array(
					'type'  => 'drug',
					'show'  => (int) $ku_counts['drug'] > 0,
					'name'  => __( 'Drug guides', 'imedix-ku' ),
					'desc'  => __( 'Medicines, from brand names to generics.', 'imedix-ku' ),
					'href'  => $vm['drugArchive'] ? $vm['drugArchive'] : '#ku-q',
					'focus' => $vm['drugArchive'] ? '' : 'drug',
				),
				array(
					'type'  => 'disease',
					'show'  => (int) $ku_counts['disease'] > 0,
					'name'  => __( 'Disease guides', 'imedix-ku' ),
					'desc'  => __( 'Conditions, from common infections to chronic disease.', 'imedix-ku' ),
					'href'  => $vm['diseaseArchive'] ? $vm['diseaseArchive'] : '#ku-q',
					'focus' => $vm['diseaseArchive'] ? '' : 'disease',
				),
			);
			?>
			<ul class="ku-cats">
				<?php foreach ( $ku_cats as $ku_c ) : ?>
					<?php
					if ( ! $ku_c['show'] ) {
						continue;
					}
					?>
					<li class="ku-cat ku-cat--<?php echo esc_attr( $ku_c['type'] ); ?>">
						<a class="ku-cat__link" href="<?php echo esc_url( $ku_c['href'] ); ?>"<?php echo $ku_c['focus'] ? ' data-ku-focus-search="' . esc_attr( $ku_c['focus'] ) . '"' : ''; ?>>
							<span class="ku-cat__emblem" aria-hidden="true"><span class="ku-glyph ku-glyph--<?php echo esc_attr( $ku_c['type'] ); ?>"></span></span>
							<span class="ku-cat__text">
								<span class="ku-cat__name"><?php echo esc_html( $ku_c['name'] ); ?></span>
								<span class="ku-cat__desc"><?php echo esc_html( $ku_c['desc'] ); ?></span>
							</span>
							<svg class="ku-icon ku-cat__chev" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m9 6 6 6-6 6"/></svg>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>

			<?php if ( $vm['areas'] ) : ?>
				<div class="ku-areas-head" id="ku-areas">
					<h3 class="ku-areas-head__title"><?php esc_html_e( 'Health areas', 'imedix-ku' ); ?></h3>
					<p class="ku-areas-head__desc"><?php esc_html_e( 'Explore conditions and medicines by health topic. Verified FDA-approved indications are explicitly identified and linked to their sources.', 'imedix-ku' ); ?></p>
					<p class="ku-areas-key"><span class="ku-verified"><svg class="ku-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg><?php esc_html_e( 'FDA-approved', 'imedix-ku' ); ?></span> <?php esc_html_e( 'marks an indication verified against the U.S. product label, with a link to it. Other medicines are grouped by topic only.', 'imedix-ku' ); ?></p>
				</div>

				<div class="ku-areas">
					<?php foreach ( $vm['areas'] as $ku_n => $ku_a ) : ?>
						<?php
						$ku_id    = 'ku-area-' . $ku_a['key'];
						$ku_cols  = array_filter(
							array(
								'disease' => array( __( 'Conditions', 'imedix-ku' ), $ku_a['diseases'] ),
								'drug'    => array( __( 'Medicines in this topic', 'imedix-ku' ), $ku_a['drugs'] ),
							),
							static fn( $c ) => ! empty( $c[1]['items'] )
						);
						$ku_meta  = array();
						if ( $ku_a['diseases']['count'] ) {
							/* translators: %s: number of disease guides. */
							$ku_meta[] = sprintf( _n( '%s condition', '%s conditions', $ku_a['diseases']['count'], 'imedix-ku' ), number_format_i18n( $ku_a['diseases']['count'] ) );
						}
						if ( $ku_a['drugs']['count'] ) {
							/* translators: %s: number of drug guides. */
							$ku_meta[] = sprintf( _n( '%s medicine', '%s medicines', $ku_a['drugs']['count'], 'imedix-ku' ), number_format_i18n( $ku_a['drugs']['count'] ) );
						}
						// Verified only: a claim counts when it links to the product label on DailyMed.
						$ku_is_verified = static fn( $m ) => ! empty( $m['claim'] ) && ! empty( $m['source'] ) && 0 === strpos( $m['source'], 'https://dailymed.nlm.nih.gov/' );
						$ku_verified    = count( array_filter( $ku_a['drugs']['items'], $ku_is_verified ) );
						?>
						<?php
						// The first area starts open (full width); an odd number of closed ones leaves the
						// last alone in its row, so it takes the whole row — no hole in the grid.
						$ku_wide = count( $vm['areas'] ) - 1 === $ku_n && 1 === $ku_n % 2;
						?>
						<details class="ku-area<?php echo 1 === count( $ku_cols ) ? ' ku-area--single' : ''; ?><?php echo $ku_wide ? ' ku-area--wide' : ''; ?>" id="<?php echo esc_attr( $ku_id ); ?>"<?php echo 0 === $ku_n ? ' open' : ''; ?>>
							<summary class="ku-area__head">
								<span class="ku-area__icon" aria-hidden="true"><?php echo \Imedix\KnowledgeUniverse\View\Icons::topic( $ku_a['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- static SVG. ?></span>
								<span class="ku-area__heading">
									<span class="ku-area__title"><?php echo esc_html( $ku_a['name'] ); ?></span>
									<span class="ku-area__meta"><?php echo esc_html( implode( ' · ', $ku_meta ) ); ?></span>
								</span>
								<?php if ( $ku_verified ) : ?>
									<span class="ku-area__badge">
										<?php
										/* translators: %s: number of verified FDA-approved indications shown. */
										echo esc_html( sprintf( _n( '%s FDA-verified', '%s FDA-verified', $ku_verified, 'imedix-ku' ), number_format_i18n( $ku_verified ) ) );
										?>
									</span>
								<?php endif; ?>
								<svg class="ku-icon ku-area__chev" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m6 9 6 6 6-6"/></svg>
							</summary>
							<div class="ku-area__cols">
								<?php foreach ( $ku_cols as $ku_type => $ku_col ) : ?>
									<div class="ku-area__col ku-area__col--<?php echo esc_attr( $ku_type ); ?>">
										<h4 class="ku-area__label"><span class="ku-glyph ku-glyph--<?php echo esc_attr( $ku_type ); ?>" aria-hidden="true"></span><?php echo esc_html( $ku_col[0] ); ?></h4>
										<ul class="ku-area__list">
											<?php foreach ( $ku_col[1]['items'] as $ku_m ) : ?>
												<li>
													<a class="ku-area__link" href="<?php echo esc_url( home_url( $ku_m['path'] ) ); ?>"><?php echo esc_html( $ku_m['name'] ); ?></a>
													<?php if ( ! empty( $ku_m['active'] ) && 0 !== strcasecmp( $ku_m['active'], $ku_m['name'] ) ) : ?>
														<span class="ku-area__active"><span class="ku-visually-hidden"><?php esc_html_e( 'Active ingredient:', 'imedix-ku' ); ?> </span>
															<?php foreach ( $ku_m['actives'] as $ku_i => $ku_g ) : ?>
																<?php echo $ku_i ? ' + ' : ''; ?>
																<?php if ( '' !== $ku_g['path'] ) : ?>
																	<a class="ku-area__ing" href="<?php echo esc_url( home_url( $ku_g['path'] ) ); ?>"><?php echo esc_html( $ku_g['label'] ); ?></a>
																<?php else : ?>
																	<?php echo esc_html( $ku_g['label'] ); ?>
																<?php endif; ?>
															<?php endforeach; ?>
														</span>
													<?php endif; ?>
													<?php if ( 'drug' === $ku_type && $ku_is_verified( $ku_m ) ) : ?>
														<span class="ku-area__claim">
															<span class="ku-verified"><svg class="ku-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg><?php echo esc_html( $ku_m['claim'] ); ?></span>
															<a class="ku-area__src" href="<?php echo esc_url( $ku_m['source'] ); ?>" target="_blank" rel="noopener noreferrer nofollow" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: medicine name */ __( 'FDA label for %s on DailyMed (opens in a new tab)', 'imedix-ku' ), $ku_m['name'] ) ); ?>"><?php esc_html_e( 'FDA label', 'imedix-ku' ); ?><svg class="ku-icon ku-area__ext" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M14 5h5v5M19 5l-8 8M17 14v5H5V7h5"/></svg></a>
														</span>
													<?php endif; ?>
												</li>
											<?php endforeach; ?>
										</ul>
									</div>
								<?php endforeach; ?>
							</div>
						</details>
					<?php endforeach; ?>
				</div>
				<p class="ku-note"><?php esc_html_e( 'Health topics group related guides; a medicine listed in a topic is not necessarily approved to treat its conditions. This is not medical advice — talk to a healthcare professional.', 'imedix-ku' ); ?></p>
			<?php endif; ?>
		</div>
	</section>

	<script type="application/json" id="imedix-ku-config"><?php echo $config; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON encoded with JSON_HEX_* flags. ?></script>
</div>
