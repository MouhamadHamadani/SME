# SME Compliance Accounting & Payroll Suite

Bilingual (AR/EN) cloud accounting and payroll SaaS for Lebanese SMEs. Native
NSSF/EOSI payroll, VAT compliance, true LBP/USD dual-currency accounting.

Laravel 13 · Tailwind 4 · Blade · Pest · PHP 8.3.

**Read [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) before changing UI or
routes.** It carries the brand tokens, the contrast rules, the component
inventory, and three non-obvious gotchas that cost real debugging time.
Launch blockers and placeholder content are in
[`docs/CONTENT-TODO.md`](docs/CONTENT-TODO.md).

## What exists today

1. **Public marketing site** — homepage, bilingual content, lead capture.
2. **App foundation** — stancl/tenancy v3 (one database per business, subdomain
   resolution), sign-in, and an authenticated shell reusing the same components.

No accounting domain yet (payroll/NSSF/EOSI/VAT), no billing, no self-serve signup.

## Hard rules

- **The marketing routes are the public front door.** `/`, `/ar` and
  `POST /early-access` are explicitly unauthenticated and must stay outside any
  tenant/auth middleware. App routes get their own group.
- **`leads` has no `tenant_id`** — a lead exists before a tenant does.
- **Infrastructure tables are CENTRAL; business data is per-tenant.** `sessions`,
  `cache` and `jobs` connections are pinned to the central connection. Unpinning
  any of them makes every authenticated page 500, and the test suite's array
  drivers will NOT catch it.
- **Route order is load-bearing.** bootstrap/app.php registers tenant routes
  before marketing routes and applies `web` to both. Read the comment there
  before touching it.
- **Do not invent the NSSF/VAT schema.** Contribution rates, ceilings and
  brackets are real regulatory data; a plausible-looking guess computes wrong
  salaries. Get the figures first.
- **Reuse the brand tokens, do not redefine them.** They live in `@theme` in
  `resources/css/app.css` (Tailwind 4 is CSS-first; there is no
  `tailwind.config.js`). Token names are contractual across the site and app.
- **Reuse the components in `resources/views/components/`** rather than creating
  parallel ones with different names. `<x-status-badge>` in particular is the
  app's filing-status badge — keep the `filed` / `due-soon` / `overdue` keys.
- **Logical CSS properties only** — `ps-*`/`pe-*`, `ms-*`/`me-*`, `border-s`.
  Never `pl-`/`pr-`/`ml-`/`mr-`. A test enforces this on the rendered page.
- **No gradients, no asymmetric decorative colour blocking.** The palette has to
  survive a full RTL mirror unchanged.
- **Never park a hidden element at a negative offset** (`left:-9999px`). Under
  RTL it overflows in the scrollable direction and destroys the layout. Hide
  with clipping (`sr-only`).
- **No NSSF or Ministry of Finance seals, logos or government imagery** anywhere.
  Text claims are fine; imagery needs a legal check that has not happened.
- **Copy lives in `lang/{en,ar}/marketing.php`**, structurally mirrored. Adding a
  key to one without the other renders a raw key on the other page. The Arabic
  is a draft pending native review — see `docs/CONTENT-TODO.md` §2.

## Contrast

Target WCAG AA. Three measured results that are easy to get wrong:

- `warm-gold` is **never a text colour** (2.4:1 on the offwhite ground). It is a
  fill under `warm-charcoal` only.
- Green text on a green tint fails (4.1:1). Use solid green + white.
- `text-warm-charcoal/60` and `/65` fail on the offwhite ground. `/70` is the floor.

## Commands

```
composer install && npm install
npm run build          # required after adding any new Tailwind class
php artisan migrate
php artisan tenant:create "Acme" acme --owner-name="A" --owner-email=a@acme.test
php artisan serve      # marketing: localhost:8000 · app: acme.localhost:8000
./vendor/bin/pest      # 59 tests
```

`npm run build` is not optional during development: Tailwind 4 scans source at
build time, so a newly written utility class silently does nothing until you
rebuild.

## Not built yet (deliberate)

Accounting domain (payroll, NSSF, EOSI, VAT, ledger) · self-serve signup and
Whish/OMT billing (Phase 2) · password reset · CRM or email automation · final
logo · native-reviewed Arabic · real photography.
