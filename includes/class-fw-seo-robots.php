<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * robots.txt editor — your own crawl rules without writing a filter.
 *
 * WordPress builds robots.txt on the fly; there is no file to edit. This adds
 * three things to what it builds: blocking internal search URLs, blocking AI
 * crawlers by name, and a table of custom Allow / Disallow rules. Every row is
 * checked before it reaches the file, because the common mistakes here are
 * syntax errors that silently do nothing (a path without a leading slash) or
 * do far too much (a stray line break splitting one rule into two).
 *
 * Rules for every crawler (`*`) are merged into WordPress's own `User-agent: *`
 * group rather than added as a second one: crawlers that follow the standard
 * combine repeated groups, but not all of them do.
 *
 * Settings are read inside the filter, never during startup (the option-types
 * trap — see FW_SEO_Redirects).
 *
 * @since 2.0.38
 */
class FW_SEO_Robots {

	/** Swapped for the preview when the settings page is drawn. */
	const PLACEHOLDER = '<!--fw-seo-robots-preview-->';

	/**
	 * Crawlers that collect pages to train AI models. Blocking them does not
	 * affect search results.
	 */
	const AI_TRAINING = [ 'GPTBot', 'ClaudeBot', 'Google-Extended', 'Applebot-Extended', 'CCBot', 'Bytespider', 'meta-externalagent', 'Amazonbot' ];

	/**
	 * Crawlers that fetch pages to answer questions in AI search and assistants.
	 * Blocking them keeps the site out of those answers.
	 */
	const AI_SEARCH = [ 'OAI-SearchBot', 'ChatGPT-User', 'Claude-SearchBot', 'Claude-User', 'PerplexityBot' ];

	public static function init() {
		// Before the sitemap's filter (10), which appends the Sitemap line last.
		add_filter( 'robots_txt', [ __CLASS__, '_filter' ], 9, 2 );
	}

	/**
	 * The settings this feature reads, with defaults.
	 *
	 * @return array
	 */
	public static function settings() {
		if ( ! class_exists( 'FW_SEO_Settings' ) ) {
			return [];
		}

		return [
			'robots_block_search' => FW_SEO_Settings::flag( 'robots_block_search', false ),
			'robots_ai_training'  => (array) FW_SEO_Settings::get( 'robots_ai_training', [] ),
			'robots_ai_search'    => (array) FW_SEO_Settings::get( 'robots_ai_search', [] ),
			'robots_rules'        => (array) FW_SEO_Settings::get( 'robots_rules', [] ),
		];
	}

	/**
	 * A user agent as robots.txt allows it, or null.
	 *
	 * @param mixed $agent
	 *
	 * @return string|null
	 */
	public static function clean_agent( $agent ) {
		$agent = trim( (string) $agent );

		return ( '' !== $agent && preg_match( '#^[A-Za-z0-9*._/-]+$#', $agent ) ) ? $agent : null;
	}

	/**
	 * A path as robots.txt allows it, or null. An empty Disallow is valid (it
	 * means "nothing is blocked"); an empty Allow means nothing and is dropped.
	 *
	 * @param mixed  $path
	 * @param string $directive
	 *
	 * @return string|null
	 */
	public static function clean_path( $path, $directive ) {
		$path = trim( (string) $path );

		if ( '' === $path ) {
			return 'disallow' === $directive ? '' : null;
		}

		if ( preg_match( '#[\x00-\x20\x7f]#', $path ) || ! in_array( $path[0], [ '/', '*' ], true ) ) {
			return null;
		}

		return $path;
	}

	/**
	 * Turn settings into robots.txt groups.
	 *
	 * @param array  $s    As returned by settings().
	 * @param string $base The home path without a trailing slash ('' at the root).
	 *
	 * @return array{groups: array<string, string[]>, skipped: array<int, array{row: array, reason: string}>}
	 */
	public static function build( array $s, $base = '' ) {
		$groups  = [];
		$skipped = [];
		$add     = static function ( $agent, $line ) use ( &$groups ) {
			if ( ! isset( $groups[ $agent ] ) || ! in_array( $line, $groups[ $agent ], true ) ) {
				$groups[ $agent ][] = $line;
			}
		};

		if ( ! empty( $s['robots_block_search'] ) ) {
			$add( '*', 'Disallow: ' . $base . '/*?s=' );
			$add( '*', 'Disallow: ' . $base . '/search/' );
		}

		foreach ( [ 'robots_ai_training' => self::AI_TRAINING, 'robots_ai_search' => self::AI_SEARCH ] as $key => $known ) {
			foreach ( (array) ( $s[ $key ] ?? [] ) as $agent => $on ) {
				// Checkboxes store { agent: true }; a plain list is accepted too.
				$listed = is_int( $agent );
				$agent  = $listed ? (string) $on : (string) $agent;

				if ( ( $listed || $on ) && in_array( $agent, $known, true ) ) {
					$add( $agent, 'Disallow: /' );
				}
			}
		}

		foreach ( (array) ( $s['robots_rules'] ?? [] ) as $row ) {
			$row       = (array) $row;
			$directive = 'allow' === ( $row['directive'] ?? '' ) ? 'allow' : 'disallow';
			$agent     = self::clean_agent( $row['agent'] ?? '*' );
			$path      = self::clean_path( $row['path'] ?? '', $directive );

			if ( null === $agent ) {
				$skipped[] = [ 'row' => $row, 'reason' => __( 'the crawler name has spaces or characters robots.txt does not allow', 'fw' ) ];
				continue;
			}

			if ( null === $path ) {
				$skipped[] = [ 'row' => $row, 'reason' => __( 'the path must start with / or *', 'fw' ) ];
				continue;
			}

			$add( $agent, ucfirst( $directive ) . ': ' . $path );
		}

		return [ 'groups' => $groups, 'skipped' => $skipped ];
	}

	/**
	 * Merge groups into WordPress's robots.txt output.
	 *
	 * @param string $output
	 * @param array  $groups
	 *
	 * @return string
	 */
	public static function apply( $output, array $groups ) {
		if ( ! $groups ) {
			return $output;
		}

		$lines = preg_split( "#\r?\n#", rtrim( (string) $output ) );

		if ( isset( $groups['*'] ) ) {
			$start = null;

			foreach ( $lines as $i => $line ) {
				if ( preg_match( '#^\s*User-agent:\s*\*\s*$#i', $line ) ) {
					$start = $i;
					break;
				}
			}

			if ( null === $start ) {
				$lines = array_merge( [ 'User-agent: *' ], $groups['*'], [ '' ], $lines );
			} else {
				// The group runs until a blank line, a Sitemap line, or the next
				// User-agent line that follows a rule.
				$end = $start;
				for ( $i = $start + 1, $n = count( $lines ); $i < $n; $i ++ ) {
					if ( '' === trim( $lines[ $i ] ) || preg_match( '#^\s*(Sitemap|User-agent):#i', $lines[ $i ] ) ) {
						break;
					}
					$end = $i;
				}

				$have = array_map( 'trim', array_slice( $lines, $start + 1, $end - $start ) );
				$new  = array_values( array_diff( $groups['*'], $have ) );
				array_splice( $lines, $end + 1, 0, $new );
			}

			unset( $groups['*'] );
		}

		foreach ( $groups as $agent => $rules ) {
			$lines[] = '';
			$lines[] = 'User-agent: ' . $agent;
			foreach ( $rules as $rule ) {
				$lines[] = $rule;
			}
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Crawlers given their own group that does not simply block everything.
	 *
	 * A crawler obeys only the most specific group naming it, so one with its
	 * own group drops every `*` rule. For a block-all group that loses
	 * nothing; for anything narrower it usually surprises people.
	 *
	 * @param array $groups
	 *
	 * @return string[]
	 */
	public static function partial_groups( array $groups ) {
		$out = [];

		foreach ( $groups as $agent => $rules ) {
			if ( '*' !== $agent && $rules !== [ 'Disallow: /' ] ) {
				$out[] = (string) $agent;
			}
		}

		return $out;
	}

	/** @return string The home path without a trailing slash. */
	public static function base() {
		return rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
	}

	/** @internal */
	public static function _filter( $output, $public ) {
		if ( ! $public ) {
			return $output; // The site asks not to be crawled at all; leave that alone.
		}

		$built = self::build( self::settings(), self::base() );

		return self::apply( $output, $built['groups'] );
	}

	/**
	 * What robots.txt will contain, built the way WordPress builds it.
	 *
	 * @return string
	 */
	public static function render_file() {
		$public = (string) get_option( 'blog_public' );
		$path   = (string) wp_parse_url( site_url(), PHP_URL_PATH );
		$output = "User-agent: *\n";

		if ( '0' === $public ) {
			$output .= "Disallow: /\n";
		} else {
			$output .= "Disallow: $path/wp-admin/\nAllow: $path/wp-admin/admin-ajax.php\n";
		}

		return (string) apply_filters( 'robots_txt', $output, $public );
	}

	/**
	 * The preview under the settings, with anything that stops it being served.
	 *
	 * @return string
	 */
	public static function preview_html() {
		$notes = [];

		if ( file_exists( ABSPATH . 'robots.txt' ) ) {
			$notes[] = __( 'There is a robots.txt FILE in the site\'s folder. The web server sends that file instead, so nothing set here is served until it is removed or renamed.', 'fw' );
		}

		if ( '0' === (string) get_option( 'blog_public' ) ) {
			$notes[] = __( 'Settings → Reading asks search engines not to index this site, so robots.txt blocks everything and the rules here are not added.', 'fw' );
		}

		if ( '' !== self::base() ) {
			$notes[] = __( 'This site lives in a sub-folder. Crawlers only read robots.txt at the top of the domain, so these rules take effect only if that file includes them.', 'fw' );
		}

		$built = self::build( self::settings(), self::base() );

		foreach ( $built['skipped'] as $skip ) {
			$row     = $skip['row'];
			$notes[] = sprintf(
				/* translators: 1: the rule as entered, 2: why it was left out */
				__( 'Left out "%1$s": %2$s.', 'fw' ),
				trim( ( $row['agent'] ?? '*' ) . ' ' . ( $row['directive'] ?? '' ) . ' ' . ( $row['path'] ?? '' ) ),
				$skip['reason']
			);
		}

		foreach ( self::partial_groups( $built['groups'] ) as $agent ) {
			$notes[] = sprintf(
				/* translators: %s: a crawler name */
				__( '%s now has its own rules, so it ignores every rule for * (all crawlers), including WordPress\'s own. Add a rule for it for anything in the * group it should still follow.', 'fw' ),
				$agent
			);
		}

		$html = '<div class="fw-seo-robots-preview">';

		foreach ( $notes as $note ) {
			$html .= '<div class="notice notice-warning inline fw-seo-robots-note"><p>' . esc_html( $note ) . '</p></div>';
		}

		$html .= '<p><strong>' . esc_html__( 'What crawlers receive', 'fw' ) . '</strong> — '
			. '<a href="' . esc_url( home_url( '/robots.txt' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'open the live file', 'fw' ) . '</a>'
			. ' · ' . esc_html__( 'save to update the preview', 'fw' ) . '</p>';
		$html .= '<pre style="background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:14px 16px;margin:0;white-space:pre-wrap;font-size:13px;line-height:1.6">' . esc_html( self::render_file() ) . '</pre>';

		return $html . '</div>';
	}
}
