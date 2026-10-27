<?php
/**
 * Knowledge Universe markup. Everything essential is server-rendered:
 * heading, working search form, latest news links and crawlable discovery
 * links. JavaScript only enhances it (graph, autocomplete, ticker motion).
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
			<p class="ku-eyebrow"><?php esc_html_e( 'iMedix Knowledge Universe', 'imedix-ku' ); ?></p>
			<h1 id="ku-title" class="ku-title">
				<?php
				/* translators: Emphasised part of the homepage heading is wrapped in <em>. */
				echo wp_kses( __( 'Find your way through <em>medical knowledge</em>', 'imedix-ku' ), array( 'em' => array() ) );
				?>
			</h1>
			<p class="ku-lede">
				<?php if ( $ku_counts['drug'] && $ku_counts['disease'] ) : ?>
					<?php
					printf(
						/* translators: 1: number of drug guides, 2: number of disease guides. */
						esc_html__( 'Search %1$s drug guides and %2$s disease guides, or explore how the iMedix library connects.', 'imedix-ku' ),
						esc_html( number_format_i18n( (int) $ku_counts['drug'] ) ),
						esc_html( number_format_i18n( (int) $ku_counts['disease'] ) )
					);
					?>
				<?php else : ?>
					<?php esc_html_e( 'Search iMedix drug and disease guides, or explore how the library connects.', 'imedix-ku' ); ?>
				<?php endif; ?>
			</p>

			<form class="ku-search" role="search" method="get" action="<?php echo esc_url( $vm['searchUrl'] ); ?>" data-ku-search>
				<label class="ku-search__label" for="ku-q"><?php esc_html_e( 'Search drugs and diseases', 'imedix-ku' ); ?></label>
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

		<div class="ku-hud" data-ku-hud hidden>
			<ul class="ku-legend" aria-label="<?php esc_attr_e( 'Map legend', 'imedix-ku' ); ?>">
				<li><span class="ku-glyph ku-glyph--drug" aria-hidden="true"></span><?php esc_html_e( 'Drug', 'imedix-ku' ); ?></li>
				<li><span class="ku-glyph ku-glyph--disease" aria-hidden="true"></span><?php esc_html_e( 'Disease', 'imedix-ku' ); ?></li>
				<li><span class="ku-glyph ku-glyph--topic" aria-hidden="true"></span><?php esc_html_e( 'Editorial topic', 'imedix-ku' ); ?></li>
			</ul>
			<div class="ku-controls" role="group" aria-label="<?php esc_attr_e( 'Map controls', 'imedix-ku' ); ?>">
				<button type="button" class="ku-btn ku-btn--explore" data-ku-action="explore">
					<svg class="ku-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 3v18M3 12h18"/><circle cx="12" cy="12" r="9"/></svg>
					<span data-ku-label-on="<?php esc_attr_e( 'Done exploring', 'imedix-ku' ); ?>" data-ku-label-off="<?php esc_attr_e( 'Explore the map', 'imedix-ku' ); ?>"><?php esc_html_e( 'Explore the map', 'imedix-ku' ); ?></span>
				</button>
				<button type="button" class="ku-btn ku-btn--icon" data-ku-action="zoom-in" aria-label="<?php esc_attr_e( 'Zoom in', 'imedix-ku' ); ?>">
					<svg class="ku-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 5v14M5 12h14"/></svg>
				</button>
				<button type="button" class="ku-btn ku-btn--icon" data-ku-action="zoom-out" aria-label="<?php esc_attr_e( 'Zoom out', 'imedix-ku' ); ?>">
					<svg class="ku-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 12h14"/></svg>
				</button>
				<button type="button" class="ku-btn ku-btn--icon" data-ku-action="reset" aria-label="<?php esc_attr_e( 'Reset view', 'imedix-ku' ); ?>">
					<svg class="ku-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 12a8 8 0 1 0 2.4-5.7"/><path d="M4 4v4h4"/></svg>
				</button>
				<button type="button" class="ku-btn ku-btn--icon" data-ku-action="motion" aria-pressed="false" aria-label="<?php esc_attr_e( 'Pause map motion', 'imedix-ku' ); ?>">
					<svg class="ku-icon ku-icon--pause" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 6v12M15 6v12"/></svg>
					<svg class="ku-icon ku-icon--play" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M8 5.5v13l10-6.5z"/></svg>
				</button>
			</div>
		</div>

		<aside class="ku-card" data-ku-card hidden aria-labelledby="ku-card-title">
			<button type="button" class="ku-card__close" data-ku-card-close aria-label="<?php esc_attr_e( 'Close details', 'imedix-ku' ); ?>">
				<svg class="ku-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6 6 18"/></svg>
			</button>
			<p class="ku-card__type" data-ku-card-type></p>
			<p class="ku-card__title" id="ku-card-title" data-ku-card-title></p>
			<p class="ku-card__meta" data-ku-card-meta></p>
			<p class="ku-card__desc" data-ku-card-desc hidden></p>
			<div class="ku-card__links" data-ku-card-links></div>
			<a class="ku-card__cta" data-ku-card-cta href="#"><?php esc_html_e( 'Read the guide', 'imedix-ku' ); ?><svg class="ku-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
		</aside>
	</section>

	<section class="ku-news" aria-labelledby="ku-news-title" data-ku-news<?php echo $vm['news'] ? '' : ' hidden'; ?>>
		<div class="ku-news__inner">
			<h2 class="ku-news__label" id="ku-news-title"><span class="ku-news__pulse" aria-hidden="true"></span><?php esc_html_e( 'Latest medical news', 'imedix-ku' ); ?></h2>
			<div class="ku-news__viewport" data-ku-ticker>
				<ul class="ku-news__track" data-ku-ticker-track>
					<?php foreach ( $vm['news'] as $ku_n ) : ?>
						<li class="ku-news__item" data-ku-news-id="<?php echo esc_attr( (string) $ku_n['id'] ); ?>">
							<a href="<?php echo esc_url( $ku_n['url'] ); ?>">
								<time datetime="<?php echo esc_attr( $ku_n['date'] ); ?>"><?php echo esc_html( date_i18n( 'M j', (int) strtotime( $ku_n['date'] ) ) ); ?></time>
								<span><?php echo esc_html( $ku_n['title'] ); ?></span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<button type="button" class="ku-news__toggle" data-ku-ticker-toggle aria-pressed="false" hidden>
				<svg class="ku-icon ku-icon--pause" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 6v12M15 6v12"/></svg>
				<svg class="ku-icon ku-icon--play" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M8 5.5v13l10-6.5z"/></svg>
				<span class="ku-visually-hidden"><?php esc_html_e( 'Pause news ticker', 'imedix-ku' ); ?></span>
			</button>
			<?php if ( $vm['newsArchive'] ) : ?>
				<a class="ku-news__all" href="<?php echo esc_url( $vm['newsArchive'] ); ?>"><?php esc_html_e( 'All news', 'imedix-ku' ); ?></a>
			<?php endif; ?>
		</div>
	</section>

	<section class="ku-discover" aria-labelledby="ku-discover-title">
		<div class="ku-wrap">
			<div class="ku-discover__head">
				<h2 class="ku-discover__title" id="ku-discover-title"><?php esc_html_e( 'Browse the library', 'imedix-ku' ); ?></h2>
				<p class="ku-discover__intro"><?php esc_html_e( 'Start from a drug, a condition, or one of the editorial topics our team uses to organise the guides.', 'imedix-ku' ); ?></p>
			</div>

			<ul class="ku-entries">
				<li class="ku-entry">
					<a class="ku-entry__link" href="<?php echo esc_url( $vm['drugArchive'] ? $vm['drugArchive'] : '#ku-q' ); ?>" data-ku-focus-search="drug">
						<span class="ku-glyph ku-glyph--drug ku-glyph--lg" aria-hidden="true"></span>
						<span class="ku-entry__title"><?php esc_html_e( 'Explore drugs', 'imedix-ku' ); ?></span>
						<span class="ku-entry__meta">
							<?php
							/* translators: %s: number of guides. */
							printf( esc_html__( '%s guides', 'imedix-ku' ), esc_html( number_format_i18n( (int) $ku_counts['drug'] ) ) );
							?>
						</span>
					</a>
				</li>
				<li class="ku-entry">
					<a class="ku-entry__link" href="<?php echo esc_url( $vm['diseaseArchive'] ? $vm['diseaseArchive'] : '#ku-q' ); ?>" data-ku-focus-search="disease">
						<span class="ku-glyph ku-glyph--disease ku-glyph--lg" aria-hidden="true"></span>
						<span class="ku-entry__title"><?php esc_html_e( 'Explore diseases', 'imedix-ku' ); ?></span>
						<span class="ku-entry__meta">
							<?php
							/* translators: %s: number of guides. */
							printf( esc_html__( '%s guides', 'imedix-ku' ), esc_html( number_format_i18n( (int) $ku_counts['disease'] ) ) );
							?>
						</span>
					</a>
				</li>
				<?php if ( $vm['topics'] ) : ?>
					<li class="ku-entry">
						<a class="ku-entry__link" href="#ku-topics">
							<span class="ku-glyph ku-glyph--topic ku-glyph--lg" aria-hidden="true"></span>
							<span class="ku-entry__title"><?php esc_html_e( 'Browse by topic', 'imedix-ku' ); ?></span>
							<span class="ku-entry__meta">
								<?php
								/* translators: %s: number of topics. */
								printf( esc_html__( '%s editorial topics', 'imedix-ku' ), esc_html( number_format_i18n( count( $vm['topics'] ) ) ) );
								?>
							</span>
						</a>
					</li>
				<?php endif; ?>
			</ul>

			<?php if ( $vm['topics'] ) : ?>
				<div class="ku-topics" id="ku-topics">
					<?php foreach ( $vm['topics'] as $ku_t ) : ?>
						<section class="ku-topic" aria-labelledby="<?php echo esc_attr( 'ku-' . str_replace( ':', '-', $ku_t['id'] ) ); ?>">
							<h3 class="ku-topic__title" id="<?php echo esc_attr( 'ku-' . str_replace( ':', '-', $ku_t['id'] ) ); ?>"><?php echo esc_html( $ku_t['name'] ); ?></h3>
							<p class="ku-topic__count">
								<?php
								/* translators: %s: number of guides. */
								printf( esc_html( _n( '%s guide', '%s guides', (int) $ku_t['total'], 'imedix-ku' ) ), esc_html( number_format_i18n( (int) $ku_t['total'] ) ) );
								?>
							</p>
							<ul class="ku-topic__list">
								<?php foreach ( $ku_t['members'] as $ku_m ) : ?>
									<li>
										<a href="<?php echo esc_url( home_url( $ku_m['path'] ) ); ?>">
											<span class="ku-glyph ku-glyph--<?php echo esc_attr( $ku_m['type'] ); ?>" aria-hidden="true"></span>
											<?php echo esc_html( $ku_m['name'] ); ?>
											<span class="ku-visually-hidden">(<?php echo esc_html( $ku_types[ $ku_m['type'] ] ); ?>)</span>
										</a>
									</li>
								<?php endforeach; ?>
							</ul>
						</section>
					<?php endforeach; ?>
				</div>
				<p class="ku-note">
					<svg class="ku-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>
					<?php esc_html_e( 'Topics and map connections show how iMedix organises and cross-links its guides. They are not medical advice and do not mean that a medicine treats a condition. Always read the full guide and talk to a healthcare professional.', 'imedix-ku' ); ?>
				</p>
			<?php endif; ?>
		</div>
	</section>

	<script type="application/json" id="imedix-ku-config"><?php echo $config; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON encoded with JSON_HEX_* flags. ?></script>
</div>
