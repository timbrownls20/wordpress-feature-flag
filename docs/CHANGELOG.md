# Changelog

## 1.0.0

- Flag registry: `wpff_register` action, `wpff_register()`, `wpff_flag()`, `wpff_is_live()`, `wpff_mode()`, `wpff_visible()`, `wpff_flags()`.
- Mode precedence: `WPFF_FLAG_<KEY>` constant > legacy constant > admin toggle (opt-in) > default. Optional `starts`/`ends` schedule window.
- Preview: `preview_cap` users, signed `?wpff-preview=<flag>:<token>` links (HMAC cookie, 14-day TTL, filterable), "view as public visitor" admin bar toggle.
- Gates: menu items (with descendants), pages/posts/products (redirect or 404), `[feature_flag]` shortcode, Gutenberg `ff--`/`ff-not--` block classes, Elementor element classes, body classes.
- Create, edit and delete flags in Tools → Feature flags (stored in `wpff_ui_flags`; mode, preview audience, schedule and redirect set in the UI).
- Block editor "Feature flag" panel on every block: toggle, flag picker and "show only while OFF".
- "Feature flag" wrapper block (`wpff/feature-flag`): nest any blocks; rendered server-side only when the gate passes; labelled "Feature flag: <key>" in List View.
- Tools → Feature flags screen with "where used" (incl. block classes and wrapper blocks in content) and stale-reference warnings; admin bar status.
- No-cache headers for previewers; cache purge on admin mode change (LiteSpeed Cache, WP Rocket, object cache).
