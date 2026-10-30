<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Structured data beyond the page itself: author profiles, FAQs, the local
 * business, and per-page products, events, recipes and branch locations.
 *
 * Everything joins the one graph (FW_SEO_Schema, through fw_seo_schema_graph),
 * linked by @id, rather than printing blocks of its own next to it:
 *
 * - Authors: job title, areas of expertise and profile links from the user's
 *   profile, on the author's Person node (knowsAbout, sameAs, jobTitle).
 * - FAQ: the questions of every accordion with "FAQ Rich Snippet" switched on,
 *   read from the page builder, become the page's FAQPage. The accordion then
 *   leaves out its own separate block (fw_sc_accordion_faq_inline), so the
 *   questions are never published twice, and never dropped either: when this
 *   page has no page node, the accordion prints its own as before.
 * - Local business: the site's Organization becomes the chosen LocalBusiness
 *   type, with address, map position, price range and opening hours.
 * - Per page: Product, Event, Recipe or a branch Location, from the page's own
 *   fields, as the page's mainEntity. What a rich result requires and is
 *   missing is said in the editor rather than emitted half-filled.
 *
 * Nothing reads settings during startup (the option-types trap).
 *
 * @since 2.0.48
 */
class FW_SEO_Entities {

	const META        = '_fw_seo_entity';
	const NONCE       = 'fw_seo_entity';
	const AUTHOR_META = [ 'fw_seo_author_job', 'fw_seo_author_knows', 'fw_seo_author_links' ];
	const DAYS        = [ 'mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday' ];
	const BUSINESS_TYPES = [ 'LocalBusiness', 'Restaurant', 'CafeOrCoffeeShop', 'BarOrPub', 'Bakery', 'Store', 'ClothingStore', 'HomeGoodsStore', 'Florist', 'HairSalon', 'BeautySalon', 'DaySpa', 'HealthClub', 'Dentist', 'MedicalClinic', 'Physician', 'Pharmacy', 'VeterinaryCare', 'LegalService', 'Attorney', 'AccountingService', 'FinancialService', 'RealEstateAgent', 'InsuranceAgency', 'AutoRepair', 'AutoDealer', 'Plumber', 'Electrician', 'HVACBusiness', 'RoofingContractor', 'GeneralContractor', 'HousePainter', 'Locksmith', 'MovingCompany', 'Hotel', 'BedAndBreakfast', 'LodgingBusiness', 'TravelAgency', 'ChildCare', 'School', 'EntertainmentBusiness', 'SportsActivityLocation' ];

	/** @var bool Whether this request's graph carried the page's FAQ. */
	protected static $faq_in_graph = false;

	public static function init() {
		add_filter( 'fw_seo_schema_graph', [ __CLASS__, '_filter_graph' ], 20, 2 );
		add_filter( 'fw_sc_accordion_faq_inline', [ __CLASS__, '_filter_accordion_inline' ] );
		add_filter( 'fw_business_info_site_details', [ __CLASS__, '_filter_business_info' ] );

		if ( is_admin() ) {
			add_action( 'show_user_profile', [ __CLASS__, '_profile_fields' ] );
			add_action( 'edit_user_profile', [ __CLASS__, '_profile_fields' ] );
			add_action( 'personal_options_update', [ __CLASS__, '_save_profile' ] );
			add_action( 'edit_user_profile_update', [ __CLASS__, '_save_profile' ] );
			add_action( 'add_meta_boxes', [ __CLASS__, '_add_box' ] );
			add_action( 'save_post', [ __CLASS__, '_save_box' ], 10, 2 );
		}
	}

	/* ================================================================== *
	 * Pure helpers
	 * ================================================================== */

	/** @return string[] Non-empty trimmed lines (or comma-separated items). */
	public static function lines( $text, $commas = false ) {
		$parts = preg_split( $commas ? '#[\r\n,]+#' : '#[\r\n]+#', (string) $text );

		return array_values( array_filter( array_map( 'trim', $parts ), 'strlen' ) );
	}

	/**
	 * One day's hours, as written ("09:00-17:00", "9:00–12:30, 13:30–18:00",
	 * "closed", "") → [ [open, close], … ], [] for closed or unset.
	 *
	 * @param string $text
	 *
	 * @return array
	 */
	public static function parse_hours( $text ) {
		$text = strtolower( trim( (string) $text ) );
		$out  = [];

		if ( '' === $text || in_array( $text, [ 'closed', 'close', '-', '—' ], true ) ) {
			return $out;
		}

		if ( preg_match( '#^(24 ?h(ours)?|open 24 hours|24/7)$#', $text ) ) {
			return [ [ '00:00', '23:59' ] ];
		}

		foreach ( preg_split( '#\s*[,;/]\s*#', $text ) as $range ) {
			if ( preg_match( '#^(\d{1,2})(?::?(\d{2}))?\s*(am|pm)?\s*[-–—to]+\s*(\d{1,2})(?::?(\d{2}))?\s*(am|pm)?$#', trim( $range ), $m ) ) {
				$out[] = [ self::clock( $m[1], $m[2], $m[3] ), self::clock( $m[4], $m[5], $m[6] ?? '' ) ];
			}
		}

		return $out;
	}

	/** @return string HH:MM */
	protected static function clock( $h, $min, $ampm ) {
		$h = (int) $h;
		if ( 'pm' === $ampm && $h < 12 ) {
			$h += 12;
		} elseif ( 'am' === $ampm && 12 === $h ) {
			$h = 0;
		}

		return sprintf( '%02d:%02d', min( 23, $h ), min( 59, (int) $min ) );
	}

	/**
	 * openingHoursSpecification from day => text, days with equal hours merged.
	 *
	 * @param array $week day key (mon…sun) => text.
	 *
	 * @return array
	 */
	public static function opening_hours( array $week ) {
		$groups = [];

		foreach ( self::DAYS as $key => $name ) {
			foreach ( self::parse_hours( $week[ $key ] ?? '' ) as $range ) {
				$id = implode( '-', $range );
				$groups[ $id ]['range']  = $range;
				$groups[ $id ]['days'][] = $name;
			}
		}

		$out = [];
		foreach ( $groups as $g ) {
			$out[] = [
				'@type'     => 'OpeningHoursSpecification',
				'dayOfWeek' => $g['days'],
				'opens'     => $g['range'][0],
				'closes'    => $g['range'][1],
			];
		}

		return $out;
	}

	/** @return array|null A PostalAddress, or null when there is no street or town. */
	public static function address( array $a ) {
		$map = [ 'streetAddress' => 'street', 'addressLocality' => 'city', 'addressRegion' => 'region', 'postalCode' => 'postcode', 'addressCountry' => 'country' ];
		$out = [ '@type' => 'PostalAddress' ];

		foreach ( $map as $prop => $key ) {
			$v = trim( (string) ( $a[ $key ] ?? '' ) );
			if ( '' !== $v ) {
				$out[ $prop ] = $v;
			}
		}

		return isset( $out['streetAddress'] ) || isset( $out['addressLocality'] ) ? $out : null;
	}

	/** @return array|null GeoCoordinates from "lat, lng". */
	public static function geo( $text ) {
		if ( ! preg_match( '#^\s*(-?\d{1,2}(?:\.\d+)?)\s*[, ]\s*(-?\d{1,3}(?:\.\d+)?)\s*$#', (string) $text, $m ) ) {
			return null;
		}

		return [ '@type' => 'GeoCoordinates', 'latitude' => (float) $m[1], 'longitude' => (float) $m[2] ];
	}

	/** @return string ISO 8601 duration from minutes ("PT1H30M"), '' for none. */
	public static function duration( $minutes ) {
		$minutes = (int) $minutes;
		if ( $minutes <= 0 ) {
			return '';
		}

		return 'PT' . ( $minutes >= 60 ? intdiv( $minutes, 60 ) . 'H' : '' ) . ( $minutes % 60 ? ( $minutes % 60 ) . 'M' : '' );
	}

	/* ================================================================== *
	 * Per-page entities
	 * ================================================================== */

	/** @return array<string, string> type => label */
	public static function types() {
		return [
			''         => __( 'None', 'fw' ),
			'product'  => __( 'Product', 'fw' ),
			'event'    => __( 'Event', 'fw' ),
			'recipe'   => __( 'Recipe', 'fw' ),
			'location' => __( 'Business location (a branch)', 'fw' ),
		];
	}

	/** @return array The page's entity fields, type first. */
	public static function get( $post_id ) {
		$e = get_post_meta( (int) $post_id, self::META, true );

		return is_array( $e ) ? $e : [];
	}

	/**
	 * What a rich result for this entity needs and does not have yet.
	 *
	 * @param array $e
	 *
	 * @return string[] Human-readable missing fields.
	 */
	public static function missing( array $e ) {
		$need = [];
		$has  = static function ( $k ) use ( $e ) {
			return '' !== trim( (string) ( $e[ $k ] ?? '' ) );
		};

		switch ( $e['type'] ?? '' ) {
			case 'product':
				if ( ! $has( 'price' ) && ! $has( 'rating' ) ) {
					$need[] = __( 'a price (or a rating)', 'fw' );
				}
				break;
			case 'event':
				if ( ! $has( 'start' ) ) {
					$need[] = __( 'a start date', 'fw' );
				}
				if ( ! $has( 'venue' ) && ! $has( 'city' ) && ! $has( 'online_url' ) ) {
					$need[] = __( 'a venue or an online address', 'fw' );
				}
				break;
			case 'recipe':
				if ( ! $has( 'ingredients' ) ) {
					$need[] = __( 'the ingredients', 'fw' );
				}
				if ( ! $has( 'steps' ) ) {
					$need[] = __( 'the steps', 'fw' );
				}
				break;
			case 'location':
				if ( ! $has( 'street' ) && ! $has( 'city' ) ) {
					$need[] = __( 'an address', 'fw' );
				}
				break;
		}

		return $need;
	}

	/**
	 * The schema node for a page's entity.
	 *
	 * @param array  $e     Fields, with 'type'.
	 * @param array  $page  name, url, description, image (url), publisher_id.
	 *
	 * @return array|null
	 */
	public static function node( array $e, array $page ) {
		$type = $e['type'] ?? '';
		$url  = (string) $page['url'];
		$name = trim( (string) ( $e['name'] ?? '' ) ) ?: (string) $page['name'];
		$v    = static function ( $k ) use ( $e ) {
			return trim( (string) ( $e[ $k ] ?? '' ) );
		};

		$node = [ '@id' => $url . '#entity', 'name' => $name, 'url' => $url ];
		if ( '' !== (string) ( $page['description'] ?? '' ) ) {
			$node['description'] = (string) $page['description'];
		}
		if ( '' !== (string) ( $page['image'] ?? '' ) ) {
			$node['image'] = (string) $page['image'];
		}

		$offer = static function ( $price, $currency, $availability = '' ) use ( $url ) {
			if ( '' === $price ) {
				return null;
			}
			$o = [ '@type' => 'Offer', 'price' => preg_replace( '#[^\d.]#', '', $price ), 'priceCurrency' => strtoupper( $currency ?: 'USD' ), 'url' => $url ];
			if ( '' !== $availability ) {
				$o['availability'] = 'https://schema.org/' . $availability;
			}

			return $o;
		};

		switch ( $type ) {
			case 'product':
				$node = [ '@type' => 'Product' ] + $node;
				if ( '' !== $v( 'brand' ) ) {
					$node['brand'] = [ '@type' => 'Brand', 'name' => $v( 'brand' ) ];
				}
				if ( '' !== $v( 'sku' ) ) {
					$node['sku'] = $v( 'sku' );
				}
				$o = $offer( $v( 'price' ), $v( 'currency' ), $v( 'availability' ) ?: 'InStock' );
				if ( $o ) {
					$node['offers'] = $o;
				}
				if ( '' !== $v( 'rating' ) && (int) $v( 'rating_count' ) > 0 ) {
					$node['aggregateRating'] = [ '@type' => 'AggregateRating', 'ratingValue' => (float) $v( 'rating' ), 'reviewCount' => (int) $v( 'rating_count' ), 'bestRating' => 5 ];
				}
				break;

			case 'event':
				$node = [ '@type' => 'Event' ] + $node;
				if ( '' !== $v( 'start' ) ) {
					$node['startDate'] = $v( 'start' );
				}
				if ( '' !== $v( 'end' ) ) {
					$node['endDate'] = $v( 'end' );
				}
				$mode = $v( 'attendance' ) ?: 'Offline';
				$node['eventAttendanceMode'] = 'https://schema.org/' . $mode . 'EventAttendanceMode';
				$node['eventStatus']         = 'https://schema.org/' . ( $v( 'status' ) ?: 'EventScheduled' );
				$places                      = [];
				if ( 'Online' !== $mode && ( '' !== $v( 'venue' ) || '' !== $v( 'city' ) ) ) {
					$place = [ '@type' => 'Place', 'name' => $v( 'venue' ) ?: $v( 'city' ) ];
					$addr  = self::address( $e );
					if ( $addr ) {
						$place['address'] = $addr;
					}
					$places[] = $place;
				}
				if ( 'Offline' !== $mode && '' !== $v( 'online_url' ) ) {
					$places[] = [ '@type' => 'VirtualLocation', 'url' => $v( 'online_url' ) ];
				}
				if ( $places ) {
					$node['location'] = 1 === count( $places ) ? $places[0] : $places;
				}
				$o = $offer( $v( 'price' ), $v( 'currency' ) );
				if ( $o ) {
					$node['offers'] = $o;
				}
				$node['organizer'] = [ '@id' => (string) $page['publisher_id'] ];
				break;

			case 'recipe':
				$node = [ '@type' => 'Recipe' ] + $node;
				$node['author'] = [ '@id' => (string) ( $page['author_id'] ?? $page['publisher_id'] ) ];
				foreach ( [ 'prepTime' => 'prep', 'cookTime' => 'cook' ] as $prop => $k ) {
					if ( '' !== self::duration( $v( $k ) ) ) {
						$node[ $prop ] = self::duration( $v( $k ) );
					}
				}
				$total = (int) $v( 'prep' ) + (int) $v( 'cook' );
				if ( $total ) {
					$node['totalTime'] = self::duration( $total );
				}
				foreach ( [ 'recipeYield' => 'yield', 'recipeCategory' => 'category', 'recipeCuisine' => 'cuisine', 'keywords' => 'keywords' ] as $prop => $k ) {
					if ( '' !== $v( $k ) ) {
						$node[ $prop ] = $v( $k );
					}
				}
				if ( self::lines( $e['ingredients'] ?? '' ) ) {
					$node['recipeIngredient'] = self::lines( $e['ingredients'] );
				}
				if ( self::lines( $e['steps'] ?? '' ) ) {
					$node['recipeInstructions'] = array_map( static function ( $s ) {
						return [ '@type' => 'HowToStep', 'text' => $s ];
					}, self::lines( $e['steps'] ) );
				}
				if ( '' !== $v( 'calories' ) ) {
					$node['nutrition'] = [ '@type' => 'NutritionInformation', 'calories' => preg_replace( '#[^\d]#', '', $v( 'calories' ) ) . ' calories' ];
				}
				break;

			case 'location':
				$node = [ '@type' => in_array( $v( 'business_type' ), self::BUSINESS_TYPES, true ) ? $v( 'business_type' ) : 'LocalBusiness' ] + $node;
				$addr = self::address( $e );
				if ( $addr ) {
					$node['address'] = $addr;
				}
				$geo = self::geo( $v( 'geo' ) );
				if ( $geo ) {
					$node['geo'] = $geo;
				}
				if ( '' !== $v( 'phone' ) ) {
					$node['telephone'] = $v( 'phone' );
				}
				$hours = self::opening_hours( array_intersect_key( $e, self::DAYS ) );
				if ( $hours ) {
					$node['openingHoursSpecification'] = $hours;
				}
				$node['parentOrganization'] = [ '@id' => (string) $page['publisher_id'] ];
				break;

			default:
				return null;
		}

		return $node;
	}

	/* ================================================================== *
	 * FAQ from the page builder
	 * ================================================================== */

	/**
	 * Questions from every accordion with its FAQ switch on, in page order,
	 * the same way the accordion itself writes them.
	 *
	 * @param mixed $tree The page-builder tree.
	 *
	 * @return array Question nodes.
	 */
	public static function faq_from_builder( $tree ) {
		$out  = [];
		$seen = [];

		$walk = static function ( $node ) use ( &$walk, &$out, &$seen ) {
			if ( ! is_array( $node ) ) {
				return;
			}

			if ( 'accordion' === ( $node['shortcode'] ?? '' ) && 'yes' === ( $node['atts']['faq_schema'] ?? '' ) ) {
				foreach ( (array) ( $node['atts']['tabs'] ?? [] ) as $tab ) {
					$q = trim( wp_strip_all_tags( (string) ( $tab['tab_title'] ?? '' ) ) );
					$a = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( strip_shortcodes( do_shortcode( (string) ( $tab['tab_content'] ?? '' ) ) ), true ) ) );
					if ( '' === $q || '' === $a || isset( $seen[ strtolower( $q ) ] ) ) {
						continue;
					}
					$seen[ strtolower( $q ) ] = true;
					$out[] = [ '@type' => 'Question', 'name' => $q, 'acceptedAnswer' => [ '@type' => 'Answer', 'text' => $a ] ];
				}
			}

			foreach ( $node as $child ) {
				if ( is_array( $child ) ) {
					$walk( $child );
				}
			}
		};

		$walk( $tree );

		return $out;
	}

	/* ================================================================== *
	 * Local business (site level)
	 * ================================================================== */

	/** @return array The site's business details, as set on the Structured data screen. */
	public static function business() {
		$s = static function ( $k ) {
			return trim( (string) FW_SEO_Settings::get( $k, '' ) );
		};

		$out = [
			'type'     => $s( 'business_type' ),
			'street'   => $s( 'business_street' ),
			'city'     => $s( 'business_city' ),
			'region'   => $s( 'business_region' ),
			'postcode' => $s( 'business_postcode' ),
			'country'  => $s( 'business_country' ),
			'geo'      => $s( 'business_geo' ),
			'price'    => $s( 'business_price_range' ),
		];
		foreach ( array_keys( self::DAYS ) as $d ) {
			$out[ $d ] = $s( 'business_hours_' . $d );
		}

		return $out;
	}

	/* ================================================================== *
	 * The graph
	 * ================================================================== */

	/** @internal */
	public static function _filter_graph( $nodes, $ctx ) {
		self::$faq_in_graph = false;

		foreach ( $nodes as $i => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			// The site as a local business.
			if ( ( $node['@id'] ?? '' ) === FW_SEO_Schema::publisher_id() && 'Organization' === ( $node['@type'] ?? '' ) ) {
				$b = self::business();
				if ( in_array( $b['type'], self::BUSINESS_TYPES, true ) ) {
					$nodes[ $i ]['@type'] = $b['type'];
					if ( $addr = self::address( $b ) ) {
						$nodes[ $i ]['address'] = $addr;
					}
					if ( $geo = self::geo( $b['geo'] ) ) {
						$nodes[ $i ]['geo'] = $geo;
					}
					if ( '' !== $b['price'] ) {
						$nodes[ $i ]['priceRange'] = $b['price'];
					}
					if ( $hours = self::opening_hours( $b ) ) {
						$nodes[ $i ]['openingHoursSpecification'] = $hours;
					}
					// A LocalBusiness wants an image; the logo serves.
					if ( empty( $nodes[ $i ]['image'] ) && ! empty( $nodes[ $i ]['logo'] ) ) {
						$nodes[ $i ]['image'] = $nodes[ $i ]['logo'];
					}
				}
			}

			// Authors: what they know and where else they are.
			if ( 'Person' === ( $node['@type'] ?? '' ) && preg_match( '#\#author-(\d+)$#', (string) ( $node['@id'] ?? '' ), $m ) ) {
				$user  = (int) $m[1];
				$job   = trim( (string) get_user_meta( $user, 'fw_seo_author_job', true ) );
				$knows = self::lines( get_user_meta( $user, 'fw_seo_author_knows', true ), true );
				$links = array_values( array_filter( self::lines( get_user_meta( $user, 'fw_seo_author_links', true ) ), static function ( $u ) {
					return (bool) preg_match( '#^https?://#i', $u );
				} ) );
				if ( '' !== $job ) {
					$nodes[ $i ]['jobTitle'] = $job;
				}
				if ( $knows ) {
					$nodes[ $i ]['knowsAbout'] = $knows;
				}
				if ( $links ) {
					$nodes[ $i ]['sameAs'] = $links;
				}
			}
		}

		// The page: its FAQ and its main entity.
		$post_id = $ctx->post_id();
		if ( ! $post_id || ! $ctx->is( FW_SEO_Context::SINGULAR ) ) {
			return $nodes;
		}

		$page_i = null;
		foreach ( $nodes as $i => $node ) {
			if ( is_array( $node ) && substr( (string) ( $node['@id'] ?? '' ), -5 ) === '#page' ) {
				$page_i = $i;
			}
		}

		if ( null === $page_i ) {
			return $nodes;
		}

		$page = $nodes[ $page_i ];

		$tree = get_post_meta( $post_id, 'fw:opt:ext:pb:page-builder:json', true );
		if ( is_string( $tree ) && false !== strpos( $tree, 'faq_schema' ) ) {
			$questions = self::faq_from_builder( json_decode( $tree, true ) );
			if ( $questions ) {
				$nodes[ $page_i ]['@type']      = array_values( array_unique( array_merge( (array) $page['@type'], [ 'FAQPage' ] ) ) );
				$nodes[ $page_i ]['mainEntity'] = $questions;
				self::$faq_in_graph             = true;
			}
		}

		$e = self::get( $post_id );
		if ( ! empty( $e['type'] ) && ! self::missing( $e ) ) {
			$author = (int) get_post_field( 'post_author', $post_id );
			$entity = self::node( $e, [
				'url'          => (string) $page['url'],
				'name'         => (string) $page['name'],
				'description'  => (string) ( $page['description'] ?? '' ),
				'image'        => (string) ( $page['image']['url'] ?? '' ),
				'publisher_id' => FW_SEO_Schema::publisher_id(),
				'author_id'    => $author ? FW_SEO_Schema::author_id( $author ) : FW_SEO_Schema::publisher_id(),
			] );
			if ( $entity ) {
				$nodes[] = $entity;
				// A page has one main entity: its FAQ questions stay as hasPart-like
				// content, the product / event / recipe / place is what it is about.
				if ( ! self::$faq_in_graph ) {
					$nodes[ $page_i ]['mainEntity'] = [ '@id' => $entity['@id'] ];
				} else {
					$nodes[ $page_i ]['about'] = [ '@id' => $entity['@id'] ];
				}
			}
		}

		return $nodes;
	}

	/** @internal The accordion leaves its FAQ to the graph when the graph carried it. */
	public static function _filter_accordion_inline( $inline ) {
		return self::$faq_in_graph ? false : $inline;
	}

	/** @internal Site-level details for a Business Info element whose own fields are empty. */
	public static function _filter_business_info( $details ) {
		$b    = self::business();
		$addr = implode( ', ', array_filter( [ $b['street'], $b['city'], $b['region'], $b['postcode'], $b['country'] ] ) );
		$week = [];
		foreach ( array_keys( self::DAYS ) as $d ) {
			$ranges = self::parse_hours( $b[ $d ] );
			$week[] = $ranges
				? [ 'day' => $d, 'closed' => 'no', 'open' => $ranges[0][0], 'close' => end( $ranges )[1] ]
				: [ 'day' => $d, 'closed' => '' === $b[ $d ] ? '' : 'yes', 'open' => '', 'close' => '' ];
		}
		$has_hours = (bool) array_filter( array_keys( self::DAYS ), static function ( $d ) use ( $b ) {
			return '' !== $b[ $d ];
		} );

		return array_merge( (array) $details, array_filter( [
			'biz_name' => (string) FW_SEO_Settings::get( 'schema_name', '' ) ?: (string) get_bloginfo( 'name' ),
			'address'  => $addr,
			'phone'    => (string) FW_SEO_Settings::get( 'schema_phone', '' ),
			'email'    => (string) FW_SEO_Settings::get( 'schema_email', '' ),
			'hours'    => $has_hours ? $week : [],
		] ) );
	}

	/* ================================================================== *
	 * Author profile fields
	 * ================================================================== */

	/** @internal */
	public static function _profile_fields( $user ) {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}
		wp_nonce_field( self::NONCE . '_profile', 'fw_seo_author_nonce' );
		?>
		<h2><?php esc_html_e( 'Author profile for search engines', 'fw' ); ?></h2>
		<p class="description" style="max-width:46em"><?php esc_html_e( 'Published with your posts as structured data, so search engines can connect what you write to who you are: the expertise and experience their guidelines ask about.', 'fw' ); ?></p>
		<table class="form-table" role="presentation">
			<tr><th><label for="fw-seo-author-job"><?php esc_html_e( 'Job title', 'fw' ); ?></label></th>
				<td><input type="text" class="regular-text" id="fw-seo-author-job" name="fw_seo_author_job" value="<?php echo esc_attr( (string) get_user_meta( $user->ID, 'fw_seo_author_job', true ) ); ?>" placeholder="<?php esc_attr_e( 'e.g. Head chef', 'fw' ); ?>"></td></tr>
			<tr><th><label for="fw-seo-author-knows"><?php esc_html_e( 'Areas of expertise', 'fw' ); ?></label></th>
				<td><input type="text" class="large-text" id="fw-seo-author-knows" name="fw_seo_author_knows" value="<?php echo esc_attr( (string) get_user_meta( $user->ID, 'fw_seo_author_knows', true ) ); ?>" placeholder="<?php esc_attr_e( 'e.g. Sourdough baking, Food safety, Restaurant management', 'fw' ); ?>">
				<p class="description"><?php esc_html_e( 'Comma-separated. The subjects you write about from real experience.', 'fw' ); ?></p></td></tr>
			<tr><th><label for="fw-seo-author-links"><?php esc_html_e( 'Profiles elsewhere', 'fw' ); ?></label></th>
				<td><textarea class="large-text" rows="3" id="fw-seo-author-links" name="fw_seo_author_links" placeholder="https://…"><?php echo esc_textarea( (string) get_user_meta( $user->ID, 'fw_seo_author_links', true ) ); ?></textarea>
				<p class="description"><?php esc_html_e( 'One address per line: your profiles on social and professional sites, an author page on another publication. Only addresses starting with http:// or https:// are used.', 'fw' ); ?></p></td></tr>
		</table>
		<?php
	}

	/** @internal */
	public static function _save_profile( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) || ! isset( $_POST['fw_seo_author_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['fw_seo_author_nonce'] ) ), self::NONCE . '_profile' ) ) {
			return;
		}

		update_user_meta( $user_id, 'fw_seo_author_job', sanitize_text_field( wp_unslash( $_POST['fw_seo_author_job'] ?? '' ) ) );
		update_user_meta( $user_id, 'fw_seo_author_knows', sanitize_text_field( wp_unslash( $_POST['fw_seo_author_knows'] ?? '' ) ) );
		update_user_meta( $user_id, 'fw_seo_author_links', implode( "\n", array_map( 'esc_url_raw', self::lines( wp_unslash( $_POST['fw_seo_author_links'] ?? '' ) ) ) ) );
	}

	/* ================================================================== *
	 * The per-page box
	 * ================================================================== */

	/** @internal */
	public static function _add_box() {
		if ( class_exists( 'FW_SEO_Additions' ) && ! FW_SEO_Additions::can_edit() ) {
			return;
		}

		foreach ( array_keys( FW_SEO_Locations::post_types() ) as $type ) {
			// WooCommerce describes its own products; a second Product would conflict.
			if ( 'product' === $type && class_exists( 'WooCommerce' ) ) {
				continue;
			}
			add_meta_box( 'fw-seo-entity', __( 'Structured data', 'fw' ), [ __CLASS__, '_render_box' ], $type, 'normal', 'default' );
		}
	}

	/** @internal */
	public static function _render_box( $post ) {
		$e    = self::get( $post->ID );
		$type = (string) ( $e['type'] ?? '' );
		$f    = static function ( $key, $label, $kind = 'text', $placeholder = '', $help = '' ) use ( $e ) {
			$val  = (string) ( $e[ $key ] ?? '' );
			$name = 'fw_seo_entity[' . $key . ']';
			echo '<p><label style="display:block;font-weight:600;margin-bottom:3px">' . esc_html( $label ) . '</label>';
			if ( 'textarea' === $kind ) {
				echo '<textarea class="large-text" rows="5" name="' . esc_attr( $name ) . '" placeholder="' . esc_attr( $placeholder ) . '">' . esc_textarea( $val ) . '</textarea>';
			} elseif ( is_array( $kind ) ) {
				echo '<select name="' . esc_attr( $name ) . '">';
				foreach ( $kind as $k => $l ) {
					echo '<option value="' . esc_attr( $k ) . '"' . selected( $val, (string) $k, false ) . '>' . esc_html( $l ) . '</option>';
				}
				echo '</select>';
			} else {
				echo '<input type="' . esc_attr( $kind ) . '" class="regular-text" name="' . esc_attr( $name ) . '" value="' . esc_attr( $val ) . '" placeholder="' . esc_attr( $placeholder ) . '">';
			}
			if ( '' !== $help ) {
				echo '<span class="description" style="display:block">' . esc_html( $help ) . '</span>';
			}
			echo '</p>';
		};
		$address = static function () use ( $f ) {
			$f( 'street', __( 'Street address', 'fw' ) );
			$f( 'city', __( 'Town or city', 'fw' ) );
			$f( 'region', __( 'Region or state', 'fw' ) );
			$f( 'postcode', __( 'Postcode', 'fw' ) );
			$f( 'country', __( 'Country', 'fw' ), 'text', 'GB' );
		};

		wp_nonce_field( self::NONCE, 'fw_seo_entity_nonce' );
		$missing = self::missing( $e );
		?>
		<div class="fw-seo-entity">
			<p><?php esc_html_e( 'What this page is about, described for search engines. The richest search results (prices and stock, event dates, recipe times and ratings, opening hours) come from these fields.', 'fw' ); ?></p>
			<p><label><strong><?php esc_html_e( 'This page is a', 'fw' ); ?></strong>
				<select name="fw_seo_entity[type]" class="fw-seo-entity-type"><?php foreach ( self::types() as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>"<?php selected( $type, $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select></label></p>

			<?php if ( $type && $missing ) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html( sprintf(
					/* translators: %s: list of missing fields */
					__( 'Not published yet: search engines need %s for this kind of result. Fill it in and update the page.', 'fw' ),
					implode( __( ' and ', 'fw' ), $missing )
				) ); ?></p></div>
			<?php elseif ( $type ) : ?>
				<div class="notice notice-success inline"><p><?php esc_html_e( 'Published with this page.', 'fw' ); ?></p></div>
			<?php endif; ?>

			<div data-type="product"<?php echo 'product' === $type ? '' : ' hidden'; ?>>
				<?php
				$f( 'name', __( 'Product name', 'fw' ), 'text', get_the_title( $post ), __( 'Leave empty to use the page title.', 'fw' ) );
				$f( 'brand', __( 'Brand', 'fw' ) );
				$f( 'sku', __( 'SKU', 'fw' ) );
				$f( 'price', __( 'Price', 'fw' ), 'text', '24.99' );
				$f( 'currency', __( 'Currency', 'fw' ), 'text', 'USD' );
				$f( 'availability', __( 'Availability', 'fw' ), [ 'InStock' => __( 'In stock', 'fw' ), 'OutOfStock' => __( 'Out of stock', 'fw' ), 'PreOrder' => __( 'Pre-order', 'fw' ), 'BackOrder' => __( 'Back-order', 'fw' ) ] );
				$f( 'rating', __( 'Average rating (out of 5)', 'fw' ), 'text', '4.6', __( 'Only real reviews from your customers. Made-up ratings break search engines\' guidelines and can cost the site its rich results.', 'fw' ) );
				$f( 'rating_count', __( 'Number of reviews', 'fw' ), 'number' );
				?>
			</div>
			<div data-type="event"<?php echo 'event' === $type ? '' : ' hidden'; ?>>
				<?php
				$f( 'name', __( 'Event name', 'fw' ), 'text', get_the_title( $post ), __( 'Leave empty to use the page title.', 'fw' ) );
				$f( 'start', __( 'Starts', 'fw' ), 'datetime-local' );
				$f( 'end', __( 'Ends', 'fw' ), 'datetime-local' );
				$f( 'attendance', __( 'Where', 'fw' ), [ 'Offline' => __( 'In person', 'fw' ), 'Online' => __( 'Online', 'fw' ), 'Mixed' => __( 'Both', 'fw' ) ] );
				$f( 'venue', __( 'Venue name', 'fw' ) );
				$address();
				$f( 'online_url', __( 'Online address', 'fw' ), 'url', 'https://…' );
				$f( 'price', __( 'Ticket price', 'fw' ), 'text', '0' );
				$f( 'currency', __( 'Currency', 'fw' ), 'text', 'USD' );
				$f( 'status', __( 'Status', 'fw' ), [ 'EventScheduled' => __( 'On as planned', 'fw' ), 'EventPostponed' => __( 'Postponed', 'fw' ), 'EventRescheduled' => __( 'Rescheduled', 'fw' ), 'EventCancelled' => __( 'Cancelled', 'fw' ) ] );
				?>
			</div>
			<div data-type="recipe"<?php echo 'recipe' === $type ? '' : ' hidden'; ?>>
				<?php
				$f( 'name', __( 'Recipe name', 'fw' ), 'text', get_the_title( $post ), __( 'Leave empty to use the page title.', 'fw' ) );
				$f( 'prep', __( 'Preparation (minutes)', 'fw' ), 'number' );
				$f( 'cook', __( 'Cooking (minutes)', 'fw' ), 'number' );
				$f( 'yield', __( 'Makes', 'fw' ), 'text', __( '4 servings', 'fw' ) );
				$f( 'category', __( 'Course', 'fw' ), 'text', __( 'Dessert', 'fw' ) );
				$f( 'cuisine', __( 'Cuisine', 'fw' ), 'text', __( 'Italian', 'fw' ) );
				$f( 'calories', __( 'Calories per serving', 'fw' ), 'number' );
				$f( 'ingredients', __( 'Ingredients', 'fw' ), 'textarea', "200 g flour\n2 eggs", __( 'One per line.', 'fw' ) );
				$f( 'steps', __( 'Steps', 'fw' ), 'textarea', "Heat the oven to 180°C.\nWhisk the eggs…", __( 'One step per line.', 'fw' ) );
				$f( 'keywords', __( 'Keywords', 'fw' ), 'text', __( 'quick, vegetarian', 'fw' ) );
				?>
			</div>
			<div data-type="location"<?php echo 'location' === $type ? '' : ' hidden'; ?>>
				<p class="description"><?php esc_html_e( 'For a business with several branches: one page per branch, each with its own address and hours, linked to your business as a whole.', 'fw' ); ?></p>
				<?php
				$f( 'name', __( 'Branch name', 'fw' ), 'text', get_the_title( $post ), __( 'Leave empty to use the page title.', 'fw' ) );
				$f( 'business_type', __( 'Kind of business', 'fw' ), array_combine( self::BUSINESS_TYPES, self::BUSINESS_TYPES ) );
				$address();
				$f( 'phone', __( 'Phone', 'fw' ) );
				$f( 'geo', __( 'Map position', 'fw' ), 'text', '51.5072, -0.1276', __( 'Latitude, longitude.', 'fw' ) );
				foreach ( self::DAYS as $k => $day ) {
					$f( $k, sprintf(
						/* translators: %s: day of the week */
						__( '%s hours', 'fw' ),
						translate( $day, 'default' )
					), 'text', '09:00-17:00', 'mon' === $k ? __( 'Like 09:00-17:00, or 09:00-12:30, 13:30-18:00, or closed. Leave empty when unknown.', 'fw' ) : '' );
				}
				?>
			</div>
		</div>
		<script>
		( function () {
			var box = document.querySelector( '.fw-seo-entity' ); if ( ! box ) { return; }
			var sel = box.querySelector( '.fw-seo-entity-type' );
			function show() {
				box.querySelectorAll( '[data-type]' ).forEach( function ( d ) {
					var on = d.dataset.type === sel.value;
					d.hidden = ! on;
					// Only the shown type's fields are sent, so a shared key ("name") is not overwritten by a hidden copy.
					d.querySelectorAll( 'input, select, textarea' ).forEach( function ( i ) { i.disabled = ! on; } );
				} );
			}
			sel.addEventListener( 'change', show ); show();
		} )();
		</script>
		<?php
	}

	/** @internal */
	public static function _save_box( $post_id, $post ) {
		if ( ! isset( $_POST['fw_seo_entity_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['fw_seo_entity_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) || ( class_exists( 'FW_SEO_Additions' ) && ! FW_SEO_Additions::can_edit() ) ) {
			return;
		}

		$in   = isset( $_POST['fw_seo_entity'] ) ? (array) wp_unslash( $_POST['fw_seo_entity'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$type = (string) ( $in['type'] ?? '' );

		if ( ! array_key_exists( $type, self::types() ) || '' === $type ) {
			delete_post_meta( $post_id, self::META );

			return;
		}

		$clean = [ 'type' => $type ];
		foreach ( $in as $k => $v ) {
			$k = sanitize_key( $k );
			if ( 'type' === $k ) {
				continue;
			}
			$clean[ $k ] = in_array( $k, [ 'ingredients', 'steps' ], true ) ? sanitize_textarea_field( (string) $v ) : sanitize_text_field( (string) $v );
		}

		update_post_meta( $post_id, self::META, $clean );
	}
}
