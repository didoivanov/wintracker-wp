# Wintracker for WordPress

Shows live casino wins and stats from the [Wintracker API](https://github.com/didoivanov/wintracker/blob/main/docs/API.md)
on casino, game and provider pages: latest wins, biggest wins, top multipliers and stats cards, as shortcodes or
PHP functions.

## Setup

1. Install `wintracker-wp.zip` from the [latest release](https://github.com/didoivanov/wintracker-wp/releases/latest)
   (Plugins → Add New → Upload, or extract it into `wp-content/plugins/wintracker-wp/`) and activate **Wintracker**.
   From then on it updates itself from this repository (below).
2. Paste the API key under **Wintracker → Settings** and save: the page tests the key right away. The same page has
   **Test connection** and **Clear cache** buttons.

   Alternatively, define the key in `wp-config.php`. It then overrides the settings page and stays out of the
   database (and its backups):

   ```php
   define( 'WINTRACKER_API_KEY', 'wt_…' );
   ```

Keys are created by the Wintracker admin (`php bin/api-key.php create "casinos.example"`), one per site.

## How it works

- **Server-side only.** Every call is a `wp_remote_get` from WordPress with the key in the `X-Api-Key` header,
  so the key never reaches the browser.
- **Cache: 60 s.** Each distinct widget configuration is cached in a transient for 60 seconds (the API refreshes
  at most once a minute). Raise it with the `wintracker_cache_ttl` filter; it never goes below 60.
- **Snapshots for outages.** The last good response of each widget is also kept as a snapshot (7 days by default,
  `wintracker_snapshot_ttl` filter). Each snapshot is overwritten on every successful refresh, so the plugin
  stores only one small entry per widget configuration (at most 100 wins), never a history.
- **Graceful fallback.** If the API times out, returns 5xx/401/429 or invalid JSON, the widget shows its
  snapshot and the plugin pauses API calls for a minute (longer when the API sends `Retry-After`, up to 15 min),
  so an outage does not slow every page view. Without a snapshot the widget renders nothing.
- **Wrong arguments** (unknown casino slug, game id) render nothing; logged-in editors see the reason in an HTML
  comment (`<!-- Wintracker: casino_not_found: … -->`) in the page source.
- **Stylesheet only where needed.** `assets/wintracker.css` (about 5 KB, less gzipped) loads only on pages that show a
  widget: in `<head>` for posts with a Wintracker shortcode, otherwise in the footer when the widget renders.

## Shortcode builder

**Wintracker → Shortcode builder** in wp-admin (for anyone who can edit posts): pick the widget, the casino and
provider from lists loaded from the API, search games by name, set the options, see a live preview, and copy the
shortcode or the PHP call. The same page has a reference of every shortcode and attribute.

## Shortcodes

| Shortcode                      | Shows                                                       |
|--------------------------------|-------------------------------------------------------------|
| `[wintracker_latest_wins]`     | the most recent wins                                        |
| `[wintracker_biggest_wins]`    | biggest wins by EUR value (last 24 h unless `period` is set) |
| `[wintracker_top_multipliers]` | highest win ÷ bet (last 24 h unless `period` is set)        |
| `[wintracker_wins sort="…"]`   | any of the above, `sort` = `latest`, `amount` or `multiplier`         |
| `[wintracker_stats]`           | all-time stats of one casino, provider or game              |

### Wins attributes

| Attribute  | Values                                                          | Default                        |
|------------|-----------------------------------------------------------------|--------------------------------|
| `casino`   | Wintracker casino slug or id (`bizzo`)                          | all casinos                    |
| `provider` | Wintracker provider slug or id (`pragmaticplay`)                | all providers                  |
| `game`     | Wintracker game id (`685`)                                      | all games                      |
| `period`   | `day`, `week`, `month`, `all`                                   | `all` for latest, else `day`   |
| `limit`    | 1–100                                                           | `10`                           |
| `layout`   | `table`, `list` (compact, for sidebars), `cards` (image grid)   | `table`                        |
| `columns`  | any of `game,casino,provider,player,bet,win,multiplier,time`, in the order to show (tables, lists and cards) | the site default, minus what the widget is filtered by |
| `currency` | `original` (the casino's currency), `eur`                       | `original`                     |
| `images`   | `yes`, `no` (game thumbnails)                                   | the site default (`yes`)       |
| `title`    | heading above the widget                                        | none                           |
| `heading`  | `h2`–`h6`, `p`, `div`                                           | `h3`                           |
| `empty`    | text when there are no wins yet; `""` hides the widget instead  | "No wins tracked yet."         |
| `class`    | extra CSS classes                                               |                                |

`casino`, `provider` and `game` combine, e.g. one game at one casino.

**Site-wide defaults.** **Wintracker → Settings → Display defaults** sets which columns and whether game images
every wins widget shows. To drop a column everywhere (say the player name), untick it there instead of editing
each shortcode. A shortcode's own `columns="…"` / `images="…"` still wins. Widgets filtered by casino leave the
casino column out, widgets filtered by game the game column.

### Stats attributes

| Attribute                      | Values                                                          | Default |
|--------------------------------|-----------------------------------------------------------------|---------|
| `casino` / `provider` / `game` | slug or id (game: id), e.g. `casino="bizzo"`                    |         |
| `type` + `id`                  | the same as `type="casino" id="bizzo"`                          |         |
| `layout`                       | `grid` (tiles), `list` (label / value rows)                     | `grid`  |
| `fields`                       | any of `wins,total_won,biggest_win,top_multiplier,last_win,games,rtp,volatility,provider` | casino: wins, total won, biggest win, top multiplier, last win · provider: + games · game: + RTP, volatility |
| `details`                      | which win the biggest win, top multiplier and last win were: `inline` (a line under the value), `tooltip` (on hover / tap), `none` | `inline` |
| `title`, `heading`, `class`    | as above                                                        |         |

Fields without a value (e.g. no RTP known) are left out.

Win details read, for example: biggest win "Gates of Olympus · Pragmatic Play · 3 days ago", top multiplier
"€530.00 on a €0.50 bet · Gates of Olympus · Pragmatic Play · 2 weeks ago", last win "€12.40 · 31× · Book of Dead".
What the card is about is left out (no game name on a game card) and the casino is added on provider and game cards.
They cost up to three more cached API calls per card (`wins.php`, `limit=1`).

### Examples

Casino review page:

```
[wintracker_stats casino="bizzo"]
[wintracker_biggest_wins casino="bizzo" period="week" title="Biggest wins at Bizzo this week"]
[wintracker_latest_wins casino="bizzo" limit="20"]
```

Provider page:

```
[wintracker_stats provider="pragmaticplay"]
[wintracker_top_multipliers provider="pragmaticplay" period="month" layout="cards" limit="8"]
```

Game page:

```
[wintracker_stats game="685" layout="list"]
[wintracker_biggest_wins game="685" period="month"]
[wintracker_latest_wins game="685" casino="bizzo" layout="list"]
```

Sidebar: `[wintracker_latest_wins layout="list" columns="game,win,time" limit="5" images="no"]`

## PHP functions

Each takes the same arguments as its shortcode and **returns** HTML:

```php
echo wintracker_latest_wins( array( 'casino' => 'bizzo', 'limit' => 20 ) );
echo wintracker_biggest_wins( array( 'provider' => 'pragmaticplay', 'period' => 'week' ) );
echo wintracker_top_multipliers( array( 'game' => 685, 'layout' => 'cards' ) );
echo wintracker_wins( array( 'sort' => 'amount', 'period' => 'month' ) );
echo wintracker_stats( array( 'casino' => 'bizzo' ) );
```

Raw data for custom markup (cached the same way, `null` when unavailable):

```php
$data  = wintracker_get_wins( array( 'casino' => 'bizzo', 'sort' => 'amount', 'period' => 'week' ) ); // ['wins' => [...], 'meta' => [...]]
$stats = wintracker_get_stats( 'game', 685 ); // wins, total_won_eur, biggest_win_eur, top_multiplier, last_win_at, rtp, …
```

Templates that call these functions get the stylesheet in the footer. To load it in `<head>` instead:

```php
add_action( 'wp_enqueue_scripts', function () {
	if ( is_singular( 'casino' ) ) { // the post types that show widgets
		wintracker_enqueue_style();
	}
} );
```

## Finding slugs and ids

The widgets take Wintracker's own identifiers:

- casinos: `slug` from `/api/v1/casinos.php` (e.g. `bizzo`)
- providers: `slug` from `/api/v1/providers.php?q=prag`
- games: `id` from `/api/v1/games.php?q=elvis` (the API has no game slugs)

## Links to your pages

Optional: **Wintracker → Settings → Links to your pages** picks the post type of your casino reviews, game (slot)
pages and provider pages. Game, casino and provider names in the widgets then link to the matching page.

- **Matching:** by name and slug, exact first, then ignoring accents, punctuation and filler words ("Bizzo Casino
  Review" ↔ Bizzo Casino, "Gates of Olympus Slot" ↔ Gates of Olympus; filter `wintracker_link_stopwords`). A name
  that matches several pages equally is not linked and is listed on the settings page.
- **Review and edit: Wintracker → Links** (editors and up). One tab per kind, search by name, filter by status
  (linked, not linked, set by hand, several matches, never linked), 50 per page. Per row: type to search your pages
  and pick one, or *Automatic*, or *Don't link*; each change saves immediately. Bulk: tick rows → *Set to automatic*
  or *Don't link*. A page belongs to one item: giving it to another item frees the first one.
- **Manual matching on the edit screen:** the **Wintracker** box on pages of those post types: *Automatic*, a specific
  casino / provider / game id, or *Don't link this page*. Applies as soon as the post is saved.
- **Instant:** manual choices, and newly published or renamed pages, update the links immediately (a new page is
  matched against the cached Wintracker list); the full rebuild then confirms them in the background.
- **When:** matching runs in the background (WP-Cron) after saving the settings or clicking **Rebuild links now**,
  daily, and about 2 minutes after a page of a linked type is published, renamed or unpublished. Games are read
  from the API 100 at a time, uncached, so the job doesn't fill the cache.
- **Storage:** `wintracker_links`, only the matches (Wintracker id → post id); `wintracker_links_manual`, the choices
  made by hand (Wintracker id → post id, or 0 = never link); both not autoloaded. Pages marked "don't link" carry the
  post meta `_wintracker_link` = `none`. Plus a small report for the settings page.
- **Front-end cost:** that option is read once per request; the linked posts of a widget are loaded in one query
  (skipped when already loaded). A page never links to itself; unpublished pages are never linked.
- The URL can be changed with the `wintracker_link_url` filter (`$url, $kind, $wintracker_id, $post_id`).
- Sites with WPML/Polylang: translations of a page share its name, so they count as several matches. Pick the page
  in the Wintracker box, or filter the URL per language with `wintracker_link_url`.

## Translations

Every label and message is translatable (text domain `wintracker`). Numbers, amounts and dates follow the site's
language automatically. The template is [`languages/wintracker.pot`](languages/wintracker.pot).

- **Loco Translate:** Loco Translate → Plugins → Wintracker → New language. Save the files in Loco's "Custom" or
  "System" location (not "Author"), so plugin updates don't overwrite them.
- **Poedit:** create `wintracker-<locale>.po` (e.g. `wintracker-pl_PL.po`) from the `.pot` and put the `.po`/`.mo`
  in `wp-content/languages/plugins/`.

After changing or adding strings in the code, run `php bin/make-pot.php` and commit the new `.pot`; the release
workflow refuses to publish when it is out of date.

## Customising

- **Theme styles leaking into the widgets** (coloured first rows, centred cells, odd borders): tick
  **Protect widgets from theme styles** in Wintracker → Settings → Display defaults. The widgets then load
  `assets/wintracker-isolated.css`: everything inside a widget is reset to browser defaults (`all: revert`) and the
  plugin's rules are applied with ID-level specificity, so ordinary theme rules — even `#content table td` — no
  longer reach them. The theme's font and text colour are still inherited; only theme rules with `!important` get
  through. The file is generated from `assets/wintracker.css` with `php bin/build-css.php` (the release workflow
  checks it is current).
- **Custom CSS** per site: the textarea under Display defaults, printed after the plugin's stylesheet and only on
  pages with a widget. With protection on, use `.wintracker.wintracker--isolated …` selectors (or `!important`).
- **CSS**: override the custom properties, e.g.
  `body .wintracker { --wt-accent: #0a7d3b; --wt-radius: 4px; }`, or turn the stylesheet off with
  `add_filter( 'wintracker_load_css', '__return_false' );` and style the `.wintracker` classes in the theme.
- **Templates**: copy any file from `templates/` to `<theme>/wintracker/` (e.g. `wintracker/wins-cards.php`) and edit it.
- **Filters**: `wintracker_cache_ttl`, `wintracker_snapshot_ttl`, `wintracker_timeout` (default 5 s),
  `wintracker_label`, `wintracker_money`, `wintracker_template`, `wintracker_html`, `wintracker_load_css`.

## Updates from GitHub

The plugin updates through the normal wp-admin screens, from this repository's
[releases](https://github.com/didoivanov/wintracker-wp/releases): update notice on Plugins and Dashboard → Updates,
"View details" with the release notes, one-click update, and WordPress's "Enable auto-updates" link.

**Shipping a new version:** raise `Version:` and `WINTRACKER_VERSION` in `wintracker.php` (they must match) and
merge to the default branch. The [Release workflow](.github/workflows/release.yml) lints the PHP, builds
`wintracker-wp.zip` and publishes release `vX.Y.Z`. Sites see it within a few hours, or right away with the **Check for updates** link under Wintracker on the Plugins screen, or
**Dashboard → Updates → Check again**. Pushes that don't change the version publish nothing.

Optional `wp-config.php` constants:

```php
define( 'WINTRACKER_AUTO_UPDATE', true );   // install new versions automatically on this site (false: never)
define( 'WINTRACKER_GITHUB_TOKEN', '…' );   // read-only GitHub token: only needed if the repository becomes private,
                                            // or if many sites share one IP (GitHub allows 60 anonymous requests/hour)
```

Without `WINTRACKER_AUTO_UPDATE`, each site's own "Enable auto-updates" link decides. The token is sent only to
`api.github.com` / `codeload.github.com` for this repository.

## What is stored in the database

No custom tables and nothing per visitor. A few small options in `wp_options`:

| Option                  | What                                                                   | Autoloaded |
|-------------------------|------------------------------------------------------------------------|------------|
| `wintracker_api_key`    | the key saved on Wintracker → Settings (absent when it's in wp-config.php) | no     |
| `wintracker_cache_gen`  | a number raised by **Clear cache**, so old cache entries are skipped and expire | yes (a few bytes) |
| `wintracker_display`    | display defaults (columns, images)                                     | yes (a few bytes) |
| `wintracker_link_types` | post types for links (only when links are used)                        | no         |
| `wintracker_links`      | the links: Wintracker id → post id, matches only (~20 bytes each)       | no         |
| `wintracker_links_manual` | links set by hand: Wintracker id → post id, or 0 = never link        | no         |
| `wintracker_links_report` | counts and unmatched names for the settings page                     | no         |

Pages marked "don't link this page" have the `_wintracker_link` post meta set to `none`.

Everything else is a [transient](https://developer.wordpress.org/apis/transients/), which WordPress keeps in
`wp_options` too (or in Redis/Memcached when the site has a persistent object cache), each with an expiry:

| Entry (`_transient_…`)        | What                                                   | Lifetime                    | Size |
|-------------------------------|--------------------------------------------------------|-----------------------------|------|
| `wintracker_f_<hash>`         | API response of one widget configuration (fresh copy)  | 60 s                        | ~6 KB at the default `limit` 10, ~60 KB at 100 |
| `wintracker_s_<hash>`         | last good response of that widget (outage snapshot)    | 7 days, rewritten on every refresh | same |
| `wintracker_l_<hash>`         | "refresh in progress" lock                             | 30 s                        | tiny |
| `wintracker_paused`           | API failed: pause calls                                | 1–15 min                    | tiny |
| `wintracker_update_release`   | latest GitHub release (site transient)                 | re-checked every 3 h        | tiny |

So the database holds at most two copies per distinct widget configuration (`casino` + `sort` + `period` + …,
not per page: ten pages with the same shortcode share one entry), and none of them is autoloaded. Snapshots of
widgets nobody shows any more expire after 7 days. Deactivating leaves them to expire; uninstalling deletes them
and the two options.

## Notes

- A game stats card needs two cached API calls: `games.php` has no id filter, so the plugin gets the game's name
  from `wins.php?game=ID` and then finds that id in `games.php?q=NAME`. Merged game ids resolve to the surviving game.
- Uninstalling the plugin deletes its cached responses and snapshots.
