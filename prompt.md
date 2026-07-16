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

9. RSS import stays draft-first and human-gated, mirroring the agent
   capability model in point 3 even though it isn't the REST agent path:
   - A new feed item (new article URL) always becomes a `draft`
     teatatu_news post — same as every other creation path in this plugin.
     Never auto-publish.
   - If the matching post is still unpublished (draft/pending), the
     importer may update it in place freely — nothing has been reviewed
     yet, so there's nothing to protect.
   - The instant a matching post is `publish`, the importer must NEVER
     write to it directly. Freshly-fetched values go into
     `_teatatu_news_rss_pending_update` postmeta only; a human applies or
     dismisses via the News tab. Don't add a "trust the feed, auto-apply"
     mode — that's the whole point of this design.
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

10. Capability grants are database state, not code — add_cap()/add_role()
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
    point 10 below already requires you to do) is what makes existing
    sites actually receive it — don't skip the version bump thinking it's
    only about the update-checker (see point 11 below).

11. Whatever you change, update in lockstep:
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
no-custom-tables constraint in point 1, or the draft-first/human-gated
re-import behavior in point 9 — those are deliberate product decisions,
not implementation details.
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
| `includes/rss-importer.php` | Cron scheduling/tick, fetch_feed(), DOM element/class/id extraction, dedupe + create/update/pending-update logic, Apply/Dismiss handlers |
| `uninstall.php` | Retention-aware cleanup, run standalone (main plugin file not loaded) |

## Original build spec

The full original specification this plugin was built from is preserved in
version control history / the initiating conversation. This file is a
distilled, forward-looking summary for *future* changes — treat the
architecture map and invariants above as the source of truth for "how this
plugin works today," not a historical record of every original requirement.
