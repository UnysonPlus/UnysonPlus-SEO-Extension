<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The JSON-LD graph.
 *
 * One `@graph` rather than a handful of separate <script> blocks, with every
 * node addressable by `@id` so they can reference each other: the article's
 * publisher IS the organisation node, not a second copy of it.
 *
 * Descriptions and images come from FW_SEO_Chain, not from a separate
 * calculation. That is the whole reason this lives in the extension rather than
 * the theme — structured data that disagrees with the meta description is worse
 * than none, because it is the machine-readable copy that gets believed.
 */
class FW_SEO_Schema {

	/**
	 * The profile settings that become `sameAs`.
	 *
	 * @return array<int,string>
	 */
	public static function profile_keys() {
		return [
			'profile_facebook',
			'profile_twitter',
			'profile_instagram',
			'profile_linkedin',
			'profile_youtube',
			'profile_tiktok',
			'profile_pinterest',
			'profile_github',
		];
	}

	/**
	 * The types a content type may be published as.
	 *
	 * A short list on purpose. schema.org defines hundreds, most of which no
	 * search engine treats differently, and a long dropdown invites picking
	 * something specific and wrong — `NewsArticle` on a company blog is a claim
	 * to be a news publisher.
	 *
	 * @return array<string,string>
	 */
	public static function type_choices() {
		return [
			'WebPage'     => __( 'Web page', 'fw' ),
			'Article'     => __( 'Article', 'fw' ),
			'BlogPosting' => __( 'Blog post', 'fw' ),
			'NewsArticle' => __( 'News article', 'fw' ),
			'none'        => __( 'No structured data', 'fw' ),
		];
	}

	/**
	 * @param string $post_type
	 *
	 * @return string
	 */
	public static function type_id( $post_type ) {
		return 'schema_type__' . $post_type;
	}

	/**
	 * The shipped default for a post type, reading NOTHING.
	 *
	 * Kept separate from type_for() because the settings form needs a default at
	 * file scope, and touching the settings there forces Unyson's option-type
	 * initialisation before the page-builder has registered its type — which
	 * fatals every builder page. The extension has hit that trap three times;
	 * this is the shape that avoids it.
	 *
	 * @param string $post_type
	 *
	 * @return string
	 */
	public static function default_type( $post_type ) {
		// A hierarchical type is a page; everything else behaves like a post.
		$object = get_post_type_object( $post_type );

		return ( $object && ! $object->hierarchical ) ? 'BlogPosting' : 'WebPage';
	}

	/**
	 * A post type's configured schema type.
	 *
	 * @param string $post_type
	 *
	 * @return string
	 */
	public static function type_for( $post_type ) {
		$saved = (string) FW_SEO_Settings::get( self::type_id( $post_type ), '' );

		if ( '' !== $saved && isset( self::type_choices()[ $saved ] ) ) {
			return $saved;
		}

		return self::default_type( $post_type );
	}

	/**
	 * @return string 'organization' or 'person'
	 */
	public static function represents() {
		return 'person' === FW_SEO_Settings::get( 'schema_represents', 'organization' ) ? 'person' : 'organization';
	}

	/**
	 * @return string
	 */
	public static function publisher_id() {
		return home_url( '/' ) . '#' . ( 'person' === self::represents() ? 'person' : 'organization' );
	}

	/**
	 * Build the whole graph for a context.
	 *
	 * @param FW_SEO_Context $ctx
	 *
	 * @return array
	 */
	public static function graph( FW_SEO_Context $ctx ) {
		$nodes = [
			self::publisher(),
			self::website( $ctx ),
			self::webpage( $ctx ),
			self::author( $ctx ),
		];

		/** Filters the complete JSON-LD graph before it is printed. */
		$nodes = (array) apply_filters( 'fw_seo_schema_graph', $nodes, $ctx );

		return array_values( array_filter( $nodes ) );
	}

	// -------------------------------------------------------------------------
	// Nodes
	// -------------------------------------------------------------------------

	/**
	 * The site's own identity — an Organization, or the Person whose site it is.
	 *
	 * @return array
	 */
	public static function publisher() {
		$is_person = 'person' === self::represents();

		$node = [
			'@type' => $is_person ? 'Person' : 'Organization',
			'@id'   => self::publisher_id(),
			'name'  => (string) FW_SEO_Settings::get( 'schema_name', get_bloginfo( 'name' ) ),
			'url'   => home_url( '/' ),
		];

		foreach ( [ 'alternate_name' => 'alternateName', 'description' => 'description' ] as $setting => $property ) {
			$value = trim( (string) FW_SEO_Settings::get( 'schema_' . $setting, '' ) );

			if ( '' !== $value ) {
				$node[ $property ] = $value;
			}
		}

		$logo = self::logo();

		if ( $logo ) {
			// schema.org gives no `logo` to a Person — the same picture is its
			// image, and inventing the property would simply be ignored.
			$node[ $is_person ? 'image' : 'logo' ] = $logo;

			if ( ! $is_person ) {
				$node['image'] = [ '@id' => $logo['@id'] ];
			}
		}

		$email = trim( (string) FW_SEO_Settings::get( 'schema_email', '' ) );

		if ( '' !== $email && is_email( $email ) ) {
			$node['email'] = $email;
		}

		$phone = trim( (string) FW_SEO_Settings::get( 'schema_phone', '' ) );

		if ( '' !== $phone ) {
			$node['telephone'] = $phone;
		}

		$same_as = self::same_as();

		if ( $same_as ) {
			$node['sameAs'] = $same_as;
		}

		return $node;
	}

	/**
	 * The profile URLs — what `sameAs` is for: telling a search engine which
	 * accounts are the same entity as this site.
	 *
	 * @return array<int,string>
	 */
	public static function same_as() {
		$urls = [];

		foreach ( self::profile_keys() as $key ) {
			$urls[] = trim( (string) FW_SEO_Settings::get( $key, '' ) );
		}

		/** Filters the sameAs profile URLs on the site's identity node. */
		$urls = (array) apply_filters( 'fw_seo_schema_same_as', $urls );

		$valid = [];

		// Validated AFTER the filter, not before. A filter that hands us a bare
		// handle would otherwise sail straight into the graph — and `sameAs`
		// only means anything if every entry resolves, so an unresolvable one is
		// a claim about the site that nothing can check.
		foreach ( $urls as $url ) {
			$url = trim( (string) $url );

			if ( '' === $url || 0 !== strpos( $url, 'http' ) ) {
				continue;
			}

			$valid[] = esc_url_raw( $url );
		}

		return array_values( array_unique( array_filter( $valid ) ) );
	}

	/**
	 * @return array|null
	 */
	protected static function logo() {
		$url = FW_SEO_Image::url_from_value( FW_SEO_Settings::get( 'schema_logo', '' ) );

		if ( '' === $url ) {
			$logo_id = (int) get_theme_mod( 'custom_logo' );

			if ( $logo_id ) {
				$src = wp_get_attachment_image_src( $logo_id, 'full' );
				$url = $src ? (string) $src[0] : '';
			}
		}

		return '' === $url ? null : self::image_node( $url, home_url( '/' ) . '#logo' );
	}

	/**
	 * @param string $url
	 * @param string $id
	 *
	 * @return array
	 */
	public static function image_node( $url, $id ) {
		$node = [
			'@type' => 'ImageObject',
			'@id'   => $id,
			'url'   => $url,
		];

		$size = FW_SEO_Image::dimensions( $url );

		if ( $size['width'] && $size['height'] ) {
			$node['width']  = $size['width'];
			$node['height'] = $size['height'];
		}

		return $node;
	}

	/**
	 * The WebSite node, plus the sitelinks search box.
	 *
	 * Only on the front page and the posts page. Google reads the SearchAction
	 * from the site's home, and repeating it on every URL does not make a search
	 * box more likely — it just makes every page claim to be the site.
	 *
	 * @param FW_SEO_Context $ctx
	 *
	 * @return array|null
	 */
	public static function website( FW_SEO_Context $ctx ) {
		if ( ! $ctx->is( FW_SEO_Context::FRONT_PAGE ) && ! $ctx->is( FW_SEO_Context::BLOG_PAGE ) ) {
			return null;
		}

		$home = home_url( '/' );

		return [
			'@type'           => 'WebSite',
			'@id'             => $home . '#website',
			'url'             => $home,
			'name'            => (string) FW_SEO_Settings::get( 'schema_name', get_bloginfo( 'name' ) ),
			'inLanguage'      => get_bloginfo( 'language' ),
			'publisher'       => [ '@id' => self::publisher_id() ],
			'potentialAction' => [
				'@type'       => 'SearchAction',
				'target'      => [
					'@type'       => 'EntryPoint',
					'urlTemplate' => $home . '?s={search_term_string}',
				],
				'query-input' => 'required name=search_term_string',
			],
		];
	}

	/**
	 * The node describing this page.
	 *
	 * Everything here is READ FROM THE CHAIN rather than recomputed: the
	 * headline is the resolved SEO title, the description is the resolved meta
	 * description, the image is the one the share card uses. A graph that
	 * disagrees with the tags beside it is the failure mode worth designing out.
	 *
	 * @param FW_SEO_Context $ctx
	 *
	 * @return array|null
	 */
	public static function webpage( FW_SEO_Context $ctx ) {
		$post_id = $ctx->post_id();

		if ( ! $post_id || ! $ctx->is( FW_SEO_Context::SINGULAR ) ) {
			return null;
		}

		$type = self::type_for( (string) get_post_type( $post_id ) );

		if ( 'none' === $type ) {
			return null;
		}

		$url = fw_seo_canonical_url( $ctx );

		$node = [
			'@type'            => $type,
			'@id'              => $url . '#page',
			'url'              => $url,
			'name'             => FW_SEO_Chain::resolve( 'title', $ctx ),
			'inLanguage'       => get_bloginfo( 'language' ),
			'isPartOf'         => [ '@id' => home_url( '/' ) . '#website' ],
			'datePublished'    => (string) get_post_time( 'c', true, $post_id ),
			'dateModified'     => (string) get_post_modified_time( 'c', true, $post_id ),
		];

		$description = FW_SEO_Chain::resolve( 'description', $ctx );

		if ( '' !== $description ) {
			$node['description'] = $description;
		}

		$image = FW_SEO_Chain::resolve( 'og_image', $ctx );

		if ( '' !== $image ) {
			$node['image']         = self::image_node( $image, $url . '#primaryimage' );
			$node['primaryImageOfPage'] = [ '@id' => $url . '#primaryimage' ];
		}

		// An Article carries a byline and a publisher; a plain WebPage does not.
		// Putting an author on a contact page is a small lie that a rich result
		// will happily repeat.
		if ( 'WebPage' !== $type ) {
			$node['headline']  = FW_SEO_Chain::resolve( 'title', $ctx );
			$node['publisher'] = [ '@id' => self::publisher_id() ];

			$author_id = (int) get_post_field( 'post_author', $post_id );

			if ( $author_id ) {
				$node['author'] = [ '@id' => self::author_id( $author_id ) ];
			}

			$node['mainEntityOfPage'] = [ '@id' => $url . '#page' ];
		}

		return $node;
	}

	/**
	 * @param int $user_id
	 *
	 * @return string
	 */
	public static function author_id( $user_id ) {
		return home_url( '/' ) . '#author-' . (int) $user_id;
	}

	/**
	 * The author as its own addressable Person.
	 *
	 * Given an `@id`, the same author on twenty posts is one entity rather than
	 * twenty unrelated strings — which is the difference between a name and an
	 * author a search engine can accumulate a reputation for.
	 *
	 * @param FW_SEO_Context $ctx
	 *
	 * @return array|null
	 */
	public static function author( FW_SEO_Context $ctx ) {
		$post_id = $ctx->post_id();

		if ( ! $post_id || ! $ctx->is( FW_SEO_Context::SINGULAR ) ) {
			return null;
		}

		$type = self::type_for( (string) get_post_type( $post_id ) );

		if ( 'none' === $type || 'WebPage' === $type ) {
			return null;
		}

		$user_id = (int) get_post_field( 'post_author', $post_id );

		if ( ! $user_id ) {
			return null;
		}

		$node = [
			'@type' => 'Person',
			'@id'   => self::author_id( $user_id ),
			'name'  => (string) get_the_author_meta( 'display_name', $user_id ),
			'url'   => (string) get_author_posts_url( $user_id ),
		];

		$bio = trim( (string) get_the_author_meta( 'description', $user_id ) );

		if ( '' !== $bio ) {
			$node['description'] = $bio;
		}

		return $node;
	}

	/**
	 * The graph as a ready-to-print <script> block.
	 *
	 * @param FW_SEO_Context $ctx
	 *
	 * @return string
	 */
	public static function markup( FW_SEO_Context $ctx ) {
		$nodes = self::graph( $ctx );

		if ( ! $nodes ) {
			return '';
		}

		$json = wp_json_encode(
			[ '@context' => 'https://schema.org', '@graph' => $nodes ],
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		if ( ! $json ) {
			return '';
		}

		return '<script type="application/ld+json">' . $json . '</script>';
	}
}
