=== Teatatu News ===
Contributors: teatatu
Tags: news, custom post type, rest api, multisite, ai agent
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.7.0
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
* An HTML Feeds importer for sites with a news listing page but no RSS/Atom
  feed: scrapes a repeating element on the page directly, with the same
  per-field content mapping approach — see == HTML Page Feeds == below

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
* **Auto-Publish New Items** and **Auto-Apply Updates to Published Items**
  — both off by default; see "Deduplication and re-imports" below. Each
  can only be turned on by a user who already holds
  `publish_teatatu_news_items` / `edit_published_teatatu_news_items`
  respectively (Administrator/Editor by default) — having
  `manage_teatatu_news_rss_feeds` alone is not enough to enable either, so
  configuring feeds never grants more publishing power than the
  configuring user already has.
* **Default Source** and **Default Category** — assigned to every News item
  the feed creates.
* **Content Mapping** for five fields — Title, Excerpt, Image URL, Read
  More URL, and Publish Date — each independently sourced from either the
  feed item's own data (Title, Description, full Content/`content:encoded`,
  its Enclosure/media-thumbnail for the image, its own Item Link for Read
  More URL, or its own reported Publish Date) or from the linked article's
  own page, fetched and scraped separately. Optionally narrow to one HTML
  element by tag and/or class/id — e.g. pull the excerpt from
  `<div class="article-body">` inside `content:encoded` rather than using
  the whole field. Leaving Element and Class/ID blank uses an RSS-based
  source's whole value as-is; "Article Page" always requires an Element
  and/or Class/ID, since scraping the entire page unnarrowed would grab far
  more than intended.
  - All formatting is stripped from mapped Excerpt content except `<a>`
    links; Title is always reduced to plain text (as WordPress post titles
    always are).
  - **Read More URL** defaults to the feed item's own link, but can be
    remapped to a link found elsewhere (e.g. an `<a href="...">` inside
    `content:encoded`, or on the article page). This only changes what's
    displayed to readers — see "Deduplication and re-imports" below for why
    it's always safe to remap.
  - **Publish Date** defaults to the feed's own reported date
    (`<pubDate>`/`<published>`). Mapped from anything else, it always
    requires an Element and/or Class/ID too — there's no reliable way to
    find a date inside an unmarked block of text. The selected element's
    `datetime="..."` attribute is used if present (e.g.
    `<time datetime="2024-03-01T10:00:00Z">`), otherwise its visible text
    is parsed automatically (e.g. "1 March 2024").
  - If a field can't be resolved, it fails safe: an unresolved Title skips
    the item entirely (nothing is created); Excerpt/Image URL are simply
    left blank; Read More URL falls back to the feed's own item link;
    Publish Date falls back to the time the item was imported.

Click **Preview Example Values** (above the Content Mapping table) at any
time — including before saving — to fetch the feed's most recent item and
show, right in the "Example" column, exactly what the current mapping
produces for each field. It uses whatever is currently filled into the
form, so you can adjust a Source/Element/Class-or-ID and preview again to
compare, without saving or waiting for the next scheduled check. Requires
a Feed URL to already be entered; the preview runs the same resolution
logic as a real import, so what it shows is exactly what saving and
checking the feed would produce.

Publish Date is stored both as the News item's actual date (`post_date`/
`post_date_gmt`) and as an explicit `_teatatu_news_rss_pub_date` meta field
— News Items and every shortcode already sort newest-first by date by
default, so imported items land in true chronological order (when the
story was published) rather than in whatever order the feed happened to be
checked. This only applies going forward: an item imported before Publish
Date mapping was added keeps its original import time until it's next
touched (re-synced while still a draft, or updated via Apply/Auto-Apply).

= Deduplication and re-imports =

A feed item is identified by its own item link — this dedupe key is
completely independent of whatever the Read More URL field above is
mapped to display, tracked separately via internal `_teatatu_news_rss_source_link`
meta, so remapping Read More URL to a different link never causes an
already-imported item to be re-imported as a duplicate:

* **New URL** → a new News item is created as a `draft`, same as every other
  News item — a human still reviews and publishes it. If the feed has
  **Auto-Publish New Items** turned on, it's created already `publish`ed
  instead, with no review step.
* **Same URL, existing item still unreviewed** (draft/pending) → that
  item's Title/Excerpt/Image/Read More URL/Publish Date are refreshed in
  place to match the feed's current content. If the feed has
  **Auto-Publish New Items** turned on, this is also where a
  previously-imported item gets published — not just brand new items — so
  turning the toggle on catches up anything the feed already created
  before the toggle was enabled.
* **Same URL, existing item already published** → by default, the live
  post is left untouched. The freshly-fetched fields are stashed as a
  pending update and surfaced on the News tab as **"Feed update
  available"** with **Apply** / **Dismiss** actions — nothing about a
  published item changes without a human explicitly clicking Apply. If the
  feed has **Auto-Apply Updates to Published Items** turned on, the change
  is written straight to the live post instead of waiting for Apply.

Both toggles are opt-in and off by default — every feed keeps the
draft-first, human-reviewed behavior above unless you deliberately turn one
on for a feed you trust.

Deleting a feed's configuration only removes that configuration; News items
it already created are left exactly as they are.

== HTML Page Feeds ==

**Teatatu News > HTML Feeds** (requires the `manage_teatatu_news_html_feeds`
capability, granted to Administrator/Editor by default — never to the
`teatatu_news_agent` role) is for sites that publish a news/events listing
page but no RSS or Atom feed — e.g. a school website's `/news` page. It
fetches that page directly with a plain HTTP request and scrapes it,
instead of parsing a feed file.

* **Page URL** — the listing page to fetch, exactly as a browser would see
  it (not a feed file). Same cadence, Active/Paused, Auto-Publish New
  Items, Auto-Apply Updates to Published Items, and Default Source/Category
  options as RSS Feeds, with the same off-by-default behavior and the same
  capability requirements for the two toggles.
* **Repeating Item** — the one element that repeats once per news item on
  the page (e.g. each card in a grid, or each row in a list), chosen by
  Element (tag) and/or Class or ID. This is required and has no default:
  without it, there is nothing to import. Every field below is then found
  by searching *inside* each matching element in turn, never the whole
  page.
* **Content Mapping** for the same five fields as RSS Feeds — Title,
  Excerpt, Image URL, Read More URL, and Publish Date — but sourced
  differently, since one repeating item is a single HTML fragment rather
  than several distinct feed fields:
  - Each field's Source is either **Within Repeating Item** (search inside
    the matched card/row itself) or **Linked Article Page** (fetch that
    item's own Read More URL and search the fetched page instead — useful
    when the listing page only shows a title and the rest lives on the
    article).
  - **Title** and **Excerpt** always require an Element and/or Class/ID —
    unlike RSS mapping, there is no single "whole item" text to fall back
    to, since one repeating item's HTML mixes together its title, date,
    excerpt, and links.
  - **Image URL** and **Read More URL** can be left with no Element/
    Class-ID: they then search the whole repeating item for its first
    image (an `<img>`, or a CSS `background-image` — some sites set card
    images that way) or its first link, respectively.
  - **Read More URL** can only be sourced **Within Repeating Item**, never
    the linked page (there would be nothing to fetch yet) — see
    "Identifying items" below for why this field is required.
  - **Publish Date** always requires an Element and/or Class/ID too. Its
    element's `datetime="..."` attribute is used if present, otherwise its
    visible text is parsed automatically. If it can't be resolved, the item
    still imports — it just uses the time it was imported as its date.
  - If a field can't be resolved, it fails safe the same way RSS mapping
    does: an unresolved Title skips the item entirely; Excerpt and Image
    URL are simply left blank. All formatting except links is stripped
    from mapped Excerpt content; Title is always reduced to plain text.

Click **Preview Example Values** (above the Content Mapping table) at any
time — including before saving — to fetch the Page URL and show, in the
"Example" column, exactly what the current Repeating Item selector and
mapping produce for the first matching item.

= Identifying items =

RSS feeds provide their own stable item link, independent of whatever the
Read More URL field is mapped to display — that's what
`_teatatu_news_rss_source_link` is for (see "Deduplication and re-imports"
above). An HTML feed has no such separate identity: its Read More URL is
always found by scraping the repeating item itself, so it doubles as that
item's permanent identity too, stored internally as
`_teatatu_news_html_source_link`. This is why Read More URL can't be
sourced from the linked page and must resolve to something for an item to
import at all — without it, there is nothing to recognise "the same item"
by on the next check.

Otherwise, HTML feeds behave exactly like RSS feeds for re-imports: a new
link creates a draft (or a published item, with Auto-Publish on); an
existing but still-unreviewed item is refreshed in place; an existing,
published item gets a pending update — shown on the News tab exactly the
same way, with the same Apply/Dismiss actions, since both importers share
the same pending-update mechanism.

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
permanently remove every News post, RSS feed configuration, HTML feed
configuration, and Source/Category term, on every site in the network.
Since images are link-only, there is never any local media to clean up.

== Changelog ==

= 1.7.0 =
* Added HTML Feeds: a second feed importer, alongside RSS Feeds, for sites
  that publish a news/events listing page but no RSS or Atom feed. Fetches
  the page directly and scrapes every element matching a configurable
  "Repeating Item" selector, then maps Title/Excerpt/Image URL/Read More
  URL/Publish Date the same general way "Article Page" mapping already
  worked in RSS Feeds. Includes its own cadence, Active toggle,
  Auto-Publish/Auto-Apply toggles, Default Source/Category, and a Preview
  Example Values button — see == HTML Page Feeds == below. Manage at
  **Teatatu News > HTML Feeds** (new `manage_teatatu_news_html_feeds`
  capability, Administrator/Editor by default).
* Pending updates from RSS and HTML feeds now share one review queue on the
  News tab; its label changed from "RSS update available" to "Feed update
  available" to reflect that.
* Image mapping (RSS "Article Page" sources and HTML Feeds) now also finds
  an article's image when a site sets it via a CSS `background-image`
  style instead of an `<img>` tag, a pattern seen on real school-website
  news cards.

= 1.6.0 =
* Added a "Preview Example Values" button to the RSS Feeds Content Mapping
  table: fetches the feed's most recent item and shows what the current
  (even unsaved) mapping produces for each field, so you can check and
  adjust a mapping without waiting for the next scheduled check.
* RSS field mapping now covers Read More URL and Publish Date, in addition
  to the existing Title, Excerpt, and Image URL. Each defaults to the
  feed's own item link / publish date and can be remapped to a different
  element on the RSS item, the article's full content, or the article
  page itself, the same way the other three fields already worked.
* Fixed: remapping Read More URL could have broken deduplication, since
  matching against an already-imported item previously relied on that
  same field. Re-imports now match on the feed's own item link
  internally (a new `_teatatu_news_rss_source_link` meta field),
  independently of whatever Read More URL is currently mapped to display
  — existing items imported before this update fall back to matching on
  their stored Read More URL, so nothing needs to be re-imported.
* Rewrote the Content Mapping explanation on the RSS Feeds tab and in
  this readme to cover all five mappable fields and clarify how the
  default-source/selector/fallback behavior works.

= 1.5.1 =
* Fixed: the Master Site setting (Network Admin > Settings > Teatatu News)
  could be selected but never actually saved. Its form posted to
  `network_admin_url('admin-post.php')`, but WordPress core has no
  `wp-admin/network/admin-post.php` file — the submission 404'd before
  ever reaching the save handler. Now posts to the plain `admin-post.php`,
  the same one every other form in this plugin already uses successfully.
  This also fixes the "Check for Updates Now" button on multisite, which
  shared the same broken form-action URL.

= 1.5.0 =
* Fixed: Auto-Publish New Items only applied to genuinely new feed items —
  an item already imported as a draft before the toggle was turned on
  stayed a draft forever, since later syncs never re-checked its status.
  Turning Auto-Publish on now also publishes previously-imported,
  still-unreviewed items from that feed, not just brand new ones.
* Each feed item's own publish date is now read from the feed (RSS
  `pubDate` / Atom `published`) and stored, both as the News item's date
  (so News Items and every shortcode, which already sort newest-first by
  date by default, list imports in true chronological order) and as an
  explicit `_teatatu_news_rss_pub_date` meta field. Added a Date column to
  the News Items list. Only applies going forward — items imported before
  this update keep their original import time until next touched.

= 1.4.0 =
* Added two per-feed RSS toggles, both off by default: "Auto-Publish New
  Items" (new items publish immediately instead of saving as draft) and
  "Auto-Apply Updates to Published Items" (changes to an already-published
  item apply immediately instead of becoming a pending update). Each can
  only be enabled by a user who already holds
  `publish_teatatu_news_items` / `edit_published_teatatu_news_items`
  respectively — configuring feeds alone is never enough to enable either.
  Feeds with either toggle on are flagged in the RSS Feeds list.

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
