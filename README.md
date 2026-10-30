# WordPress Feature Flags

Generic feature flags for WordPress. Hide menu items, pages, blocks, Elementor elements and code paths until a flag goes live, with preview for admins and for logged-out reviewers who have a secret link.

- No dependencies, no build step, no front-end JS. The block editor panel and the Feature flag block share one plain ES5 file.
- **Fails closed.** A flag that isn't registered, or is misspelt, is off for everyone.
- Every gate runs on the server. Hidden content is never sent to the browser.

Requires WordPress 5.4+ and PHP 7.4+. Licence: GPL-2.0-or-later.

> **⚠ Deactivation caveat.** The plugin itself enforces gates set through fields: menu items, pages/posts, block classes, Elementor classes and the shortcode. **Deactivating the plugin reveals everything those gates hide.** Before you deactivate, retire the flags (see [Retiring a flag](#retiring-a-flag)). Code that guards with `function_exists( 'wpff_flag' )` still fails closed.

---

## Creating a flag in the admin

**Tools → Feature flags → Add a flag** creates a flag without code. Fill in:

- a key
- a label and description
- the starting mode
- the preview audience: Administrators, Editors and above, or Contributors and above
- an optional schedule window
- where hidden pages redirect to

Admin-created flags are stored in the `wpff_ui_flags` option. You change their mode from the flags table, and the **Edit** and **Delete** buttons are in their row. **Delete** is refused while pages or menu items still use the flag, because deleting it would hide them all.

If code registers a flag with the same key, the code registration takes over.

`WPFF_FLAG_<KEY>` constants and preview tokens work for admin-created flags exactly as they do for code flags.

## Registering a flag in code

Plugins, site-specific plugins and themes can also register flags in code.

```php
add_action( 'wpff_register', function () {
	wpff_register( 'store', array(
		'label'        => 'Recordings store',
		'description'  => 'Shop, cart, checkout and gated pages.',
		'owner'        => 'my-commerce-plugin',
		'default'      => 'preview',         // off | preview | on
		'constant'     => 'MY_STORE_LIVE',   // optional legacy constant to honour
		'admin_toggle' => false,             // true = mode can be set in Tools → Feature flags
		'starts'       => '',                // 'Y-m-d H:i' in the site timezone, optional
		'ends'         => '',                // optional
		'redirect'     => '',                // where hidden pages send visitors; '' = home. '/path/' or full URL
		'preview_cap'  => 'manage_options',  // who sees preview-mode flags
	) );
} );
```

Key rules: lowercase `a-z 0-9 _ -`, a letter or digit first, at most 40 characters, and no `not-` prefix.

`wpff_register` fires once, the first time anything looks up a flag after `plugins_loaded`. A lookup made before `plugins_loaded` returns false and triggers `_doing_it_wrong()`.

### Checking a flag in code

Guard against the plugin being missing so that check fails closed too:

```php
$store_on = function_exists( 'wpff_flag' ) && wpff_flag( 'store' );
```

In the consuming plugin's header, declare `Requires Plugins: wordpress-feature-flags` (WordPress 6.5+).

| Function | Returns | Use |
|---|---|---|
| `wpff_flag( $key )` | bool | Whether the flag is on **for the current visitor**. |
| `wpff_is_live( $key )` | bool | Whether the flag is on **for everyone** right now. It doesn't depend on the visitor, so use it in cron, REST and emails. |
| `wpff_mode( $key )` | `[mode, source]` | Source is `constant:NAME`, `admin`, `default` or `unregistered`. |
| `wpff_visible( $key, $invert )` | bool | Gate helper. With `$invert`, it means "show only while the flag is OFF". |
| `wpff_flags()` | array | All registered flags. |

Hooks:

| Hook | Type | Notes |
|---|---|---|
| `wpff_register` | action | Register flags here. |
| `wpff_flag_enabled` | filter | `( bool $enabled, string $key, string $mode )`. Runs for registered flags only. |
| `wpff_can_preview` | filter | `( bool $can, string $key )`. Extension point for preview audiences beyond `preview_cap`. |
| `wpff_cookie_ttl` | filter | `( int $seconds, string $key )`. Default is 14 days. |
| `wpff_gate_post_types` | filter | Post types that get the meta box. Default: `page`, `post`, and `product` when WooCommerce is active. |
| `wpff_mode_changed` | action | `( $key, $mode, $old )`. Fires after an admin toggle is saved. Purge caches here. |

## Modes

| Mode | Who sees it |
|---|---|
| `off` | Nobody, admins included. This is the kill switch. |
| `preview` | Users with `preview_cap`, plus browsers holding a valid preview cookie. |
| `on` | Everyone. If `starts`/`ends` are set, only inside that window; outside it the flag behaves as `preview`. |

### Precedence (first valid value wins)

1. The `WPFF_FLAG_<KEY>` constant in `wp-config.php`. Dashes become underscores, so `event-2027` reads `WPFF_FLAG_EVENT_2027`.
2. The flag's legacy `constant`.
3. The admin-set mode, used only for flags registered with `admin_toggle => true`.
4. The registered `default`.

Constant values are `'on'`, `'preview'` or `'off'`, or a boolean: `true` = on, `false` = preview. That way an existing "is it live?" boolean maps across directly.

```php
// wp-config.php
define( 'WPFF_FLAG_STORE', 'on' );
define( 'WPFF_FLAG_EVENT_2027', false ); // preview
```

## Preview

### Admins

Logged-in users with the flag's `preview_cap` see `preview` flags. Nobody sees `off` flags.

### Secret preview links

Set a token in `wp-config.php`. A per-flag token takes priority over the global one:

```php
define( 'WPFF_PREVIEW_TOKEN', 'a-long-random-string' );         // all flags
define( 'WPFF_FLAG_STORE_TOKEN', 'another-long-random-string' ); // just "store"
```

A token shorter than 12 characters disables link preview for that flag. Admin preview still works.

| Link | Effect |
|---|---|
| `/?wpff-preview=store:<token>` | Reveals `store` in this browser for 14 days. |
| `/?wpff-preview=store:off` | Clears `store` in this browser. |
| `/?wpff-preview=off` | Clears every flag in this browser. |

- Every link 302s to the same URL without the parameter, so the token stays out of browser history, analytics and forwarded links.
- A wrong token is ignored without any error, and the parameter is still stripped from the URL.
- The cookie `wpff_preview_<key>` holds an HMAC of the key and token, never the token itself. **Rotating the token invalidates every cookie issued with it.**
- Preview links are ignored on AJAX, cron and REST requests.
- **Tools → Feature flags** shows each flag's preview link.

### View as public visitor

The front-end admin bar has a **Flags** menu. It lists each flag's state and has a **View as public visitor** toggle. While the toggle is on (1-day cookie), you see exactly what a logged-out visitor with no cookies sees, including the redirects off gated pages. Use it to check go-live instead of an incognito window. Turn it off with **Back to admin view**.

## Gates (no code per use)

Every gate uses `wpff_visible()`. A normal gate shows content while the flag is on. An inverted gate shows content only while the flag is off. An unregistered key is treated as off.

### Menu items

Appearance → Menus → open an item → **Feature flag** and **Show only while flag is OFF**. A hidden item takes all of its descendants with it. Flags are only written when the Menus form posts them, so programmatic menu updates (Customizer, imports, AJAX) keep the existing flags.

### Pages, posts, products

The **Feature flag** box in the edit-screen sidebar. A visitor who can't see the page is sent with a 302 to the flag's `redirect`, or to home if none is set. If the target is the page itself, or the hidden page is the front page, the response is a 404 instead of a redirect loop. The WooCommerce shop page and the posts page are covered too.

Hidden posts **still appear** in archives, search, the shop loop and sitemaps in v1. Only direct visits are gated.

### Shortcode

Works in the classic editor, widgets and Elementor's Shortcode widget:

```
[feature_flag name="store"]Shown when on[/feature_flag]
[feature_flag name="store" show="off"]Coming soon[/feature_flag]
```

### Gutenberg blocks

Select a block. In the block settings sidebar, the **Feature flag** panel has three controls:

- **Show or hide this block with a feature flag** switches gating on for the block.
- **Feature flag** picks the flag.
- **Show only while flag is OFF** (optional) inverts the gate, for content like "coming soon" notices.

The panel stores the choice as a CSS class. You can also type the class yourself in Advanced → **Additional CSS class(es)**:

- `ff--store` renders the block only while `store` is on.
- `ff-not--store` renders the block only while `store` is off.

If a block has several flag classes, all of them must pass. Blocks that don't support custom classes don't get the panel.

### Feature flag block (wrapper)

To gate a whole section, insert the **Feature flag** block (Design category, or type "/feature flag") and put any blocks inside it. Then pick the flag in the block settings, and optionally turn on **Show only while flag is OFF**.

- List View names the block **Feature flag: store** (or **Feature flag: store (while OFF)**), so gated sections are easy to spot.
- In the editor, a header bar on the block shows the flag and its current mode.
- On the front end, the block adds no wrapper markup. The server renders its inner blocks only when the gate passes.
- A block with no flag picked yet shows its content to everyone.

To launch: switch the flag on. A common pattern is a "coming soon" wrapper set to **while OFF** next to a launch wrapper on the same flag, so one switch swaps them.

### Elementor

Use the same classes in Advanced → **CSS Classes** on widgets, sections, columns and containers. In the Elementor editor everything stays visible so you can still edit it. This gate uses Elementor's `elementor/frontend/{widget|section|column|container}/should_render` filters and the `_css_classes` / `css_classes` settings. **Check these against your installed Elementor version.** If they have moved, use the shortcode instead.

### Body classes

`ff-on--<key>` or `ff-off--<key>` is added for every flag. Use them for tweaks that only change presentation. Nothing is hidden from the HTML.

## Admin screen

**Tools → Feature flags** (`manage_options`) has one row per flag. Each row shows:

- key, label, owner and description
- mode and its **source**
- schedule window and where now falls in it
- whether the flag is live for the public
- what you see now
- the preview link
- **Where used**: every post and menu item using the flag, through the page field, menu fields, block panels or Feature flag blocks, with edit links

Flags registered with `admin_toggle`, and every admin-created flag, also get a mode selector there. Saving it fires `wpff_mode_changed`.

Under the table, **stale references** lists objects pointing at an unregistered key. Those objects are currently hidden, or shown if they are inverted.

## Caching

- Logged-in admins normally bypass the page cache, so admin preview always works.
- **A logged-out previewer can be served a cached page before PHP runs.** The plugin prevents pages rendered for previewers from being stored in the cache: it sends `DONOTCACHEPAGE`, `X-LiteSpeed-Cache-Control: no-cache` and `nocache_headers()`. It **cannot** stop a cache serving an entry it already has. You need to fix that at the cache layer: **don't cache requests carrying cookies that start with `wpff_preview_`**.

| Cache | Setting |
|---|---|
| LiteSpeed Cache (plugin) | LiteSpeed Cache → Cache → Excludes → **Do Not Cache Cookies**: add `wpff_preview_` |
| WP Rocket | Settings → Advanced Rules → **Never Cache Cookies**: add `wpff_preview_` |
| Hosting/server cache (Nginx, Varnish, hPanel and similar) | Bypass the cache when the `Cookie` header matches `wpff_preview_`. If the host doesn't expose this, ask support to add it. |
| Cloudflare APO / cache rules | Bypass the cache on the cookie `wpff_preview_*`. |

**Every state change needs a cache purge.** That includes a constant flip, a token rotation, and a schedule window starting or ending. When `wpff_mode_changed` fires, the plugin purges LiteSpeed Cache (`litespeed_purge_all`), WP Rocket (`rocket_clean_domain()`) and the object cache (`wp_cache_flush()`) where they are available. For anything else, purge by hand.

Flags with a schedule window flip with no one around to purge. Either exclude pages gated by windowed flags from the page cache, or accept up to one cache TTL of lag.

## Retiring a flag

Once a feature is permanently live:

1. Remove the `wpff_flag()` checks from code.
2. Clear the flag from menu items, posts, block panels and Feature flag blocks. Tools → Feature flags → **Where used** lists them all. For a launched flag, unwrap Feature flag blocks (keep their inner blocks) and delete the "while OFF" ones.
3. Remove the registration.
4. Remove its constants and tokens from `wp-config.php`.

**Never remove the registration first.** Everything still referencing the flag goes dark.

## Uninstall

Deleting the plugin removes the `wpff_modes` and `wpff_ui_flags` options. Post and menu-item meta (`_wpff_flag`, `_wpff_flag_invert`) is left in place.
