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
3. **Payroll & NSSF** — the contribution engine, effective-dated rate
   schedules, payroll runs, employee management.
   **Read [docs/PAYROLL.md](docs/PAYROLL.md) first.**
4. **VAT** — net/gross calculation, quarterly returns, credit carry-forward.
   **Read [docs/VAT.md](docs/VAT.md) first.**
5. **Invoicing** — customers, invoices, credit notes, gap-free numbering.
   Issuing WRITES the VAT transactions the return reads.
   **Read [docs/INVOICING.md](docs/INVOICING.md) first.**

How to run it and sign in as a tenant: **[docs/RUNNING.md](docs/RUNNING.md)**.

No invoicing, no billing, no self-serve signup.

## Hard rules

- **The marketing routes are the public front door.** `/`, `/ar` and
  `POST /early-access` are explicitly unauthenticated and must stay outside any
  tenant/auth middleware. App routes get their own group.
- **`leads` has no `tenant_id`** — a lead exists before a tenant does.
- **Infrastructure tables are CENTRAL; business data is per-tenant.** `sessions`,
  `cache` and `jobs` connections are pinned to the central connection. Unpinning
  any of them makes every authenticated page 500, and the test suite's array
  drivers will NOT catch it.
- **Never remove `TenantRouteParameter` from the tenant route group.** Without
  it, route parameters are passed positionally and every controller taking a
  model receives the tenant subdomain string instead. Read its docblock first.
- **Payroll changes are gated on `manage-payroll`** (owner + accountant). The
  base Controller carries AuthorizesRequests so `$this->authorize()` works.
- **Route order is load-bearing.** bootstrap/app.php registers tenant routes
  before marketing routes and applies `web` to both. Read the comment there
  before touching it.
- **Money lives in `App\Support\Money`** (integer minor units, never float).
  Adding LBP to USD throws. VAT has TWO directions — `fromNet` adds tax on
  top, `fromGross` extracts it from within; using the wrong one inflates
  every line.
- **An invoice writes ONE VAT TRANSACTION PER TREATMENT**, never one per
  invoice. A mixed invoice reported under a single treatment puts exempt
  amounts into taxable turnover and misstates the return. Regression test.
- **Issued invoices are immutable and their numbers are never reused.**
  Void while the VAT period is open; credit note after it is filed.
- **Invoices and credit notes share the `invoices` table**, separated by
  `document_type` and a GLOBAL SCOPE per model. Never query that table without
  going through Invoice or CreditNote. Relation foreign keys are named
  explicitly (`invoice_id`) because Eloquent infers `credit_note_id` from the
  subclass.
- **Zero-rated is not exempt.** Zero-rated counts as taxable turnover and its
  input VAT is recoverable; exempt does neither. Tests pin both.
- **Never hard-code a contribution rate, ceiling or tax bracket.** They are
  effective-dated data in `nssf_rate_schedules`, and payroll REFUSES to run
  when none covers the period. A payroll that computes from guessed figures is
  worse than one that refuses — it looks authoritative and gets declared.
- **Money is integer minor units, never float.** Use `App\Payroll\Money`.
  Rates are integer basis points (850 = 8.5%). Rounding happens in exactly one
  place; do not add a second.
- **End-of-service has NO ceiling.** Capping it under-contributes for every
  employee above the ceiling. Sickness and family have DIFFERENT ceilings from
  each other. There are tests asserting both.
- **Finalised payroll runs are immutable** and carry a snapshot of the rates
  they used. Never recompute a finalised period from the current schedule.
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
php artisan nssf:schedule   # rates: none ship, see docs/PAYROLL.md
php artisan vat:schedule    # VAT rates: none ship either
./vendor/bin/pest      # 233 tests
```

`npm run build` is not optional during development: Tailwind 4 scans source at
build time, so a newly written utility class silently does nothing until you
rebuild.

## Not built yet (deliberate)

Partial credit-note screen · WhatsApp delivery · invoice PDF · payments · family-allowance benefit ·
part-month pro-rating · self-serve signup and Whish/OMT billing (Phase 2) ·
password reset · CRM or email automation · final logo · native-reviewed
Arabic · real photography. Payroll gaps are listed in docs/PAYROLL.md §6.
