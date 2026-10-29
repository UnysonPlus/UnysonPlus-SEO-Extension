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

$manifest['version']    = '2.0.31';
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
// Requirements
$manifest['requirements'] = array(
	'framework' => array(
		'min_version' => '3.0.49', // class FW_Admin_App — the admin app shell these screens render inside.
	),
);

$manifest['license']      = 'GPL-2.0-or-later';
$manifest['text_domain']  = 'fw';
$manifest['requires_php'] = '7.4';
$manifest['requires_wp']  = '5.8';

/**
 * Changelog
 * -----------------------------------------------------------------------------
 * 2.0.26 - Search Console: your own data, shown where you can act on it.
 *
 *          Setup is a seven-step walkthrough in the box itself, with links
 *          straight to the Google Cloud pages and a copy button for the
 *          redirect URI, rather than two fields and a sentence. The two
 *          things that otherwise send people to a search engine are named
 *          BEFORE they are hit: the unverified-app warning Google shows for
 *          your own client, and the consent screen having to be PUBLISHED.
 *
 *          An app left on "Testing" has its refresh tokens cancelled by
 *          Google after exactly seven days, so a connection made last week
 *          dies for a reason that has nothing to do with the site. The
 *          connection date is stored and that signature is recognised, so
 *          the error says to publish the app instead of "connect again" —
 *          which would send someone round the same loop next week.
 *
 *          Read-only, connected with an OAuth client the SITE OWNER creates.
 *          There is no shared client, no proxy and no account with us — the
 *          site talks to Google directly and the tokens never leave its
 *          database. That costs ten minutes of setup and is the only
 *          arrangement consistent with everything else here working without
 *          an account.
 *
 *          The headline report is "seen but not clicked": pages with plenty
 *          of impressions and few clicks. That is a title-and-description
 *          problem far more often than a ranking one, and both are editable
 *          two tabs away — which is the whole reason this belongs in an SEO
 *          extension rather than in an analytics plugin. Pages under 100
 *          impressions are excluded, because below that one click swings the
 *          rate and the row says nothing.
 *
 *          Average position is deliberately NOT presented as a ranking.
 *          Search Console averages it across everyone who saw the result,
 *          which varies by location, device and personalisation. It is a
 *          trend; calling it "your position" would be a lie the UI tells
 *          every time it loads.
 *
 *          Three things that fail quietly and are therefore pinned by tests:
 *          a renewal response does NOT resend the refresh token, so absorbing
 *          one must keep the stored value or the connection dies an hour
 *          later; `state` is single-use, so an authorisation cannot be
 *          replayed; and the window ends three days behind today, because
 *          Google finishes a day late and asking for today asks for a gap.
 *
 *          Error reasons are kept distinct rather than collapsed into
 *          "something went wrong": revoked, rejected credentials, no access
 *          to the property, rate limited and network each lead somewhere
 *          different.
 *
 * 2.0.24 - Core Web Vitals from real visits, and the tab becomes Reports.
 *
 *          The Asset Optimizer already optimises for these metrics — it
 *          preloads the likely LCP image and hoists blocks against a measured
 *          layout shift — but has no way of knowing whether any of it worked.
 *          It optimises blind. Google's Chrome UX Report answers that for
 *          free, for the site's own pages, from 28 days of real visits.
 *
 *          Field data, not a lab score: it says whether the site is fast for
 *          the people using it, rather than on one simulated connection. The
 *          75th percentile is shown because that is the number Google judges
 *          on — an average is flattered by a handful of fast visits.
 *
 *          The important case is the ordinary one: a page or site without
 *          enough traffic is simply NOT IN CrUX, and Google answers 404.
 *          That is not an error and must never render as a zero, because a
 *          zero reads as a result. It is reported as "not enough traffic", said
 *          not to be a fault, and cached anyway so a small site does not
 *          re-ask every page load for an answer that will not change today. A
 *          rejected key is reported differently again — the two are easy to
 *          confuse and lead somewhere different.
 *
 *          Nothing is fetched while rendering the settings page: readings come
 *          from a twelve-hour cache, and checking is a button. A remote call
 *          on page load is a slow settings screen on somebody else's bad day.
 *
 *          The Content tab becomes "Reports" and now carries three: what
 *          visitors could not find, what has not been touched, and how fast
 *          pages really are.
 *
 * 2.0.23 - Content report: pages going stale, beside the site-search gaps.
 *
 *          The most useful editorial work on an established site is rarely
 *          writing new pages; it is finding the ones that quietly stopped
 *          being true. Paid platforms sell this as content-decay reporting
 *          built on traffic history. Age and length are what can be known
 *          WITHOUT that history, and they answer "what should I look at this
 *          month" well enough to be worth having — the report says so in as
 *          many words, because a page can be four years old and perfectly
 *          correct.
 *
 *          Word count comes from FW_SEO_Content, so a builder page is measured
 *          by the prose in its TREE. Counting post_content would measure
 *          rendered markup and every builder page would read as thin, which
 *          would make the whole report noise. Counted by splitting on
 *          whitespace rather than str_word_count, which is ASCII-minded and
 *          undercounts accented or non-Latin prose badly.
 *
 *          The "Site search" tab becomes "Content" and carries both reports:
 *          what visitors could not find, and what has not been touched. Two
 *          questions about the same thing, and one tab rather than two.
 *
 *          Staleness is counted site-wide (a date query, cheap); thinness is
 *          NOT, because it would mean parsing every post's prose. A number we
 *          cannot afford to compute honestly is worse than no number.
 *
 * 2.0.22 - Share images drawn for pages that have none.
 *
 *          The image ladder ended at a site-wide default, so every text page
 *          without a picture shared one image and every link to the site
 *          looked identical in a feed. A card is now drawn from the page title
 *          at the rung BETWEEN "nothing on the page" and "the site default" —
 *          so a page that has a picture, or a chosen image, is never
 *          overridden. Tests pin exactly that.
 *
 *          No font is bundled. Nothing in the plugin or theme ships a text
 *          face, and carrying one means carrying a licence and 300KB for a
 *          feature many sites will not use. The font is FOUND instead: a file
 *          chosen in the settings, then a theme's own, then the usual system
 *          paths (DejaVu and Liberation on Linux, Arial on Windows). With none
 *          found, nothing is drawn and the ladder simply falls through — the
 *          feature declines rather than drawing something unreadable.
 *
 *          Type is sized by MEASURING the glyphs: the largest size whose
 *          wrapped title actually fits the available height wins. An earlier
 *          version bumped short titles to a bigger size while keeping the
 *          smaller size's line cap, which truncated titles that had room to
 *          spare.
 *
 *          Cards are cached by a signature over the title, colours, font and
 *          render version, so a retitled or recoloured page gets a new file
 *          rather than a stale one, and `save_post` sweeps the old ones.
 *
 *          `fw_seo_card_font` filters the RESULT of the font search, so it can
 *          supply a font, replace the one found, or return '' to switch
 *          drawing off entirely.
 *
 * 2.0.21 - Site-search gap report: what visitors look for and do not find.
 *
 *          WordPress discards what people type into a site's own search box.
 *          That is the only keyword data about a site's ACTUAL audience that
 *          costs nothing and is not an estimate — and a search returning no
 *          results is a visitor saying, in their own words, that a page is
 *          missing. Paid keyword tools approximate this from outside with
 *          licensed clickstream data; the site has the real thing.
 *
 *          Aggregated by phrase in its own table, one row per phrase with a
 *          counter, not one row per search: "forty people searched for this
 *          and found nothing" is the finding, not the forty timestamps.
 *          Phrases are normalised (case, whitespace, tags) so three spellings
 *          are one row, and recorded on `wp` rather than `pre_get_posts`
 *          because by then the result count is known — and the count is the
 *          whole point.
 *
 *          Reported in two lists: found nothing, and found only one or two
 *          (often a page exists but does not use the words people search
 *          with). Each gap offers a pre-titled new page.
 *
 *          Two things it deliberately does NOT do. It does not record
 *          crawlers — search endpoints are hammered, and a log full of bot
 *          queries answers nothing — and it does not keep phrases for ever:
 *          retention defaults to 90 days, because search text is typed by
 *          visitors who paste things they did not mean to, and a phrase last
 *          seen two years ago is not a content gap any more. Nothing is ever
 *          sent off the site.
 *
 * 2.0.17 - Bulk edit in a spreadsheet: CSV export and import.
 *
 *          A different job from the plugin importer beside it. That one reads
 *          Yoast and friends; this round-trips your OWN values, so three
 *          hundred descriptions can be written where they can all be seen at
 *          once — or handed to whoever writes them, who will never log in.
 *
 *          Each row carries the RESOLVED title and description as read-only
 *          context beside the editable override. Without seeing what a page
 *          emits today you cannot tell which rows are worth touching, and you
 *          end up overriding pages that were already fine.
 *
 *          Import is a site-wide write, so it is fenced:
 *            - Rows match on post ID only. Titles repeat and slugs change; a
 *              fuzzy match writes one page's description onto another.
 *            - It writes SEO META ONLY — never creates a post, never deletes
 *              one, never touches content, title, status, dates or terms. A row
 *              for a post that is not here is reported and ignored; an id that
 *              is absent from the file is never visited.
 *            - Only columns PRESENT in the file are considered, so a hand-built
 *              two-column sheet cannot blank everything else.
 *            - Within a present column an empty cell CLEARS the override, which
 *              matches "empty means follow the template" everywhere else — and
 *              because the export writes the current override into the cell, a
 *              blank-in/blank-out round trip changes nothing. Clears are counted
 *              separately in the preview so they are never silent.
 *            - Nothing is written until a preview has been shown and confirmed.
 *
 *          Two details that quietly ruin CSV in practice are handled: the file
 *          carries a UTF-8 BOM (without it a spreadsheet reads it as the system
 *          codepage and mangles every accent), and any value beginning =, +, -
 *          or @ is tab-prefixed on the way out and unprefixed on the way back.
 *          Those characters make a cell a FORMULA, so an exported description
 *          starting with a dash would otherwise run code on the machine of
 *          whoever opened the file.
 *
 * 2.0.15 - Stand down when another SEO plugin is active, instead of competing.
 *
 *          Yoast, Rank Math, SEOPress, AIOSEO, The SEO Framework and Slim SEO
 *          are detected by the constant/class each defines — which survives a
 *          renamed folder or an mu-plugin install — and the extension then
 *          attaches NO front-end hooks at all.
 *
 *          Not "emit less": attach nothing. Several of those hooks have side
 *          effects beyond printing — resolving the context removes core's
 *          rel_canonical, and the robots filter takes over wp_robots — so
 *          half-running would damage the page we are standing aside from. Two
 *          canonicals is worse than either alone, because a search engine is
 *          entitled to ignore both.
 *
 *          The admin side keeps working, so every stored value survives and
 *          returns the moment the other plugin is switched off. An admin notice
 *          explains what stood down and offers the two ways out (keep theirs,
 *          or import from it and keep ours); dismissal is remembered against
 *          the plugin it was about, so installing a DIFFERENT one later raises
 *          it again rather than inheriting a dismissal that was never about it.
 *
 *          `fw_seo_should_emit` forces emission back on for a site that has
 *          silenced the other plugin itself.
 *
 *          The new shared detector `fw_upw_seo_plugin_active()` lives in core
 *          (framework/includes/seo-plugin-detect.php) because the default
 *          extension seed needs it before any extension loads: SEO is now
 *          seeded ON for a new site, but only when nothing else is already
 *          doing the job.
 *
 * 2.0.14 - AI Assistant abilities. With the AI Assistant extension active: seo-get-page
 *         (what a page really outputs and where each value comes from),
 *         seo-update-page (set / clear per-page overrides, undoable) and seo-audit
 *         (missing, long, auto-generated or duplicate titles / descriptions, noindex)
 *         — see includes/ai-abilities.php.
 *
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
