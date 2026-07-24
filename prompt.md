# Teatatu News — Reusable AI Development Prompt

This file distills the design contract behind the Teatatu News WordPress
plugin into a prompt you can hand to an AI coding assistant for **any future
change** — a new feature, a bug fix, a refactor — so the result stays
consistent with how the plugin was originally built instead of drifting
into a different style each time.

## How to use this

1. Paste the **"Prompt template"** section below into a fresh AI session.
2. Fill in the `<describe the change>` blank with what you actually want.
3. Point the AI at the `teatatu-news/` folder in this repo as the existing
   codebase to work from — it should read the relevant `includes/*.php`
   files before changing them, not regenerate the plugin from scratch.

---

## Prompt template

```
You are extending an existing WordPress plugin called "Teatatu News"
(folder: teatatu-news/, prefix: teatatu_news_). Read the existing code
before changing it — do not regenerate files from scratch.

CHANGE REQUESTED: <describe the change>

Preserve these invariants unless the change explicitly asks to alter them:

1. Standard WordPress tables only. No custom database tables, ever.
   News content lives in wp_posts/wp_postmeta; Sources and Categories are
   taxonomies in wp_terms/wp_term_taxonomy/wp_termmeta.

2. Everything is prefixed teatatu_news_ — functions, hooks, meta keys,
   options, capabilities. No exceptions.

3. Capability model stays exactly as scoped:
   - teatatu_news_agent (the AI-agent role) gets: read,
     edit_teatatu_news_items, delete_teatatu_news_items,
     assign_teatatu_news_sources, assign_teatatu_news_categories,
     manage_teatatu_news_sources, manage_teatatu_news_categories.
   - It never gets publish_teatatu_news_items,
     edit_others_teatatu_news_items, or edit_published_teatatu_news_items.
     Draft-and-hand-off is the permanent design, not a temporary
     restriction — do not add a way for the agent role to publish.
   - Administrator/Editor get full News capabilities (see
     includes/capabilities.php).

4. Multisite-correct by construction:
   - Post type, taxonomies, meta boxes, the maintenance page, and REST
     routes are registered per-site via init/rest_api_init — never touch
     that pattern to make something "network-wide" unless it's meant to
     aggregate (in which case follow the Master Site + switch_to_blog()
     pattern already used in includes/shortcodes.php).
   - Roles are per-site. Any change touching roles/capabilities must stay
     provisioned through the same get_sites() + switch_to_blog() loop used
     in teatatu-news.php's activation hook (gated on the activation
     callback's own $network_wide flag — a plain per-site "Activate" on a
     multisite install must NOT loop every site, only a "Network Activate"
     does), plus the wp_initialize_site hook for brand-new sites. Don't
     reintroduce a generic "loop every site whenever is_multisite()" helper
     — that was tried and removed because it silently ignored $network_wide
     and would provision sites that were never asked for.
   - Non-multisite installs must keep working via the same code paths,
     just running once against the current site
     (see teatatu_news_get_option() / is_multisite() branches).
   - Admin-post.php form handlers ALWAYS post to the plain
     admin_url( 'admin-post.php' ) — never network_admin_url( 'admin-post.php' ).
     WordPress core has no wp-admin/network/admin-post.php file at all (verified
     directly against core's file listing); that URL 404s. This was a real,
     confirmed bug: the Master Site setting in includes/settings.php used
     network_admin_url('admin-post.php') for its form action, so every
     save silently 404'd and nothing ever reached teatatu_news_handle_save_settings()
     — "Master Site can be selected but never actually saves." The plain
     admin-post.php works identically from a Network Admin-rendered page
     (Network Admin only ever runs on the network's main site anyway), and
     every other form in this plugin (News items, Sources/Categories, RSS
     Feeds) already used it successfully — rely on current_user_can() inside
     the handler for the actual permission boundary, not which URL variant
     was used to reach it. (network_admin_url('settings.php') for a
     *redirect* target, by contrast, is fine and correct — settings.php
     genuinely exists under wp-admin/network/ — this gotcha is specific to
     admin-post.php.)

5. Images are link-only. This was a deliberate reversal of an earlier
   design (v1.0.0 originally sideloaded images into the Media Library via
   WP-Cron) — do not reintroduce local image storage:
   - Editors/agents only ever submit a URL, and that URL is exactly what
     gets stored (_teatatu_news_image_url post meta,
     teatatu_news_source_default_image term meta) and exactly what gets
     served. teatatu_news_get_image_url() resolves post's raw URL ->
     Source's raw default URL -> empty string, in that order — nothing
     else. It takes only a $post_id argument; there is no local size/
     attachment concept to parameterize.
   - No wp_safe_remote_get()/download_url()/media_handle_sideload() calls
     anywhere in this plugin. No WP-Cron job fetches or writes image files.
     No wp-admin/includes/{file,image,media}.php requires.
   - The `teatatu_news` CPT does not support 'thumbnail'. There is no
     Featured Image, no `_thumbnail_id`, no `featured_media` REST field,
     and no add_image_size() registration for News images — all deleted
     on purpose. Don't re-add 'thumbnail' support or a registered image
     size as a shortcut for "resizing" a remote URL; that requires
     downloading the file, which is exactly what this invariant forbids.
   - Nothing to clean up on post/term delete or on uninstall — images were
     never stored locally, so there's no attachment-deletion step anywhere
     in the codebase for News images. Don't add one.
   - If a future change wants thumbnail-style resizing, that means
     re-introducing local storage — treat it as a product decision to
     confirm with the user first, not a routine implementation detail.

6. Security baseline on every new admin/REST code path:
   - Any handler that accepts an ID for an existing News post/term must
     verify the object is actually ours (get_post_type() === 'teatatu_news',
     or let wp_update_term()/wp_delete_term() do the taxonomy check they
     already do internally) before acting on it — current_user_can() alone
     isn't enough, since a user's capability check for an arbitrary ID
     resolves against whatever post type that ID actually is, not the type
     the form assumes. Never pass 'post_type' in a wp_update_post() array
     for an ID that wasn't just verified — it will silently retype the
     target post.
   - current_user_can() checks scoped to the narrowest capability that
     fits (never fall back to a broad check like manage_options for
     something News-specific).
   - Nonces on every form/admin-post.php handler.
   - esc_url()/esc_html()/esc_attr() on all output, esc_url_raw()/
     sanitize_text_field() on all input.

7. Shared rendering/aggregation functions are shared, not duplicated:
   - Per-site and network-wide shortcodes both call
     teatatu_news_render_items() for markup.
   - The network shortcodes and the /wp-json/teatatu-news/v1/network-feed
     REST endpoint both call teatatu_news_get_network_feed_items() for
     data — if the change touches cross-site aggregation, change it once,
     there.
   - The network cache key in teatatu_news_get_network_feed_items() is
     deliberately built from only the attributes that change which items
     get fetched (count/source/category/orderby/order/exclude_sites) —
     never the full $atts array. If a new shortcode attribute changes the
     underlying query, add it to that allow-list; if it's purely visual (a
     new show_* toggle, a new display-only option), leave it out, or the
     network shortcodes, their _variant siblings, and the REST endpoint
     will stop sharing cache entries for identical data.

8. Auto-updates stay GitHub-Releases-based, using only wp_remote_get() and
   core update filters (pre_set_site_transient_update_plugins,
   plugins_api, upgrader_source_selection) — no bundled update library.

9. RSS import is draft-first and human-gated *by default*, with two
   explicit, per-feed, off-by-default opt-outs — this was a deliberate,
   user-requested reversal of an earlier stricter invariant ("never
   auto-publish, ever"); the capability boundary is what's non-negotiable
   now, not the draft-first behavior itself:
   - Default behavior, unchanged: a new feed item (new article URL)
     becomes a `draft` teatatu_news post, and a change to an
     already-published item becomes a `_teatatu_news_rss_pending_update`
     for a human to Apply/Dismiss via the News tab.
   - `_teatatu_news_rss_auto_publish` (per feed, postmeta on the
     teatatu_news_rssfeed post): when true, teatatu_news_rss_create_post()
     sets `post_status` to `publish` instead of `draft` for new items from
     that feed only. Read in teatatu_news_rss_process_feed() and threaded
     through teatatu_news_rss_import_item().
   - `_teatatu_news_rss_auto_apply_updates` (per feed): when true,
     teatatu_news_rss_maybe_flag_pending_update() calls
     teatatu_news_rss_apply_fields_to_post() directly instead of stashing
     a pending update, for that feed's already-published items only.
     teatatu_news_rss_apply_fields_to_post() is shared with the manual
     "Apply" admin-post.php handler — same effect either way, keep it that
     way rather than duplicating the update logic.
   - If the matching post is still unpublished (draft/pending), the
     importer keeps updating its Title/Excerpt/Image in place regardless of
     either toggle — nothing has been reviewed yet, so there's nothing to
     protect. This branch (in teatatu_news_rss_import_item(), the
     `'publish' !== get_post_status( $existing_id )` case) ALSO promotes
     the item to `publish` when auto_publish is true — not just brand new
     items. This was a real bug (fixed): auto_publish originally only
     applied in teatatu_news_rss_create_post(), so any item already
     imported once (as draft) before the toggle was turned on stayed a
     draft forever, since it would never be treated as "new" again on
     later syncs. The toggle's promise is "every item from this feed ends
     up published" — not just ones lucky enough to be new at the exact
     moment the toggle happened to already be on — so re-check this branch
     if auto_publish ever seems to stop working again; it's the most
     likely place a similar regression would hide.
   - Each item's original publish date (RSS `pubDate` / Atom `published`,
     by default; see the field-mapping bullet below for the option to
     source it elsewhere) is applied via the shared
     teatatu_news_rss_apply_pub_date() helper, which sets both
     `post_date`/`post_date_gmt` (so News Items and every shortcode's
     already-default `orderby=date&order=DESC` sort correctly by it with
     no other code changes needed) and `_teatatu_news_rss_pub_date`
     postmeta (an explicit, auditable copy). Threaded through every write
     path: create, unpublished-resync, auto-apply, and the pending-update
     array (`pub_date_gmt` key — guard with `isset()` when reading it back,
     since pending updates stashed before this existed won't have the
     key). Only applies going forward; an item imported before this
     existed keeps its original import-time post_date until it's next
     touched by one of those paths.
   - Content mapping covers five fields, all resolved through
     `$field_map` (an array keyed `title`/`excerpt`/`image`/
     `read_more_url`/`pub_date`, each value a `source`/`tag`/`attr_type`/
     `attr_value` tuple — see teatatu_news_rss_default_field_map() and
     teatatu_news_rss_field_source_options() in includes/rss-feeds.php).
     Each field has its own resolver in includes/rss-importer.php
     (teatatu_news_rss_resolve_text_field(),
     teatatu_news_rss_resolve_image_field(),
     teatatu_news_rss_resolve_read_more_url(),
     teatatu_news_rss_resolve_pub_date_field()) and its own "whole-value"
     default source that needs no selector — `rss_title`/
     `rss_description` for text, `rss_image` (enclosure/media) for image,
     `rss_link` (SimplePie's own permalink) for Read More URL,
     `rss_pub_date` (teatatu_news_rss_get_item_pub_date_gmt(), SimplePie's
     get_date() — safe to treat as GMT directly since WordPress forces
     PHP's default timezone to UTC) for Publish Date. Any other source
     (rss_description/rss_content/page, or the corresponding item field
     for Read More URL/Publish Date) requires a tag + class/id selector;
     if one isn't set, the resolver falls back to the field's own default
     rather than returning empty — Read More URL falls back to the feed's
     real item link, Publish Date falls back to empty (which means "keep
     whatever post_date already applies," not the current time). Adding a
     sixth mappable field means adding it to all three of
     teatatu_news_rss_default_field_map(),
     teatatu_news_rss_field_source_options(), and the `$field_labels`
     array in teatatu_news_render_rss_feeds_tab() — they're intentionally
     kept in sync by hand, not generated from one list.
   - The RSS Feeds tab's "Preview Example Values" button
     (teatatu_news_ajax_preview_rss_mapping(), a wp_ajax_ handler in
     includes/rss-importer.php, capability-gated on
     manage_teatatu_news_rss_feeds and nonce-checked against
     'teatatu_news_rss_preview_mapping') fetches the feed's most recent
     item and resolves it through the exact same
     teatatu_news_rss_resolve_*() functions the real import path calls —
     it does not have (and must never grow) its own parallel resolution
     logic, or the preview could show something an actual import
     wouldn't produce. It reads the mapping straight from the unsaved
     form via teatatu_news_rss_sanitize_field_map_from_request()
     (includes/rss-feeds.php), sanitized identically to what Save does —
     shared with teatatu_news_handle_save_rss_feed() rather than
     duplicated, so preview and save can never silently disagree about
     what a given form submission means.
   - Read More URL is mappable independently of deduplication — remapping
     it to scrape a different link out of the article must never change
     which existing post an incoming item matches against. This is why
     teatatu_news_rss_create_post() writes a separate, never-remapped
     `_teatatu_news_rss_source_link` postmeta (always the feed item's own
     permalink, never the mapped/displayed Read More URL) and
     teatatu_news_rss_find_existing_post() matches on that field, with an
     OR-fallback to `_teatatu_news_read_more_url` for posts imported
     before `_teatatu_news_rss_source_link` existed. Don't collapse these
     back into one field — doing so would make changing the Read More URL
     mapping silently break dedupe (either creating duplicate posts or
     stalling updates) for every feed using it.
   - The capability boundary that must never move: `manage_teatatu_news_rss_feeds`
     alone is not sufficient to turn either toggle on. Writing
     `_teatatu_news_rss_auto_publish` requires the saving user to also
     hold `publish_teatatu_news_items`; writing
     `_teatatu_news_rss_auto_apply_updates` requires
     `edit_published_teatatu_news_items` (see the two separate
     current_user_can() gates around each update_post_meta() call in
     teatatu_news_handle_save_rss_feed(), and the matching visibility
     gates in the form). If a user can't see/change one of these fields,
     the save handler leaves the existing stored value untouched rather
     than resetting it — don't change that to "unchecked box = force off",
     it would let a lower-privileged edit silently downgrade a setting a
     higher-privileged user deliberately turned on. Never let
     manage_teatatu_news_rss_feeds by itself grant publishing power.
   - `manage_teatatu_news_rss_feeds` (feed configuration: URL, cadence,
     mapping) is granted to Administrator/Editor only, never to
     teatatu_news_agent — configuring automated imports is a
     site-configuration capability, not a content-drafting one. The
     `teatatu_news_rssfeed` CPT is `public => false`, `show_ui => false`,
     `show_in_rest => false` on purpose; it has no native wp-admin screen
     and is never reachable over REST. Its slug is exactly 20 characters —
     WordPress's hard limit for `post_type` (the column is `VARCHAR(20)`).
     `teatatu_news_rss_feed` (21 chars) was the original name and silently
     failed every insert under strict SQL mode ("Could not insert post
     into the database.") while only logging a doing_it_wrong notice, not
     an error — easy to miss. Any new post type prefixed `teatatu_news_`
     (13 chars) has only 7 characters of budget left; check the length
     before naming one.
   - Article-page HTML (the "Article Page" mapping source) is fetched with
     wp_safe_remote_get() and a size cap, same as any other plugin-initiated
     fetch of a URL that ultimately traces back to a third party — even
     though the feed URL itself came from a trusted admin, the individual
     article links inside that feed did not.
   - The element/class/id selector (includes/rss-importer.php,
     teatatu_news_rss_select_element()) interpolates the tag and class/id
     directly into an XPath query string. This is only safe because both
     are already sanitized to a closed character set before storage
     (preg_replace to [a-z0-9] for the tag, sanitize_html_class() for the
     value) and sanitized again defensively at read time — don't feed
     unsanitized input into that function.
   - The recurring cron tick (`teatatu_news_rss_cron_tick`) is
     re-verified on every 'init', not just at activation — WP-Cron
     schedules can silently go missing outside of deactivation. Keep that
     safety net if you touch the scheduling code.

10. HTML Feeds (includes/html-feeds.php, includes/html-importer.php) is a
    second, parallel importer for sites with a news listing page but no
    RSS/Atom feed — scraping the page directly instead of parsing a feed
    file. It deliberately mirrors RSS Feeds' shape (own CPT
    `teatatu_news_webfeed`, exactly 20 characters — see point 9's slug-
    length note, same trap applies to any new post type) and reuses,
    rather than duplicates, everything from RSS import that's actually
    generic:
    - All DOM work — teatatu_news_rss_select_element(),
      _node_inner_html(), _node_image_url(), _node_link_url(),
      _node_date_gmt(), _clean_html() — plus teatatu_news_rss_fetch_page_html()
      and teatatu_news_rss_apply_pub_date(), all in includes/rss-importer.php.
      None of that is RSS-specific despite the `rss_` prefix; it's plain
      HTML-fragment parsing and a generic post_date writer. Don't fork a
      second copy of any of these for an HTML-feed-specific tweak — fix or
      extend the shared one (this is how the CSS background-image fallback
      in teatatu_news_rss_node_image_url() ended up benefiting both
      importers at once).
    - The pending-update mechanism entirely: teatatu_news_rss_maybe_flag_pending_update(),
      teatatu_news_rss_apply_fields_to_post(), the `_teatatu_news_rss_pending_update`
      meta key, and the News tab's existing Apply/Dismiss admin-post.php
      handlers. A pending update is just "fields waiting for review,"
      regardless of which importer produced them — HTML-sourced updates
      show up and get applied/dismissed through the exact same UI with no
      HTML-specific code at all. Don't build a second pending-update
      queue/UI for HTML feeds.
    - `_teatatu_news_rss_pub_date` postmeta, for the same reason — "the
      feed's own reported publish date" is equally true whichever importer
      resolved it.
    The one thing HTML feeds need that RSS doesn't: a required "Repeating
    Item" selector (tag + class/id, stored as
    `_teatatu_news_html_item_selector`) identifying which element repeats
    once per news item on the page — teatatu_news_html_select_all_items()
    finds every match (unlike teatatu_news_rss_select_element(), which
    only ever returns the first). Each match's inner HTML becomes the
    per-item fragment that Content Mapping's five fields are then resolved
    against, via teatatu_news_html_resolve_*() functions using a simpler
    'item'/'page' source model than RSS's four sources (there's no
    SimplePie title/description/content split for a scraped fragment).
    Because there's no separate feed-native "item link" the way SimplePie
    provides one for RSS, HTML feeds have no
    `_teatatu_news_rss_source_link`-style decoupling: Read More URL can
    only be sourced from within the repeating item (never the linked
    page — there'd be nothing to fetch yet) and doubles as this item's
    dedupe identity directly, stored as `_teatatu_news_html_source_link`
    and matched by teatatu_news_html_find_existing_post(). If it can't be
    resolved, the item is skipped — there's nothing to dedupe against.
    Title/Excerpt/Publish Date always require an Element/Class-ID with no
    "whole item" fallback (unlike RSS's whole-value defaults) since one
    repeating item mixes title/date/excerpt/links together with no single
    field that's "just the text"; Image/Read More URL default to "search
    the whole item for its first image/link" when unmapped, which is safe
    precisely because those look for one specific element type rather than
    concatenating text.

11. Capability grants are database state, not code — add_cap()/add_role()
    only ever run at activation (teatatu_news_provision_site(), called from
    the activation hook, wp_initialize_site, and — for drift-correction —
    teatatu_news_maybe_upgrade_caps() in includes/capabilities.php, which
    re-runs teatatu_news_add_role_and_caps() on 'admin_init' whenever
    TEATATU_NEWS_VERSION has changed since it last ran on that site). This
    exists because a site whose plugin files get replaced in place
    (updated without deactivating/reactivating — the normal workflow when
    testing local code changes against an already-active install) never
    otherwise receives capabilities added in a later version; the gate
    just silently denies access with no code-level bug to find. If you add
    a new capability to teatatu_news_editor_capabilities() or
    teatatu_news_agent_capabilities(), bumping TEATATU_NEWS_VERSION (which
    point 12 below already requires you to do) is what makes existing
    sites actually receive it — don't skip the version bump thinking it's
    only about the update-checker (see point 12 below).

12. Whatever you change, update in lockstep:
    - teatatu-news/readme.txt (user-facing docs, REST/shortcode reference)
    - teatatu-news/SKILL.md, if the change touches anything an AI agent
      interacts with over the REST API
    - The plugin's Version header in teatatu-news.php, and note the change
      under == Changelog == in readme.txt
    - The in-admin Shortcodes tab (teatatu_news_render_shortcodes_tab() in
      includes/admin-page.php), if the change adds, removes, or changes the
      meaning/default of a shortcode attribute — it's a second, independent
      copy of the same reference readme.txt carries, specifically so
      editors don't have to leave wp-admin; a change to one without the
      other goes stale silently since nothing enforces they match.

Ask before changing: the capability boundaries in point 3, the
no-custom-tables constraint in point 1, the off-by-default state of
_teatatu_news_rss_auto_publish/_teatatu_news_rss_auto_apply_updates, or
the current_user_can() gating around who can turn either on (point 9) —
those are deliberate product decisions, not implementation details. The
existence of the two toggles themselves is not up for debate; it was a
direct, explicit user request — don't revert to the old hard "never
auto-publish" rule on your own judgment.
```

---

## Architecture map (for the AI's own orientation)

| File | Responsibility |
|---|---|
| `teatatu-news.php` | Plugin header, activation/deactivation, per-site provisioning, new-site hook |
| `includes/capabilities.php` | Role/capability definitions, plus the version-gated re-sync on 'admin_init' |
| `includes/multisite.php` | Master Site resolution, network aggregate-cache registry |
| `includes/settings.php` | Network/single-site settings page (Master Site, GitHub repo/token, retention flag) |
| `includes/post-type.php` | `teatatu_news` CPT registration (no 'thumbnail' support), `the_content` filter |
| `includes/taxonomies.php` | `teatatu_news_source` / `teatatu_news_category` registration, term meta, capability arrays |
| `includes/meta-boxes.php` | Post meta registration (REST-exposed), "News Details" meta box UI + save |
| `includes/image-url.php` | `teatatu_news_get_image_url()` — link-only URL resolution, no download/storage |
| `includes/admin-columns.php` | Sortable admin list-table columns |
| `includes/admin-page.php` | Top-level maintenance screen: News/Sources/Categories tabs + admin-post.php handlers, and the read-only Shortcodes reference tab |
| `includes/rest-api.php` | The one custom REST route: `/teatatu-news/v1/network-feed` |
| `includes/shortcodes.php` | Per-site + network shortcodes, shared renderer, shared aggregation/cache function |
| `includes/updater.php` | GitHub-release-based self-hosted auto-updater |
| `includes/rss-feeds.php` | `teatatu_news_rssfeed` CPT (admin-only, no native UI, never in REST), "RSS Feeds" tab + CRUD |
| `includes/rss-importer.php` | Cron scheduling/tick, fetch_feed(), DOM element/class/id extraction (shared with HTML Feeds), dedupe + create/update/pending-update logic (incl. auto-publish/auto-apply and pub-date handling), Apply/Dismiss handlers |
| `includes/html-feeds.php` | `teatatu_news_webfeed` CPT (admin-only, no native UI, never in REST), "HTML Feeds" tab + CRUD, Repeating Item selector |
| `includes/html-importer.php` | Cron scheduling/tick, page fetch + repeating-element extraction, per-item field resolution, create/update/pending-update logic (reuses rss-importer.php's generic helpers throughout) |
| `uninstall.php` | Retention-aware cleanup, run standalone (main plugin file not loaded) |

## Original build spec

The full original specification this plugin was built from is preserved in
version control history / the initiating conversation. This file is a
distilled, forward-looking summary for *future* changes — treat the
architecture map and invariants above as the source of truth for "how this
plugin works today," not a historical record of every original requirement.
