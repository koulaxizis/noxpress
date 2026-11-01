# DESIGN.md — Noxpress

Design language for every Noxpress surface: the WordPress admin screens of all suite plugins, the Hub, and noxpress.tech. Read this before designing or reviewing any Noxpress UI.

The source of truth is [Noxpress_bible.md](Noxpress_bible.md) (§2 palette, §3 panel, §4 hardening, §5 UI recipes, §7 footer, §14 frontend, §17 download counter, §18 support). Where this file and the Bible differ, the Bible wins and this file gets fixed. The reference implementation for the admin is `plugins/revenue-splitter/assets/admin.css` (Bible: "leading design").

## Identity

- Free, MIT WordPress/WooCommerce plugins that replace third-party plugins. Calm, professional, dark.
- Accent colour: violet `#6d4aff`. Purple is Noxpress's colour; Christos's other projects (e.g. NULL JSFX) do not use it.
- No ads, upsells, licence keys, nags or tracking anywhere in the UI.
- Support inside the plugins: only the Ko-fi pill in the footer (Bible §7, §18). On the site: the `#support` section and the Ko-fi follow box (`#updates`); Ko-fi is the only news channel.

## Admin palette (dark, same in every plugin)

Tokens are CSS custom properties with the plugin's prefix (`--rs-*`, `--sp-*`, `--sf-*`, `--tp-*` …). Bible §2.

| Token | Value | Use |
|---|---|---|
| bg | `#101218` | page panel |
| panel | `#14161d` | zones, tables |
| panel-2 | `#1d2029` | thead, progress track, code |
| input | `#1a1d26` | inputs, selects, textareas |
| border | `#2e3240` | zone and table borders |
| border-input | `#3a3f52` | input borders |
| row-border | `#23262f` | table rows, footer |
| stripe | `#181b23` | even rows |
| hover | `#1e212c` | row hover |
| text | `#eae8fa` | body text |
| heading | `#f6f4ff` | h1/h2 |
| muted | `#b7b2d6` | descriptions, hints |
| link / link-hover | `#beb1ff` / `#d4c9ff` | links |
| accent / accent-hover | `#6d4aff` / `#8263ff` | primary buttons, checked states, Ko-fi |
| warn | `#f5c97e` | warnings |
| danger | `#ff9a9a` | errors |

Supporting values (Bible §2, used by every plugin through Noxpress Core):

| Value | Use |
|---|---|
| `#2b2e36` | border of the page panel |
| `#f0eefc` | text inside inputs, selects, textareas |
| `#8b87a3` | placeholders |
| `#e6e1fd` | table header text |
| `#eeeafc` | text of dark `.button` |
| `#ffffff` | text of `.button-primary` and the Ko-fi pill, emphasised numbers in tables |
| `#a29dc0` | footer text |
| `#9fe0b5` | success / "ok" text and badges |
| `#3d6b4f` / `#6b5a33` / `#6b3a3a` | badge borders: ok / warn / danger |

## Site palette (noxpress.tech)

`--bg #0d0f14`, `--surface #14161d`, `--border #262a35`, `--text #e4e2f5`, `--muted #9b97b8`, `--accent #6d4aff`, `--accent-bright #b3a4ff`. Bilingual EN/EL.

- Layout: one column, `max-width: 960px`, 24px side padding.
- The site may use a soft accent glow and a faint radial gradient on buttons and highlight cards; the admin never does.
- Beta badge (orange): border `#e0a84f`, text `#f5c97e`, background `rgba(224, 168, 79, .14)`.

## Typography

- Admin: the WordPress admin font stack; no fonts are loaded into wp-admin. Code and ids: `Consolas, Monaco, monospace`.
- Site: `system-ui, -apple-system, "Segoe UI", Roboto, sans-serif`, 16px/1.65; headings bold, `clamp(2rem, 5vw, 3rem)` for the hero.
- No web-font services or CDNs. Any added font is self-hosted woff2 and must contain Greek glyphs.

## Layout and components (admin)

- Page = `<div class="wrap {p}-wrap">`, `color-scheme: dark`, bg background, border `#2b2e36`, full width (no `max-width` on the wrap; limits only on single elements), radius 8px, padding 32/36/28, shadow `0 1px 3px rgba(0,0,0,.35)`. Bible §3.
- Section titles `h2.{p}-h2`: 15px, uppercase, muted, bottom border.
- Field groups `.{p}-zone`: panel background, border, radius 6px, padding 16/18.
- Buttons: `.button` dark (`#1e2130`, border `#4a4f66`), `.button-primary` in the accent. Bible §5.
- Notices inside the page: `.notice.inline`.
- Unified «Noxpress» menu; the Hub is its root (Bible §8).
- Colours carry `!important` inside the plugin's own scope so core/third-party light styles cannot break the panel; nothing leaks outside `.{p}-wrap` (Bible §4).

## Footers

- Admin footer, identical on every plugin page, at the end of `.{p}-wrap` (Bible §7):
  `Made with ❤ by Christos Koulaxizis · glarolykoi.net · More plugins at noxpress.tech`, then the Ko-fi pill in the accent (`https://ko-fi.com/koulaxizis`) and the «Noxpress Dashboard» link (`admin.php?page=noxpress`). External links open with `target="_blank" rel="noopener noreferrer"`.
- Site credits, verbatim: "Designed with <3 by Christos Koulaxizis (koulaxizis.gr). Assisted by Lumo. Audited by Claude."

## Frontend (what shoppers see)

- No visible change until the store owner configures it; test mode shows changes to `manage_woocommerce` users only (Bible §14.1–2).
- The theme's look wins: plugin output inherits the theme's fonts and buttons (`class="button"` plus `wc_wp_theme_get_element_class_name( 'button' )`, §14.16). A scoped reset protects plugin markup from hostile theme rules (§14.13).
- Inline SVG icons, no icon fonts; `<details>` for anything that opens and closes.
- Visitor-facing texts are store settings with Greek and English values (§14.17).

## Do / don't

- Do: dark admin, one accent, generous spacing, clear numbered steps, beta badge (orange) until Christos says «stable».
- Don't: light admin panels, gradients or glow in the admin, second accent colours, third-party CDNs, tracking, marketing pop-ups.
