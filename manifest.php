<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

$manifest = [];

$manifest['name']        = __( 'SEO', 'fw' );
$manifest['slug']        = 'unysonplus-seo';
$manifest['description'] = __(
	'Search engine optimisation: dynamic title and description templates with auto-generation, a live search-result preview, canonical URLs and indexing control.',
	'fw'
);

$manifest['version']    = '2.0.11';
$manifest['display']    = true;
$manifest['standalone'] = true;

// Repository Info
$manifest['github_update'] = 'UnysonPlus/UnysonPlus-SEO-Extension';
$manifest['github_repo']   = 'https://github.com/UnysonPlus/UnysonPlus-SEO-Extension';
$manifest['github_branch'] = 'master';

// Author Info
$manifest['author']     = 'UnysonPlus';
$manifest['author_uri'] = 'https://www.lastimosa.com.ph/unysonplus';

// Meta
$manifest['license']      = 'GPL-2.0-or-later';
$manifest['text_domain']  = 'fw';
$manifest['requires_php'] = '7.4';
$manifest['requires_wp']  = '5.8';

/**
 * Changelog
 * -----------------------------------------------------------------------------
 * 2.0.10 - Import from Yoast, Rank Math, SEOPress and All in One SEO.
 *
 *         An adoption feature, not a convenience one: a site with three
 *         hundred hand-written descriptions could not switch to this
 *         extension at all, however good the engine was.
 *
 *         The part that is NOT a mapping table is the template tags. Every one
 *         of these plugins has its own syntax, and Yoast's is almost identical
 *         to ours — which is the trap, because it makes the whole job look
 *         like a copy loop. Import a Rank Math title verbatim and `%title%` is
 *         an unrecognised tag, so it renders as NOTHING: the page silently
 *         loses its title and the owner finds out from Search Console weeks
 *         later. Tags are translated per source, longest-match first so
 *         `%%category_description%%` is not eaten by `%%category%%`.
 *
 *         A tag with no counterpart is LEFT IN the value and reported, never
 *         stripped. Deleting it would leave a title that reads fine and is
 *         quietly missing a word.
 *
 *         Three properties the tests pin, because each would do damage
 *         silently:
 *           - A second import never overwrites what you have written here
 *             since the first, unless overwrite is asked for explicitly.
 *           - Another plugin's "use the default" (Yoast writes 2 for index)
 *             does not become an explicit switch here — otherwise an import
 *             turns a site's defaults into hundreds of hard-coded overrides.
 *           - An empty source value is not imported as an empty override.
 *
 *         AIOSEO v4 keeps its data in its own table rather than post meta, so
 *         that source reads differently from the other three.
 *
 *         Batched over AJAX at 100 posts a time. One long request on a large
 *         site would race PHP's time limit, and a timeout halfway through is
 *         the worst available outcome: partly imported, with nothing saying
 *         how far it got.
 *
 *         NOTE: the meta key names come from each plugin's documented storage,
 *         not from a site with them installed. Worth confirming against real
 *         data before relying on it — a wrong key finds nothing rather than
 *         corrupting anything, so the failure mode is safe but silent.
 *
 * 2.0.9 - Settings move to Unyson+ → SEO.
 *
 *         They were reachable only through the Extensions manager, which is
 *         where you go to install things, not to configure them — every other
 *         configurable extension (Site Converter, Asset Optimizer, Shortcodes)
 *         already has its own entry in the Unyson+ menu.
 *
 *         Same schema, same store, same option ids: only the route changes, so
 *         nothing that reads a setting knows this happened. The Extensions
 *         card's Settings link is redirected to the new page via
 *         `fw_ext_manager_settings_url` so there is one settings screen rather
 *         than two that can disagree.
 *
 *         Native WordPress nav-tabs with hash deep-linking, matching the Site
 *         Converter page rather than inventing a third tab style.
 *
 *         The save merges over what is stored rather than writing the form
 *         wholesale, so a filtered-out tab cannot silently drop the keys it
 *         never rendered — and it re-fires
 *         `fw_extension_settings_form_saved:seo`, which the manager's own save
 *         emitted, so anything listening for a settings change keeps working.
 *
 * 2.0.8 - Structured data: an editable, linked JSON-LD graph.
 *
 *         One `@graph` with every node addressable by `@id`, so the page's
 *         publisher IS the identity node rather than a second copy of it, and
 *         one author across twenty posts is one entity rather than twenty
 *         unrelated strings.
 *
 *         Descriptions, titles and images are read from FW_SEO_Chain, never
 *         recomputed. Structured data that contradicts the meta description
 *         beside it is worse than none — it is the machine-readable copy, so
 *         it is the one that gets believed. Same consolidation as the share
 *         cards in 2.0.6.
 *
 *         Makes the social profile URLs stored in 2.0.6 actually do something:
 *         they become `sameAs`. Validation happens AFTER the filter, not
 *         before, so a filter cannot inject a bare handle that resolves to
 *         nothing.
 *
 *         Site identity is editable (Organization or Person, name, alternate
 *         name, description, logo, contact) and each content type chooses what
 *         it is. The type list is deliberately short: schema.org defines
 *         hundreds, and a long dropdown invites picking something specific and
 *         wrong — `NewsArticle` on a company blog is a claim to be a news
 *         publisher.
 *
 *         A plain WebPage carries no byline and no headline. Putting an author
 *         on a contact page is a small lie a rich result will repeat.
 *
 *         Claims `unysonplus_emit_schema` so the parent theme stands down. Its
 *         gate defaults to on unless a KNOWN SEO plugin is active, and its list
 *         does not include us, so without this a site would carry two
 *         Organization nodes claiming the same @id.
 *
 *         Note: FW_SEO_Schema::default_type() exists so the settings form can
 *         get a default without reading the settings. Reading them at file
 *         scope forces Unyson's option-type init before the page-builder
 *         registers its type, which fatals every builder page — the fourth
 *         encounter with that trap.
 *
 * 2.0.7 - SEO columns, filters and inline editing on the posts list.
 *
 *         The surface the storage design was chosen for: overrides live in
 *         discrete meta keys rather than the Unyson option blob precisely so
 *         WP_Query can see them, and until now nothing collected on that.
 *
 *         Columns show the RESOLVED value with the stage that produced it
 *         (Custom / Template / Generated / Fallback). Showing stored values
 *         would leave most rows blank on a correctly configured site, since
 *         most pages quite properly have no override — the column would read
 *         as "nothing set" for a site that is fully described.
 *
 *         Quick Edit gets its own nonce and its own handler rather than
 *         reusing the metabox save. The metabox bails when its nonce is
 *         absent, and that check is exactly what stops an inline edit — a form
 *         that never rendered the SEO fields — from wiping every override on
 *         the post. Sharing the path would mean weakening it.
 *
 *         Filters are limited to what SQL can answer: not indexed, has custom
 *         SEO, following the template, no custom share image. Deliberately NOT
 *         "pages with no description" — a description is resolved at render
 *         time from an override, a template or the content, so it is not a
 *         stored fact and cannot be queried without materialising every
 *         resolved value into an index. The per-row badges cover the visible
 *         page honestly; a site-wide answer needs the audit index.
 *
 *         Fixes a latent cache bug this surfaced: FW_SEO_Chain keyed its cache
 *         on spl_object_id(), which is unique only among LIVE objects. Once a
 *         context was collected its id was reused and the next context read
 *         the previous one's values. Invisible on the front end, which
 *         resolves one context per request; immediate in a list, where row
 *         three showed row one's title. The chain now holds a reference to
 *         each cached context so ids cannot be recycled.
 *
 * 2.0.6 - Open Graph and Twitter cards, taken over from the parent theme.
 *
 *         Until now titles, descriptions and canonicals came from this
 *         extension while the share card came from the theme's own separate
 *         calculation — so editing a page's SEO title changed the search result
 *         and not the Facebook card. Both are now resolved from one place.
 *
 *         Social fields do not duplicate the title logic: og_title's TEMPLATE
 *         stage is the resolved `title` field, and twitter_title's is og_title.
 *         Inheritance therefore costs nothing, a site-wide template change
 *         reaches the share card automatically, and the per-page panel can show
 *         four fields rather than eight while still emitting both networks.
 *
 *         The image is found by walking the page-builder tree (FW_SEO_Image),
 *         not by regexing rendered HTML: chosen image, then featured image,
 *         then the first real picture on the page, then a site-wide default.
 *         Candidates below Open Graph's 200px floor are SKIPPED rather than
 *         emitted — the first image on a page is usually a logo, and a card
 *         cropped from a 441x84 logo is a smear. Only images we host can be
 *         measured, so a remote URL is trusted rather than fetched; a featured
 *         image is honoured whatever its size, because it was chosen
 *         deliberately and second-guessing that is a different kind of wrong.
 *
 *         The theme gained `unysonplus_emit_meta_social` to match the two
 *         filters it already had, so the hand-over stays surface by surface and
 *         a site never carries two competing og:title tags.
 *
 * 2.0.5 - Three editor fixes, two of them bugs that would have cost data or time.
 *
 *         Inserting a tag always landed at the far left, whatever the caret was
 *         doing. Clicking a toolbar button moves focus out of the editor and
 *         destroys the live selection; calling focus() afterwards puts the caret
 *         back at position zero, and a "is the selection inside my editor?"
 *         check believes that position because it genuinely is. The editor now
 *         RECORDS the caret while it still has focus and inserts there, the
 *         buttons cancel their own mousedown so focus never leaves in the first
 *         place, and each insert returns the new caret so consecutive inserts
 *         advance instead of stacking on one spot. The tag browser, which really
 *         does take focus, uses the same remembered caret.
 *
 *         Tabs are now the framework's own `tab` container rather than
 *         hand-rolled nav-tab markup. The theme's Page Settings metabox is built
 *         that way, and a metabox that styles its own tabs sits next to one that
 *         does not and reads as broken.
 *
 *         Two hardening measures went in alongside that switch. The tab
 *         container supports lazy rendering — with it on, an unopened tab's
 *         fields live in a data attribute rather than in the form — so these
 *         tabs opt out and render eagerly, which keeps the DOM predictable and
 *         the metabox is small enough that it costs nothing. And the save path
 *         now consults the raw submission, so an option id that never reached
 *         the browser is left alone instead of being written over with the
 *         default fw_get_options_values_from_input() supplies for it.
 *
 *         To be clear about what that second guard is and is not for: the
 *         framework already injects every lazy tab into the form on submit
 *         (backend-options.js, initAllTabs on submit.fw-tabs), so normal saves
 *         were never at risk — measured on a page metabox, 6 rendered fields
 *         become 32 at submit time. The guard covers the case where that
 *         injection does not run at all, such as a JS error earlier in the page,
 *         where the difference between "cleared" and "never rendered" would
 *         otherwise be invisible to the server.
 *
 * 2.0.4 - Template tags now render as chips in the editor, the way AIOSEO and
 *         Yoast present them: "%%title%% %%sep%% %%sitename%%" reads as
 *         Title · Separator · Site title. The chip editor is progressive
 *         enhancement over the real control, which stays in the DOM holding the
 *         value and the form name — the editor serialises into it on every
 *         keystroke, so nothing but the element that submits ever holds the
 *         value, and with the script absent the field is still a plain working
 *         text input. Chips are contenteditable="false" so the caret steps over
 *         them and backspace removes a whole tag rather than eating one % and
 *         quietly breaking it.
 *
 *         The metabox is now tabbed (General / Advanced). It had grown tall
 *         enough to push the editor off screen once it carried a preview, two
 *         template fields and the full robots matrix; it is now 648px. Panels
 *         are hidden with a class rather than omitted, so every field still
 *         submits from a tab the user never opened — hiding a field is
 *         presentation, not exclusion, and a field absent from the form would
 *         make the server derive its default and wipe the stored value. The
 *         robots matrix sits behind a "use the settings for this content type"
 *         switch, so the common case is one control instead of eight.
 *
 * 2.0.3 - The SEO title and meta description now ship PRE-FILLED and editable,
 *         matching how Yoast and All in One SEO present them: the title carries
 *         its template with the %%tags%% intact so the pattern itself can be seen
 *         and edited, and the description carries the generated prose. This
 *         replaces the 2.0.1 placeholder, which was correct but read as an empty,
 *         unconfigured field.
 *
 *         Pre-filling is only safe because an untouched pre-fill is never stored.
 *         Each field ships with a hidden companion recording exactly what was
 *         rendered into it; on save, a value still equal to that (whitespace
 *         aside) is discarded rather than written, so the post stays bound to its
 *         template. Editing the field stores a real override; clearing it, or
 *         restoring the original text, removes that override again. Without this,
 *         pre-filling would hand every post a frozen copy of the template and a
 *         later site-wide title change would silently update nothing.
 *
 *         The comparison is against the value we rendered, not a freshly computed
 *         one: by `save_post` the content has already changed, so regenerating the
 *         description would produce different text than the form carries and every
 *         save of an edited post would look like a deliberate customisation. The
 *         live preview receives the same pristine value, so an unedited field is
 *         reported as "From the template" rather than "Your text".
 *
 * 2.0.2 - XML sitemaps, rebuilt as a provider registry. A provider declares how
 *         many entries it has and how to fetch one page of them; chunking, the
 *         index, the XSL stylesheet, the rewrite rules and the noindex filtering
 *         are handled once, centrally. So a portfolio, an events archive or any
 *         third-party content type joins the sitemap through the same
 *         registration the built-in post types and taxonomies use — there is no
 *         privileged path. Built-ins ship for the homepage, every public post
 *         type and every public taxonomy.
 *
 *         Anything marked noindex is excluded automatically, whether that is a
 *         single page in its own SEO panel or a whole content type in the
 *         settings — a sitemap should never advertise a URL whose page tells
 *         crawlers to ignore it. That exclusion is a plain meta query, which is
 *         only possible because the overrides live in discrete meta keys rather
 *         than inside a serialised option blob.
 *
 *         Image sitemaps are included by default, listing the featured image
 *         plus on-site images found in the content (capped per URL, data URIs
 *         and off-site sources skipped). Sitemaps carry an XSL stylesheet so a
 *         person opening one gets a readable table rather than raw XML. Core's
 *         own sitemap at /wp-sitemap.xml is switched off by default so the site
 *         has one sitemap rather than two competing ones, and core's now-dead
 *         robots.txt line pointing at it is stripped.
 *
 *         Two things the old sitemap did are deliberately not carried over.
 *         Search-engine pinging: Google retired its ping endpoint in 2023 and
 *         Bing followed, so both now discover sitemaps from robots.txt — the
 *         ping is a request guaranteed to do nothing. And priority/changefreq:
 *         Google has said for years that it ignores both, so emitting them is
 *         noise that also invites tuning which cannot have an effect.
 *
 * 2.0.1 - The per-post SEO title and description now show their resolved value
 *         as the field's placeholder. The fields stay empty on purpose — an
 *         empty box means "use the template, or generate one", and pre-filling
 *         the value would write a frozen copy of the template onto every post as
 *         a real override, silently detaching it so later site-wide title
 *         changes do nothing.
 *
 * 2.0.0 - Rebuilt from scratch. The extension was the original ThemeFuse Unyson
 *         SEO module and had not had feature work in years: it emitted a title,
 *         a meta description and meta keywords, and nothing else — no canonical
 *         URL, no indexing controls, and no way to produce a description without
 *         a human typing one on every single post. It is now a small engine plus
 *         thin feature modules.
 *
 *         The engine is four pieces. FW_SEO_Context resolves the current request
 *         once into a typed object, replacing the loose location array whose keys
 *         varied by branch. FW_SEO_Tags is a registry of lazy resolvers: a tag is
 *         computed only when a template actually uses it, it receives the context
 *         rather than guessing from globals, and prefix-matched families let one
 *         registration serve unlimited tags — %%cf_<key>%% for any custom field,
 *         %%tax_<taxonomy>%% for any taxonomy, %%user_<key>%% for any author meta.
 *         Modifiers (|truncate, |words, |upper, |lower, |capitalize) post-process
 *         a resolved value. Tags that resolve empty now collapse together with
 *         their orphaned separator, so a template never renders "Title |  | Site".
 *         FW_SEO_Chain is the single override -> template -> auto -> fallback
 *         resolver every field shares, so adding a field is configuration rather
 *         than another copy of the same ladder. FW_SEO_Head collects every head
 *         tag into one deduplicated, deterministically ordered bag before
 *         printing, which structurally prevents the duplicate-description bug the
 *         old blog-page branch shipped.
 *
 *         On top of that: descriptions and titles are auto-generated by default,
 *         with a builder-aware extractor that walks the page-builder tree for real
 *         prose instead of flattening rendered markup; canonical URLs are emitted
 *         with a per-post override; robots directives cover noindex, nofollow,
 *         noarchive, nosnippet, max-snippet, max-image-preview and
 *         max-video-preview; and the editor gets a tabbed SEO metabox with a live
 *         Google-style preview, tag insert buttons, a searchable all-tags browser
 *         and counters measured on the resolved text rather than the raw template.
 *
 *         Not carried over from 1.0.12: the XML sitemap module, which is being
 *         rebuilt separately against the new provider registry. Meta keywords are
 *         gone — no search engine has used them for over a decade. Per-post data
 *         now lives in discrete _fw_seo_* meta keys rather than the Unyson option
 *         blob, so it can be queried; there is deliberately no migration from
 *         1.0.12.
 */
