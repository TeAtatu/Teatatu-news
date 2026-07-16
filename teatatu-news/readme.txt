=== Teatatu News ===
Contributors: teatatu
Tags: news, custom post type, rest api, multisite, ai agent
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPL-3.0
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Aggregates news items into a "News" custom post type with Source/Category
taxonomies, link-only image handling (no local downloads), REST API access
for AI agents, and per-site or network-wide shortcodes. Standard WordPress
tables only.

== Description ==

Teatatu News adds a "News" content type to WordPress for aggregating
short-form news items — a title, an excerpt, a source, a category, a link to
the original article, and an image. It is built for a mixed editorial
workflow: a human editor working through wp-admin, and/or an AI agent
drafting items over the REST API, with every agent-created item requiring
human review before it goes live.

Everything lives in standard WordPress database tables (`wp_posts`,
`wp_postmeta`, `wp_terms`, `wp_term_taxonomy`, `wp_term_relationships`,
`wp_termmeta`, `wp_users`/`wp_usermeta`). No custom tables, and no local
media storage — images are always referenced by URL and hotlinked from
their original source, never downloaded into the Media Library.

= Key features =

* Custom post type `teatatu_news`, REST base `/wp/v2/news`
* Two dedicated taxonomies: `teatatu_news_source` (REST base `news-sources`)
  and `teatatu_news_category` (REST base `news-categories`) — kept fully
  separate from the site's normal blog categories
* Images are link-only: editors and agents submit a URL, and the plugin
  always stores and serves that URL directly — nothing is downloaded or
  copied into the Media Library
* A `teatatu_news_agent` role for AI agents: can draft and edit its own
  unpublished News items over the REST API, but can never publish directly
  — see `SKILL.md` for the full agent workflow
* A dedicated maintenance screen (Teatatu News menu) for non-technical
  editors to manage News items, Sources, and Categories without touching
  the standard post-list UI
* Per-site shortcodes (`[teatatu_news_list]`, `[teatatu_news_grid]`,
  `[teatatu_news_latest]`) and, on multisite, network-wide equivalents that
  aggregate News from every site in the network onto one designated Master
  Site
* Full multisite support: network activation provisions every site (and
  every site created afterward), while News content itself stays per-site
  in that site's own standard tables
* Self-hosted auto-updates from GitHub Releases — no WordPress.org listing
  required
* An RSS Feeds importer: add any number of feed URLs, each on its own check
  cadence, with configurable per-field content mapping — see == RSS Import ==
  below

= Multisite =

Network-activate this plugin from **Network Admin > Plugins**. Each site in
the network gets its own independent News post type, taxonomies, and
maintenance screen, backed by that site's own tables — there is no shared
network table for News content.

Roles are per-site in WordPress, so the `teatatu_news_agent` role is
provisioned on every site individually (on network activation, and
automatically on any site created afterward). An agent authenticates against
one specific site and is scoped to that site only — it is never given
network Super Admin access.

Configure a **Master Site** at **Network Admin > Settings > Teatatu News**.
The network-wide shortcodes and the network REST endpoint only produce
output on that site.

On a single-site (non-multisite) install, everything above still works —
the Master Site setting simply doesn't apply and is hidden, and settings
live at **Settings > Teatatu News** instead of Network Admin.

== REST API for AI agents ==

An AI agent authenticates as a normal WordPress user with the
`teatatu_news_agent` role, using a
[WordPress Application Password](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/)
(core since 5.6) over HTTP Basic Auth. See `SKILL.md` in the plugin root for
the full step-by-step workflow, error-handling guidance, and constraints —
it's written to be handed directly to an AI agent (e.g. as a Claude Skill).

= Endpoints =

* `GET/POST /wp-json/wp/v2/news` — list / create News items
* `GET/PUT/DELETE /wp-json/wp/v2/news/<id>` — read / update / delete a News item
* `GET/POST /wp-json/wp/v2/news-sources` — list / create Sources
* `GET/POST /wp-json/wp/v2/news-categories` — list / create News Categories
  (distinct from `/wp/v2/categories`, the site's regular blog category
  endpoint, which this plugin does not touch)
* `GET /wp-json/teatatu-news/v1/network-feed` — cross-site aggregated News
  as JSON (multisite only, responds only on the configured Master Site)

The meta fields `_teatatu_news_read_more_url` and `_teatatu_news_image_url`
are readable/writable in the `meta` object of the `/wp/v2/news` endpoint.
`_teatatu_news_image_url` is the only source of truth for a News item's
image — it is never downloaded, copied, or mirrored into the Media Library,
so there is no separate `featured_media` field to consult.

= Example: creating a draft News item =

The `teatatu_news_agent` role can never publish directly — any request with
`"status": "publish"` is rejected with `403 Forbidden`. New items are always
created as `draft` (or `pending`) for a human editor to review.

`curl -u "news-agent:xxxx xxxx xxxx xxxx xxxx xxxx" \`
`  -X POST "https://example.com/wp-json/wp/v2/news" \`
`  -H "Content-Type: application/json" \`
`  -d '{`
`    "title": "Example headline",`
`    "excerpt": "One or two sentence summary.",`
`    "status": "draft",`
`    "teatatu_news_source": [12],`
`    "teatatu_news_category": [7],`
`    "meta": {`
`      "_teatatu_news_read_more_url": "https://example.com/original-article",`
`      "_teatatu_news_image_url": "https://example.com/images/photo.jpg"`
`    }`
`  }'`

== Shortcodes ==

The same reference below is also available in-admin at **Teatatu News >
Shortcodes**, including the Source/Category slugs actually available on the
current site.

= Per-site =

`[teatatu_news_list]`, `[teatatu_news_grid]`, `[teatatu_news_latest]`

Shared attributes (all optional): `count`, `columns` (grid only, 1-6,
default 3), `source` (Source slug), `category` (Category slug), `orderby`,
`order`, `show_image`, `show_excerpt`, `show_source`, `show_category`,
`show_date`, `link_target`, `display`.

`display="summary"` is a convenience preset for a minimal card — header,
excerpt, thumbnail, and the Read More link only — it forces
`show_source`/`show_category`/`show_date` off regardless of their own
values (default `display` is `"full"`, which honors every `show_*`
attribute as normal). `show_image`/`show_excerpt` still apply on top of a
summary, e.g. `display="summary" show_excerpt="false"` drops the excerpt
too. Renders with an extra `teatatu-news-summary` CSS class for styling.

= Network-wide (Master Site only, multisite) =

`[teatatu_news_network_list]`, `[teatatu_news_network_grid]`,
`[teatatu_news_network_latest]`

Same attributes as above, plus `exclude_sites` (comma-separated blog IDs)
and `site_label` (true/false). These produce no output on any site other
than the configured Master Site; admins see an explanatory notice instead.

== RSS Import ==

**Teatatu News > RSS Feeds** (requires the `manage_teatatu_news_rss_feeds`
capability, granted to Administrator/Editor by default — never to the
`teatatu_news_agent` role) lets you add any number of RSS/Atom feeds, each
independently configured:

* **Feed URL** and a **cadence** (minimum 5 minutes) — a recurring WP-Cron
  tick checks every *active* feed, but only actually re-fetches one once its
  own cadence has elapsed since it was last checked. As with every other
  WP-Cron job in WordPress, timing is best-effort and depends on site
  traffic.
* **Active / Paused** — pause a feed without losing its configuration or
  deleting the News items it already created.
* **Default Source** and **Default Category** — assigned to every News item
  the feed creates.
* **Content Mapping** for Title, Excerpt, and Image URL, each independently
  sourced from either the feed item's own data (Title, Description, full
  Content/`content:encoded`, or its Enclosure/media-thumbnail for the image)
  or from the linked article's own page, fetched and scraped separately.
  Optionally narrow to one HTML element by tag and/or class/id — e.g. pull
  the excerpt from `<div class="article-body">` inside `content:encoded`
  rather than using the whole field. All formatting is stripped from mapped
  content except `<a>` links; Title is always reduced to plain text (as
  WordPress post titles always are).

= Deduplication and re-imports =

A feed item is identified by its own article URL (used as both the dedupe
key and the News item's Read More URL):

* **New URL** → a new News item is created as a `draft`, same as every other
  News item — a human still reviews and publishes it.
* **Same URL, existing item still unreviewed** (draft/pending) → that item's
  Title/Excerpt/Image are refreshed in place to match the feed's current
  content.
* **Same URL, existing item already published** → the live post is left
  untouched. The freshly-fetched fields are stashed as a pending update and
  surfaced on the News tab as **"RSS update available"** with **Apply** /
  **Dismiss** actions — nothing about a published item ever changes without
  a human explicitly clicking Apply.

Deleting a feed's configuration only removes that configuration; News items
it already created are left exactly as they are.

== Installation ==

1. Upload the `teatatu-news` folder to `wp-content/plugins/`.
2. Activate through the **Plugins** screen (or **Network Activate** from
   Network Admin on a multisite install).
3. Visit the **Teatatu News** admin menu to add Sources, Categories, and
   News items, or configure an AI agent per `SKILL.md`.
4. On multisite, set a Master Site at **Network Admin > Settings > Teatatu
   News** if you plan to use the network-wide shortcodes/endpoint.

== Auto-updates ==

This plugin isn't distributed on WordPress.org. It checks
`https://github.com/TeAtatu/Teatatu-news` for new GitHub Releases and, when
a newer tagged release is found, surfaces it in the normal Plugins screen
"update available" UI — including native "Enable auto-updates" support.
Configure the repository (and an access token, for a private fork) at
**Network Admin > Settings > Teatatu News** (or **Settings > Teatatu News**
on single-site).

== Uninstall ==

By default, uninstalling this plugin only removes the `teatatu_news_agent`
role/capabilities and the plugin's own settings — all News content is left
in place. Check **"Delete all Teatatu News data when this plugin is
uninstalled"** on the settings page beforehand to have uninstall also
permanently remove every News post, RSS feed configuration, and
Source/Category term, on every site in the network. Since images are
link-only, there is never any local media to clean up.

== Changelog ==

= 1.3.0 =
* Added a "Shortcodes" tab to the Teatatu News admin page: an in-admin
  quick reference covering every shortcode, every attribute (including
  columns and the display="summary" preset), and the Source/Category
  slugs actually available on the current site.

= 1.2.0 =
* Added the `display="summary"` shortcode attribute (all six per-site and
  network-wide shortcodes) — a minimal-card preset showing only the title,
  excerpt, thumbnail, and Read More link, suppressing Source/Category/Date/
  site-label regardless of their own attribute values. Renders with an
  additional `teatatu-news-summary` CSS class for styling.
* `columns` is now capped at a maximum of 6 (minimum was already 1).

= 1.1.2 =
* Fixed: the RSS Feed configuration post type's slug, `teatatu_news_rss_feed`
  (21 characters), exceeded WordPress's 20-character limit for post type
  names. WordPress only logged a notice about this and still registered
  it, but every attempt to save an RSS feed then failed at the database
  layer with "Could not insert post into the database." (or, under
  non-strict SQL modes, could have silently truncated the stored value
  instead). Renamed to `teatatu_news_rssfeed` (20 characters). Also:
  admin-notice error messages from failed post/term saves now include the
  underlying database error when WordPress provides one, instead of only
  showing its generic wrapper text.

= 1.1.1 =
* Fixed: sites whose plugin files were updated in place (without a
  deactivate/reactivate cycle) never received capabilities added in a
  later version — most visibly, `manage_teatatu_news_rss_feeds` added in
  1.1.0, which made the RSS Feeds tab appear to silently fail to save.
  Role capabilities are now re-synced automatically the next time any
  wp-admin page loads after an update, no reactivation required.

= 1.1.0 =
* Added an RSS Feeds importer: any number of feeds, each on its own check
  cadence, with configurable per-field (Title/Excerpt/Image) content
  mapping by HTML element/class/id, sourced from either the feed's own data
  or the linked article page. New items become drafts like any other News
  item; re-imports of an already-published item are held as a pending
  update for a human to review and apply, never applied automatically.

= 1.0.0 =
* Initial release. Images are link-only — the plugin stores and serves the
  submitted URL directly and never downloads or copies it into the Media
  Library.
