<?php if ( ! defined( 'FW' ) ) {
    die( 'Forbidden' );
}

$manifest = array();

// Basic Info
$manifest['name']        = __( 'SEO', 'fw' );
$manifest['slug']        = 'unysonplus-seo';
$manifest['description'] = __(
    'This extension will enable you to have a fully optimized WordPress website by adding optimized meta titles, keywords, and descriptions.',
    'fw'
);

$manifest['version']     = '1.0.11';
$manifest['display']     = true;
$manifest['standalone']  = true;

// Repository Info
$manifest['github_update'] = 'UnysonPlus/UnysonPlus-SEO-Extension';
$manifest['github_repo']   = 'https://github.com/UnysonPlus/UnysonPlus-SEO-Extension';
$manifest['github_branch'] = 'master';
$manifest['uri']           = 'http://manual.unyson.io/en/latest/extension/seo/index.html#content';

// Author Info
$manifest['author']     = 'UnysonPlus';
$manifest['author_uri'] = 'https://www.lastimosa.com.ph/unysonplus';

// Meta
$manifest['license']      = 'GPL-2.0-or-later';
$manifest['requires_php'] = '7.4';
$manifest['requires_wp']  = '5.8';
