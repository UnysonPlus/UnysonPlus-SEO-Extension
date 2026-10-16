<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Share images drawn for pages that have none.
 *
 * The image ladder ends at a site-wide default, which means every text page
 * without a picture shares the same card — so every link to the site looks
 * identical in a feed. Drawing one per page from its title fixes the common
 * case, and it is the rung between "nothing on the page" and "the site
 * default", so a page that HAS a picture is never overridden.
 *
 * Deliberately no bundled font. Nothing in the plugin or theme ships a text
 * face, and adding one means carrying a licence and 300KB for a feature many
 * sites will not use. Instead the font is FOUND: a file the user chose, then a
 * theme's own, then the usual system paths — DejaVu and Liberation are present
 * on nearly every Linux host, Arial on Windows. When none is found the feature
 * says so rather than drawing something unreadable.
 */
class FW_SEO_Card {

	const WIDTH  = 1200;
	const HEIGHT = 630;

	/** Bumped when the drawing changes, so cached cards are redrawn. */
	const RENDER_VERSION = '1';

	/**
	 * @return string Directory cards are written to.
	 */
	public static function dir() {
		return function_exists( 'fw_upw_uploads_dir' )
			? fw_upw_uploads_dir( 'social-cards' )['path']
			: trailingslashit( wp_upload_dir()['basedir'] ) . 'unysonplus/social-cards';
	}

	/**
	 * @return string
	 */
	public static function url_base() {
		return function_exists( 'fw_upw_uploads_dir' )
			? fw_upw_uploads_dir( 'social-cards' )['url']
			: trailingslashit( wp_upload_dir()['baseurl'] ) . 'unysonplus/social-cards';
	}

	/**
	 * Where to draw text from.
	 *
	 * @return string Absolute path, or '' when nothing usable exists.
	 */
	public static function font_path() {
		$found = '';

		$chosen = FW_SEO_Image::url_from_value( FW_SEO_Settings::get( 'social_card_font', '' ) );

		if ( '' !== $chosen ) {
			$id   = attachment_url_to_postid( $chosen );
			$file = $id ? get_attached_file( $id ) : '';

			if ( $file && is_readable( $file ) ) {
				$found = $file;
			}
		}

		if ( '' === $found ) {
			foreach ( [ get_stylesheet_directory() . '/fonts', get_template_directory() . '/fonts' ] as $dir ) {
				if ( ! is_dir( $dir ) ) {
					continue;
				}

				foreach ( (array) glob( $dir . '/*.ttf' ) as $file ) {
					if ( is_readable( $file ) ) {
						$found = $file;
						break 2;
					}
				}
			}
		}

		if ( '' === $found ) {
			$system = [
				'/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
				'/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
				'/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
				'/usr/share/fonts/dejavu/DejaVuSans.ttf',
				'/Library/Fonts/Arial.ttf',
				'C:/Windows/Fonts/arialbd.ttf',
				'C:/Windows/Fonts/arial.ttf',
			];

			foreach ( $system as $file ) {
				if ( is_readable( $file ) ) {
					$found = $file;
					break;
				}
			}
		}

		/**
		 * Filters the TrueType font used to draw generated share images.
		 *
		 * Applied to the RESULT of the search, not consulted only when the
		 * search fails — so it can supply a font, replace the one found, or
		 * return '' to switch card drawing off entirely. A filter that could
		 * only ever add is a filter that cannot be used to say no.
		 */
		return (string) apply_filters( 'fw_seo_card_font', $found );
	}

	/**
	 * Can a card be drawn at all on this server?
	 *
	 * @return bool
	 */
	public static function available() {
		if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagettfbbox' ) ) {
			return false;
		}

		return '' !== self::font_path();
	}

	/**
	 * @return bool
	 */
	public static function enabled() {
		return (bool) FW_SEO_Settings::flag( 'social_card_enabled', true );
	}

	/**
	 * The colours a card is drawn in.
	 *
	 * @return array{bg:array,fg:array,accent:array}
	 */
	public static function palette() {
		$bg     = self::hex( FW_SEO_Settings::get( 'social_card_bg', '#111827' ), [ 17, 24, 39 ] );
		$fg     = self::hex( FW_SEO_Settings::get( 'social_card_fg', '#ffffff' ), [ 255, 255, 255 ] );
		$accent = self::hex( FW_SEO_Settings::get( 'social_card_accent', '#2271b1' ), [ 34, 113, 177 ] );

		return [ 'bg' => $bg, 'fg' => $fg, 'accent' => $accent ];
	}

	/**
	 * @param mixed $value
	 * @param array $fallback
	 *
	 * @return array{0:int,1:int,2:int}
	 */
	protected static function hex( $value, array $fallback ) {
		$hex = ltrim( trim( (string) $value ), '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		if ( ! preg_match( '/^[0-9a-f]{6}$/i', $hex ) ) {
			return $fallback;
		}

		return [ hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) ];
	}

	/**
	 * Break a title into lines that fit, measuring the real glyphs.
	 *
	 * Measured rather than estimated by character count, because a title of
	 * capitals and one of lowercase occupy very different widths at the same
	 * length — and the overflow only shows up on the finished image.
	 *
	 * @param string $text
	 * @param string $font
	 * @param int    $size
	 * @param int    $max_width
	 * @param int    $max_lines
	 *
	 * @return array<int,string>
	 */
	public static function wrap( $text, $font, $size, $max_width, $max_lines = 4 ) {
		$words = preg_split( '/\s+/u', trim( (string) $text ) ) ?: [];
		$lines = [];
		$line  = '';

		foreach ( $words as $word ) {
			$try = '' === $line ? $word : $line . ' ' . $word;
			$box = imagettfbbox( $size, 0, $font, $try );
			$w   = abs( $box[2] - $box[0] );

			if ( $w <= $max_width || '' === $line ) {
				$line = $try;
				continue;
			}

			$lines[] = $line;
			$line    = $word;

			if ( count( $lines ) >= $max_lines ) {
				break;
			}
		}

		if ( '' !== $line && count( $lines ) < $max_lines ) {
			$lines[] = $line;
		}

		// A title too long to fit is truncated on the LAST line with an
		// ellipsis, rather than silently losing its tail off the edge.
		if ( count( $lines ) >= $max_lines ) {
			$last = array_pop( $lines );

			while ( '' !== $last ) {
				$box = imagettfbbox( $size, 0, $font, $last . '…' );

				if ( abs( $box[2] - $box[0] ) <= $max_width ) {
					break;
				}

				$last = preg_replace( '/\s*\S+$/u', '', $last );
			}

			$lines[] = rtrim( (string) $last ) . '…';
		}

		return $lines;
	}

	/**
	 * A fingerprint of everything that affects the drawing.
	 *
	 * In the filename, so changing the title or the colours produces a new file
	 * rather than serving a stale one — and so the old file is obvious when it
	 * is time to sweep it up.
	 *
	 * @param string $title
	 *
	 * @return string
	 */
	public static function signature( $title ) {
		$palette = self::palette();

		return substr( md5( wp_json_encode( [
			self::RENDER_VERSION,
			$title,
			$palette,
			(bool) FW_SEO_Settings::flag( 'social_card_sitename', true ),
			get_bloginfo( 'name' ),
			basename( self::font_path() ),
		] ) ), 0, 12 );
	}

	/**
	 * Draw the card and write it.
	 *
	 * @param int    $post_id
	 * @param string $title
	 *
	 * @return string Absolute path, or '' on failure.
	 */
	public static function render( $post_id, $title ) {
		$font = self::font_path();

		if ( '' === $font || ! function_exists( 'imagecreatetruecolor' ) ) {
			return '';
		}

		$dir = self::dir();

		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		$file = trailingslashit( $dir ) . 'card-' . (int) $post_id . '-' . self::signature( $title ) . '.png';

		if ( file_exists( $file ) ) {
			return $file;
		}

		$palette = self::palette();
		$im      = imagecreatetruecolor( self::WIDTH, self::HEIGHT );

		if ( ! $im ) {
			return '';
		}

		$bg     = imagecolorallocate( $im, $palette['bg'][0], $palette['bg'][1], $palette['bg'][2] );
		$fg     = imagecolorallocate( $im, $palette['fg'][0], $palette['fg'][1], $palette['fg'][2] );
		$accent = imagecolorallocate( $im, $palette['accent'][0], $palette['accent'][1], $palette['accent'][2] );

		imagefilledrectangle( $im, 0, 0, self::WIDTH, self::HEIGHT, $bg );

		// A bar down the left edge, so the card reads as designed rather than
		// as text that failed to load.
		imagefilledrectangle( $im, 0, 0, 14, self::HEIGHT, $accent );

		$pad   = 90;
		$max_w = self::WIDTH - ( $pad * 2 );
		$show  = (bool) FW_SEO_Settings::flag( 'social_card_sitename', true );

		// The space the title may occupy, once the padding and the site-name
		// strip are taken out.
		$usable = self::HEIGHT - ( $pad * 2 ) - ( $show ? 70 : 0 );

		// Largest size whose wrapped title FITS, rather than a fixed size with a
		// fixed line cap. The earlier version bumped short titles to a larger
		// size while keeping the smaller size's line cap, so a title that
		// wrapped to two lines at 54 was re-wrapped at 68 and truncated — the
		// ellipsis appeared on titles that had room to spare.
		$size  = 44;
		$lines = [];

		foreach ( [ 68, 60, 52, 44 ] as $try ) {
			// A generous cap so this pass measures rather than truncates.
			$candidate = self::wrap( $title, $font, $try, $max_w, 20 );
			$allowed   = max( 1, (int) floor( $usable / ( $try * 1.34 ) ) );

			if ( count( $candidate ) <= $allowed ) {
				$size  = $try;
				$lines = $candidate;
				break;
			}
		}

		// Nothing fitted even at the smallest size: truncate there.
		if ( ! $lines ) {
			$allowed = max( 1, (int) floor( $usable / ( $size * 1.34 ) ) );
			$lines   = self::wrap( $title, $font, $size, $max_w, $allowed );
		}

		$line_h = (int) round( $size * 1.34 );
		$block  = count( $lines ) * $line_h;
		$y      = (int) round( ( self::HEIGHT - $block ) / 2 ) + $size - ( $show ? 18 : 0 );

		foreach ( $lines as $line ) {
			imagettftext( $im, $size, 0, $pad, $y, $fg, $font, $line );
			$y += $line_h;
		}

		if ( $show ) {
			$name = get_bloginfo( 'name' );

			if ( '' !== $name ) {
				imagettftext( $im, 24, 0, $pad, self::HEIGHT - 70, $accent, $font, $name );
			}
		}

		$ok = imagepng( $im, $file, 6 );
		imagedestroy( $im );

		return $ok ? $file : '';
	}

	/**
	 * The card URL for a post, drawing it if needed.
	 *
	 * @param FW_SEO_Context $ctx
	 *
	 * @return string
	 */
	public static function url( FW_SEO_Context $ctx ) {
		if ( ! self::enabled() ) {
			return '';
		}

		$post_id = $ctx->post_id();

		if ( ! $post_id ) {
			return '';
		}

		// The card shows the page's OWN title, not the resolved SEO title: the
		// SEO title usually ends "| Site Name", and the site name is already on
		// the card. Repeating it would waste the one line that matters.
		$title = wp_strip_all_tags( get_the_title( $post_id ) );

		if ( '' === trim( $title ) ) {
			return '';
		}

		$file = self::render( $post_id, $title );

		if ( '' === $file ) {
			return '';
		}

		return trailingslashit( self::url_base() ) . basename( $file );
	}

	/**
	 * Remove every card drawn for a post (all signatures).
	 *
	 * @param int $post_id
	 *
	 * @return int
	 */
	public static function forget( $post_id ) {
		$files = (array) glob( trailingslashit( self::dir() ) . 'card-' . (int) $post_id . '-*.png' );
		$gone  = 0;

		foreach ( $files as $file ) {
			if ( is_file( $file ) && @unlink( $file ) ) {
				$gone ++;
			}
		}

		return $gone;
	}

	/**
	 * @return int
	 */
	public static function clear() {
		$files = (array) glob( trailingslashit( self::dir() ) . 'card-*.png' );
		$gone  = 0;

		foreach ( $files as $file ) {
			if ( is_file( $file ) && @unlink( $file ) ) {
				$gone ++;
			}
		}

		return $gone;
	}

	public static function init() {
		// A retitled page needs a new card; the old one is dead weight.
		add_action( 'save_post', [ __CLASS__, 'forget' ] );
		add_action( 'before_delete_post', [ __CLASS__, 'forget' ] );

		if ( is_admin() ) {
			add_action( 'admin_post_fw_seo_cards_clear', [ __CLASS__, '_action_clear' ] );
		}
	}

	/**
	 * @internal
	 */
	public static function _action_clear() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'fw' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( 'fw_seo_cards_clear' );

		self::clear();

		wp_safe_redirect( add_query_arg( 'fw-seo-cards', 'cleared', FW_SEO_Settings_Page::url( 'social_tab' ) ) );
		exit;
	}
}
