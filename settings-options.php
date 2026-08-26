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
			'desc'  => __( 'Keep this whole group out of search results.', 'fw' ),
			'type'  => 'switch',
			'value' => false,
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
$fw_seo_box = static function ( $group_key, $title, $description, array $locations, callable $builder ) {
	$inner = [];

	foreach ( $locations as $location ) {
		if ( $location['group'] !== $group_key ) {
			continue;
		}

		$inner[ 'heading__' . FW_SEO_Locations::slug( $location['key'] ) ] = [
			'type'  => 'html-full',
			'label' => false,
			'value' => '<h4 class="fw-seo-settings-heading">' . esc_html( $location['label'] ) . '</h4>',
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
				'<code>' . esc_html( FW_SEO_Sitemap::index_url() ) . '</code>'
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
					],
				],
			],
		],
	],
];

/** Filters the SEO extension settings options tree. */
$options = apply_filters( 'fw_seo_settings_options', $options );
