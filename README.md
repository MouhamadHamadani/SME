# SME Compliance Accounting & Payroll Suite

*Working name pending branding — see [Blockers](#blockers).*

Bilingual (Arabic/English) cloud accounting and payroll for Lebanese SMEs, where
Lebanon-specific compliance is native rather than bolted on: NSSF contributions,
end-of-service indemnity, VAT, and genuine LBP/USD dual-currency accounting.

Sold self-serve at published prices — the wedge is the *combination* of
self-serve signup, transparent pricing, dual-currency, and NSSF/EOSI/VAT
compliance in one product. Not "the only one doing NSSF"; that is no longer
accurate (`docs/knowledge-base.md` §11).

Laravel 13 · Blade · Tailwind 4 · MySQL · Pest · PHP 8.3

---

## Where the project is

| Phase | State |
|---|---|
| Knowledge base — regulatory mechanics, market, data model | Done — [`docs/knowledge-base.md`](docs/knowledge-base.md) |
| Design — marketing site visual system | Done — [`docs/design-brief.md`](docs/design-brief.md) |
| Public marketing site | Built, **not launchable** — see [Blockers](#blockers) |
| App: tenancy, sign-in, shell | Built |
| Payroll & NSSF | Built — [`docs/PAYROLL.md`](docs/PAYROLL.md) |
| VAT & quarterly returns | Built — [`docs/VAT.md`](docs/VAT.md) |
| Invoicing & credit notes | Built — [`docs/INVOICING.md`](docs/INVOICING.md) |
| WhatsApp delivery | Built — [`docs/WHATSAPP.md`](docs/WHATSAPP.md) |
| Dual-currency rates & revaluation | Built — [`docs/DUAL-CURRENCY-STATUS.md`](docs/DUAL-CURRENCY-STATUS.md) |
| End-of-service accrual | Built — [`docs/EOSI-STATUS.md`](docs/EOSI-STATUS.md) |
| Design — **app UI** phase | Not started. The marketing system extends into it by design. |
| Payments & receipts ledger | Built — [`docs/PAYMENTS-STATUS.md`](docs/PAYMENTS-STATUS.md) |
| Invoice/receipt PDF, VAT purchase entry | Not built |
| Self-serve signup & Whish/OMT billing | Phase 2, not built |

**No regulatory figures ship with this system.** NSSF rates and ceilings, VAT
rates, and both exchange rates are effective-dated data a human must enter and
verify. Payroll, VAT and filings **refuse to run** rather than compute from a
guess — a wrong figure that looks authoritative gets declared.

---

## Documentation

Read [`CLAUDE.md`](CLAUDE.md) first if you are changing anything — it carries
the hard rules that cost real debugging time to learn.

| | |
|---|---|
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | Brand tokens, contrast rules, tenancy, component inventory, gotchas |
| [`docs/RUNNING.md`](docs/RUNNING.md) | How to run it and sign in as a tenant |
| [`docs/CONTENT-TODO.md`](docs/CONTENT-TODO.md) | What still needs a human before launch |
| [`docs/ARCHITECTURE-DECISION-tenancy.md`](docs/ARCHITECTURE-DECISION-tenancy.md) | One database per tenant — **open decision** |
| [`docs/knowledge-base.md`](docs/knowledge-base.md) | Founding reference: regulation, market, data model |
| [`docs/design-brief.md`](docs/design-brief.md) | Design decisions log for the marketing site |

---

## Quick start

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan deploy:migrate   # central + every tenant; never bare `migrate`
php artisan tenant:create "Acme" acme --owner-name="A" --owner-email=a@acme.test
npm run build
php artisan serve      # marketing: localhost:8000 · app: acme.localhost:8000
```

Rates must be loaded before payroll or VAT will compute anything — see
[`docs/RUNNING.md`](docs/RUNNING.md) §4.

```bash
./vendor/bin/pest                          # 323 tests, SQLite
./vendor/bin/pest -c phpunit.mysql.xml     # the same suite against real MySQL
```

Run the MySQL one before a release: SQLite forgives what MySQL does not, and
two real defects here were exactly that divergence.

---

## Blockers

Nothing below is a coding task. Full detail in
[`docs/CONTENT-TODO.md`](docs/CONTENT-TODO.md).

1. **Product name** — blocks the logo and wordmark. The site renders a
   text-only placeholder reading "Compliance Suite" from a single Blade
   component, so swapping it is a one-file change.
2. **Native Lebanese Arabic review** — the Arabic is a best-effort draft.
   Translated copy undercuts the "built for us" positioning, which is the pitch.
3. **Photography** — placeholder slots, deliberately not stock or illustration.
4. **Legal check on NSSF / Ministry of Finance references** — text claims only;
   no official seals or imagery anywhere, and a test asserts that.
5. **Accessibility target** — built and measured to WCAG AA; the brief asks for
   that target to be explicitly confirmed.
6. **Meta approval** before WhatsApp can send anything —
   [`docs/WHATSAPP.md`](docs/WHATSAPP.md) §1.
