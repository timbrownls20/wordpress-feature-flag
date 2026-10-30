# wordpress-feature-flags — Handover spec for Claude Code

A generic, site-agnostic WordPress plugin for feature flags: hide menu items, pages, blocks and code paths until a flag goes live, with admin and secret-link preview. It generalises the pattern built for the QHTA recordings store (`QHTA_STORE_LIVE` constant + admin/cookie preview + per-menu-item "store link" checkbox). **QHTA is the first consumer, not part of the plugin** — nothing site-specific lives in this codebase.

**Status:** spec only — not built. Defaults are proposed; **Q#** = open question (see end). Build with clearly-commented defaults where a question is unanswered.

---

## 1. Goals / non-goals

**Goals**
- Any plugin or theme can register a flag in a few lines and check it with one function.
- Editors can gate menu items, pages/posts, blocks and Elementor elements by picking a flag — no code per use.
- Safe preview: admins, plus a secret link for logged-out reviewers.
- Fail closed: unknown flags are off.
- No dependencies, no build step, no front-end JS (the block editor panel is a single plain-ES5 admin script). Drop-in on any WordPress site.
- Flags can be created in the admin UI as well as in code, and blocks/pages gated from the editor without typing classes.

**Non-goals (v1)**
- Percentage rollouts / A-B testing, per-user targeting, remote flag services, multisite network-level flags.

## 2. Naming

| Thing | Value |
|---|---|
| Plugin folder / slug | `wordpress-feature-flags` (**Q11**) |
| Main file | `wordpress-feature-flags.php` |
| Plugin Name header | WordPress Feature Flags |
| Text domain | `wordpress-feature-flags` |
| Function / hook prefix | `wpff_` (public helper `wpff_flag()`) |
| Constant prefix | `WPFF_` |
| Meta keys | `_wpff_flag`, `_wpff_flag_invert` |
| Option | `wpff_modes` (autoload no) |
| Cookie prefix | `wpff_preview_` |
| Query arg | `wpff-preview` |
| Shortcode | `[feature_flag]` |
| CSS classes | `ff--<key>`, `ff-not--<key>` (blocks/Elementor); `ff-on--<key>` / `ff-off--<key>` (body) |

## 3. Concepts

### Flags
A flag is a short key (`store`, `new-checkout`, `event-2027`) registered **in code** by whatever owns the feature. Keys: lowercase `a-z 0-9 _ -`, max 40 chars, must not start with `not-`.

```php
add_action( 'wpff_register', function () {
	wpff_register( 'store', array(
		'label'        => 'Recordings store',
		'description'  => 'Shop, cart, checkout and gated pages.',
		'owner'        => 'my-commerce-plugin',
		'default'      => 'preview',         // off | preview | on
		'constant'     => 'MY_STORE_LIVE',   // optional legacy constant to honour
		'admin_toggle' => false,             // Q2
		'starts'       => '',                // 'Y-m-d H:i' site timezone, optional
		'ends'         => '',                // optional
		'redirect'     => '',                // where hidden pages send visitors; '' = home
		'preview_cap'  => 'manage_options',  // who sees preview-mode flags
	) );
} );
```

`wpff_register` (action) fires once, lazily, on the first lookup after `plugins_loaded`, so registrations from any plugin or the theme are picked up. Lookups before `plugins_loaded` return false and trigger `_doing_it_wrong()`.

### Modes
| Mode | Who sees it |
|---|---|
| `off` | Nobody, admins included (kill switch) |
| `preview` | Users with `preview_cap` + browsers holding a valid preview cookie |
| `on` | Everyone — only inside `starts`/`ends` if set; outside the window it behaves as `preview` |

### Mode precedence (first valid value wins)
1. `WPFF_FLAG_<KEY>` constant in wp-config (`'on'|'preview'|'off'`, or `true` = on / `false` = preview). Dashes → underscores: `event-2027` → `WPFF_FLAG_EVENT_2027`.
2. The flag's legacy `constant`, same value rules — lets existing switches (e.g. `QHTA_STORE_LIVE`) keep working.
3. Admin-set mode — only if registered with `admin_toggle => true` (**Q2**).
4. Registered `default`.

`true = on, false = preview` so a pre-existing "is it live?" boolean maps naturally.

### Fail closed
- Unregistered / misspelt flag → **off** for everyone.
- Consumers guard so a deactivated plugin also fails closed: `$on = function_exists( 'wpff_flag' ) && wpff_flag( 'store' );` — and declare `Requires Plugins: wordpress-feature-flags` (WP 6.5+).
- Known exception: field/meta-driven gates (menus, pages, blocks) are enforced *by this plugin*, so **deactivating it reveals what they gate**. Document prominently; see **Q6**.

## 4. Public API

| Function | Returns | Use |
|---|---|---|
| `wpff_flag( $key )` | bool | On **for the current visitor**? The check feature code uses. Filter: `wpff_flag_enabled`. |
| `wpff_is_live( $key )` | bool | On **for everyone** now (mode on + in window). Visitor-independent — cron, REST, emails. |
| `wpff_register( $key, $args )` | bool | Register, inside the `wpff_register` action. |
| `wpff_mode( $key )` | `[mode, source]` | Source: `constant:NAME` / `admin` / `default` / `unregistered`. |
| `wpff_visible( $key, $invert )` | bool | Gate helper; inverted = "show only while OFF". |
| `wpff_flags()` | array | All registered flags. |

Hooks: `wpff_register` (action), `wpff_flag_enabled`, `wpff_cookie_ttl`, `wpff_gate_post_types`, `wpff_mode_changed` (action, after an admin toggle save — integrations purge caches here).

## 5. Preview audience

### Admins
Logged-in users with the flag's `preview_cap` see `preview` flags. Never `off` flags.

### Secret preview link
- `/?wpff-preview=<flag>:<token>` → sets a cookie for that flag, then **302s to the same URL without the param** (token stays out of history, analytics and forwarded links).
- `?wpff-preview=<flag>:off` clears that flag; `?wpff-preview=off` clears all.
- Token: `WPFF_FLAG_<KEY>_TOKEN` in wp-config, falling back to global `WPFF_PREVIEW_TOKEN`. Under 12 chars → link preview disabled for that flag.
- Cookie `wpff_preview_<key>` holds `hash_hmac('sha256', key|token, wp_salt('auth'))`, never the token. Forged cookies fail; **rotating the token invalidates every issued cookie**.
- Cookie flags: HttpOnly, Secure on SSL, SameSite=Lax, COOKIEPATH/COOKIE_DOMAIN, 14-day TTL (**Q4**).
- Wrong token: silently ignored (no oracle) but still stripped from the URL.
- Not processed on AJAX, cron or REST requests.

### "View as public"
Admin bar toggle sets `wpff_preview__view_public` (nonce-protected link, 1-day cookie). While set, the admin sees exactly what a logged-out, cookieless visitor sees — replaces the incognito check at go-live.

## 6. Integrations (field-driven, no code per use)

All gates evaluate server-side; hidden content is never sent to the browser. No front-end JS, no global DOM selectors.

### 6.1 Menu items
- Per-item fields via `wp_nav_menu_item_custom_fields`: **Feature flag** select (blank = none) + **Show only while flag is OFF**.
- Meta on the menu item. Save on `wp_update_nav_menu_item` only when the form posted a hidden marker for that item (programmatic updates don't clobber), requiring `edit_theme_options`.
- `wp_nav_menu_objects` (front end): drop hidden items **and their descendants**.

### 6.2 Posts / pages / products
- Side meta box: **Feature flag** + **Show only while OFF**. Post types: `page`, `post`, plus `product` if WooCommerce is active; filter `wpff_gate_post_types`.
- `template_redirect` priority 5: hidden for this visitor → `nocache_headers()` + 302 to the flag's `redirect` (or home). If that target is the current URL or the front page, 404 instead of looping.
- Nonce + `current_user_can( 'edit_post' )` + autosave guard; reject unregistered keys.
- Hidden posts still appear in archives/search/sitemaps — **Q5**.

### 6.3 Shortcode (works in Elementor's Shortcode widget, classic editor, widgets)
```
[feature_flag name="store"]Shown when on[/feature_flag]
[feature_flag name="store" show="off"]Coming soon[/feature_flag]
```
Content is `do_shortcode`d. Unknown flag = off.

### 6.4 Gutenberg blocks
`render_block` filter acting **only** when the block's `className` contains `ff--` or `ff-not--`:
- Additional CSS class `ff--store` → render only when on.
- `ff-not--store` → render only when off.
Hidden blocks return `''`.

### 6.5 Elementor
Same class convention via `elementor/frontend/{widget|section|column|container}/should_render`, reading `_css_classes` / `css_classes`. **Verify hook names and setting keys against the installed Elementor before relying on it**; fall back to the shortcode if they've moved. No-op when Elementor is absent.

### 6.6 Body classes
`ff-on--<key>` / `ff-off--<key>` for presentation-only tweaks.

## 7. Admin

### Tools → Feature flags (`manage_options`)
One row per flag: key, label, owner, description; mode + **source**; window + state (before/within/after); **live for public?**; **what you see now**; preview link (if a token is set); mode select + Save for `admin_toggle` flags (fires `wpff_mode_changed`); **Where used** — posts and menu items with `_wpff_flag = key` (any status), with edit links.

Below: **stale-reference warnings** — meta pointing at an unregistered key (those objects are currently hidden).

### Admin bar (front end, `manage_options`)
"Flags" node → one child per flag (`store: preview — you see it`) + "View as public visitor" / "Back to admin view".

## 8. Caching

- Logged-in admins normally bypass page cache → admin preview always works.
- A **logged-out cookie previewer can be served a cached page** before PHP runs. The plugin stops previewer-rendered pages being cached (`DONOTCACHEPAGE`, `X-LiteSpeed-Cache-Control: no-cache`, `nocache_headers()`), but can't stop a cache serving an existing entry.
- Fix at the cache layer: exclude requests with cookies prefixed `wpff_preview_` (e.g. LiteSpeed Cache → Excludes → Do Not Cache Cookies; WP Rocket "Never cache cookies"). README gives recipes for common caches (**Q3**).
- **Any state change needs a purge** — constant flip, token rotation, window start/end. Built-in: on `wpff_mode_changed`, call `do_action( 'litespeed_purge_all' )` / `rocket_clean_domain()` / `wp_cache_flush()` when available. Windowed flags flip with nobody there to purge → README says: exclude pages gated by windowed flags from page cache, or accept up to one TTL of lag.

## 9. Conventions

- Standard header; GPL-2.0-or-later; `Requires at least: 5.4`; `Requires PHP: 7.4`.
- Version constant `WPFF_VERSION` — **dual version bump** (header + constant).
- `if ( ! defined( 'ABSPATH' ) ) exit;` in every file; `function_exists()` / `class_exists()` guards around WooCommerce, Elementor and cache-plugin calls.
- Nonce + capability on every save path; escape all output; i18n-ready strings.
- No Composer, no build step, no JS (plain ES5 only if ever unavoidable).
- README.md + CHANGELOG.md from 1.0.0. `uninstall.php` deletes the option; leaves post meta (**Q6**).

```
wordpress-feature-flags/
├── wordpress-feature-flags.php   # header, constants, requires
├── includes/
│   ├── registry.php              # register, modes, precedence, window, wpff_flag()
│   ├── preview.php               # token, signed cookie, ?wpff-preview, view-as-public, no-cache headers
│   ├── integrations.php          # menus, post gate, shortcode, blocks, Elementor, body class
│   ├── cache.php                 # purge-on-change adapters
│   ├── admin.php                 # Tools → Feature flags
│   └── admin-bar.php
├── uninstall.php
├── README.md
└── CHANGELOG.md
```

## 10. Acceptance criteria (done =)

1. Unregistered flag → false for everyone, admins included; gated items hidden; admin screen warns.
2. Precedence: `WPFF_FLAG_<KEY>` > legacy constant > admin option > default; source shown correctly.
3. `preview`: logged-out sees nothing gated; admin sees it. `off`: admin sees nothing either.
4. `on` with future `starts` behaves as preview until then; past `ends` behaves as preview.
5. `?wpff-preview=store:<token>` reveals for that browser logged-out, then redirects without the param; `store:off` and `off` hide again; wrong token does nothing and is stripped.
6. Forged cookie, or valid cookie after token rotation → nothing revealed.
7. No/short token → link preview disabled; admin preview still works.
8. View-as-public shows exactly the logged-out view until toggled back.
9. Menu: flagged item + descendants hidden when off; inverted shown only while off; unflagged untouched; programmatic menu saves keep flags.
10. Post gate redirects to the flag's redirect (or home); never loops (404 when target = self/front page).
11. Shortcode, block classes and (if verified) Elementor classes render/strip correctly incl. inverted.
12. Works with WooCommerce, Elementor and every cache plugin absent — no fatals, no notices under `WP_DEBUG`.
13. Nothing site-specific in the codebase (grep for `qhta` returns nothing).
14. README: registering a flag, constants/tokens, preview links, view-as-public, all gate types, caching recipes, deactivation caveat, retiring a flag. CHANGELOG at 1.0.0.

## 11. Retiring a flag (README)

Once permanently live: remove `wpff_flag()` checks from code → clear the flag from menu items/posts (admin "Where used") → remove the registration → remove its constants/tokens. Never remove the registration first — everything still referencing it goes dark.

---

## 12. First consumer: QHTA (lives in QHTA plugins, not here)

- Install `wordpress-feature-flags` 1.0.0 on qhta.com.au.
- `qhta-commerce` registers `store` with `'constant' => 'QHTA_STORE_LIVE'`, owner `qhta-commerce`, declares `Requires Plugins: wordpress-feature-flags`.
- Replace store-visibility checks with `function_exists( 'wpff_flag' ) && wpff_flag( 'store' )`.
- One-off idempotent migration on `qhta-commerce` upgrade: old "Store link" menu checkbox meta → `_wpff_flag = store`; remove the old checkbox.
- Store pages (Cart 3947, Checkout 3948, Shop, gated pages): tag with the field, or keep the hard-coded list calling `wpff_flag()` (**Q1**).
- Hostinger cache: add the `wpff_preview_` cookie exclusion (**Q3**).
- Regression: with `QHTA_STORE_LIVE = true` the store behaves exactly as today.

---

## Open questions (defaults in brackets)

- **Q1 — QHTA store migration.** Migrate `qhta-commerce`'s store preview now, or use flags only for new work? If migrating: tag pages via the field or keep the hard-coded list; keep `?qhta-store-preview=` as an alias? *[Later qhta-commerce release; tag via the field; drop the old link — store is live, token retired.]*
- **Q2 — Admin toggles.** Constant-only, or opt-in `admin_toggle` per flag so non-technical people can flip marketing/event flags without wp-config? *[Opt-in per flag, off by default.]*
- **Q3 — Cache recipes.** Which caches must the README and purge adapters cover? On QHTA: is it the LiteSpeed Cache plugin or hPanel's own cache? *[LiteSpeed Cache + WP Rocket + generic; confirm QHTA's.]*
- **Q4 — Preview cookie lifetime.** *[14 days, filterable.]*
- **Q5 — Hidden posts in listings.** Also remove from archives, search, shop loop and sitemaps, or is the redirect enough? *[Redirect only in v1.]*
- **Q6 — Fail-open on deactivation.** Accept with README warning, or ship an optional mu-plugin guard that hides anything carrying `_wpff_flag` when the main plugin is missing? *[README warning + `Requires Plugins`; no mu-plugin.]*
- **Q7 — Preview audience.** Per-flag `preview_cap` enough, or do you want role lists / membership-level audiences (e.g. a PMPro committee level)? *[`preview_cap` only; a `wpff_can_preview` filter as the extension point.]*
- **Q8 — Schedule windows.** Keep `starts`/`ends` in v1 despite the cache-lag caveat? *[Keep — small, and the QHTA event banner already wants it.]*
- **Q9 — Registering ad-hoc flags.** A flag for one page/banner with no owning plugin needs a home: the theme's `functions.php`, a site-specific plugin, or an admin "Add flag" screen (stored in the option, implies admin toggles)? *[**Decided:** "Add flag" UI in v1 — stored in `wpff_ui_flags`, always admin-toggled; code registration of the same key wins. Delete refused while referenced.]*
- **Q10 — WP-CLI.** `wp feature-flags list | status <key> | set <key> <mode>`? *[Not in v1.]*
- **Q11 — Distribution / slug.** Private GitHub repo only, or might it go on wordpress.org? The directory rejects slugs and names starting with "WordPress" (trademark) — would need e.g. `feature-flags-for-wp`. *[Private repo; keep `wordpress-feature-flags`.]*
- **Q12 — Licence & repo.** GPL-2.0-or-later under your personal GitHub (not the QHTA org), since it's generic? *[Yes.]*