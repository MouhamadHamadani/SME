# Architecture

One repo, one deploy: the public marketing site and the authenticated app share
a Tailwind theme, a component library and a brand system, so the front door and
the product cannot drift apart.

**Content and launch blockers live in [CONTENT-TODO.md](CONTENT-TODO.md).**

---

## What exists

1. **Public marketing site** — bilingual (EN/AR), lead capture, brand system.
2. **App foundation** — multi-tenancy, sign-in, and an authenticated shell that
   reuses the marketing component library unchanged.
3. **Payroll & NSSF** — exact-integer money, the contribution engine,
   effective-dated rate schedules, immutable runs. See **[PAYROLL.md](PAYROLL.md)**.
4. **VAT** — net/gross calculation, quarterly returns, credit carry-forward.
   See **[VAT.md](VAT.md)**.
5. **Invoicing** — customers, invoices, credit notes, gap-free numbering;
   issuing feeds the VAT return. See **[INVOICING.md](INVOICING.md)**.
6. **WhatsApp delivery** — Meta Cloud API, per-business credentials, signed
   delivery webhooks. See **[WHATSAPP.md](WHATSAPP.md)**.

Running it and signing in as a tenant: **[RUNNING.md](RUNNING.md)**.

Laravel 13 · Tailwind 4 · Blade (no Livewire — nothing needs it yet) ·
stancl/tenancy v3 · MySQL · Pest.

**No regulatory figures ship with the system.** Rates and ceilings are
effective-dated data, and payroll refuses to run without them rather than
computing from a guess — see [PAYROLL.md §1](PAYROLL.md#1-the-figures-are-data-not-code).
Invoice PDFs and inbound WhatsApp messages are not built.

---

## Tenancy

**One database per tenant** (stancl/tenancy v3), resolved by **subdomain**.

For this product that is a compliance decision, not a scaling one: a business's
payroll and employee records are separated at the database level, so a missing
`where tenant_id = ?` cannot leak one business's salaries into another's. It
also makes "export or delete everything for this business" one operation.

| | |
|---|---|
| Central DB | `tenants`, `domains`, `leads`, `sessions`, `cache`, `jobs`, `nssf_rate_schedules`, `vat_rate_schedules` |
| Tenant DB | `users`, `employees`, `payroll_runs`, `payroll_lines`, `vat_transactions`, `vat_returns`, `customers`, `invoices`, `invoice_lines`, `whatsapp_messages` |
| Tenant model | `App\Models\Tenant` — uuid, name, plan, `wa_phone_number`, `settings` JSON |
| Resolution | `acme.{app_domain}` → tenant `acme` |
| Domain source | `config('tenancy.app_domain')`, derived from `APP_URL` — **one** source of truth for routing and provisioning |

`plan` is `starter|growth|pro`, matching the published §7 pricing exactly. Keep
it in step with the `pricing.tiers` keys in the marketing lang files.

`settings` holds `exchange_rate`, `currency`, `language`. The LBP/USD rate is
per-tenant because it is a business decision, not a system constant — two
businesses can legitimately book at different rates on the same day.

### Infrastructure tables are CENTRAL. Business data is per-tenant.

This rule is load-bearing. Tenancy repoints `database.default` at the tenant, so
any *unpinned* database-backed service looks for its table inside the tenant's
database and 500s. `session.connection`, `cache.stores.database.connection` and
`queue.connections.database.connection` are all explicitly pinned to the central
connection for exactly this reason. `NssfRateSchedule` pins its connection the
same way — the rates are national law, held once centrally, and read from
inside a tenant during a payroll run.

> The test suite runs `SESSION_DRIVER=array` and `CACHE_STORE=array`, so it will
> **not** catch a regression here on its own. `TenantInfrastructureTest` exists
> to close that gap — it asserts the pinning and exercises the database drivers.

### Onboarding

There is no self-serve signup, because there is no self-serve billing
(Whish/OMT is Phase 2, §6). During early access the founder provisions each
business by hand from a row in the `leads` table:

```
php artisan tenant:create "Haddad Grill" haddadgrill \
  --plan=growth --owner-name="Rami Haddad" --owner-email=rami@haddadgrill.com \
  --wa="+96170123456" --rate=89500
```

Creates the tenant, its database, its domain, and the owner account. Omit
`--owner-password` to generate one (printed once).

---

## Local setup

`APP_URL` decides the tenant subdomain base. It defaults to `http://localhost`
because browsers resolve `*.localhost` to 127.0.0.1 (RFC 6761) — so
`acme.localhost:8000` works with no hosts-file editing and no admin rights.
Point it at a real domain for staging and production.

MySQL is required (per-tenant `CREATE DATABASE`). **`engine` is pinned to
InnoDB** in `config/database.php`: this WAMP install ships
`default_storage_engine=MyISAM`, which has no transactions and no foreign keys —
unusable for a payroll and VAT ledger, where a half-written pay run is a
correctness bug rather than an inconvenience.

---

## Route boundary

Marketing routes are the public front door: **explicitly unauthenticated and
outside all tenancy middleware**. App routes live in their own subdomain group.
Do not merge the two.

| Route | Name | Domain |
|---|---|---|
| `GET /` | `marketing.home` | central (en) |
| `GET /ar` | `marketing.home.ar` | central (ar) |
| `POST /early-access` | `marketing.leads.store` | central |
| `GET /login` · `POST /login` | `app.login` · `app.login.attempt` | `{tenant}.` |
| `GET /` | `app.dashboard` | `{tenant}.` |
| `GET/POST /payroll`, `POST /payroll/finalise` | `app.payroll*` | `{tenant}.` |
| `GET/POST /vat`, `POST /vat/file` | `app.vat*` | `{tenant}.` |
| `/invoices` CRUD, issue/paid/void/credit | `app.invoices.*` | `{tenant}.` |
| `/credit-notes/{id}` | `app.credit-notes.show` | `{tenant}.` |
| `POST /invoices/{id}/whatsapp` | `app.invoices.whatsapp` | `{tenant}.` |
| `/webhooks/whatsapp` | `whatsapp.webhook*` | **central**, HMAC-signed |
| `/customers` CRUD | `app.customers.*` | `{tenant}.` |
| `/employees` CRUD | `app.employees.*` | `{tenant}.` |
| `POST /logout` · `POST /locale` | `app.logout` · `app.locale` | `{tenant}.` |

> **Route ORDER is load-bearing, and the defaults get it wrong.**
> Marketing routes are unconstrained; tenant routes are subdomain-constrained.
> Laravel matches in registration order, so if marketing went first,
> `acme.example.com/` would serve the *public marketing homepage* instead of the
> app. stancl's `TenancyServiceProvider::mapRoutes` registers tenant routes
> inside `app->booted()` — dead last — which is precisely that bug. It is
> disabled there; `bootstrap/app.php` owns route order, and applies the `web`
> middleware group to both files (taking over registration means it is no longer
> applied for you — losing it silently breaks CSRF and sessions on the lead form).
> `TenancyTest` has a regression test for the shadowing case.

> **The `{tenant}` domain parameter is a trap for route model binding.**
> Tenant routes sit under `Route::domain('{tenant}.…')`, so `tenant` is the
> route's FIRST parameter — and Laravel passes route parameters to controllers
> POSITIONALLY. A controller declaring `edit(Employee $employee)` therefore
> received the string `"acme"`, even though implicit binding had resolved the
> Employee correctly. Every route that takes a model hits this.
> `TenantRouteParameter` forgets the parameter once tenancy is initialized, and
> registers the URL default so `route()` still works. stancl does the same for
> PATH identification; subdomain identification has no equivalent.
> `TenancyTest` has a regression test.

On the marketing side locale is carried **in the URL, not the session**, so a link shared on WhatsApp —
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
| `<x-document-lines>` | Editable line rows, shared by invoices and credit notes |
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

## Locale: two different strategies, on purpose

- **Marketing site** — locale in the URL (`/` and `/ar`). A shared link or an ad
  destination carries its own language, and each locale is separately indexable.
- **App** — locale per *person*: their saved preference, then the business
  default, then Arabic. A URL prefix would be noise on every internal link, since
  nobody shares deep links into someone else's books. The switcher is a POST
  (it writes a preference) and persists to `users.locale`.

---

## Tests

Pest, `./vendor/bin/pest`. 271 tests.

**Marketing**

- `MarketingHomeTest` — both locales and `dir`; six sections in order; hero price
  precedes the pricing table and the form; §7 tiers and prices; logical-property
  enforcement; gold never paired with light text; no negative-offset regression;
  no government imagery
- `LeadCaptureTest` — storage, honeypot dropped silently, per-locale validation
  messages, rendered thank-you state in both languages, rate limiting
- `LanguageSwitcherTest` — switcher present, locale persists, no English stubs

**App foundation**

- `TenancyTest` — separate databases per business; users isolated; the same email
  can exist at two businesses; subdomain resolution; unknown subdomain 404s;
  app not exposed on a central domain; **marketing homepage does not shadow the
  app on a tenant subdomain**; marketing stays public; `tenant:create` including
  its validation failures
- `AppAuthTest` — sign-in scoped to the business; another tenant's real
  credentials rejected; identical message for wrong-password and unknown-address
  (no user enumeration); session regenerated on login (fixation); guests
  redirected to the app login not the marketing site; password never echoed back;
  rate limiting; Arabic RTL by default; language preference persisted; `noindex`
- `TenantInfrastructureTest` — the central/tenant table split, and the
  database-driver session/cache path the array drivers hide

**Payroll** — see [PAYROLL.md](PAYROLL.md)

- `MoneyTest`, `NssfCalculatorTest` — the engine, on fixture rates
- `PayrollRunTest`, `PayrollScreenTest` — runs, snapshots, the finalise gate
- `EmployeeManagementTest` — salary parsing, the role gate, CRUD, drafting

**VAT** — see [VAT.md](VAT.md)

- `VatCalculatorTest` — net vs gross, zero-rated vs exempt, rounding
- `VatReturnTest`, `VatScreenTest` — returns, carry-forward, the filing gate

**Invoicing** — see [INVOICING.md](INVOICING.md)

- `InvoicingTest` — line rounding, numbering, immutability, the VAT hand-off
  including the mixed-treatment split
- `InvoiceScreenTest` — quantity parsing, issuing, void, the role gate
- `PartialCreditNoteTest` — the pre-filled form and the over-credit ceiling
- `WhatsAppTest`, `WhatsAppWebhookTest` — number normalisation, the Cloud API
  payload, per-tenant credentials, signature verification, delivery statuses
- `CreditNoteTest`, `CreditNoteScreenTest` — corrections after a return is
  filed, negative transactions, the separate number series, scope isolation

---

## Explicitly not built

The accounting domain (payroll, NSSF, EOSI, VAT, ledger — needs the real
regulatory figures) · self-serve signup and Whish/OMT billing (§6 Phase 2) ·
password reset · CRM or email automation · final logo · native-reviewed Arabic ·
real photography.
