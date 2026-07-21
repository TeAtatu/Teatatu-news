# Teatatu News

A WordPress plugin that adds a "News" content type for aggregating short-form
news items — title, excerpt, source, category, a link to the original
article, and an image — with a workflow designed for a mix of human editors
and AI agents.

The plugin folder ([`teatatu-news/`](teatatu-news/)) is a complete,
self-contained WordPress plugin. Drop it into `wp-content/plugins/` on any
WordPress 6.0+ / PHP 7.4+ site (single-site or multisite) and activate it.

## What it does

- Registers a `teatatu_news` custom post type (REST base `/wp/v2/news`) with
  two dedicated taxonomies, **Source** and **Category**, kept fully separate
  from the site's normal blog categories.
- Editors and AI agents only ever submit a plain image URL. The plugin
  stores and serves that URL directly (hotlinked) — images are never
  downloaded, copied, or stored locally in any form.
- Ships a `teatatu_news_agent` WordPress role, scoped so an AI agent can
  draft News items over the REST API but can **never** publish directly —
  every agent-created item requires a human to review and click Publish.
  See [`teatatu-news/SKILL.md`](teatatu-news/SKILL.md) for the full
  agent-facing workflow.
- Gives non-technical editors one maintenance screen (**Teatatu News** in
  the admin menu) to manage News items, Sources, and Categories without
  touching the standard post-list UI — including a **Shortcodes** tab that
  documents every shortcode and attribute in plain language, right where
  editors are already working.
- Provides shortcodes — `[teatatu_news_list]`, `[teatatu_news_grid]`,
  `[teatatu_news_latest]` — for displaying News anywhere on the site.
- On a **multisite** network: network-activate once and every site gets its
  own independent News content in its own tables. A single designated
  "Master Site" can display network-wide aggregated News via
  `[teatatu_news_network_*]` shortcodes and a
  `GET /wp-json/teatatu-news/v1/network-feed` REST endpoint.
- Self-updates from [GitHub Releases](https://github.com/TeAtatu/Teatatu-news/releases)
  on this repo, showing up in the normal wp-admin "update available" UI —
  no WordPress.org listing needed.
- Imports News items from RSS/Atom feeds: add any number of feeds (each with
  its own check cadence, and pausable without deleting its config), with a
  configurable per-field content mapping (Title/Excerpt/Image, by HTML
  element and class/id) sourced from either the feed's own data or the
  linked article page. By default, new items land as drafts like everything
  else, and a later update to an already-published item is held as a
  pending update for a human to review and apply — never applied
  automatically. Each feed can independently opt into publishing new items
  immediately or applying updates immediately, but only for a user who
  already holds real publish/edit-published rights.
- Uses **only standard WordPress database tables** — no custom tables, and
  no local file storage of any kind. Images are link-only.

## Getting started

1. Copy or symlink [`teatatu-news/`](teatatu-news/) into
   `wp-content/plugins/` on your WordPress install.
2. Activate it from **Plugins** (or **Network Activate** from Network Admin
   on a multisite install).
3. Visit the **Teatatu News** admin menu to add a Source, a Category, and
   your first News item.
4. To let an AI agent draft items, create a WordPress user with the
   **News Agent** role and generate an Application Password for it under
   **Users > Profile**, then follow
   [`teatatu-news/SKILL.md`](teatatu-news/SKILL.md).
5. On a multisite network, set a Master Site at **Network Admin > Settings >
   Teatatu News** if you want the network-wide shortcodes/endpoint.

Full technical documentation — every REST endpoint, shortcode attribute,
capability, and the uninstall/data-retention behavior — lives in
[`teatatu-news/readme.txt`](teatatu-news/readme.txt), in the standard
WordPress.org plugin readme format.

## Repo layout

```
teatatu-news/            the plugin itself — drop this folder into wp-content/plugins/
  teatatu-news.php        plugin header, activation/deactivation, multisite provisioning
  uninstall.php            retention-aware cleanup
  readme.txt               full technical/user documentation (WordPress.org format)
  SKILL.md                 agent-facing usage guide (REST workflow, auth, error handling)
  includes/                one file per concern — post type, taxonomies, meta boxes,
                             the image URL resolver, admin columns, the maintenance admin
                             page, REST routes, shortcodes, settings, the GitHub updater,
                             the RSS feed config + importer engine, capabilities, and
                             multisite helpers
  assets/css/               the shortcodes' minimal structural stylesheet
prompt.md                  reusable AI prompt for extending or regenerating this plugin
README.md                  this file
LICENSE                    GPL-3.0
```

## Extending this plugin with AI

[`prompt.md`](prompt.md) at the repo root is a condensed, reusable prompt
that captures this plugin's architecture and conventions (prefixing,
capability model, multisite behavior, link-only image handling, and so on).
Paste it into a fresh AI coding session along with a description of the
change you want, so future updates stay consistent with how the plugin was
originally built rather than drifting into a different style.

## License

GPL-3.0 — see [LICENSE](LICENSE).
