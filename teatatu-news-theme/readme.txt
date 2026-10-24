=== Teatatu News Theme ===
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPL-3.0

Display theme for a Te Atatū Peninsula news site. Requires the Teatatu News plugin; shows a "What's on" strip when the Teatatu Events plugin is also active.

== Description ==

This theme only displays. Every story comes from the Teatatu News plugin and every event from the Teatatu Events plugin; the theme stores no data and reads no post meta.

* Stories are read with teatatu_news_query_items() and teatatu_news_normalize_item() — headline, summary, source, category, date, image and the link to the original article. News images are always hotlinked URLs, never stored locally.
* Search and filters are a plain GET form: s (words), teatatu_news_category, teatatu_news_source and sort (newest, oldest, title). WordPress applies the taxonomy filters itself; the theme only sets the page size and sort.
* The "What's on" strip comes from the Teatatu Events plugin's front-end API, teatatu_events_search() (or its network feed on multisite, so events held on another site of the network still appear). Without the Events plugin the strip is simply absent.

= Templates =

* front-page.php — hero with search and topic chips (featuring the latest story, or a photo you set), latest news, "What's on", browse by topic, sources, and a share-a-story call to action.
* archive-teatatu_news.php — the news list with search, topic and source filters, removable filter chips, sorting and pagination. Also used for every Source and Category archive and for news search.
* single-teatatu_news.php — headline, source and date, image, summary, a prominent "Read the full story at <source>" button, share, "What's on", and more stories in the same category.
* page.php, single.php, index.php, 404.php — plain content.

= "What's on" — the events viewpoint =

News is the site; events are the neighbours. The strip uses the design system's EventCard, date block and compact event row, which re-point the accent at Pōhutukawa pink on their own — so events read as events inside the blue News site. It appears as date-led cards on the home page and as compact rows beside news on story and list pages.

"See all events" goes to the Customizer link if set; on a single site to the Events archive; otherwise to the network's Events site.

= Customizer (Appearance > Customize > Te Atatū News) =

Home page photo URL and alt text (blank: the latest story is featured), "Share a story" link, "See all events" link, "What's on" strip on or off, footer line.

= Menus =

Header and Footer menu locations. With no header menu assigned, the section links are Latest, the most-used topics, and What's on (when the Events plugin is active).

= When the News plugin is disabled =

The header keeps its details instead of going bare: the search box searches the whole site (it is limited to news only while the plugin is active), and the section links become Home, the site's top pages, and What's on when Events is active. The footer keeps its "What's on" link. The page content shows "News is not available right now" until the plugin is activated again.

= Design system =

Styled with the Te Atatū Local design system, the shared look of the five Te Atatū sites:

* assets/css/te-atatu-local-tokens.css — colour (light and dark), type, spacing, radius and shadow tokens, generated from the design system's tokens.json. Do not edit by hand.
* assets/css/te-atatu-local.css — the design system's tal-* components (NetworkNav, Button, bands, badges, EventCard), copied unchanged from its bundle.css.
* <html data-site="news"> makes every component use the News accent, Kōtare blue. A wrapper with data-site="events" (the What's on band and panel) switches back to Pōhutukawa.
* The header is the shared NetworkNav: the black strip linking the Te Atatū sites the "Te Atatū News" wordmark, search, "Share a story", and the section links.
* The strip follows the plugins: Te Atatū and News (this site) always show; Events shows only while Teatatu Events (teatatu-events.php) is active, Homes while Teatatu Homes (teatatu-homes.php) is, and Market while Teatatu Market (teatatu-market.php) is. A plugin counts as active when it is active on this site, network-activated, or — on multisite — active on any site of the network, since each Te Atatū site is its own site. Plugin files are matched by file name, whatever their folder.
* Each item links to its real address: on multisite the network site whose address starts with the section name (events.example.nz or example.nz/events/), else the one site the plugin is active on; on a single site its listing page; otherwise the default teatatu.nz address. The scan of each site is cached for ten minutes and cleared whenever a plugin is activated or deactivated. The same rule decides whether "What's on" appears in the section links and footer. Filter `ttn_network_sites` to change labels, addresses or the list itself.
* style.css lays out the pages using only design-system tokens. When the design system changes, re-copy both CSS files rather than editing them here.
* Font: Plus Jakarta Sans (400, 600, 700, 800) from Google Fonts.

= Updates =

The theme updates itself from the same GitHub release as the Teatatu News plugin (repository and token are set in the plugin's Settings > Teatatu News; default TeAtatu/Teatatu-news).

* It compares its own Version in teatatu-news-theme/style.css at the latest release, so a plugin-only release doesn't offer a theme update.
* Updates install automatically only when "Enable auto-updates" is on for the theme (Appearance > Themes; Network Admin > Themes on multisite). Otherwise click Update now.
* Only the teatatu-news-theme/ folder from the release is installed.

To release: bump Version in style.css (and/or the plugin header), commit, then publish a GitHub release tagged vX.Y.Z.

== Changelog ==

= 1.1.0 =
* The network strip now shows Events, Homes and Market only while Teatatu Events, Teatatu Homes and Teatatu Market are active (here, network-wide, or on any site of the network), and links each to its real site address rather than a fixed one. "What's on" in the section links and footer follows the same rule. With the News plugin disabled, the header's search and section links stay (site search, Home and top pages) rather than disappearing.

= 1.0.0 =
* First release: home page, news list with search and filters, story page, "What's on" events strip, Te Atatū Local design system (Kōtare blue; events in Pōhutukawa), GitHub updater shared with the plugin's releases.
