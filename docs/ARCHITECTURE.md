# Architecture — public marketing site

This repo holds both the public marketing site and (eventually) the
authenticated app. One deploy, one Tailwind theme, one place the brand system
stays in sync.

**Content and launch blockers live in [CONTENT-TODO.md](CONTENT-TODO.md).**

---

## What this session built

A bilingual (EN/AR) pre-launch marketing site with lead capture, plus the shared
component library and brand tokens the app-foundation session will reuse.

Laravel 13 · Tailwind 4 · Blade (no Livewire — nothing here needs it) · Pest.

---

## Route boundary

Marketing routes are the public front door and are **explicitly unauthenticated
and outside any tenancy middleware**. When the app-foundation session adds
tenant/auth routes, they get their own group — do not wrap these.

| Route | Name | Locale |
|---|---|---|
| `GET /` | `marketing.home` | en |
| `GET /ar` | `marketing.home.ar` | ar |
| `POST /early-access` | `marketing.leads.store` | from form field |

Locale is carried **in the URL, not the session**, so a link shared on WhatsApp —
or an ad destination — keeps its language, and each locale is separately
indexable (`hreflang` tags are in the layout). `SetMarketingLocale` reads it from
a route default on GET, and from the form's hidden `locale` field on POST, so a
submission's validation errors and thank-you state come back in the language it
was filled in.

The `leads` table has **no `tenant_id`** — a lead exists before a tenant does.

---

## Brand tokens

Defined once, in `@theme` in [`resources/css/app.css`](../resources/css/app.css).
Tailwind 4 is CSS-first, so there is no `tailwind.config.js`; that file is the
config. **Token names are contractual** — the app must use the same ones.

| Token | Hex | Role |
|---|---|---|
| `trust-navy` | `#0B3B5C` | Primary / structural |
| `compliance-green` | `#1B7A5B` | Secondary; also "filed / compliant" |
| `warm-gold` | `#C9971F` | Sparing accent — CTAs and highlights only |
| `warm-offwhite` | `#F7F5F1` | Background |
| `warm-charcoal` | `#22262B` | Body text |
| `status-due-soon` | `#D9822B` | Amber — deliberately outside the brand palette |
| `status-overdue` | `#C0392B` | Red — same |

Roughly 60% offwhite ground / 30% navy structure / 10% green+gold accent.

**No directional gradients, no asymmetric decorative colour blocking** — the
palette has to survive a full RTL mirror unchanged. Backgrounds are flat fills.

### Contrast — measured, not assumed

The whole page was audited in-browser (135 text nodes, both locales, alpha
composited against actual painted backgrounds). **Zero WCAG AA failures.**
Three results worth carrying into the app:

- **Gold is never a text colour.** `#C9971F` on the offwhite ground is 2.4:1.
  It is only ever a *fill under `warm-charcoal`* (5.7:1). Gold text on navy is
  4.4:1 — fails AA at body sizes; use a gold chip instead.
- **Green-on-green-tint chips fail.** `compliance-green` on
  `compliance-green/12` measures 4.1:1. Use solid green + white (5.3:1).
- **`/60` and `/65` muted text fails** on the offwhite ground (≈4.0–4.2:1).
  `/70` is the floor (5.5:1).

Status badge pairings, all AA: green + white (5.3), amber + charcoal (5.2),
red + white (5.4). Colour is never the only signal — the badge always states
the status in words.

---

## Typography

Inter (Latin) + IBM Plex Sans Arabic — a real pairing, matched on x-height and
weight axis. Both are **self-hosted at build time** by the Vite fonts plugin
(`vite.config.js`), not pulled from a CDN at runtime: one less third party, and
no cross-origin request on a first paint from a phone on mobile data.
`Vite::fonts()` in the layout emits the `@font-face` rules and preloads.

> **Gotcha:** the Arabic family needs `subsets: ['arabic', 'latin']`. The
> provider defaults to latin-only, which ships `@font-face` rules whose
> `unicode-range` excludes U+0600–06FF — every Arabic glyph then silently falls
> back to a system font and the pairing never actually loads. This was caught in
> the browser, not by any test.

Both families load on both locales, so the wordmark stays Latin under RTL and
the language switcher can render the other language in its own script.

---

## RTL

- `dir` and `lang` set on `<html>` from the active locale
- **Logical properties throughout** — `ps-*`/`pe-*`, `ms-*`/`me-*`, `border-s`,
  `text-start`. A test asserts no `pl-`/`pr-`/`ml-`/`mr-` reaches the rendered
  page.
- Accent rules sit on the *start* edge (`border-s-4`), so they mirror
- Nav, CTA placement and the disclosure chevron all flip
- `.rtl-flip` utility in `app.css` for any icon that encodes direction

> **Gotcha, learned the hard way:** never park a hidden field at a negative
> offset (`left:-9999px`) on a bidirectional page. Under RTL a large negative
> offset overflows *in the scrollable direction* — it stretched the Arabic
> document to ~10,000px wide and pushed every section off-screen. The honeypot
> now hides with clipping (`sr-only`). There is a regression test for it.

---

## Component library

Built for both this site and the app. Reuse and extend these rather than
creating parallel components with different names.

| Component | Notes |
|---|---|
| `<x-wordmark>` | **Placeholder.** One-file logo swap — see CONTENT-TODO §1 |
| `<x-button>` | `cta` / `primary` / `secondary` / `ghost`, 3 sizes; `<a>` when `:href` given |
| `<x-card>` | `light` / `muted` / `navy`; optional start-edge accent rule |
| `<x-status-badge>` | `filed` / `due-soon` / `overdue`. **The app's filing-status badge** — keep these keys stable |
| `<x-form.field>` | Label, hint, optional marker, error — the shared validation state |
| `<x-form.input>` | Renders through `field`; wires `aria-invalid` / `aria-describedby` |
| `<x-form.select>` | Same. No custom chevron background-image (it would need mirroring) |
| `<x-pricing-table>` | Reads tiers from the locale files; reusable on an in-app upgrade screen |
| `<x-photo-slot>` | Deliberate image placeholder — see CONTENT-TODO §3 |
| `<x-nav>` | Sticky header, language switcher, native `<details>` mobile menu (no JS) |

> **Gotcha:** passing `hidden sm:block` to `<x-button>` does not work. The
> component already carries `inline-flex`, and Tailwind emits `inline-flex`
> *after* `hidden`, so the utility on the call site loses the cascade regardless
> of attribute order. Wrap the button in a `<span>` and put the responsive
> display utility there.

---

## Content

All copy is in `lang/{en,ar}/marketing.php`, structurally mirrored. Adding a key
to one without the other renders a raw key on the other page.

`lang/ar/validation.php` covers only the rules the lead form uses, plus
`attributes` so Arabic errors name the field in Arabic.

**The Arabic is a best-effort draft pending native review — see CONTENT-TODO §2.**

---

## Tests

Pest, `./vendor/bin/pest`. 29 tests.

- `MarketingHomeTest` — renders in both locales with the right `dir`; all six
  sections present and in order; hero price precedes both the pricing table and
  the form; the three §7 tiers and prices; logical-property enforcement; gold
  never paired with light text; no negative-offset regression; no government
  imagery
- `LeadCaptureTest` — valid storage, optional field, locale recording, honeypot
  dropped silently, required-field and format validation, **per-locale error
  messages**, rendered thank-you state in both languages, rate limiting
- `LanguageSwitcherTest` — switcher on every page, locale persists across page
  loads, whole page in one language with no English stubs, `lang`/`dir` correct

---

## Explicitly not built

Authenticated app screens · Whish/OMT billing (§6 Phase 2) · CRM or email
automation · final logo · native-reviewed Arabic · real photography.
