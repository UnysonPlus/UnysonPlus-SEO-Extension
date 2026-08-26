<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The XSL stylesheet browsers apply when a person opens a sitemap.
 *
 * Crawlers ignore it entirely. It exists because the people who open a sitemap
 * are almost always trying to answer "is my content actually in here?", and raw
 * XML answers that badly.
 */

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<xsl:stylesheet version="1.0"
	xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
	xmlns:sm="http://www.sitemaps.org/schemas/sitemap/0.9"
	xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">

	<xsl:output method="html" encoding="UTF-8" indent="yes"/>

	<xsl:template match="/">
		<html lang="en">
			<head>
				<meta charset="UTF-8"/>
				<meta name="viewport" content="width=device-width, initial-scale=1"/>
				<title><?php echo esc_html( get_bloginfo( 'name' ) ); ?> &#8212; <?php esc_html_e( 'XML Sitemap', 'fw' ); ?></title>
				<style>
					:root { color-scheme: light dark; }
					body {
						margin: 0;
						padding: 2rem 1.5rem;
						background: #f6f7f7;
						color: #1d2327;
						font: 14px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
					}
					.wrap { max-width: 1100px; margin: 0 auto; }
					h1 { margin: 0 0 .25rem; font-size: 1.5rem; }
					p.meta { margin: 0 0 1.5rem; color: #646970; }
					table { width: 100%; border-collapse: collapse; background: #fff;
						border: 1px solid #dcdcde; border-radius: 4px; overflow: hidden; }
					th, td { padding: .6rem .9rem; text-align: left; border-bottom: 1px solid #f0f0f1;
						font-size: 13px; vertical-align: top; }
					th { background: #f6f7f7; font-weight: 600; color: #50575e;
						text-transform: uppercase; letter-spacing: .04em; font-size: 11px; }
					tr:last-child td { border-bottom: 0; }
					td.num { color: #8c8f94; width: 3.5rem; }
					td.date, td.imgs { color: #646970; white-space: nowrap; }
					a { color: #2271b1; text-decoration: none; word-break: break-all; }
					a:hover { text-decoration: underline; }
					@media (prefers-color-scheme: dark) {
						body { background: #1d2327; color: #f0f0f1; }
						table { background: #2c3338; border-color: #3c434a; }
						th { background: #32373c; color: #c3c4c7; }
						th, td { border-color: #3c434a; }
						a { color: #72aee6; }
					}
				</style>
			</head>
			<body>
				<div class="wrap">
					<xsl:apply-templates/>
				</div>
			</body>
		</html>
	</xsl:template>

	<!-- The index -->
	<xsl:template match="sm:sitemapindex">
		<h1><?php esc_html_e( 'XML Sitemap Index', 'fw' ); ?></h1>
		<p class="meta">
			<xsl:value-of select="count(sm:sitemap)"/>
			<xsl:text> </xsl:text>
			<?php esc_html_e( 'sitemaps. This file is for search engines; the links below are the individual sitemaps.', 'fw' ); ?>
		</p>
		<table>
			<tr>
				<th>#</th>
				<th><?php esc_html_e( 'Sitemap', 'fw' ); ?></th>
				<th><?php esc_html_e( 'Last modified', 'fw' ); ?></th>
			</tr>
			<xsl:for-each select="sm:sitemap">
				<tr>
					<td class="num"><xsl:value-of select="position()"/></td>
					<td>
						<a href="{sm:loc}"><xsl:value-of select="sm:loc"/></a>
					</td>
					<td class="date"><xsl:value-of select="sm:lastmod"/></td>
				</tr>
			</xsl:for-each>
		</table>
	</xsl:template>

	<!-- One sitemap -->
	<xsl:template match="sm:urlset">
		<h1><?php esc_html_e( 'XML Sitemap', 'fw' ); ?></h1>
		<p class="meta">
			<xsl:value-of select="count(sm:url)"/>
			<xsl:text> </xsl:text>
			<?php esc_html_e( 'URLs.', 'fw' ); ?>
		</p>
		<table>
			<tr>
				<th>#</th>
				<th><?php esc_html_e( 'URL', 'fw' ); ?></th>
				<th><?php esc_html_e( 'Images', 'fw' ); ?></th>
				<th><?php esc_html_e( 'Last modified', 'fw' ); ?></th>
			</tr>
			<xsl:for-each select="sm:url">
				<tr>
					<td class="num"><xsl:value-of select="position()"/></td>
					<td>
						<a href="{sm:loc}"><xsl:value-of select="sm:loc"/></a>
					</td>
					<td class="imgs">
						<xsl:if test="count(image:image) &gt; 0">
							<xsl:value-of select="count(image:image)"/>
						</xsl:if>
					</td>
					<td class="date"><xsl:value-of select="sm:lastmod"/></td>
				</tr>
			</xsl:for-each>
		</table>
	</xsl:template>

</xsl:stylesheet>
