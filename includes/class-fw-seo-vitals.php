<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * What real visitors actually experienced, from Google's CrUX field data.
 *
 * The Asset Optimizer already optimises for these metrics — it preloads the
 * likely LCP image and hoists blocks to avoid a measured layout shift — but it
 * has no idea whether any of it worked. It optimises blind. This closes that
 * loop, and it lives here because per-URL reporting already does.
 *
 * Field data, not a lab score: these are percentiles over 28 days of real
 * visits, so they answer "is the site fast for the people using it" rather than
 * "is it fast on one simulated connection". The trade is that a page without
 * enough traffic has no data at all — which the report must say plainly rather
 * than showing a zero, because a zero reads as a result.
 */
class FW_SEO_Vitals {

	const ENDPOINT  = 'https://chromeuxreport.googleapis.com/v1/records:queryRecord';
	const CACHE_KEY = 'fw_seo_vitals';
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * The three metrics, with the thresholds Google treats as good.
	 *
	 * @return array<string,array{label:string,unit:string,good:float}>
	 */
	public static function metrics() {
		return [
			'largest_contentful_paint' => [ 'label' => __( 'Largest Contentful Paint', 'fw' ), 'unit' => 'ms', 'good' => 2500 ],
			'interaction_to_next_paint' => [ 'label' => __( 'Interaction to Next Paint', 'fw' ), 'unit' => 'ms', 'good' => 200 ],
			'cumulative_layout_shift'  => [ 'label' => __( 'Cumulative Layout Shift', 'fw' ), 'unit' => '', 'good' => 0.1 ],
		];
	}

	/**
	 * @return string
	 */
	public static function api_key() {
		return trim( (string) FW_SEO_Settings::get( 'crux_api_key', '' ) );
	}

	/**
	 * @return bool
	 */
	public static function configured() {
		return '' !== self::api_key();
	}

	/**
	 * Ask CrUX about a URL, or about the site as a whole.
	 *
	 * @param string $target
	 * @param bool   $is_origin
	 *
	 * @return array{ok:bool,reason:string,metrics:array,checked:int}
	 */
	public static function fetch( $target, $is_origin = true ) {
		$key = self::api_key();

		if ( '' === $key ) {
			return [ 'ok' => false, 'reason' => 'nokey', 'metrics' => [], 'checked' => time() ];
		}

		$body = $is_origin ? [ 'origin' => $target ] : [ 'url' => $target ];

		$response = wp_remote_post(
			add_query_arg( 'key', rawurlencode( $key ), self::ENDPOINT ),
			[
				'timeout' => 12,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( $body ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return [ 'ok' => false, 'reason' => 'network', 'metrics' => [], 'checked' => time() ];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		// 404 is not a failure. It is CrUX saying this URL has too few visits
		// to report on, which is the ordinary case for most pages on most
		// sites — and must never be shown as an error or as a zero.
		if ( 404 === $code ) {
			return [ 'ok' => false, 'reason' => 'nodata', 'metrics' => [], 'checked' => time() ];
		}

		if ( 200 !== $code || ! is_array( $data ) ) {
			$reason = ( 403 === $code || 400 === $code ) ? 'badkey' : 'error';

			return [ 'ok' => false, 'reason' => $reason, 'metrics' => [], 'checked' => time() ];
		}

		return [
			'ok'      => true,
			'reason'  => '',
			'metrics' => self::parse( $data['record']['metrics'] ?? [] ),
			'checked' => time(),
		];
	}

	/**
	 * Reduce CrUX's response to the three numbers worth showing.
	 *
	 * @param array $raw
	 *
	 * @return array<string,array{p75:float,good:float,ni:float,poor:float}>
	 */
	public static function parse( array $raw ) {
		$out = [];

		foreach ( self::metrics() as $key => $meta ) {
			if ( empty( $raw[ $key ] ) ) {
				continue;
			}

			$metric = $raw[ $key ];
			$bins   = $metric['histogram'] ?? [];

			$out[ $key ] = [
				// The 75th percentile is the number Google judges a site on —
				// not the average, which a handful of fast visits can flatter.
				'p75'  => isset( $metric['percentiles']['p75'] ) ? (float) $metric['percentiles']['p75'] : 0.0,
				'good' => isset( $bins[0]['density'] ) ? (float) $bins[0]['density'] : 0.0,
				'ni'   => isset( $bins[1]['density'] ) ? (float) $bins[1]['density'] : 0.0,
				'poor' => isset( $bins[2]['density'] ) ? (float) $bins[2]['density'] : 0.0,
			];
		}

		return $out;
	}

	/**
	 * The cached reading, if there is one.
	 *
	 * @return array|null
	 */
	public static function cached() {
		$value = get_transient( self::CACHE_KEY );

		return is_array( $value ) ? $value : null;
	}

	/**
	 * Fetch the site-wide reading and cache it.
	 *
	 * @return array
	 */
	public static function refresh() {
		$result = self::fetch( untrailingslashit( home_url() ), true );

		// Cached even when there is no data, so a site too small to be in CrUX
		// does not re-ask Google on every page load for an answer that will not
		// change today.
		set_transient( self::CACHE_KEY, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * @param string $metric
	 * @param float  $value
	 *
	 * @return string good|ni|poor
	 */
	public static function verdict( $metric, $value ) {
		$metrics = self::metrics();
		$good    = $metrics[ $metric ]['good'] ?? 0;

		if ( ! $good ) {
			return 'ni';
		}

		if ( $value <= $good ) {
			return 'good';
		}

		// Google's "needs improvement" band runs to roughly 4x the good
		// threshold for LCP/INP and 2.5x for CLS; close enough for a label,
		// and the exact boundary is not a decision anyone makes on.
		$poor_at = 'cumulative_layout_shift' === $metric ? $good * 2.5 : $good * 4;

		return $value <= $poor_at ? 'ni' : 'poor';
	}

	/**
	 * @param string $metric
	 * @param float  $value
	 *
	 * @return string
	 */
	public static function format( $metric, $value ) {
		if ( 'cumulative_layout_shift' === $metric ) {
			return number_format_i18n( $value, 2 );
		}

		return $value >= 1000
			? sprintf( '%ss', number_format_i18n( $value / 1000, 2 ) )
			: sprintf( '%sms', number_format_i18n( $value ) );
	}

	public static function init() {
		if ( is_admin() ) {
			add_action( 'admin_post_fw_seo_vitals_refresh', [ __CLASS__, '_action_refresh' ] );
		}
	}

	/**
	 * @internal
	 */
	public static function _action_refresh() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'fw' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( 'fw_seo_vitals_refresh' );

		delete_transient( self::CACHE_KEY );
		self::refresh();

		wp_safe_redirect( FW_SEO_Settings_Page::url( 'vitals_tab' ) );
		exit;
	}

	/**
	 * @return string
	 */
	public static function report_html() {
		$cached = self::cached();
		$button = wp_nonce_url( admin_url( 'admin-post.php?action=fw_seo_vitals_refresh' ), 'fw_seo_vitals_refresh' );

		ob_start();
		?>
		<div class="fw-seo-vitals">
			<?php if ( ! self::configured() ) : ?>
				<p class="description">
					<?php esc_html_e( 'Add a Chrome UX Report API key above to see how fast your pages actually are for real visitors. The key is free from Google Cloud and the data costs nothing.', 'fw' ); ?>
				</p>
			<?php elseif ( ! $cached ) : ?>
				<p><a class="button" href="<?php echo esc_url( $button ); ?>"><?php esc_html_e( 'Check now', 'fw' ); ?></a></p>
				<p class="description"><?php esc_html_e( 'Nothing checked yet. Readings are cached for twelve hours, so this never slows the page down on its own.', 'fw' ); ?></p>
			<?php elseif ( 'nodata' === ( $cached['reason'] ?? '' ) ) : ?>
				<div class="notice notice-info inline" style="margin:0 0 1em"><p>
					<?php esc_html_e( 'Not enough traffic for field data. Chrome only reports on sites with enough real visits, so this is the ordinary answer for a small or new site — it is not a problem with the site or the key.', 'fw' ); ?>
				</p></div>
				<p><a class="button" href="<?php echo esc_url( $button ); ?>"><?php esc_html_e( 'Check again', 'fw' ); ?></a></p>
			<?php elseif ( empty( $cached['ok'] ) ) : ?>
				<div class="notice notice-warning inline" style="margin:0 0 1em"><p>
					<?php
					$reason = $cached['reason'] ?? 'error';

					if ( 'badkey' === $reason ) {
						esc_html_e( 'Google rejected the API key. Check it is a Chrome UX Report key and that the API is enabled for it.', 'fw' );
					} elseif ( 'network' === $reason ) {
						esc_html_e( 'Could not reach Google. This is usually a temporary network problem — try again.', 'fw' );
					} else {
						esc_html_e( 'Google returned an unexpected response. Try again shortly.', 'fw' );
					}
					?>
				</p></div>
				<p><a class="button" href="<?php echo esc_url( $button ); ?>"><?php esc_html_e( 'Try again', 'fw' ); ?></a></p>
			<?php else : ?>
				<table>
					<thead>
						<tr>
							<th><?php esc_html_e( 'Metric', 'fw' ); ?></th>
							<th class="num"><?php esc_html_e( '75th percentile', 'fw' ); ?></th>
							<th><?php esc_html_e( 'Share of visits', 'fw' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( self::metrics() as $key => $meta ) :
							if ( empty( $cached['metrics'][ $key ] ) ) {
								continue;
							}

							$m       = $cached['metrics'][ $key ];
							$verdict = self::verdict( $key, $m['p75'] );
							?>
							<tr>
								<td><?php echo esc_html( $meta['label'] ); ?></td>
								<td class="num">
									<span class="fw-seo-v fw-seo-v-<?php echo esc_attr( $verdict ); ?>"><?php echo esc_html( self::format( $key, $m['p75'] ) ); ?></span>
								</td>
								<td>
									<span class="fw-seo-bar">
										<span class="good" style="width:<?php echo esc_attr( round( $m['good'] * 100 ) ); ?>%"></span>
										<span class="ni" style="width:<?php echo esc_attr( round( $m['ni'] * 100 ) ); ?>%"></span>
										<span class="poor" style="width:<?php echo esc_attr( round( $m['poor'] * 100 ) ); ?>%"></span>
									</span>
									<span class="fw-seo-bar-key"><?php
										printf(
											/* translators: %s: percentage of visits rated good. */
											esc_html__( '%s good', 'fw' ),
											esc_html( number_format_i18n( round( $m['good'] * 100 ) ) . '%' )
										);
									?></span>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">
					<?php
					printf(
						/* translators: %s: human time difference. */
						esc_html__( 'Real visits over the last 28 days, checked %s ago. The 75th percentile is the number Google judges a site on — not the average, which a handful of fast visits can flatter.', 'fw' ),
						esc_html( human_time_diff( (int) ( $cached['checked'] ?? time() ) ) )
					);
					?>
				</p>
				<p><a class="button" href="<?php echo esc_url( $button ); ?>"><?php esc_html_e( 'Check again', 'fw' ); ?></a></p>
			<?php endif; ?>
		</div>
		<style>
		.fw-seo-vitals table{border-collapse:collapse;width:100%;max-width:46em}
		.fw-seo-vitals th,.fw-seo-vitals td{text-align:left;padding:7px 10px;border-bottom:1px solid #e6e6ea;font-size:13px;vertical-align:middle}
		.fw-seo-vitals th{font-weight:600;color:#50575e}
		.fw-seo-vitals td.num,.fw-seo-vitals th.num{text-align:right;width:10em}
		.fw-seo-v{font-weight:600}
		.fw-seo-v-good{color:#1a7f47}.fw-seo-v-ni{color:#8a6116}.fw-seo-v-poor{color:#b32d2e}
		.fw-seo-bar{display:inline-block;vertical-align:middle;width:11em;height:8px;border-radius:4px;overflow:hidden;background:#e6e6ea;white-space:nowrap;font-size:0}
		.fw-seo-bar span{display:inline-block;height:100%}
		.fw-seo-bar .good{background:#1a7f47}.fw-seo-bar .ni{background:#dba617}.fw-seo-bar .poor{background:#b32d2e}
		.fw-seo-bar-key{margin-left:.6em;font-size:12px;color:#50575e}
		</style>
		<?php

		return (string) ob_get_clean();
	}
}
