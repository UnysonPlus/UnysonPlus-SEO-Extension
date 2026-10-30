<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Site health — the failures nobody notices until traffic drops.
 *
 * Each one is a site-wide outage of search presence caused by a single switch:
 * the site left on "discourage search engines" after launch, the homepage served
 * as noindex, robots.txt erroring or blocking everything, the sitemap gone, the
 * Search Console connection quietly expired. The gap between it happening and
 * somebody noticing is usually weeks, so the checks run daily on their own.
 *
 * The site fetches its own pages, the way a search engine would, because what
 * matters is what is SERVED. Settings can say one thing while a cache, another
 * plugin or a physical file on the server says another.
 *
 * Results are shown on SEO → Site health, in WordPress's Tools → Site Health,
 * and, only for a critical failure, as an admin notice.
 *
 * Nothing here reads settings during startup (the option-types trap — see
 * FW_SEO_Redirects); the checks run from cron, an admin action or a screen.
 *
 * @since 2.0.40
 */
class FW_SEO_Health {

	const OPTION    = 'fw_seo_health';
	const CRON_HOOK = 'fw_seo_health_check';

	/** Swapped for report_html() when the settings page is drawn. */
	const PLACEHOLDER = '<!--fw-seo-health-report-->';

	const CRITICAL = 'critical';
	const WARNING  = 'warning';
	const GOOD     = 'good';

	public static function init() {
		add_action( self::CRON_HOOK, [ __CLASS__, 'run' ] );
		add_action( 'admin_init', [ __CLASS__, '_schedule' ] );
		add_action( 'admin_post_fw_seo_health_run', [ __CLASS__, '_action_run' ] );
		add_action( 'admin_notices', [ __CLASS__, '_notice' ] );
		add_filter( 'site_status_tests', [ __CLASS__, '_site_status_tests' ] );
	}

	/** @internal */
	public static function _schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Judging what was fetched — pure, so it can be tested without a network
	 * ------------------------------------------------------------------ */

	/**
	 * Does a page's HTML or headers tell search engines not to index it?
	 *
	 * @param string $html
	 * @param string $x_robots The X-Robots-Tag header, '' if none.
	 *
	 * @return bool
	 */
	public static function says_noindex( $html, $x_robots = '' ) {
		if ( preg_match( '#\b(noindex|none)\b#i', (string) $x_robots ) ) {
			return true;
		}

		if ( ! preg_match_all( '#<meta\b[^>]*>#i', (string) $html, $tags ) ) {
			return false;
		}

		foreach ( $tags[0] as $tag ) {
			if ( ! preg_match( '#\bname\s*=\s*["\']?(robots|googlebot)["\'\s/>]#i', $tag ) ) {
				continue;
			}

			if ( preg_match( '#\bcontent\s*=\s*["\']([^"\']*)["\']#i', $tag, $m ) && preg_match( '#\b(noindex|none)\b#i', $m[1] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Does a robots.txt block the whole site for every crawler (or Googlebot)?
	 *
	 * Only a group for `*` or `Googlebot` that disallows `/` with no Allow
	 * counts; a site blocking one crawler, or one folder, is a choice.
	 *
	 * @param string $txt
	 *
	 * @return bool
	 */
	public static function blocks_everything( $txt ) {
		$agents  = [];
		$in_rules = false;
		$groups  = [];

		foreach ( preg_split( "#\r?\n#", (string) $txt ) as $line ) {
			$line = trim( preg_replace( '/#.*$/', '', $line ) );

			if ( '' === $line || ! preg_match( '#^([A-Za-z-]+)\s*:\s*(.*)$#', $line, $m ) ) {
				continue;
			}

			$field = strtolower( $m[1] );
			$value = trim( $m[2] );

			if ( 'user-agent' === $field ) {
				if ( $in_rules ) {
					$agents   = [];
					$in_rules = false;
				}
				$agents[] = strtolower( $value );
				continue;
			}

			if ( in_array( $field, [ 'allow', 'disallow' ], true ) ) {
				$in_rules = true;
				foreach ( $agents as $agent ) {
					$groups[ $agent ][] = [ $field, $value ];
				}
			}
		}

		foreach ( [ 'googlebot', '*' ] as $agent ) {
			if ( ! isset( $groups[ $agent ] ) ) {
				continue;
			}

			$blocked = false;
			foreach ( $groups[ $agent ] as $rule ) {
				if ( 'disallow' === $rule[0] && '/' === $rule[1] ) {
					$blocked = true;
				}
				if ( 'allow' === $rule[0] && '' !== $rule[1] ) {
					$blocked = false;
					break;
				}
			}

			// Googlebot follows its own group if it has one, so it decides first.
			return $blocked;
		}

		return false;
	}

	/**
	 * Is this a sitemap (or sitemap index) with at least one entry?
	 *
	 * @param string $xml
	 *
	 * @return bool
	 */
	public static function has_entries( $xml ) {
		return (bool) preg_match( '#<(sitemap|url)\b[^>]*>\s*<loc>\s*\S+#i', (string) $xml );
	}

	/* ------------------------------------------------------------------ *
	 * The checks
	 * ------------------------------------------------------------------ */

	/**
	 * robots.txt at the top of the site's domain.
	 *
	 * @return string
	 */
	public static function robots_url() {
		$home = wp_parse_url( home_url() );
		$port = isset( $home['port'] ) ? ':' . $home['port'] : '';

		return ( $home['scheme'] ?? 'https' ) . '://' . ( $home['host'] ?? '' ) . $port . '/robots.txt';
	}

	/**
	 * @param string $url
	 *
	 * @return array{code: int, body: string, x_robots: string, error: string}
	 */
	protected static function fetch( $url ) {
		$response = wp_remote_get( add_query_arg( 'fw-seo-health', time(), $url ), [
			'timeout'     => 15,
			'redirection' => 3,
			'sslverify'   => false,
			'headers'     => [ 'Cache-Control' => 'no-cache' ],
			'user-agent'  => 'Mozilla/5.0 (compatible; Unyson+ SEO health check)',
		] );

		if ( is_wp_error( $response ) ) {
			return [ 'code' => 0, 'body' => '', 'x_robots' => '', 'error' => $response->get_error_message() ];
		}

		return [
			'code'     => (int) wp_remote_retrieve_response_code( $response ),
			'body'     => (string) wp_remote_retrieve_body( $response ),
			'x_robots' => (string) wp_remote_retrieve_header( $response, 'x-robots-tag' ),
			'error'    => '',
		];
	}

	/**
	 * @param string $id
	 * @param string $status
	 * @param string $label  What is wrong, or that it is fine.
	 * @param string $fix    What to do about it ('' when nothing).
	 * @param string $link   Where to do it.
	 *
	 * @return array
	 */
	protected static function result( $id, $status, $label, $fix = '', $link = '' ) {
		return compact( 'id', 'status', 'label', 'fix', 'link' );
	}

	/**
	 * Run every check and store the results.
	 *
	 * @return array
	 */
	public static function run() {
		$results = [];
		$public  = '0' !== (string) get_option( 'blog_public' );

		// 1. The switch that hides the whole site.
		$results[] = $public
			? self::result( 'visibility', self::GOOD, __( 'Search engines are allowed to index the site.', 'fw' ) )
			: self::result(
				'visibility',
				self::CRITICAL,
				__( 'Settings → Reading asks search engines not to index this site.', 'fw' ),
				__( 'Untick "Discourage search engines from indexing this site", unless the site is not meant to be found yet.', 'fw' ),
				admin_url( 'options-reading.php' )
			);

		// 2. The homepage, as served.
		$home = $public ? self::fetch( home_url( '/' ) ) : null;
		if ( ! $public ) {
			$results[] = self::result( 'home', self::WARNING, __( 'The homepage was not checked: the whole site is set to be hidden (above).', 'fw' ) );
		} elseif ( $home['error'] || $home['code'] >= 400 || 0 === $home['code'] ) {
			$results[] = self::result(
				'home',
				self::WARNING,
				sprintf(
					/* translators: %s: an HTTP status or an error message */
					__( 'The homepage could not be checked (%s).', 'fw' ),
					$home['error'] ?: 'HTTP ' . $home['code']
				),
				__( 'If the homepage opens normally in a browser, the server may block requests to itself; the other checks still run.', 'fw' )
			);
		} elseif ( self::says_noindex( $home['body'], $home['x_robots'] ) ) {
			$results[] = self::result(
				'home',
				self::CRITICAL,
				__( 'The homepage tells search engines not to index it (noindex).', 'fw' ),
				__( 'Open the homepage\'s SEO box and switch off "No index", or check SEO → Titles & Meta for the front page. A caching or maintenance plugin can also add it.', 'fw' ),
				(int) get_option( 'page_on_front' ) ? (string) get_edit_post_link( (int) get_option( 'page_on_front' ), 'raw' ) : admin_url( 'admin.php?page=' . FW_SEO_Settings_Page::PAGE_SLUG )
			);
		} else {
			$results[] = self::result( 'home', self::GOOD, __( 'The homepage can be indexed.', 'fw' ) );
		}

		// 3. robots.txt, as served — at the top of the domain, the only place
		// crawlers read it (and the only place WordPress serves it), even when
		// the site itself lives in a sub-folder.
		$robots_url = self::robots_url();
		$robots     = self::fetch( $robots_url );
		if ( 200 !== $robots['code'] ) {
			$results[] = self::result(
				'robots',
				self::CRITICAL,
				sprintf(
					/* translators: %s: an HTTP status or an error message */
					__( 'robots.txt does not answer (%s). Search engines may stop crawling a site whose robots.txt errors.', 'fw' ),
					$robots['error'] ?: 'HTTP ' . $robots['code']
				),
				__( 'Open the file in a browser. A server rule or a security plugin blocking it is the usual cause.', 'fw' ),
				$robots_url
			);
		} elseif ( $public && self::blocks_everything( $robots['body'] ) ) {
			$results[] = self::result(
				'robots',
				self::CRITICAL,
				__( 'robots.txt blocks the whole site (Disallow: /).', 'fw' ),
				file_exists( ABSPATH . 'robots.txt' )
					? __( 'A robots.txt FILE in the site\'s folder is doing it, often one left over from a staging site. Remove or fix it.', 'fw' )
					: __( 'Check your own rules under SEO → robots.txt.', 'fw' ),
				admin_url( 'admin.php?page=' . FW_SEO_Settings_Page::PAGE_SLUG . '&view=robots_tab' )
			);
		} else {
			$results[] = self::result( 'robots', self::GOOD, __( 'robots.txt answers and lets crawlers in.', 'fw' ) );
		}

		if ( file_exists( ABSPATH . 'robots.txt' ) ) {
			$results[] = self::result(
				'robots_file',
				self::WARNING,
				__( 'A robots.txt file on the server replaces the one WordPress and this extension write.', 'fw' ),
				__( 'Your rules under SEO → robots.txt, and the sitemap line, are not being served. Remove the file unless you maintain it on purpose.', 'fw' )
			);
		}

		// 4. The sitemap, as served.
		if ( class_exists( 'FW_SEO_Sitemap' ) && FW_SEO_Sitemap::is_enabled() ) {
			$map = self::fetch( FW_SEO_Sitemap::index_url() );
			if ( 200 !== $map['code'] || ! self::has_entries( $map['body'] ) ) {
				$results[] = self::result(
					'sitemap',
					self::CRITICAL,
					200 !== $map['code']
						? sprintf(
							/* translators: %s: an HTTP status or an error message */
							__( 'The sitemap does not answer (%s).', 'fw' ),
							$map['error'] ?: 'HTTP ' . $map['code']
						)
						: __( 'The sitemap answers but lists nothing.', 'fw' ),
					__( 'Re-save Settings → Permalinks to rebuild the addresses, then run the check again.', 'fw' ),
					admin_url( 'options-permalink.php' )
				);
			} else {
				$results[] = self::result( 'sitemap', self::GOOD, __( 'The sitemap answers and lists your content.', 'fw' ) );
			}
		}

		// 5. Search Console, when connected.
		if ( class_exists( 'FW_SEO_Console' ) && FW_SEO_Console::connected() ) {
			if ( '' === FW_SEO_Console::access_token() ) {
				$results[] = self::result(
					'console',
					self::WARNING,
					__( 'The Search Console connection has stopped working, so the reports that use it are no longer updating.', 'fw' ),
					FW_SEO_Console::looks_like_testing_expiry()
						? __( 'It stopped about a week after connecting, which is what happens when the Google Cloud project is left in "Testing". Publish the app\'s consent screen, then connect again.', 'fw' )
						: __( 'Connect again from SEO → Search Console.', 'fw' ),
					admin_url( 'admin.php?page=' . FW_SEO_Settings_Page::PAGE_SLUG . '&view=console_tab' )
				);
			} else {
				$results[] = self::result( 'console', self::GOOD, __( 'Search Console is connected.', 'fw' ) );
			}
		}

		$stored = [ 'time' => time(), 'results' => $results ];
		update_option( self::OPTION, $stored, false );

		return $stored;
	}

	/**
	 * The last stored run.
	 *
	 * @return array{time: int, results: array}
	 */
	public static function last() {
		$stored = get_option( self::OPTION, [] );

		return [
			'time'    => (int) ( $stored['time'] ?? 0 ),
			'results' => (array) ( $stored['results'] ?? [] ),
		];
	}

	/**
	 * @param array  $results
	 * @param string $status
	 *
	 * @return array
	 */
	public static function with_status( array $results, $status ) {
		return array_values( array_filter( $results, static function ( $r ) use ( $status ) {
			return ( $r['status'] ?? '' ) === $status;
		} ) );
	}

	/* ------------------------------------------------------------------ *
	 * Where the results are shown
	 * ------------------------------------------------------------------ */

	/** @internal */
	public static function _action_run() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'fw' ), 403 );
		}

		check_admin_referer( 'fw_seo_health_run' );
		self::run();

		wp_safe_redirect( admin_url( 'admin.php?page=' . FW_SEO_Settings_Page::PAGE_SLUG . '&view=health_tab' ) );
		exit;
	}

	/**
	 * A critical failure, on every admin screen, for administrators. Rare by
	 * design: only the checks that mean the site is invisible to search.
	 *
	 * @internal
	 */
	public static function _notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$results  = self::last()['results'];
		$critical = self::with_status( $results, self::CRITICAL );

		// A broken Search Console connection is the one warning that interrupts:
		// without it every report goes quietly stale while still looking fine.
		foreach ( self::with_status( $results, self::WARNING ) as $r ) {
			if ( 'console' === $r['id'] ) {
				$critical[] = $r;
			}
		}

		if ( ! $critical ) {
			return;
		}

		// Not on the report itself, which already says it.
		if ( isset( $_GET['page'], $_GET['view'] ) && FW_SEO_Settings_Page::PAGE_SLUG === $_GET['page'] && 'health_tab' === $_GET['view'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		$headline = self::CRITICAL === $critical[0]['status']
			? __( 'SEO: search engines may not be able to find this site.', 'fw' )
			: __( 'SEO: the reports have stopped updating.', 'fw' );

		echo '<div class="notice ' . ( self::CRITICAL === $critical[0]['status'] ? 'notice-error' : 'notice-warning' ) . '"><p><strong>' . esc_html( $headline ) . '</strong> ';
		echo esc_html( $critical[0]['label'] );
		if ( count( $critical ) > 1 ) {
			/* translators: %d: number of other problems */
			echo ' ' . esc_html( sprintf( _n( '(and %d more)', '(and %d more)', count( $critical ) - 1, 'fw' ), count( $critical ) - 1 ) );
		}
		echo ' <a href="' . esc_url( admin_url( 'admin.php?page=' . FW_SEO_Settings_Page::PAGE_SLUG . '&view=health_tab' ) ) . '">' . esc_html__( 'See what to do', 'fw' ) . '</a></p></div>';
	}

	/**
	 * Add the checks to Tools → Site Health, from the last stored run (Site
	 * Health runs its direct tests on every visit; fetching the site three
	 * times there would make that screen slow).
	 *
	 * @internal
	 */
	public static function _site_status_tests( $tests ) {
		$tests['direct']['fw_seo_health'] = [
			'label' => __( 'Search visibility (SEO)', 'fw' ),
			'test'  => [ __CLASS__, '_site_status_result' ],
		];

		return $tests;
	}

	/** @internal */
	public static function _site_status_result() {
		$last     = self::last();
		$critical = self::with_status( $last['results'], self::CRITICAL );
		$warning  = self::with_status( $last['results'], self::WARNING );
		$problems = array_merge( $critical, $warning );
		$link     = admin_url( 'admin.php?page=' . FW_SEO_Settings_Page::PAGE_SLUG . '&view=health_tab' );

		$description = '<p>' . esc_html__( 'The SEO extension checks every day that search engines can still reach and index the site.', 'fw' ) . '</p>';
		if ( $problems ) {
			$description .= '<ul>';
			foreach ( $problems as $p ) {
				$description .= '<li>' . esc_html( $p['label'] ) . ( $p['fix'] ? ' ' . esc_html( $p['fix'] ) : '' ) . '</li>';
			}
			$description .= '</ul>';
		}

		return [
			'label'       => ! $last['time']
				? __( 'Search visibility has not been checked yet', 'fw' )
				: ( $problems ? __( 'Search engines may not be able to find all of this site', 'fw' ) : __( 'Search engines can reach and index the site', 'fw' ) ),
			'status'      => $critical ? 'critical' : ( $warning || ! $last['time'] ? 'recommended' : 'good' ),
			'badge'       => [ 'label' => __( 'SEO', 'fw' ), 'color' => 'blue' ],
			'description' => $description,
			'actions'     => '<p><a href="' . esc_url( $link ) . '">' . esc_html__( 'Open the SEO site health report', 'fw' ) . '</a></p>',
			'test'        => 'fw_seo_health',
		];
	}

	/**
	 * The report on SEO → Site health.
	 *
	 * @return string
	 */
	public static function report_html() {
		$last = self::last();
		$run  = wp_nonce_url( admin_url( 'admin-post.php?action=fw_seo_health_run' ), 'fw_seo_health_run' );

		$icons = [
			self::CRITICAL => [ 'dashicons-dismiss', '#d63638', __( 'Problem', 'fw' ) ],
			self::WARNING  => [ 'dashicons-warning', '#dba617', __( 'Check', 'fw' ) ],
			self::GOOD     => [ 'dashicons-yes-alt', '#00a32a', __( 'Fine', 'fw' ) ],
		];

		$html  = '<div class="fw-seo-health">';
		$html .= '<p>' . ( $last['time']
			? sprintf(
				/* translators: %s: how long ago, e.g. "3 hours" */
				esc_html__( 'Checked %s ago. It runs by itself once a day.', 'fw' ),
				esc_html( human_time_diff( $last['time'] ) )
			)
			: esc_html__( 'Not checked yet. It runs by itself once a day.', 'fw' ) );
		$html .= ' <a class="button" href="' . esc_url( $run ) . '">' . esc_html__( 'Check now', 'fw' ) . '</a></p>';

		if ( ! $last['results'] ) {
			return $html . '</div>';
		}

		// Problems first.
		$order   = [ self::CRITICAL => 0, self::WARNING => 1, self::GOOD => 2 ];
		$results = $last['results'];
		usort( $results, static function ( $a, $b ) use ( $order ) {
			return ( $order[ $a['status'] ] ?? 3 ) <=> ( $order[ $b['status'] ] ?? 3 );
		} );

		$html .= '<table class="widefat striped" style="max-width:960px"><tbody>';
		foreach ( $results as $r ) {
			$icon  = $icons[ $r['status'] ] ?? $icons[ self::WARNING ];
			$html .= '<tr><td style="width:2em"><span class="dashicons ' . esc_attr( $icon[0] ) . '" style="color:' . esc_attr( $icon[1] ) . '" title="' . esc_attr( $icon[2] ) . '"></span></td><td>';
			$html .= '<strong>' . esc_html( $r['label'] ) . '</strong>';
			if ( '' !== $r['fix'] ) {
				$html .= '<br>' . esc_html( $r['fix'] );
			}
			if ( '' !== $r['link'] ) {
				$html .= ' <a href="' . esc_url( $r['link'] ) . '">' . esc_html__( 'Go there', 'fw' ) . '</a>';
			}
			$html .= '</td></tr>';
		}

		return $html . '</tbody></table></div>';
	}
}
