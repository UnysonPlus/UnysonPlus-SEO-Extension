<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The SEO extension's use of the site's AI connection: suggestions written from
 * the page being edited, a draft outline for a topic worth writing about, and a
 * sample of how AI assistants answer the questions that matter to the site.
 *
 * It runs through WordPress's own AI client, on the connection the site owner
 * set up (their key, their usage); without one, none of it appears. Nothing is
 * written into a page: suggestions come with a Copy button, and the person
 * decides.
 *
 * "Visibility in AI search" is presented as what it is: a sample of what an
 * assistant said on a given day. Answers vary between runs, users and model
 * versions, so it is never shown as a position, and it only runs when someone
 * presses the button, because every question is a paid call on their account.
 *
 * @since 2.0.51
 */
class FW_SEO_AI {

	const NONCE   = 'fw_seo_ai';
	const SAMPLES = 'fw_seo_ai_samples';
	const KINDS   = [ 'title', 'description', 'faq', 'points' ];

	/** Swapped for visibility_html() when the settings page is drawn. */
	const PLACEHOLDER = '<!--fw-seo-ai-visibility-->';

	public static function init() {
		add_action( 'wp_ajax_fw_seo_ai_suggest', [ __CLASS__, '_ajax_suggest' ] );
		add_action( 'wp_ajax_fw_seo_ai_outline', [ __CLASS__, '_ajax_outline' ] );
		add_action( 'admin_post_fw_seo_ai_visibility', [ __CLASS__, '_action_visibility' ] );
	}

	/** @return bool Whether a text-generating AI connection is ready. */
	public static function ready() {
		return class_exists( 'FW_AI_Panel' ) && method_exists( 'FW_AI_Panel', 'wp_backend_ready' ) && FW_AI_Panel::wp_backend_ready();
	}

	/**
	 * Ask the model.
	 *
	 * @return string|WP_Error
	 */
	public static function ask( $system, $prompt, $max_tokens = 700 ) {
		if ( ! self::ready() ) {
			return new WP_Error( 'not_ready', __( 'No AI connection is set up. Connect one under the AI Assistant\'s settings.', 'fw' ) );
		}

		$text = wp_ai_client_prompt( (string) $prompt )
			->using_system_instruction( (string) $system )
			->using_max_tokens( (int) $max_tokens )
			->generate_text();

		return is_wp_error( $text ) ? $text : trim( (string) $text );
	}

	/** The voice every request asks for. */
	public static function voice() {
		$tone     = trim( (string) FW_SEO_Settings::get( 'ai_tone', '' ) );
		$audience = trim( (string) FW_SEO_Settings::get( 'ai_audience', '' ) );

		return sprintf(
			'You write search-result copy for the website "%s". %s%sWrite in the same language as the page. Never invent facts, prices or claims the page does not make. Reply with the requested text only: no preamble, no quotation marks, no markdown headings.',
			wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'' !== $tone ? 'Tone: ' . $tone . '. ' : '',
			'' !== $audience ? 'Audience: ' . $audience . '. ' : ''
		);
	}

	/**
	 * What to ask for each kind of suggestion.
	 *
	 * @param string $kind
	 * @param string $title
	 * @param string $text
	 * @param string $phrase Focus keyphrase, or ''.
	 *
	 * @return string
	 */
	public static function prompt( $kind, $title, $text, $phrase = '' ) {
		$ask = [
			'title'       => 'Suggest 3 SEO titles for this page, each under 60 characters, each on its own line. They should say plainly what the reader gets.',
			'description' => 'Suggest 3 meta descriptions for this page, each 120 to 155 characters, each on its own line. Each should tell a searcher what they will find and why to click.',
			'faq'         => 'Write 4 questions a reader of this page would really ask, each followed by a short answer taken only from the page. Format: "Q: …" on one line, "A: …" on the next.',
			'points'      => 'List the 5 key points of this page, one per line, each under 15 words.',
		][ $kind ] ?? '';

		return $ask . ( '' !== $phrase ? "\nThe page should rank for: " . $phrase . ' (use it naturally).' : '' )
			. "\n\nPage title: " . $title . "\n\nPage text:\n" . mb_substr( preg_replace( '#\s+#u', ' ', wp_strip_all_tags( (string) $text ) ), 0, 6000 );
	}

	/** @internal */
	public static function _ajax_suggest() {
		check_ajax_referer( self::NONCE, 'nonce' );
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$kind    = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) || ! in_array( $kind, self::KINDS, true ) || ( class_exists( 'FW_SEO_Additions' ) && ! FW_SEO_Additions::can_edit() ) ) {
			wp_send_json_error( [ 'message' => __( 'Not allowed.', 'fw' ) ], 403 );
		}

		$m    = FW_SEO_Analysis::material( $post_id );
		$text = self::ask( self::voice(), self::prompt( $kind, get_the_title( $post_id ), implode( "\n", FW_SEO_Analysis::paragraphs( $m['html'] ) ), (string) get_post_meta( $post_id, FW_SEO_Analysis::META, true ) ) );

		is_wp_error( $text ) ? wp_send_json_error( [ 'message' => $text->get_error_message() ] ) : wp_send_json_success( [ 'text' => $text ] );
	}

	/** @internal */
	public static function _ajax_outline() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Not allowed.', 'fw' ) ], 403 );
		}

		$topic = sanitize_text_field( wp_unslash( $_POST['topic'] ?? '' ) );
		$page  = isset( $_POST['page'] ) ? (int) $_POST['page'] : 0;
		$about = $page ? "\nThe site already has this page about it, to be refreshed rather than replaced:\nTitle: " . get_the_title( $page ) . "\n" . mb_substr( wp_strip_all_tags( (string) get_post_field( 'post_content', $page ) ), 0, 3000 ) : '';

		$text = self::ask( self::voice(), 'Interest in "' . $topic . '" is rising. Propose a page title (under 60 characters) and an outline of 5 to 7 section headings with one line each on what the section should say, for a page that answers what people searching for it want to know.' . $about, 800 );

		is_wp_error( $text ) ? wp_send_json_error( [ 'message' => $text->get_error_message() ] ) : wp_send_json_success( [ 'text' => $text ] );
	}

	/* ------------------------------------------------------------------ *
	 * The editor
	 * ------------------------------------------------------------------ */

	/** @return string The suggestion buttons in the SEO box. */
	public static function editor_html( $post_id ) {
		if ( ! self::ready() ) {
			return '';
		}

		$labels = [ 'title' => __( 'Titles', 'fw' ), 'description' => __( 'Descriptions', 'fw' ), 'faq' => __( 'FAQ questions', 'fw' ), 'points' => __( 'Key points', 'fw' ) ];
		$html   = '<div class="fw-seo-ai-suggest" data-post="' . (int) $post_id . '" data-nonce="' . esc_attr( wp_create_nonce( self::NONCE ) ) . '" style="margin:0 0 14px"><strong>' . esc_html__( 'Suggest with AI:', 'fw' ) . '</strong> ';
		foreach ( $labels as $kind => $label ) {
			$html .= '<button type="button" class="button button-small" data-kind="' . esc_attr( $kind ) . '">' . esc_html( $label ) . '</button> ';
		}
		$html .= '<span class="description">' . esc_html__( 'Written from this page as last saved, in the tone set under SEO → General. Nothing is changed until you paste it in.', 'fw' ) . '</span><div class="fw-seo-ai-result" style="margin-top:8px"></div></div>';

		return $html . self::suggest_script();
	}

	protected static function suggest_script() {
		static $done = false;
		if ( $done ) {
			return '';
		}
		$done = true;

		return '<script>(function(){document.addEventListener("click",function(e){var b=e.target.closest(".fw-seo-ai-suggest [data-kind]");if(!b){return;}var box=b.closest(".fw-seo-ai-suggest"),out=box.querySelector(".fw-seo-ai-result");out.textContent=' . wp_json_encode( __( 'Writing…', 'fw' ) ) . ';var f=new FormData();f.append("action","fw_seo_ai_suggest");f.append("nonce",box.dataset.nonce);f.append("post_id",box.dataset.post);f.append("kind",b.dataset.kind);fetch(ajaxurl,{method:"POST",body:f,credentials:"same-origin"}).then(function(r){return r.json();}).then(function(j){out.innerHTML="";if(!j.success){out.textContent=(j.data&&j.data.message)||"Error";return;}var pre=document.createElement("pre");pre.style.cssText="white-space:pre-wrap;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:10px 12px;margin:0 0 6px;font:inherit";pre.textContent=j.data.text;var c=document.createElement("button");c.type="button";c.className="button button-small";c.textContent=' . wp_json_encode( __( 'Copy', 'fw' ) ) . ';c.onclick=function(){navigator.clipboard.writeText(j.data.text);c.textContent=' . wp_json_encode( __( 'Copied', 'fw' ) ) . ';};out.appendChild(pre);out.appendChild(c);});});})();</script>';
	}

	/** @return string The outline button behaviour on the momentum table. */
	public static function outline_script() {
		if ( ! self::ready() ) {
			return '';
		}

		return '<script>(function(){var n=' . wp_json_encode( wp_create_nonce( self::NONCE ) ) . ';document.addEventListener("click",function(e){var b=e.target.closest(".fw-seo-ai-outline");if(!b){return;}var out=b.nextElementSibling;out.textContent=' . wp_json_encode( __( 'Writing…', 'fw' ) ) . ';var f=new FormData();f.append("action","fw_seo_ai_outline");f.append("nonce",n);f.append("topic",b.dataset.topic);f.append("page",b.dataset.page);fetch(ajaxurl,{method:"POST",body:f,credentials:"same-origin"}).then(function(r){return r.json();}).then(function(j){out.innerHTML="";var pre=document.createElement("pre");pre.style.cssText="white-space:pre-wrap;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:10px 12px;margin:6px 0 0;font:inherit";pre.textContent=j.success?j.data.text:((j.data&&j.data.message)||"Error");out.appendChild(pre);});});})();</script>';
	}

	/* ------------------------------------------------------------------ *
	 * Visibility in AI search
	 * ------------------------------------------------------------------ */

	/**
	 * Does an answer mention the site: its domain, or its name as whole words?
	 *
	 * @return bool
	 */
	public static function mentions( $answer, $domain, $name ) {
		$answer = (string) $answer;
		$domain = preg_replace( '#^www\.#', '', strtolower( (string) $domain ) );

		if ( '' !== $domain && false !== stripos( $answer, $domain ) ) {
			return true;
		}

		return '' !== trim( (string) $name ) && FW_SEO_Analysis::has_phrase( $answer, $name );
	}

	/** @return string[] The questions to ask, from the setting. */
	public static function questions() {
		return array_slice( array_values( array_filter( array_map( 'trim', preg_split( '#[\r\n]+#', (string) FW_SEO_Settings::get( 'ai_visibility_questions', '' ) ) ) ) ), 0, 20 );
	}

	/** @internal Ask every question now and store the dated sample. */
	public static function _action_visibility() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'fw' ), 403 );
		}
		check_admin_referer( self::NONCE );

		$domain  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$name    = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$samples = (array) get_option( self::SAMPLES, [] );
		$run     = time();

		foreach ( self::questions() as $q ) {
			$a = self::ask( 'Answer the question as a helpful assistant would for a member of the public. Where it helps, name specific businesses, websites or sources.', $q, 600 );
			$samples[] = [
				'run'       => $run,
				'question'  => $q,
				'mentioned' => ! is_wp_error( $a ) && self::mentions( $a, $domain, $name ),
				'answer'    => is_wp_error( $a ) ? '' : mb_substr( (string) $a, 0, 1200 ),
				'error'     => is_wp_error( $a ) ? $a->get_error_message() : '',
			];
		}

		update_option( self::SAMPLES, array_slice( $samples, -400 ), false );
		wp_safe_redirect( admin_url( 'admin.php?page=fw-seo-settings&view=ai_tab' ) );
		exit;
	}

	/** @return string The samples, newest run first. */
	public static function visibility_html() {
		if ( ! self::ready() ) {
			return '<p>' . esc_html__( 'Needs an AI connection, set up under the AI Assistant\'s settings. Every question is a call on your own account.', 'fw' ) . '</p>';
		}

		$runs = [];
		foreach ( (array) get_option( self::SAMPLES, [] ) as $s ) {
			$runs[ (int) $s['run'] ][] = $s;
		}
		krsort( $runs );

		$ask  = wp_nonce_url( admin_url( 'admin-post.php?action=fw_seo_ai_visibility' ), self::NONCE );
		$html = '<p>' . ( self::questions()
			? '<a class="button button-primary" href="' . esc_url( $ask ) . '">' . esc_html( sprintf(
				/* translators: %d: number of questions */
				_n( 'Ask the %d question now', 'Ask the %d questions now', count( self::questions() ), 'fw' ),
				count( self::questions() )
			) ) . '</a>'
			: esc_html__( 'Add the questions above and save first.', 'fw' ) ) . '</p>';

		foreach ( array_slice( $runs, 0, 5, true ) as $time => $samples ) {
			$hit   = count( array_filter( wp_list_pluck( $samples, 'mentioned' ) ) );
			$html .= '<h4 style="margin:16px 0 6px">' . esc_html( sprintf(
				/* translators: 1: date, 2: mentioned count, 3: questions */
				__( '%1$s: your site was mentioned in %2$d of %3$d answers', 'fw' ),
				date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ),
				$hit,
				count( $samples )
			) ) . '</h4><table class="widefat striped" style="max-width:1100px"><tbody>';
			foreach ( $samples as $s ) {
				$html .= '<tr><td style="width:2em"><span class="dashicons ' . ( $s['mentioned'] ? 'dashicons-yes-alt" style="color:#00a32a' : 'dashicons-minus" style="color:#8c8f94' ) . '"></span></td><td><strong>' . esc_html( $s['question'] ) . '</strong>'
					. ( '' !== $s['error'] ? '<br><span class="description">' . esc_html( $s['error'] ) . '</span>' : '<details><summary class="description" style="cursor:pointer">' . esc_html__( 'The answer', 'fw' ) . '</summary><div style="white-space:pre-wrap">' . esc_html( $s['answer'] ) . '</div></details>' ) . '</td></tr>';
			}
			$html .= '</tbody></table>';
		}

		return $html;
	}
}
