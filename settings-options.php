<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The SEO settings screen.
 *
 * Built from FW_SEO_Locations rather than a hand-written list, so a site that
 * registers a custom post type or taxonomy gets template fields for it without
 * anyone touching this file.
 */

$fw_seo_locations = FW_SEO_Locations::all();

/**
 * One location's fields.
 *
 * @param array $location
 *
 * @return array
 */
$fw_seo_location_group = static function ( array $location ) {
	$key = $location['key'];

	$fields = [
		FW_SEO_Settings::template_id( 'title', $key )       => [
			'label'   => __( 'Title template', 'fw' ),
			'type'    => 'seo-template',
			'value'   => FW_SEO_Locations::default_title( $key ),
			'field'   => 'title',
			'measure' => 'pixels',
			'limit'   => 600,
		],
		FW_SEO_Settings::template_id( 'description', $key ) => [
			'label'     => __( 'Description template', 'fw' ),
			'desc'      => __( 'Leave empty to generate a description from the content.', 'fw' ),
			'type'      => 'seo-template',
			'value'     => FW_SEO_Locations::default_description( $key ),
			'field'     => 'description',
			'multiline' => true,
			'measure'   => 'characters',
			'limit'     => 160,
		],
	];

	// Search results and 404s are forced to noindex regardless, so offering the
	// switch there would be a control that does nothing.
	if ( ! in_array( $key, [ 'search', '404' ], true ) ) {
		$fields[ FW_SEO_Settings::robots_id( 'noindex', $key ) ] = [
			'label' => __( 'No index', 'fw' ),
			'desc'  => in_array( $key, [ 'author', 'date' ], true )
				? __( 'Keep this whole group out of search results. On by default for date archives, and for author archives when one person writes the posts: there they repeat the blog.', 'fw' )
				: __( 'Keep this whole group out of search results.', 'fw' ),
			'type'  => 'switch',
			'value' => FW_SEO_Locations::default_noindex( $key ),
		];

		$fields[ FW_SEO_Settings::robots_id( 'nofollow', $key ) ] = [
			'label' => __( 'No follow', 'fw' ),
			'desc'  => __( 'Ask search engines not to follow links on these pages.', 'fw' ),
			'type'  => 'switch',
			'value' => false,
		];
	}

	return [
		'type'    => 'group',
		'options' => $fields,
	];
};

/**
 * Every location in one settings group, wrapped in a titled box.
 *
 * @param string   $group_key
 * @param string   $title
 * @param string   $description
 * @param array    $locations
 * @param callable $builder
 *
 * @return array|null
 */
/**
 * Whether a report belonging to a tab is worth generating for this request.
 *
 * The reports below are queries, not settings, and only one tab is on screen at
 * a time. Outside the settings page — or before its class is loaded — the whole
 * tree is built, exactly as it always was.
 */
$fw_seo_wants = static function ( $tab ) {
	return ! class_exists( 'FW_SEO_Settings_Page' ) || FW_SEO_Settings_Page::wants( $tab );
};

$fw_seo_box = static function ( $group_key, $title, $description, array $locations, callable $builder ) {
	$inner = [];

	foreach ( $locations as $location ) {
		if ( $location['group'] !== $group_key ) {
			continue;
		}

		$inner[ 'heading__' . FW_SEO_Locations::slug( $location['key'] ) ] = [
			'type'  => 'html-full',
			'label' => false,
			// The markup goes in `html`, NOT `value`: the option type reads that key
			// and otherwise falls back to its own "default html" placeholder — which
			// is exactly what every one of these headings was rendering.
			'html'  => '<h4 class="fw-seo-settings-heading">' . esc_html( $location['label'] ) . '</h4>',
		];

		$inner[ 'group__' . FW_SEO_Locations::slug( $location['key'] ) ] = $builder( $location );
	}

	if ( ! $inner ) {
		return null;
	}

	return [
		'title'   => $title,
		'type'    => 'box',
		'desc'    => $description,
		'options' => $inner,
	];
};

$options = [
	'general_tab' => [
		'title'   => __( 'General', 'fw' ),
		'type'    => 'tab',
		'options' => [
			'general_box' => [
				'title'   => __( 'General', 'fw' ),
				'type'    => 'box',
				'options' => [
					'general_group' => [
						'type'    => 'group',
						'options' => [
							'separator'           => [
								'label'   => __( 'Title separator', 'fw' ),
								'desc'    => __( 'Used wherever a template contains %%sep%%.', 'fw' ),
								'type'    => 'select',
								'value'   => '|',
								'choices' => [
									'|' => '|',
									'-' => '-',
									'–' => '–',
									'—' => '—',
									'·' => '·',
									'•' => '•',
									'/' => '/',
									'>' => '>',
									'»' => '»',
								],
							],
							'autogen_description' => [
								'label' => __( 'Auto-generate descriptions', 'fw' ),
								'desc'  => __( 'When a page has no description of its own, write one from its content. Turning this off means pages without a hand-written description emit no description at all.', 'fw' ),
								'type'  => 'switch',
								'value' => true,
							],
							'canonical_enabled'   => [
								'label' => __( 'Canonical URLs', 'fw' ),
								'desc'  => __( 'Tell search engines which address is the original for each page. Leave this on unless another plugin is already doing it.', 'fw' ),
								'type'  => 'switch',
								'value' => true,
							],
							'paged_titles'        => [
								'label' => __( 'Number the pages of a list', 'fw' ),
								'desc'  => __( 'On page 2 and beyond of the blog, a category or a search, add "Page 2 of 7" to the title when its template does not already say which page it is, so each page has a title of its own.', 'fw' ),
								'type'  => 'switch',
								'value' => true,
							],
						],
					],
				],
			],
			'attachments_box' => [
				'title'   => __( 'Attachment pages', 'fw' ),
				'type'    => 'box',
				'desc'    => __( 'WordPress makes a page for every file you upload — a title, the image, and little else. Search engines index those thin pages anyway.', 'fw' ) . ' ' . FW_SEO_Attachments::core_state_text(),
				'options' => [
					'attachments_group' => [
						'type'    => 'group',
						'options' => [
							'attachment_pages' => [
								'label'   => __( 'Attachment pages', 'fw' ),
								'type'    => 'select',
								'value'   => 'core',
								'choices' => [
									'core'   => __( 'Leave it to WordPress', 'fw' ),
									'parent' => __( 'Send visitors to the post the file belongs to (recommended)', 'fw' ),
									'file'   => __( 'Send visitors to the file itself', 'fw' ),
									'keep'   => __( 'Keep attachment pages', 'fw' ),
								],
								'desc'    => __( 'A file not attached to any post goes to the file itself.', 'fw' ),
							],
						],
					],
				],
			],
		],
	],
];

$fw_seo_template_boxes = array_filter( [
	$fw_seo_box( 'general', __( 'Homepage and blog', 'fw' ), '', $fw_seo_locations, $fw_seo_location_group ),
	$fw_seo_box( 'post_types', __( 'Content types', 'fw' ), '', $fw_seo_locations, $fw_seo_location_group ),
	$fw_seo_box( 'taxonomies', __( 'Taxonomies', 'fw' ), '', $fw_seo_locations, $fw_seo_location_group ),
	$fw_seo_box( 'archives', __( 'Archives', 'fw' ), '', $fw_seo_locations, $fw_seo_location_group ),
	$fw_seo_box( 'special', __( 'Search and 404', 'fw' ), '', $fw_seo_locations, $fw_seo_location_group ),
] );

$fw_seo_template_options = [];

foreach ( $fw_seo_template_boxes as $index => $box ) {
	$fw_seo_template_options[ 'templates_box_' . $index ] = $box;
}

$options['templates_tab'] = [
	'title'   => __( 'Titles & Meta', 'fw' ),
	'type'    => 'tab',
	'options' => $fw_seo_template_options,
];

// -----------------------------------------------------------------------------
// Sitemap
// -----------------------------------------------------------------------------

$fw_seo_sitemap_sources = [
	'sitemap_home' => [
		'label' => __( 'Homepage', 'fw' ),
		'type'  => 'switch',
		'value' => true,
	],
];

foreach ( FW_SEO_Locations::post_types() as $fw_seo_pt => $fw_seo_pt_object ) {
	$fw_seo_sitemap_sources[ 'sitemap_pt__' . FW_SEO_Locations::slug( $fw_seo_pt ) ] = [
		'label' => $fw_seo_pt_object->labels->name,
		'type'  => 'switch',
		'value' => true,
	];
}

foreach ( FW_SEO_Locations::taxonomies() as $fw_seo_tax => $fw_seo_tax_object ) {
	$fw_seo_sitemap_sources[ 'sitemap_tax__' . FW_SEO_Locations::slug( $fw_seo_tax ) ] = [
		'label' => $fw_seo_tax_object->labels->name,
		'type'  => 'switch',
		'value' => true,
	];
}

unset( $fw_seo_pt, $fw_seo_pt_object, $fw_seo_tax, $fw_seo_tax_object );

$options['sitemap_tab'] = [
	'title'   => __( 'Sitemap', 'fw' ),
	'type'    => 'tab',
	'options' => [
		'sitemap_box'         => [
			'title'   => __( 'XML sitemap', 'fw' ),
			'type'    => 'box',
			'desc'    => sprintf(
				/* translators: %s: the sitemap URL. */
				__( 'Your sitemap lives at %s and is linked from robots.txt, which is how search engines discover it. Submit that URL once in Search Console; there is nothing to re-submit after that.', 'fw' ),
				'<a href="' . esc_url( FW_SEO_Sitemap::index_url() ) . '" target="_blank" rel="noopener"><code>' . esc_html( FW_SEO_Sitemap::index_url() ) . '</code></a>'
			),
			'options' => [
				'sitemap_group' => [
					'type'    => 'group',
					'options' => [
						'sitemap_enabled'      => [
							'label' => __( 'Enable sitemap', 'fw' ),
							'desc'  => __( 'Publish an XML sitemap and advertise it in robots.txt.', 'fw' ),
							'type'  => 'switch',
							'value' => true,
						],
						'sitemap_images'       => [
							'label' => __( 'Include images', 'fw' ),
							'desc'  => __( 'List each page\'s images so they can be found in image search.', 'fw' ),
							'type'  => 'switch',
							'value' => true,
						],
						'sitemap_replace_core' => [
							'label' => __( 'Replace the WordPress sitemap', 'fw' ),
							'desc'  => __( 'WordPress publishes its own sitemap at /wp-sitemap.xml. Leave this on so the site has one sitemap rather than two competing ones.', 'fw' ),
							'type'  => 'switch',
							'value' => true,
						],
					],
				],
			],
		],
		'sitemap_sources_box' => [
			'title'   => __( 'What to include', 'fw' ),
			'type'    => 'box',
			'desc'    => __( 'Anything set to no-index — a whole content type here, or a single page in its own SEO panel — is left out automatically, whatever these switches say.', 'fw' ),
			'options' => [
				'sitemap_sources_group' => [
					'type'    => 'group',
					'options' => $fw_seo_sitemap_sources,
				],
			],
		],
	],
];

$options['social_tab'] = [
	'title'   => __( 'Social', 'fw' ),
	'type'    => 'tab',
	'options' => [
		'social_box'          => [
			'title'   => __( 'Sharing cards', 'fw' ),
			'type'    => 'box',
			'desc'    => __( 'How a page looks when someone shares it. Titles and descriptions follow the same templates as your search results, so there is nothing to configure twice — set a default image and a card style here, and override either on a single page from its SEO panel.', 'fw' ),
			'options' => [
				'social_group' => [
					'type'    => 'group',
					'options' => [
						'social_enabled'       => [
							'label' => __( 'Sharing cards', 'fw' ),
							'desc'  => __( 'Emit Open Graph and Twitter card tags. Switch this off if another plugin is already producing them.', 'fw' ),
							'type'  => 'switch',
							'value' => true,
						],
						'social_default_image' => [
							'label' => __( 'Default share image', 'fw' ),
							'desc'  => __( 'Used when a page has no featured image and no image of its own. 1200 × 630 pixels is the size every network crops to.', 'fw' ),
							'type'  => 'upload',
							'images_only' => true,
							'value' => '',
						],
						'twitter_card'         => [
							'label'   => __( 'Card style', 'fw' ),
							'desc'    => __( 'The large card gives a full-width image and is the better choice for almost every site. Pages with no image fall back to the small card on their own.', 'fw' ),
							'type'    => 'select',
							'value'   => 'summary_large_image',
							'choices' => [
								'summary_large_image' => __( 'Large image', 'fw' ),
								'summary'             => __( 'Small thumbnail', 'fw' ),
							],
						],
						'social_card_enabled'  => [
							'label' => __( 'Draw a card when a page has no image', 'fw' ),
							'desc'  => __( 'Pages with no featured image and no picture of their own get one drawn from their title, instead of all sharing the default above. A page that already has an image is never changed.', 'fw' ),
							'type'  => 'switch',
							'value' => true,
						],
						'social_card_bg'       => [
							'label' => __( 'Card background', 'fw' ),
							'type'  => 'color-picker',
							'value' => '#111827',
						],
						'social_card_fg'       => [
							'label' => __( 'Card text', 'fw' ),
							'type'  => 'color-picker',
							'value' => '#ffffff',
						],
						'social_card_accent'   => [
							'label' => __( 'Card accent', 'fw' ),
							'desc'  => __( 'The edge bar and the site name.', 'fw' ),
							'type'  => 'color-picker',
							'value' => '#2271b1',
						],
						'social_card_sitename' => [
							'label' => __( 'Show the site name on the card', 'fw' ),
							'type'  => 'switch',
							'value' => true,
						],
						'social_card_font'     => [
							'label' => __( 'Card font', 'fw' ),
							'desc'  => __( 'Optional. A .ttf file to draw the title with. Left empty, the theme\'s own font is used if it has one, otherwise a system font. If none is found, cards are not drawn.', 'fw' ),
							'type'  => 'upload',
							'value' => '',
						],
						'twitter_site'         => [
							'label' => __( 'Site X / Twitter account', 'fw' ),
							'desc'  => __( 'The account this site belongs to. Paste the handle or the profile URL — either works.', 'fw' ),
							'type'  => 'text',
							'value' => '',
						],
					],
				],
			],
		],
		'social_profiles_box' => [
			'title'   => __( 'Social profiles', 'fw' ),
			'type'    => 'box',
			'desc'    => __( 'The accounts that genuinely belong to this site. These are not decoration: they are what tells a search engine which profiles are yours, so it can connect them to your site in results. Full profile URLs, one per field.', 'fw' ),
			'options' => [
				'social_profiles_group' => [
					'type'    => 'group',
					'options' => [
						'profile_facebook'  => [ 'label' => __( 'Facebook', 'fw' ), 'type' => 'text', 'value' => '' ],
						'profile_twitter'   => [ 'label' => __( 'X / Twitter', 'fw' ), 'type' => 'text', 'value' => '' ],
						'profile_instagram' => [ 'label' => __( 'Instagram', 'fw' ), 'type' => 'text', 'value' => '' ],
						'profile_linkedin'  => [ 'label' => __( 'LinkedIn', 'fw' ), 'type' => 'text', 'value' => '' ],
						'profile_youtube'   => [ 'label' => __( 'YouTube', 'fw' ), 'type' => 'text', 'value' => '' ],
						'profile_tiktok'    => [ 'label' => __( 'TikTok', 'fw' ), 'type' => 'text', 'value' => '' ],
						'profile_pinterest' => [ 'label' => __( 'Pinterest', 'fw' ), 'type' => 'text', 'value' => '' ],
						'profile_github'    => [ 'label' => __( 'GitHub', 'fw' ), 'type' => 'text', 'value' => '' ],
					],
				],
			],
		],
	],
];

$schema_type_options = [];

foreach ( FW_SEO_Locations::post_types() as $post_type => $object ) {
	$schema_type_options[ FW_SEO_Schema::type_id( $post_type ) ] = [
		'label'   => $object->labels->name,
		'type'    => 'select',
		'value'   => FW_SEO_Schema::default_type( $post_type ),
		'choices' => FW_SEO_Schema::type_choices(),
	];
}

$options['search_tab'] = [
	'title'   => __( 'Site search', 'fw' ),
	'type'    => 'tab',
	'options' => [
		'search_box' => [
			'title'   => __( 'What visitors search for', 'fw' ),
			'type'    => 'box',
			'desc'    => __( 'WordPress records nothing about your own search box. A search that comes back empty is a visitor telling you, in their words, that a page is missing — the only keyword data about your actual audience that costs nothing and is not an estimate.', 'fw' ),
			'options' => [
				'search_group' => [
					'type'    => 'group',
					'options' => [
						'search_log_enabled' => [
							'label' => __( 'Record searches', 'fw' ),
							'desc'  => __( 'Counts each phrase and whether it found anything. Stored on this site only and never sent anywhere.', 'fw' ),
							'type'  => 'switch',
							'value' => true,
						],
						'search_log_days'    => [
							'label'   => __( 'Keep for', 'fw' ),
							'desc'    => __( 'Phrases nobody has searched for in this long are deleted. Search text is typed by visitors and can contain anything, so keeping it for ever is a liability with no analytical value.', 'fw' ),
							'type'    => 'select',
							'value'   => '90',
							'choices' => [
								'30'  => __( '30 days', 'fw' ),
								'90'  => __( '90 days', 'fw' ),
								'180' => __( '180 days', 'fw' ),
								'365' => __( 'A year', 'fw' ),
							],
						],
						'search_report'      => [
							'type'  => 'html-full',
							'label' => false,
							// Generated at render time: a report is a query, not a setting.
							'html'  => $fw_seo_wants( 'search_tab' ) ? FW_SEO_Search_Log::report_html() : '',
						],
					],
				],
			],
		],
	],
];

$options['freshness_tab'] = [
	'title'   => __( 'Going stale', 'fw' ),
	'type'    => 'tab',
	'options' => [
		'freshness_box' => [
			'title'   => __( 'Pages going stale', 'fw' ),
			'type'    => 'box',
			'desc'    => __( 'The most useful editorial work on an established site is rarely writing new pages; it is finding the ones that quietly stopped being true. Age and length are what can be known without traffic history — they say what to look at, not what is wrong.', 'fw' ),
			'options' => [
				'freshness_group' => [
					'type'    => 'group',
					'options' => [
						'freshness_months' => [
							'label'   => __( 'Call a page stale after', 'fw' ),
							'type'    => 'select',
							'value'   => '18',
							'choices' => [
								'6'  => __( '6 months', 'fw' ),
								'12' => __( '12 months', 'fw' ),
								'18' => __( '18 months', 'fw' ),
								'24' => __( '2 years', 'fw' ),
								'36' => __( '3 years', 'fw' ),
							],
						],
						'freshness_thin'   => [
							'label'   => __( 'Call a page thin under', 'fw' ),
							'desc'    => __( 'Counted from the words a reader actually sees — on a builder page, the prose in the elements rather than the markup around them.', 'fw' ),
							'type'    => 'select',
							'value'   => '150',
							'choices' => [
								'0'   => __( 'Never flag thin pages', 'fw' ),
								'100' => __( '100 words', 'fw' ),
								'150' => __( '150 words', 'fw' ),
								'300' => __( '300 words', 'fw' ),
								'600' => __( '600 words', 'fw' ),
							],
						],
						'freshness_report' => [
							'type'  => 'html-full',
							'label' => false,
							'html'  => $fw_seo_wants( 'freshness_tab' ) ? FW_SEO_Freshness::report_html() : '',
						],
					],
				],
			],
		],
	],
];

$options['vitals_tab'] = [
	'title'   => __( 'Page speed', 'fw' ),
	'type'    => 'tab',
	'options' => [
		'vitals_box' => [
			'title'   => __( 'How fast pages really are', 'fw' ),
			'type'    => 'box',
			'desc'    => __( 'Real visits over the last 28 days, from Google\'s Chrome UX Report. Field data rather than a lab score: it answers whether the site is fast for the people using it, not whether it is fast on one simulated connection.', 'fw' ),
			'options' => [
				'vitals_group' => [
					'type'    => 'group',
					'options' => [
						'crux_api_key'  => [
							'label' => __( 'Chrome UX Report API key', 'fw' ),
							'desc'  => __( 'Free from Google Cloud: create an API key and enable two APIs for it — the Chrome UX Report API (the field data above) and the PageSpeed Insights API (the diagnostics below). Both are free, and only your own site is ever queried.', 'fw' ),
							'type'  => 'text',
							'value' => '',
						],
						'vitals_report' => [
							'type'  => 'html-full',
							'label' => false,
							'html'  => $fw_seo_wants( 'vitals_tab' ) ? FW_SEO_Vitals::report_html() . FW_SEO_Speed::report_html() : '',
						],
					],
				],
			],
		],
	],
];

$options['console_tab'] = [
	'title'   => __( 'Search Console', 'fw' ),
	'type'    => 'tab',
	'options' => [
		'console_box' => [
			'title'   => __( 'Search Console', 'fw' ),
			'type'    => 'box',
			'desc'    => __( 'Your own data from Google, shown where you can act on it. Read-only, and the site talks to Google directly — there is no account here and nothing passes through us.', 'fw' ),
			'options' => [
				'console_group' => [
					'type'    => 'group',
					'options' => [
						'console_client_id'     => [
							'label' => __( 'OAuth client ID', 'fw' ),
							'desc'  => __( 'From a Google Cloud project you create: enable the Search Console API, then make an OAuth client of type "Web application".', 'fw' ),
							'type'  => 'text',
							'value' => '',
						],
						'console_client_secret' => [
							'label' => __( 'OAuth client secret', 'fw' ),
							'type'  => 'password',
							'value' => '',
						],
						'console_report'        => [
							'type'  => 'html-full',
							'label' => false,
							'html'  => $fw_seo_wants( 'console_tab' ) ? FW_SEO_Console::report_html() : '',
						],
					],
				],
			],
		],
	],
];

$options['rank_tab'] = [
	'title'   => __( 'Where pages rank', 'fw' ),
	'type'    => 'tab',
	'options' => [
		'rank_box' => [
			'title'   => __( 'Almost on page one', 'fw' ),
			'type'    => 'box',
			'desc'    => __( 'Searches where a page sits at positions 8 to 15 — close enough to the first page that a small improvement pays, far enough down that nobody sees it. Your own Search Console data, not a scrape: averaged across everyone who saw the result, so treat it as a trend.', 'fw' ),
			'options' => [
				'rank_group' => [
					'type'    => 'group',
					'options' => [
						'rank_report' => [
							'type'  => 'html-full',
							'label' => false,
							'html'  => $fw_seo_wants( 'rank_tab' ) ? FW_SEO_Rank::report_html() : '',
						],
					],
				],
			],
		],
	],
];

$options['coverage_tab'] = [
	'title'   => __( 'Index coverage', 'fw' ),
	'type'    => 'tab',
	'options' => [
		'coverage_box' => [
			'title'   => __( 'Is Google indexing your pages?', 'fw' ),
			'type'    => 'box',
			'desc'    => __( 'A page Google never kept cannot bring anyone to the site, and nothing else in WordPress tells the difference between "nobody searches for this" and "it was never in the index". Checked in the background, a few pages at a time, because Google allows a fixed number of these a day.', 'fw' ),
			'options' => [
				'coverage_group' => [
					'type'    => 'group',
					'options' => [
						'coverage_report' => [
							'type'  => 'html-full',
							'label' => false,
							'html'  => $fw_seo_wants( 'coverage_tab' ) ? FW_SEO_Inspect::report_html() : '',
						],
					],
				],
			],
		],
	],
];

$options['redirects_tab'] = [
	'title'   => __( 'Redirects', 'fw' ),
	'type'    => 'tab',
	'options' => [
		'redirects_box' => [
			'title'   => __( 'When an address changes', 'fw' ),
			'type'    => 'box',
			'desc'    => __( 'Rename a page, move it under another parent, or rename a category, and its address changes — every link to the old one would land on a 404. This keeps the old address working by sending visitors and search engines on to the new one.', 'fw' ),
			'options' => [
				'redirects_group' => [
					'type'    => 'group',
					'options' => [
						'redirects_auto' => [
							'label' => __( 'Redirect old addresses automatically', 'fw' ),
							'desc'  => __( 'Leave on unless another plugin already manages redirects for this site.', 'fw' ),
							'type'  => 'switch',
							'value' => true,
						],
						'redirects_report' => [
							'type'  => 'html-full',
							'label' => false,
							// A placeholder, filled in when the page is drawn: this schema can be
							// built before `init`, when WordPress cannot yet make pretty term
							// links, and a category target would read as the home page.
							'html'  => FW_SEO_Redirects::PLACEHOLDER,
						],
					],
				],
			],
		],
		'redirect_rules_box' => [
			'title'   => __( 'Your redirects', 'fw' ),
			'type'    => 'box',
			'desc'    => __( 'For what the automatic redirects cannot know: pages that moved before this site existed, addresses from an old platform, campaign links that should point somewhere new, and pages removed for good.', 'fw' ),
			'options' => [
				'redirect_rules' => [
					'type'  => 'html-full',
					'label' => false,
					'html'  => FW_SEO_Redirect_Rules::PLACEHOLDER,
				],
			],
		],
		'site_move_box'      => [
			'title'   => __( 'Moving the whole site', 'fw' ),
			'type'    => 'box',
			'desc'    => __( 'When this site moves to a new domain, send every address to the same page there, with a permanent (301) redirect, so links and search rankings follow. The dashboard and the login page are never redirected, so you can always come back and switch it off, and administrators can still browse this site to check it.', 'fw' ),
			'options' => [
				'site_move_group' => [
					'type'    => 'group',
					'options' => [
						'site_move_to'      => [
							'label' => __( 'New address', 'fw' ),
							'desc'  => __( 'The new site\'s address, e.g. https://www.new-name.com. Only its domain (and a folder, if the new site lives in one) is used; each page keeps its own path.', 'fw' ),
							'type'  => 'text',
							'value' => '',
						],
						'site_move'         => [
							'label' => __( 'Redirect the whole site', 'fw' ),
							'desc'  => __( 'Switch on only once the new site is live, with the same addresses.', 'fw' ),
							'type'  => 'switch',
							'value' => false,
						],
						'site_move_preview' => [
							'type'  => 'html-full',
							'label' => false,
							'html'  => FW_SEO_Site_Move::PLACEHOLDER,
						],
					],
				],
			],
		],
	],
];

$options['notfound_tab'] = [
	'title'   => __( '404s', 'fw' ),
	'type'    => 'tab',
	'options' => [
		'notfound_box' => [
			'title'   => __( 'Pages not found', 'fw' ),
			'type'    => 'box',
			'desc'    => __( 'Every address that came back "not found" is evidence: an old link somewhere, a page that moved, a typo in a newsletter. Redirect the ones that matter, from here.', 'fw' ),
			'options' => [
				'notfound_group' => [
					'type'    => 'group',
					'options' => [
						'log_404'       => [
							'label' => __( 'Log pages not found', 'fw' ),
							'type'  => 'switch',
							'value' => true,
						],
						'notfound_log'  => [
							'type'  => 'html-full',
							'label' => false,
							'html'  => FW_SEO_Redirect_Rules::PLACEHOLDER_404,
						],
					],
				],
			],
		],
	],
];

$fw_seo_roles = [];
foreach ( ( function_exists( 'wp_roles' ) ? wp_roles()->get_names() : [] ) as $fw_seo_role => $fw_seo_role_name ) {
	$fw_seo_roles[ $fw_seo_role ] = translate_user_role( $fw_seo_role_name );
}

$options['general_tab']['options']['feed_box'] = [
	'title'   => __( 'RSS feed', 'fw' ),
	'type'    => 'box',
	'desc'    => __( 'Sites that copy content automatically usually copy it from your feed. A line linking back to the original, added to every feed item, means the copies link to you.', 'fw' ),
	'options' => [
		'feed_group' => [
			'type'    => 'group',
			'options' => [
				'rss_after' => [
					'label' => __( 'Add after each feed item', 'fw' ),
					'desc'  => __( 'Leave empty to add nothing. %%post_link%% is a link to the post, %%site_link%% a link to your site. Basic HTML is allowed.', 'fw' ),
					'type'  => 'textarea',
					'value' => '',
					'attr'  => [ 'placeholder' => 'The post %%post_link%% first appeared on %%site_link%%.' ],
				],
				'feeds_off' => [
					'label'   => __( 'Switch off these feeds', 'fw' ),
					'desc'    => __( 'WordPress publishes a feed for nearly everything, and few sites use more than the main one. A feed switched off is no longer linked from your pages, and its address redirects to the page it belongs to. The main feed always stays.', 'fw' ),
					'type'    => 'checkboxes',
					'value'   => [],
					'choices' => [
						'comments'  => __( 'Comment feeds (the site\'s and each post\'s)', 'fw' ),
						'taxonomy'  => __( 'Category, tag and other taxonomy feeds', 'fw' ),
						'author'    => __( 'Author feeds', 'fw' ),
						'search'    => __( 'Search result feeds', 'fw' ),
						'post_type' => __( 'Content type archive feeds', 'fw' ),
					],
				],
			],
		],
	],
];

$options['general_tab']['options']['images_box'] = [
	'title'   => __( 'Images', 'fw' ),
	'type'    => 'box',
	'desc'    => __( 'Alt text is how someone using a screen reader learns what an image shows, and how search engines read it. Written by a person it is best; a fallback is better than nothing.', 'fw' ),
	'options' => [
		'images_group' => [
			'type'    => 'group',
			'options' => [
				'image_alt_status'   => [
					'type'  => 'html-full',
					'label' => false,
					'html'  => FW_SEO_Alt::PLACEHOLDER,
				],
				'image_alt_fallback' => [
					'label'   => __( 'Images without alt text', 'fw' ),
					'desc'    => __( 'Used only for an image that has no alt text of its own. An empty alt written into the content on purpose (a decorative image) is left alone. Titles that are just a camera file name (IMG_4021) are skipped.', 'fw' ),
					'type'    => 'select',
					'value'   => 'none',
					'choices' => [
						'none'    => __( 'Leave them without', 'fw' ),
						'title'   => __( 'Use the image\'s title', 'fw' ),
						'caption' => __( 'Use the image\'s caption', 'fw' ),
						'post'    => __( 'Use the title of the page it is on', 'fw' ),
					],
				],
			],
		],
	],
];

$options['general_tab']['options']['ai_box'] = [
	'title'   => __( 'AI suggestions', 'fw' ),
	'type'    => 'box',
	'desc'    => __( 'With an AI connection set up (AI Assistant settings), each page\'s SEO box can suggest titles, descriptions, FAQ questions and key points, written from the page. These two lines make the suggestions sound like your site rather than like everyone\'s.', 'fw' ),
	'options' => [
		'ai_group' => [
			'type'    => 'group',
			'options' => [
				'ai_tone'     => [
					'label' => __( 'Tone', 'fw' ),
					'type'  => 'text',
					'value' => '',
					'attr'  => [ 'placeholder' => __( 'e.g. warm and plain-spoken, no hype', 'fw' ) ],
				],
				'ai_audience' => [
					'label' => __( 'Audience', 'fw' ),
					'type'  => 'text',
					'value' => '',
					'attr'  => [ 'placeholder' => __( 'e.g. families planning a seaside weekend', 'fw' ) ],
				],
			],
		],
	],
];

$options['general_tab']['options']['access_box'] = [
	'title'   => __( 'Access and removal', 'fw' ),
	'type'    => 'box',
	'options' => [
		'access_group' => [
			'type'    => 'group',
			'options' => [
				'editor_roles'        => [
					'label'   => __( 'Who can edit SEO fields', 'fw' ),
					'desc'    => __( 'The SEO box on posts and terms, its column in lists, and Quick Edit. Nothing ticked means everyone who can edit the content. Administrators can always edit them.', 'fw' ),
					'type'    => 'checkboxes',
					'inline'  => true,
					'value'   => [],
					'choices' => $fw_seo_roles,
				],
				'delete_on_uninstall' => [
					'label' => __( 'Delete all SEO data when the extension is deleted', 'fw' ),
					'desc'  => __( 'Every setting, every title and description written for a post or term, the redirects, the search log and the generated sharing cards. Deactivating never deletes anything; only deleting the extension does, and only with this on. It cannot be undone.', 'fw' ),
					'type'  => 'switch',
					'value' => false,
				],
			],
		],
	],
];

$options['sitemap_tab']['options']['media_sitemaps_box'] = [
	'title'   => __( 'Video and news sitemaps', 'fw' ),
	'type'    => 'box',
	'options' => [
		'media_sitemaps_group' => [
			'type'    => 'group',
			'options' => [
				'sitemap_video'      => [
					'label' => __( 'Video sitemap', 'fw' ),
					'desc'  => __( 'Lists every page with a video (from video elements, and YouTube, Vimeo or video files in the content) with the thumbnail, title and description search engines need to show a video result. A video with no thumbnail anywhere is left out.', 'fw' ),
					'type'  => 'switch',
					'value' => true,
				],
				'sitemap_news'       => [
					'label' => __( 'News sitemap', 'fw' ),
					'desc'  => __( 'Articles published in the last 48 hours, in Google News\' format. Only useful to a publisher accepted into Google News; leave it off otherwise.', 'fw' ),
					'type'  => 'switch',
					'value' => false,
				],
				'sitemap_news_name'  => [
					'label' => __( 'Publication name', 'fw' ),
					'desc'  => __( 'Exactly as it appears in Google News. Leave empty to use the site title.', 'fw' ),
					'type'  => 'text',
					'value' => '',
				],
				'sitemap_news_types' => [
					'label' => __( 'Content types that are news', 'fw' ),
					'desc'  => __( 'Comma-separated, e.g. post, or post, press_release.', 'fw' ),
					'type'  => 'text',
					'value' => 'post',
				],
			],
		],
	],
];

$options['sitemap_tab']['options']['html_sitemap_box'] = [
	'title'   => __( 'A sitemap page for visitors', 'fw' ),
	'type'    => 'box',
	'options' => [
		'html_sitemap_note' => [
			'type'  => 'html-full',
			'label' => false,
			'html'  => '<p>' . esc_html__( 'The sitemap above is for search engines. To give visitors a page listing your content, add this shortcode to any page (in a text element, or the editor):', 'fw' ) . '</p><p><code>[seo_sitemap]</code></p><p class="description">' . esc_html__( 'Each content type gets its own heading, pages are nested under their parents, and anything kept out of search results is left out. Options: types="page,post" for these types in this order, limit="50" for the newest 50 per type, exclude="12,48" to leave pages out.', 'fw' ) . '</p>',
		],
	],
];

$options['sitemap_tab']['options']['indexnow_box'] = [
	'title'   => __( 'IndexNow', 'fw' ),
	'type'    => 'box',
	'desc'    => __( 'Tell Bing, Yandex and the other search engines that share IndexNow about a page the moment it is published or updated, instead of waiting for them to crawl it again. No account or key is needed: the site proves it is yours with a small key file it serves itself. Google does not take part; it keeps finding changes through the sitemap.', 'fw' ),
	'options' => [
		'indexnow_group' => [
			'type'    => 'group',
			'options' => [
				'indexnow'        => [
					'label' => __( 'Send new and updated pages', 'fw' ),
					'type'  => 'switch',
					'value' => false,
				],
				'indexnow_status' => [
					'type'  => 'html-full',
					'label' => false,
					'html'  => FW_SEO_IndexNow::PLACEHOLDER,
				],
			],
		],
	],
];

$fw_seo_audit_types = [];
foreach ( FW_SEO_Locations::post_types() as $fw_seo_type => $fw_seo_type_label ) {
	$fw_seo_object                      = get_post_type_object( $fw_seo_type );
	$fw_seo_audit_types[ $fw_seo_type ] = $fw_seo_object ? $fw_seo_object->labels->name : $fw_seo_type;
}

$options['performance_tab'] = [
	'title'   => __( 'Search performance', 'fw' ),
	'type'    => 'tab',
	'options' => [
		'performance_tab_box' => [
			'title'   => __( 'How each page is doing in Google', 'fw' ),
			'type'    => 'box',
			'desc'    => __( 'Clicks, impressions, click-through and position for each page over the last four weeks, with the change against the four weeks before: what is gaining, what is slipping, and what people see but do not choose.', 'fw' ),
			'options' => [
				'performance_tab_report' => [
					'type'  => 'html-full',
					'label' => false,
					'html'  => FW_SEO_Performance::PLACEHOLDER,
				],
			],
		],
	],
];

$options['momentum_tab'] = [
	'title'   => __( 'Topic momentum', 'fw' ),
	'type'    => 'tab',
	'options' => [
		'momentum_tab_box' => [
			'title'   => __( 'Which topics are rising, and what to do about them', 'fw' ),
			'type'    => 'box',
			'desc'    => __( 'Import exports from Google Trends, and each topic is joined with what the site already knows: whether you get impressions for it, whether a page targets it, and whether your visitors search for it. Every row ends in one thing to do, including, for a topic that is fading, nothing.', 'fw' ),
			'options' => [
				'momentum_tab_report' => [
					'type'  => 'html-full',
					'label' => false,
					'html'  => FW_SEO_Trends::PLACEHOLDER,
				],
			],
		],
	],
];

$options['ai_tab'] = [
	'title'   => __( 'AI answers', 'fw' ),
	'type'    => 'tab',
	'options' => [
		'ai_tab_box' => [
			'title'   => __( 'Does an AI assistant mention you?', 'fw' ),
			'type'    => 'box',
			'desc'    => __( 'Ask an AI assistant the questions your customers ask, and see whether its answer mentions your site. Each run is a sample of what one assistant said on one day: answers vary between runs, people and model versions, so this is not a position you can track. Each question is a call on your own AI account, and runs only when you press the button.', 'fw' ),
			'options' => [
				'ai_visibility_questions' => [
					'type'  => 'textarea',
					'label' => __( 'Questions', 'fw' ),
					'desc'  => __( 'One per line, as a customer would ask them (up to 20). For example: "Where can I get a good crab linguine in Harbourtown?"', 'fw' ),
					'value' => '',
				],
				'ai_tab_report' => [
					'type'  => 'html-full',
					'label' => false,
					'html'  => FW_SEO_AI::PLACEHOLDER,
				],
			],
		],
	],
];

$options['audit_tab'] = [
	'title'   => __( 'Audit', 'fw' ),
	'type'    => 'tab',
	'options' => [
		'audit_box'      => [
			'title'   => __( 'What to fix, page by page', 'fw' ),
			'type'    => 'box',
			'desc'    => __( 'Every published page, checked for the problems that are worth fixing: missing or duplicated titles and descriptions, near-duplicate pages, heading structure, a focus keyphrase the page does not use, thin content. A list of specific pages for each, not a score.', 'fw' ),
			'options' => [
				'audit_report' => [
					'type'  => 'html-full',
					'label' => false,
					'html'  => FW_SEO_Audit::PLACEHOLDER,
				],
			],
		],
		'guardrails_box' => [
			'title'   => __( 'Pages generated in bulk', 'fw' ),
			'type'    => 'box',
			'options' => [
				'guardrails_group' => [
					'type'    => 'group',
					'options' => [
						'thin_noindex_types' => [
							'label'   => __( 'Keep thin pages out of search results', 'fw' ),
							'desc'    => __( 'For content made from a template and a list (a page per town, per service, per product): pages of these types are kept out of search results, and out of the sitemap, until they have more words than the thin-page threshold set under Going stale. Search engines judge mass-produced thin pages as a whole, so a few hundred empty ones can pull down the rest of the site.', 'fw' ),
							'type'    => 'checkboxes',
							'value'   => [],
							'choices' => $fw_seo_audit_types,
						],
					],
				],
			],
		],
	],
];

$options['alerts_tab'] = [
	'title'   => __( 'Alerts', 'fw' ),
	'type'    => 'tab',
	'options' => [
		'alerts_box'       => [
			'title'   => __( 'What needs attention', 'fw' ),
			'type'    => 'box',
			'desc'    => __( 'The few findings worth interrupting you for, collected once a day from the reports: a page many people see in Google but few click, a page whose impressions fell by half, an old page that still brings visitors, a page set to noindex that was getting clicks, a page just off the first page, a search on your site that keeps finding nothing. Each one names the page and what to do. They also show on the Dashboard and at the top of the page\'s SEO box.', 'fw' ),
			'options' => [
				'alerts_report' => [
					'type'  => 'html-full',
					'label' => false,
					'html'  => FW_SEO_Alerts::PLACEHOLDER,
				],
			],
		],
		'alerts_email_box' => [
			'title'   => __( 'Weekly email', 'fw' ),
			'type'    => 'box',
			'options' => [
				'alerts_email_group' => [
					'type'    => 'group',
					'options' => [
						'alerts_email'    => [
							'label' => __( 'Email the alerts every week', 'fw' ),
							'desc'  => __( 'Only when there is something to say, and never the same list twice. SEO moves in months, so weekly is the most often it is worth hearing about.', 'fw' ),
							'type'  => 'switch',
							'value' => false,
						],
						'alerts_email_to' => [
							'label' => __( 'Send to', 'fw' ),
							'desc'  => __( 'Leave empty to use the site\'s administration email address.', 'fw' ),
							'type'  => 'text',
							'value' => '',
						],
					],
				],
			],
		],
	],
];

$options['links_tab'] = [
	'title'   => __( 'Links', 'fw' ),
	'type'    => 'tab',
	'options' => [
		'links_box'          => [
			'title'   => __( 'Links on the site', 'fw' ),
			'type'    => 'box',
			'desc'    => __( 'Every link in your pages, read from the content and from the page builder (buttons, cards and accordions included), but not from the header, footer or menus, which link to everything. It answers which pages nothing links to, which affiliate links still pass authority, and which other sites you link to most.', 'fw' ),
			'options' => [
				'links_report' => [
					'type'  => 'html-full',
					'label' => false,
					'html'  => FW_SEO_Links::PLACEHOLDER,
				],
			],
		],
		'links_settings_box' => [
			'title'   => __( 'Affiliate links', 'fw' ),
			'type'    => 'box',
			'options' => [
				'links_settings_group' => [
					'type'    => 'group',
					'options' => [
						'links_affiliate_patterns' => [
							'label' => __( 'Also count as affiliate', 'fw' ),
							'desc'  => __( 'Links are already counted as affiliate when they go through the usual folders on your own site (/go/, /out/, /recommends/, /refer/…), through one of your short links, or carry an affiliate tracking parameter. Add your own, one per line: a folder on your site starting with / (for example /deals/), or a site\'s address (for example partner.example.com). Rebuild the report after changing this.', 'fw' ),
							'type'  => 'textarea',
							'value' => '',
						],
					],
				],
			],
		],
	],
];

$options['health_tab'] = [
	'title'   => __( 'Site health', 'fw' ),
	'type'    => 'tab',
	'options' => [
		'health_box' => [
			'title'   => __( 'Can search engines find the site?', 'fw' ),
			'type'    => 'box',
			'desc'    => __( 'The failures that hide a whole site from search are usually one switch, and nobody notices for weeks: the site left on "discourage search engines" after launch, the homepage set to noindex, robots.txt blocking everything, the sitemap gone. The site fetches its own pages the way a search engine would and checks.', 'fw' ),
			'options' => [
				'health_report' => [
					'type'  => 'html-full',
					'label' => false,
					'html'  => FW_SEO_Health::PLACEHOLDER,
				],
			],
		],
	],
];

$options['robots_tab'] = [
	'title'   => __( 'robots.txt', 'fw' ),
	'type'    => 'tab',
	'options' => [
		'robots_box'       => [
			'title'   => __( 'robots.txt', 'fw' ),
			'type'    => 'box',
			'desc'    => __( 'robots.txt tells crawlers which addresses not to fetch. WordPress writes it for you; add your own rules here. It controls crawling, not indexing: to keep a page out of search results, use its noindex switch and leave it crawlable.', 'fw' ),
			'options' => [
				'robots_group' => [
					'type'    => 'group',
					'options' => [
						'robots_block_search' => [
							'label' => __( 'Block internal search results', 'fw' ),
							'desc'  => __( 'Search result pages (/?s=…) can be generated without end and are thin copies of your content. Blocking them saves crawlers for the pages that matter.', 'fw' ),
							'type'  => 'switch',
							'value' => false,
						],
						'robots_ai_training'  => [
							'label'   => __( 'Block AI training crawlers', 'fw' ),
							'desc'    => __( 'These collect pages to train AI models. Blocking them does not affect search results. Whether to block them is your call.', 'fw' ),
							'type'    => 'checkboxes',
							'inline'  => true,
							'value'   => [],
							'choices' => array_combine( FW_SEO_Robots::AI_TRAINING, FW_SEO_Robots::AI_TRAINING ),
						],
						'robots_ai_search'    => [
							'label'   => __( 'Block AI search crawlers', 'fw' ),
							'desc'    => __( 'These fetch pages to answer questions in AI search and assistants. Blocking them keeps the site out of those answers, which also means no visitors from them.', 'fw' ),
							'type'    => 'checkboxes',
							'inline'  => true,
							'value'   => [],
							'choices' => array_combine( FW_SEO_Robots::AI_SEARCH, FW_SEO_Robots::AI_SEARCH ),
						],
						'robots_rules'        => [
							'label'           => __( 'Your own rules', 'fw' ),
							'desc'            => __( 'Each rule: which crawler (* means all), Allow or Disallow, and a path starting with / (use * as a wildcard and $ for "ends here"). A rule that would break the file is left out, and the preview says why.', 'fw' ),
							'type'            => 'addable-box',
							'value'           => [],
							'add-button-text' => __( 'Add rule', 'fw' ),
							'template'        => '{{= o.agent || "*" }} — {{= o.directive === "allow" ? "Allow" : "Disallow" }}: {{= o.path }}',
							'box-options'     => [
								'agent'     => [
									'type'  => 'text',
									'label' => __( 'Crawler', 'fw' ),
									'value' => '*',
								],
								'directive' => [
									'type'    => 'select',
									'label'   => __( 'Rule', 'fw' ),
									'value'   => 'disallow',
									'choices' => [
										'disallow' => __( 'Disallow', 'fw' ),
										'allow'    => __( 'Allow', 'fw' ),
									],
								],
								'path'      => [
									'type'  => 'text',
									'label' => __( 'Path', 'fw' ),
									'value' => '',
								],
							],
						],
					],
				],
			],
		],
		'robots_preview_box' => [
			'title'   => __( 'Preview', 'fw' ),
			'type'    => 'box',
			'options' => [
				'robots_preview' => [
					'type'  => 'html-full',
					'label' => false,
					// Filled in when the page is drawn: it runs the robots_txt filters,
					// which other plugins may register late.
					'html'  => FW_SEO_Robots::PLACEHOLDER,
				],
			],
		],
	],
];

$options['schema_tab'] = [
	'title'   => __( 'Structured data', 'fw' ),
	'type'    => 'tab',
	'options' => [
		'schema_box'       => [
			'title'   => __( 'Site identity', 'fw' ),
			'type'    => 'box',
			'desc'    => __( 'Structured data tells a search engine what your site IS, rather than what a page says. It is what produces a knowledge panel, a proper site name in results, and the sitelinks search box.', 'fw' ),
			'options' => [
				'schema_group' => [
					'type'    => 'group',
					'options' => [
						'schema_enabled'        => [
							'label' => __( 'Structured data', 'fw' ),
							'desc'  => __( 'Emit a JSON-LD graph describing the site and each page. Switch off if another plugin is already producing one.', 'fw' ),
							'type'  => 'switch',
							'value' => true,
						],
						'schema_represents'     => [
							'label'   => __( 'This site represents', 'fw' ),
							'desc'    => __( 'A company, shop or organisation, or a single person — a freelancer, author or consultant. The choice changes which entity the whole graph is built around.', 'fw' ),
							'type'    => 'select',
							'value'   => 'organization',
							'choices' => [
								'organization' => __( 'An organisation', 'fw' ),
								'person'       => __( 'A person', 'fw' ),
							],
						],
						'schema_name'           => [
							'label' => __( 'Name', 'fw' ),
							'desc'  => __( 'Leave empty to use the site title. Fill it in when the legal or brand name differs from what the site is called.', 'fw' ),
							'type'  => 'text',
							'value' => '',
						],
						'schema_alternate_name' => [
							'label' => __( 'Alternate name', 'fw' ),
							'desc'  => __( 'An abbreviation or a name people also search for.', 'fw' ),
							'type'  => 'text',
							'value' => '',
						],
						'schema_description'    => [
							'label' => __( 'Description', 'fw' ),
							'desc'  => __( 'One or two sentences about the organisation or person — not about the website.', 'fw' ),
							'type'  => 'textarea',
							'value' => '',
						],
						'schema_logo'           => [
							'label' => __( 'Logo', 'fw' ),
							'desc'  => __( 'Leave empty to use the logo from the Customizer. Square or wide both work; at least 112 pixels on the shortest side.', 'fw' ),
							'type'  => 'upload',
							'images_only' => true,
							'value' => '',
						],
						'schema_email'          => [
							'label' => __( 'Contact email', 'fw' ),
							'type'  => 'text',
							'value' => '',
						],
						'schema_phone'          => [
							'label' => __( 'Contact phone', 'fw' ),
							'type'  => 'text',
							'value' => '',
						],
					],
				],
			],
		],
		'schema_types_box' => [
			'title'   => __( 'What each content type is', 'fw' ),
			'type'    => 'box',
			'desc'    => __( 'Choose deliberately. Marking a company blog as a news article claims to be a news publisher, which is a claim search engines check.', 'fw' ),
			'options' => [
				'schema_types_group' => [
					'type'    => 'group',
					'options' => $schema_type_options,
				],
			],
		],
	],
];

$options['verification_tab'] = [
	'title'   => __( 'Verification', 'fw' ),
	'type'    => 'tab',
	'options' => [
		'verification_box' => [
			'title'   => __( 'Site verification', 'fw' ),
			'type'    => 'box',
			'desc'    => __( 'Paste only the code from the verification tag each service gives you, not the whole tag.', 'fw' ),
			'options' => [
				'verification_group' => [
					'type'    => 'group',
					'options' => [
						'verify_google'    => [
							'label' => __( 'Google Search Console', 'fw' ),
							'type'  => 'text',
							'value' => '',
						],
						'verify_bing'      => [
							'label' => __( 'Bing Webmaster Tools', 'fw' ),
							'type'  => 'text',
							'value' => '',
						],
						'verify_yandex'    => [
							'label' => __( 'Yandex Webmaster', 'fw' ),
							'type'  => 'text',
							'value' => '',
						],
						'verify_pinterest' => [
							'label' => __( 'Pinterest', 'fw' ),
							'type'  => 'text',
							'value' => '',
						],
						'verify_baidu'     => [
							'label' => __( 'Baidu Webmaster', 'fw' ),
							'type'  => 'text',
							'value' => '',
						],
						'verify_other'     => [
							'label' => __( 'Other services', 'fw' ),
							'desc'  => __( 'For a service without a box above, paste its whole verification tag here, one per line: <meta name="…" content="…">. Only meta tags with a name and content are output.', 'fw' ),
							'type'  => 'textarea',
							'value' => '',
						],
					],
				],
			],
		],
	],
];

$options['schema_tab']['options']['business_box'] = [
	'title'   => __( 'Local business', 'fw' ),
	'type'    => 'box',
	'options' => [
		'business_group' => [
			'type'    => 'group',
			'options' => [
				'business_type'        => [
					'label'   => __( 'Kind of business', 'fw' ),
					'desc'    => __( 'For a business people visit or call. Your organisation is then described as this kind of local business, with the address and hours below: what produces the opening-hours line, map pin and directions link next to a search result.', 'fw' ),
					'type'    => 'select',
					'value'   => '',
					'choices' => array_merge( [ '' => __( 'Not a local business', 'fw' ) ], array_combine( FW_SEO_Entities::BUSINESS_TYPES, FW_SEO_Entities::BUSINESS_TYPES ) ),
				],
				'business_street' => [
					'label' => __( 'Street address', 'fw' ),
					'type'  => 'text',
					'value' => '',
				],
				'business_city' => [
					'label' => __( 'Town or city', 'fw' ),
					'type'  => 'text',
					'value' => '',
				],
				'business_region' => [
					'label' => __( 'Region or state', 'fw' ),
					'type'  => 'text',
					'value' => '',
				],
				'business_postcode' => [
					'label' => __( 'Postcode', 'fw' ),
					'type'  => 'text',
					'value' => '',
				],
				'business_country' => [
					'label' => __( 'Country', 'fw' ),
					'type'  => 'text',
					'value' => '',
					'attr'  => [ 'placeholder' => 'GB' ],
				],
				'business_geo' => [
					'label' => __( 'Map position', 'fw' ),
					'type'  => 'text',
					'value' => '',
					'attr'  => [ 'placeholder' => '51.5072, -0.1276' ],
				],
				'business_price_range' => [
					'label' => __( 'Price range', 'fw' ),
					'type'  => 'text',
					'value' => '',
					'attr'  => [ 'placeholder' => '££' ],
				],
				'business_hours_mon' => [
					'label' => __( 'Monday', 'fw' ),
					'type'  => 'text',
					'value' => '',
					'desc'  => __( 'Like 09:00-17:00, or 09:00-12:30, 13:30-18:00 for a lunch break, or closed. Leave empty when unknown. A Business Info element with no hours of its own shows these.', 'fw' ),
					'attr'  => [ 'placeholder' => '09:00-17:00' ],
				],
				'business_hours_tue' => [
					'label' => __( 'Tuesday', 'fw' ),
					'type'  => 'text',
					'value' => '',
					'attr'  => [ 'placeholder' => '09:00-17:00' ],
				],
				'business_hours_wed' => [
					'label' => __( 'Wednesday', 'fw' ),
					'type'  => 'text',
					'value' => '',
					'attr'  => [ 'placeholder' => '09:00-17:00' ],
				],
				'business_hours_thu' => [
					'label' => __( 'Thursday', 'fw' ),
					'type'  => 'text',
					'value' => '',
					'attr'  => [ 'placeholder' => '09:00-17:00' ],
				],
				'business_hours_fri' => [
					'label' => __( 'Friday', 'fw' ),
					'type'  => 'text',
					'value' => '',
					'attr'  => [ 'placeholder' => '09:00-17:00' ],
				],
				'business_hours_sat' => [
					'label' => __( 'Saturday', 'fw' ),
					'type'  => 'text',
					'value' => '',
					'attr'  => [ 'placeholder' => '09:00-17:00' ],
				],
				'business_hours_sun' => [
					'label' => __( 'Sunday', 'fw' ),
					'type'  => 'text',
					'value' => '',
					'attr'  => [ 'placeholder' => 'closed' ],
				],
			],
		],
	],
];

/** Filters the SEO extension settings options tree. */
$options = apply_filters( 'fw_seo_settings_options', $options );
