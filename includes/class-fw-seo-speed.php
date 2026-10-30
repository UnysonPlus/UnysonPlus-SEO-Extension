<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Page speed diagnostics — what to fix, not just whether it is slow.
 *
 * The Page speed report shows field data from the Chrome UX Report: how fast
 * the site really is for the people using it. That answers *whether* speed is
 * worth an afternoon. It cannot say *where the afternoon should go*. Lab
 * diagnostics from the PageSpeed Insights API can: the render-blocking file, the
 * oversized image, the element that shifts the layout.
 *
 * The API is free and needs no OAuth. A key is optional; without one Google
 * applies a small shared quota. The Chrome UX Report key from the field-data
 * report is tried first, and if that project has not enabled PageSpeed
 * Insights, the request is retried without a key rather than failing.
 *
 * A run takes 10–30 seconds on Google's side, so it is only ever started by a
 * button — never while rendering a screen.
 *
 * @since 2.0.34
 */
class FW_SEO_Speed {

	const ENDPOINT  = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
	const CACHE_KEY = 'fw_seo_speed';
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/** How many opportunities to list. Past five the list stops being a to-do. */
	const TOP = 5;

	/**
	 * Audits that name a specific cause, beyond the ones Lighthouse marks as
	 * "opportunities" with a time saving.
	 */
	const DIAGNOSTICS = [
		'largest-contentful-paint-element',
		'layout-shift-elements',
		'dom-size',
		'third-party-summary',
	];

	public static function init() {
		if ( is_admin() ) {
			add_action( 'admin_post_fw_seo_speed_run', [ __CLASS__, '_action_run' ] );
		}
	}

	/* ---------------------------------------------------------------------- *
	 * Pure parsing — tested without a network
	 * ---------------------------------------------------------------------- */

	/**
	 * Reduce a PageSpeed Insights response to what is worth showing.
	 *
	 * @param array $data
	 *
	 * @return array
	 */
	public static function parse( array $data ) {
		$lh     = (array) ( $data['lighthouseResult'] ?? [] );
		$audits = (array) ( $lh['audits'] ?? [] );
		$score  = $lh['categories']['performance']['score'] ?? null;

		$opportunities = [];

		foreach ( $audits as $id => $audit ) {
			$details = (array) ( $audit['details'] ?? [] );

			if ( 'opportunity' !== ( $details['type'] ?? '' ) ) {
				continue;
			}

			$savings = self::savings( (array) $audit );
			$passing = isset( $audit['score'] ) && (float) $audit['score'] >= 0.9;

			// A passing audit, or one known to save under a tenth of a second, is
			// not a to-do item; listing it dilutes the ones that are. An UNKNOWN
			// saving (null) on a failing audit is kept: Lighthouse has moved where
			// it reports savings before, and dropping everything it cannot measure
			// would turn a format change into a false all-clear.
			if ( $passing || ( null !== $savings && $savings < 100 ) ) {
				continue;
			}

			$opportunities[] = [
				'id'      => (string) $id,
				'title'   => (string) ( $audit['title'] ?? $id ),
				'savings' => null === $savings ? null : (int) round( $savings ),
				'items'   => self::item_urls( $details ),
			];
		}

		usort( $opportunities, static function ( $a, $b ) {
			return (int) $b['savings'] <=> (int) $a['savings'];
		} );

		$diagnostics = [];

		foreach ( self::DIAGNOSTICS as $id ) {
			if ( empty( $audits[ $id ] ) ) {
				continue;
			}

			$audit = (array) $audits[ $id ];

			if ( isset( $audit['score'] ) && (float) $audit['score'] >= 0.9 ) {
				continue;
			}

			$diagnostics[] = [
				'id'      => $id,
				'title'   => (string) ( $audit['title'] ?? $id ),
				'display' => (string) ( $audit['displayValue'] ?? '' ),
				'items'   => self::item_urls( (array) ( $audit['details'] ?? [] ) ),
			];
		}

		return [
			'score'         => null === $score ? null : (int) round( (float) $score * 100 ),
			'url'           => (string) ( $lh['finalUrl'] ?? $lh['requestedUrl'] ?? '' ),
			'opportunities' => array_slice( $opportunities, 0, self::TOP ),
			'diagnostics'   => $diagnostics,
			'checked'       => time(),
		];
	}

	/**
	 * Estimated saving in milliseconds, wherever this Lighthouse version keeps it.
	 *
	 * Older responses carry `details.overallSavingsMs`; newer ones add
	 * `metricSavings` per metric. Null means "not stated", which is different
	 * from zero.
	 *
	 * @param array $audit
	 *
	 * @return float|null
	 */
	public static function savings( array $audit ) {
		if ( isset( $audit['details']['overallSavingsMs'] ) ) {
			return (float) $audit['details']['overallSavingsMs'];
		}

		if ( ! empty( $audit['metricSavings'] ) && is_array( $audit['metricSavings'] ) ) {
			$ms = [];

			foreach ( [ 'LCP', 'FCP', 'TBT' ] as $metric ) {
				if ( isset( $audit['metricSavings'][ $metric ] ) ) {
					$ms[] = (float) $audit['metricSavings'][ $metric ];
				}
			}

			if ( $ms ) {
				return max( $ms );
			}
		}

		return null;
	}

	/**
	 * Up to three things an audit points at — a file URL, or an element's
	 * snippet when there is no URL.
	 *
	 * @param array $details
	 *
	 * @return string[]
	 */
	protected static function item_urls( array $details ) {
		$out = [];

		foreach ( (array) ( $details['items'] ?? [] ) as $item ) {
			$label = '';

			if ( ! empty( $item['url'] ) ) {
				$label = (string) $item['url'];
			} elseif ( ! empty( $item['node']['snippet'] ) ) {
				$label = (string) $item['node']['snippet'];
			} elseif ( ! empty( $item['entity'] ) && is_string( $item['entity'] ) ) {
				$label = $item['entity'];
			}

			if ( '' !== $label ) {
				$out[] = $label;
			}

			if ( count( $out ) >= 3 ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * The page being diagnosed must belong to this site: the button is not an
	 * open proxy for running PageSpeed against anybody's URL.
	 *
	 * @param string $url
	 *
	 * @return bool
	 */
	public static function is_own_url( $url ) {
		$home = wp_parse_url( home_url() );
		$test = wp_parse_url( (string) $url );

		return ! empty( $test['host'] ) && ! empty( $home['host'] )
			&& strtolower( $test['host'] ) === strtolower( $home['host'] )
			&& in_array( $test['scheme'] ?? '', [ 'http', 'https' ], true );
	}

	/* ---------------------------------------------------------------------- *
	 * Running
	 * ---------------------------------------------------------------------- */

	/**
	 * @param string $url
	 * @param string $strategy mobile|desktop
	 *
	 * @return array{ok:bool,reason:string,result:array}
	 */
	public static function run( $url, $strategy = 'mobile' ) {
		if ( ! self::is_own_url( $url ) ) {
			return [ 'ok' => false, 'reason' => 'foreign', 'result' => [] ];
		}

		$key  = class_exists( 'FW_SEO_Vitals' ) ? FW_SEO_Vitals::api_key() : '';
		$args = [
			'url'      => $url,
			'strategy' => 'desktop' === $strategy ? 'desktop' : 'mobile',
			'category' => 'performance',
		];

		$response = self::get( $args, $key );

		// The CrUX key belongs to a project that may not have PageSpeed Insights
		// enabled. That is a 403 about the KEY, not the page, so try once
		// without it before giving up.
		if ( '' !== $key && 403 === $response['code'] ) {
			$response = self::get( $args, '' );
		}

		if ( 'network' === $response['reason'] ) {
			return [ 'ok' => false, 'reason' => 'network', 'result' => [] ];
		}

		if ( 200 !== $response['code'] ) {
			return [ 'ok' => false, 'reason' => 429 === $response['code'] ? 'ratelimited' : 'error', 'result' => [] ];
		}

		$result             = self::parse( $response['data'] );
		$result['strategy'] = $args['strategy'];

		return [ 'ok' => true, 'reason' => '', 'result' => $result ];
	}

	/**
	 * @param array  $args
	 * @param string $key
	 *
	 * @return array{code:int,reason:string,data:array}
	 */
	protected static function get( array $args, $key ) {
		if ( '' !== $key ) {
			$args['key'] = $key;
		}

		$response = wp_remote_get( add_query_arg( array_map( 'rawurlencode', $args ), self::ENDPOINT ), [ 'timeout' => 60 ] );

		if ( is_wp_error( $response ) ) {
			return [ 'code' => 0, 'reason' => 'network', 'data' => [] ];
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return [
			'code'   => (int) wp_remote_retrieve_response_code( $response ),
			'reason' => '',
			'data'   => is_array( $data ) ? $data : [],
		];
	}

	/**
	 * @return array|null
	 */
	public static function cached() {
		$c = get_transient( self::CACHE_KEY );

		return is_array( $c ) ? $c : null;
	}

	/**
	 * @internal admin-post: run a diagnosis.
	 */
	public static function _action_run() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'fw' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( 'fw_seo_speed_run' );

		// phpcs:disable WordPress.Security.NonceVerification -- verified above.
		$url      = isset( $_GET['url'] ) ? esc_url_raw( wp_unslash( $_GET['url'] ) ) : home_url( '/' );
		$strategy = isset( $_GET['strategy'] ) ? sanitize_key( wp_unslash( $_GET['strategy'] ) ) : 'mobile';
		// phpcs:enable

		// Lighthouse can take half a minute; do not let PHP give up first.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 90 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$out = self::run( $url, $strategy );

		set_transient( self::CACHE_KEY, $out + [ 'requested' => $url ], $out['ok'] ? self::CACHE_TTL : 10 * MINUTE_IN_SECONDS );

		wp_safe_redirect( FW_SEO_Settings_Page::url( 'vitals_tab' ) . '#fw-seo-speed' );
		exit;
	}

	/* ---------------------------------------------------------------------- *
	 * Rendering
	 * ---------------------------------------------------------------------- */

	/**
	 * Shorten an item for display WITHOUT touching its markup.
	 *
	 * Not wp_html_excerpt(): that strips tags, and an element snippet such as
	 * `<img class="hero">` is nothing but a tag, so it came out empty.
	 *
	 * @param string $text
	 *
	 * @return string
	 */
	public static function clip( $text ) {
		$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );

		return function_exists( 'mb_strimwidth' ) ? mb_strimwidth( $text, 0, 110, '…' ) : substr( $text, 0, 110 );
	}

	/**
	 * @param int $ms
	 *
	 * @return string
	 */
	public static function seconds( $ms ) {
		/* translators: %s: seconds, e.g. 1.2 */
		return sprintf( __( '%s s', 'fw' ), number_format_i18n( $ms / 1000, 1 ) );
	}

	/**
	 * @return string
	 */
	public static function report_html() {
		$cached = self::cached();
		$url    = $cached['requested'] ?? home_url( '/' );

		ob_start();
		?>
		<div class="fw-seo-speed" id="fw-seo-speed">
			<h3><?php esc_html_e( 'What to fix', 'fw' ); ?></h3>
			<p class="description" style="max-width:52em">
				<?php esc_html_e( 'A lab test of one page, run by Google, naming the specific files and elements slowing it down. The numbers above say whether speed is worth working on; this says where to start. It takes up to half a minute.', 'fw' ); ?>
			</p>

			<?php
			// Not a <form>: this report renders inside the settings form, and a
			// nested form is dropped by the browser, so its button would save
			// the settings instead. A nonced link carries the run; the fields
			// have no `name`, so they never ride along on a settings save.
			$base = wp_nonce_url( admin_url( 'admin-post.php?action=fw_seo_speed_run' ), 'fw_seo_speed_run' );
			$run  = add_query_arg( [ 'url' => rawurlencode( $url ), 'strategy' => 'mobile' ], $base );
			?>
			<div class="fw-seo-speed-form" data-base="<?php echo esc_url( $base ); ?>">
				<label class="screen-reader-text" for="fw-seo-speed-url"><?php esc_html_e( 'Page to test', 'fw' ); ?></label>
				<input type="url" id="fw-seo-speed-url" value="<?php echo esc_attr( $url ); ?>" class="regular-text" />
				<select id="fw-seo-speed-strategy">
					<option value="mobile" <?php selected( ( $cached['result']['strategy'] ?? 'mobile' ), 'mobile' ); ?>><?php esc_html_e( 'Mobile', 'fw' ); ?></option>
					<option value="desktop" <?php selected( ( $cached['result']['strategy'] ?? '' ), 'desktop' ); ?>><?php esc_html_e( 'Desktop', 'fw' ); ?></option>
				</select>
				<a class="button" id="fw-seo-speed-run" href="<?php echo esc_url( $run ); ?>"><?php esc_html_e( 'Run diagnostics', 'fw' ); ?></a>
				<span class="fw-seo-speed-wait" hidden><?php esc_html_e( 'Testing — this takes up to half a minute…', 'fw' ); ?></span>
			</div>
			<script>
			( function () {
				var box = document.querySelector( '.fw-seo-speed-form' );
				if ( ! box ) { return; }
				var link = box.querySelector( '#fw-seo-speed-run' );
				link.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					var url = box.querySelector( '#fw-seo-speed-url' ).value;
					var strategy = box.querySelector( '#fw-seo-speed-strategy' ).value;
					box.querySelector( '.fw-seo-speed-wait' ).hidden = false;
					link.setAttribute( 'aria-disabled', 'true' );
					link.style.pointerEvents = 'none';
					window.location.href = box.dataset.base + '&url=' + encodeURIComponent( url ) + '&strategy=' + encodeURIComponent( strategy );
				} );
				// Enter in the URL field must not submit the settings form.
				box.querySelector( '#fw-seo-speed-url' ).addEventListener( 'keydown', function ( e ) {
					if ( 'Enter' === e.key ) { e.preventDefault(); link.click(); }
				} );
			}() );
			</script>

			<?php if ( $cached && empty( $cached['ok'] ) ) : ?>
				<div class="notice notice-warning inline" style="margin:1em 0 0"><p>
					<?php
					switch ( $cached['reason'] ?? '' ) {
						case 'foreign':
							esc_html_e( 'Only pages on this site can be tested from here.', 'fw' );
							break;
						case 'network':
							esc_html_e( 'Could not reach Google. This is usually a passing network problem — try again.', 'fw' );
							break;
						case 'ratelimited':
							esc_html_e( 'Google is limiting requests without a key. Add a Chrome UX Report API key above and enable the PageSpeed Insights API for the same project.', 'fw' );
							break;
						default:
							esc_html_e( 'Google could not test that page. Check it loads for a visitor who is not logged in, then try again.', 'fw' );
					}
					?>
				</p></div>
			<?php elseif ( $cached ) :
				$r = $cached['result'];
				?>
				<p class="fw-seo-speed-meta">
					<?php if ( null !== $r['score'] ) : ?>
						<span class="fw-seo-speed-score fw-seo-speed-score-<?php echo esc_attr( $r['score'] >= 90 ? 'good' : ( $r['score'] >= 50 ? 'ni' : 'poor' ) ); ?>"><?php echo esc_html( $r['score'] ); ?></span>
					<?php endif; ?>
					<?php
					printf(
						/* translators: 1: tested URL, 2: mobile or desktop, 3: when. */
						esc_html__( '%1$s, %2$s, tested %3$s ago.', 'fw' ),
						'<code>' . esc_html( $r['url'] ) . '</code>',
						'desktop' === $r['strategy'] ? esc_html__( 'desktop', 'fw' ) : esc_html__( 'mobile', 'fw' ),
						esc_html( human_time_diff( (int) $r['checked'] ) )
					);
					?>
				</p>

				<?php if ( ! $r['opportunities'] && ! $r['diagnostics'] ) : ?>
					<?php if ( null !== $r['score'] && $r['score'] < 90 ) : ?>
						<?php // Never an all-clear on a low score: an empty list there means we could not read the causes, not that there are none. ?>
						<p><?php esc_html_e( 'Google scored this page below 90 but this test did not name a specific cause. Open the full report on pagespeed.web.dev for the detail.', 'fw' ); ?></p>
					<?php else : ?>
						<p><?php esc_html_e( 'Nothing worth fixing turned up. The page is in good shape for this test.', 'fw' ); ?></p>
					<?php endif; ?>
				<?php endif; ?>

				<?php if ( $r['opportunities'] ) : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Fix', 'fw' ); ?></th>
								<th class="num"><?php esc_html_e( 'Could save', 'fw' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $r['opportunities'] as $o ) : ?>
								<tr>
									<td>
										<strong><?php echo esc_html( $o['title'] ); ?></strong>
										<?php foreach ( $o['items'] as $item ) : ?>
											<br /><code><?php echo esc_html( self::clip( $item ) ); ?></code>
										<?php endforeach; ?>
									</td>
									<td class="num"><?php echo null === $o['savings'] ? '&mdash;' : esc_html( self::seconds( $o['savings'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<?php if ( $r['diagnostics'] ) : ?>
					<h4><?php esc_html_e( 'Also worth knowing', 'fw' ); ?></h4>
					<ul class="fw-seo-speed-diag">
						<?php foreach ( $r['diagnostics'] as $d ) : ?>
							<li>
								<strong><?php echo esc_html( $d['title'] ); ?></strong>
								<?php if ( '' !== $d['display'] ) : ?> — <?php echo esc_html( $d['display'] ); ?><?php endif; ?>
								<?php foreach ( $d['items'] as $item ) : ?>
									<br /><code><?php echo esc_html( self::clip( $item ) ); ?></code>
								<?php endforeach; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			<?php endif; ?>
		</div>

		<style>
		.fw-seo-speed{margin-top:1.6em;padding-top:1.2em;border-top:1px solid #e6e6ea}
		.fw-seo-speed h3{margin:0 0 .3em}
		.fw-seo-speed-form{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:.8em 0}
		/* The admin skin makes every field 100% wide; here they share one row. */
		body .fw-seo-speed .fw-seo-speed-form input[type=url]{width:28em!important;max-width:100%!important;flex:0 1 auto}
		body .fw-seo-speed .fw-seo-speed-form select{width:auto!important;min-width:8em;flex:0 0 auto}
		.fw-seo-speed-wait{color:#646970}
		.fw-seo-speed-meta{display:flex;align-items:center;gap:10px;margin:1em 0 .6em}
		.fw-seo-speed-score{display:inline-grid;place-items:center;width:38px;height:38px;border-radius:50%;font-weight:700;font-size:14px}
		.fw-seo-speed-score-good{background:#eaf6ee;color:#1d6336}
		.fw-seo-speed-score-ni{background:#fcf3e6;color:#8a6116}
		.fw-seo-speed-score-poor{background:#fcecec;color:#8a1f1f}
		.fw-seo-speed table{max-width:62em}
		.fw-seo-speed td,.fw-seo-speed th{vertical-align:top}
		.fw-seo-speed .num{text-align:right;white-space:nowrap;width:8em}
		.fw-seo-speed code{font-size:11px;word-break:break-all}
		.fw-seo-speed-diag li{margin-bottom:.7em}
		</style>
		<?php

		return (string) ob_get_clean();
	}
}
