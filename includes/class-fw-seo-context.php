<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The resolved SEO context for one request.
 *
 * Everything downstream — tag resolvers, the value chain, the head providers —
 * reads the request through this object instead of poking at globals or at a
 * loose array whose keys change depending on which branch built it. Resolve it
 * once (on `wp`), pass it everywhere.
 *
 * It is also constructible for an arbitrary post (`for_post()`), which is what
 * lets the editor preview resolve the very same templates the front end will.
 */
class FW_SEO_Context {

	/** Page kinds. A context is always exactly one of these. */
	const FRONT_PAGE        = 'front_page';
	const BLOG_PAGE         = 'blog_page';
	const SINGULAR          = 'singular';
	const TERM              = 'term';
	const AUTHOR            = 'author';
	const DATE              = 'date';
	const POST_TYPE_ARCHIVE = 'post_type_archive';
	const SEARCH            = 'search';
	const NOT_FOUND         = '404';

	/** @var string One of the constants above. */
	protected $type = self::NOT_FOUND;

	/** @var WP_Post|null */
	protected $post;

	/** @var WP_Term|null */
	protected $term;

	/** @var WP_User|null */
	protected $user;

	/** @var string */
	protected $post_type = '';

	/** @var string */
	protected $taxonomy = '';

	/** @var int Current page of a paginated archive or multi-page post. */
	protected $page = 1;

	/** @var int Total pages, 0 when unknown. */
	protected $max_page = 0;

	/** @var array{year:int,month:int,day:int} */
	protected $date = [ 'year' => 0, 'month' => 0, 'day' => 0 ];

	/** @var string */
	protected $search_query = '';

	/** @var bool True when built outside the main query (editor preview). */
	protected $simulated = false;

	protected function __construct() {}

	/**
	 * Build the context from the current main query. Call on `wp` or later.
	 *
	 * @return self
	 */
	public static function from_query() {
		$ctx = new self();

		if ( is_404() ) {
			$ctx->type = self::NOT_FOUND;
		} elseif ( is_search() ) {
			$ctx->type         = self::SEARCH;
			$ctx->search_query = (string) get_search_query();
		} elseif ( is_front_page() ) {
			$ctx->type = self::FRONT_PAGE;

			// A static front page is also a post; a posts-index front page is not.
			if ( ! is_home() ) {
				$ctx->post      = get_post( (int) get_option( 'page_on_front' ) );
				$ctx->post_type = $ctx->post ? $ctx->post->post_type : '';
			}
		} elseif ( is_home() ) {
			$ctx->type      = self::BLOG_PAGE;
			$ctx->post      = get_post( (int) get_option( 'page_for_posts' ) );
			$ctx->post_type = $ctx->post ? $ctx->post->post_type : '';
		} elseif ( is_singular() ) {
			$queried = get_queried_object();

			$ctx->type      = self::SINGULAR;
			$ctx->post      = $queried instanceof WP_Post ? $queried : null;
			$ctx->post_type = $ctx->post ? $ctx->post->post_type : '';
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$queried = get_queried_object();

			$ctx->type     = self::TERM;
			$ctx->term     = $queried instanceof WP_Term ? $queried : null;
			$ctx->taxonomy = $ctx->term ? $ctx->term->taxonomy : '';
		} elseif ( is_author() ) {
			$queried = get_queried_object();

			$ctx->type = self::AUTHOR;
			$ctx->user = $queried instanceof WP_User ? $queried : null;
		} elseif ( is_post_type_archive() ) {
			$ctx->type      = self::POST_TYPE_ARCHIVE;
			$ctx->post_type = (string) get_query_var( 'post_type' );

			// `post_type` is an array when several types share one archive.
			if ( is_array( get_query_var( 'post_type' ) ) ) {
				$types          = array_values( (array) get_query_var( 'post_type' ) );
				$ctx->post_type = $types[0] ?? '';
			}
		} elseif ( is_date() ) {
			$ctx->type = self::DATE;
			$ctx->date = [
				'year'  => (int) get_query_var( 'year' ),
				'month' => (int) get_query_var( 'monthnum' ),
				'day'   => (int) get_query_var( 'day' ),
			];
		} else {
			$ctx->type = self::NOT_FOUND;
		}

		$ctx->resolve_paging();

		/** Filters the SEO context resolved from the main query, before it is cached for the request. */
		return apply_filters( 'fw_seo_context', $ctx );
	}

	/**
	 * Build a context for one post, outside the main query.
	 *
	 * Used by the editor preview so the admin resolves templates exactly as the
	 * front end will. The post kind is honoured: the page set as the static
	 * front page previews as a front page, the posts page as the blog page.
	 *
	 * @param int|WP_Post $post
	 *
	 * @return self|null Null when the post cannot be loaded.
	 */
	public static function for_post( $post ) {
		$post = get_post( $post );

		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		$ctx            = new self();
		$ctx->post      = $post;
		$ctx->post_type = $post->post_type;
		$ctx->simulated = true;

		$front = (int) get_option( 'page_on_front' );
		$blog  = (int) get_option( 'page_for_posts' );

		if ( 'page' === get_option( 'show_on_front' ) && $front && $front === (int) $post->ID ) {
			$ctx->type = self::FRONT_PAGE;
		} elseif ( $blog && $blog === (int) $post->ID ) {
			$ctx->type = self::BLOG_PAGE;
		} else {
			$ctx->type = self::SINGULAR;
		}

		/** Filters a simulated SEO context built for a single post (editor preview). */
		return apply_filters( 'fw_seo_context', $ctx );
	}

	/**
	 * Build a context for one term, outside the main query.
	 *
	 * The term-edit screen's counterpart to for_post(), so the same prefill and
	 * preview code serves both surfaces.
	 *
	 * @param int|WP_Term $term
	 * @param string      $taxonomy Only needed when passing an id.
	 *
	 * @return self|null
	 */
	public static function for_term( $term, $taxonomy = '' ) {
		if ( ! $term instanceof WP_Term ) {
			$term = get_term( (int) $term, $taxonomy );
		}

		if ( ! $term instanceof WP_Term ) {
			return null;
		}

		$ctx            = new self();
		$ctx->type      = self::TERM;
		$ctx->term      = $term;
		$ctx->taxonomy  = $term->taxonomy;
		$ctx->simulated = true;

		/** Filters a simulated SEO context built for a single term (term editor preview). */
		return apply_filters( 'fw_seo_context', $ctx );
	}

	/**
	 * Current page number and total, for %%page%% / %%pagenumber%% / %%pagetotal%%.
	 */
	protected function resolve_paging() {
		global $wp_query, $page, $numpages;

		$paged = (int) max( get_query_var( 'paged' ), get_query_var( 'page' ) );

		if ( self::SINGULAR === $this->type && $page ) {
			// A <!--nextpage--> split post pages through $page/$numpages, not `paged`.
			$this->page     = (int) $page;
			$this->max_page = (int) $numpages;

			return;
		}

		$this->page     = $paged > 0 ? $paged : 1;
		$this->max_page = isset( $wp_query->max_num_pages ) ? (int) $wp_query->max_num_pages : 0;
	}

	// -------------------------------------------------------------------------
	// Accessors
	// -------------------------------------------------------------------------

	/** @return string */
	public function type() {
		return $this->type;
	}

	/** @return bool */
	public function is( $type ) {
		return $this->type === $type;
	}

	/**
	 * True for anything that resolves to a single post — including the static
	 * front page and the posts page, which are posts wearing a different hat.
	 *
	 * @return bool
	 */
	public function has_post() {
		return $this->post instanceof WP_Post;
	}

	/** @return WP_Post|null */
	public function post() {
		return $this->post;
	}

	/** @return int */
	public function post_id() {
		return $this->post instanceof WP_Post ? (int) $this->post->ID : 0;
	}

	/** @return WP_Term|null */
	public function term() {
		return $this->term;
	}

	/** @return int */
	public function term_id() {
		return $this->term instanceof WP_Term ? (int) $this->term->term_id : 0;
	}

	/**
	 * The author of the current post, or the archive's author.
	 *
	 * @return WP_User|null
	 */
	public function user() {
		if ( $this->user instanceof WP_User ) {
			return $this->user;
		}

		if ( $this->post instanceof WP_Post ) {
			$user = get_userdata( (int) $this->post->post_author );

			return $user instanceof WP_User ? $user : null;
		}

		return null;
	}

	/** @return string */
	public function post_type() {
		return $this->post_type;
	}

	/** @return string */
	public function taxonomy() {
		return $this->taxonomy;
	}

	/** @return int */
	public function page() {
		return $this->page;
	}

	/** @return int */
	public function max_page() {
		return $this->max_page;
	}

	/** @return bool True on page 2+ of anything. */
	public function is_paged() {
		return $this->page > 1;
	}

	/** @return array{year:int,month:int,day:int} */
	public function date() {
		return $this->date;
	}

	/** @return string */
	public function search_query() {
		return $this->search_query;
	}

	/** @return bool */
	public function is_simulated() {
		return $this->simulated;
	}

	/**
	 * The settings key identifying which template set applies here.
	 *
	 * Post types and taxonomies get their own slot so a site can template
	 * products differently from posts; the singleton contexts share one each.
	 *
	 * @return string e.g. 'front_page', 'post:page', 'tax:category', 'author'
	 */
	public function template_key() {
		switch ( $this->type ) {
			case self::SINGULAR:
				return 'post:' . $this->post_type;

			case self::POST_TYPE_ARCHIVE:
				return 'archive:' . $this->post_type;

			case self::TERM:
				return 'tax:' . $this->taxonomy;

			default:
				return $this->type;
		}
	}

	/**
	 * The canonical URL of this context, before any override.
	 *
	 * @return string Empty when the context has no stable URL (search, 404).
	 */
	public function url() {
		$url = '';

		switch ( $this->type ) {
			case self::FRONT_PAGE:
				$url = home_url( '/' );
				break;

			case self::BLOG_PAGE:
			case self::SINGULAR:
				$url = $this->post ? (string) get_permalink( $this->post ) : '';
				break;

			case self::TERM:
				$link = $this->term ? get_term_link( $this->term ) : '';
				$url  = is_wp_error( $link ) ? '' : (string) $link;
				break;

			case self::AUTHOR:
				$user = $this->user();
				$url  = $user ? (string) get_author_posts_url( $user->ID ) : '';
				break;

			case self::POST_TYPE_ARCHIVE:
				$link = $this->post_type ? get_post_type_archive_link( $this->post_type ) : '';
				$url  = $link ? (string) $link : '';
				break;

			case self::DATE:
				if ( $this->date['day'] ) {
					$url = (string) get_day_link( $this->date['year'], $this->date['month'], $this->date['day'] );
				} elseif ( $this->date['month'] ) {
					$url = (string) get_month_link( $this->date['year'], $this->date['month'] );
				} elseif ( $this->date['year'] ) {
					$url = (string) get_year_link( $this->date['year'] );
				}
				break;
		}

		return $url;
	}
}
