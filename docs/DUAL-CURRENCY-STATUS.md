# Dual currency — status

Audit taken at commit `4ac697f`, **before** anything in this session was
written, then extended with what the session added.

> **Source.** `docs/knowledge-base.md` appeared in the repository partway
> through this session; §3.6 and §8 were read directly from it and the design
> below matches them. §8 asks for exactly this table — *"daily exchange-rate
> snapshot job, storing both a market rate and a separate MoF-prescribed rate
> per day"* — and §3.6 gives the reason: foreign-currency salaries are
> reported to the MoF at the prescribed rate, not the market one.
>
> The rates themselves remain figures a human must confirm against a current
> MoF circular. Nothing here ships with a rate.

---

## Before: what already existed

More than expected. The money layer was sound; the rate layer was not.

### Solid, and reused rather than rebuilt

| | |
|---|---|
| `App\Support\Money` | Integer minor units. `plus`/`minus` **throw** on a currency mismatch, so LBP and USD cannot silently combine. Rounding half-up in one place. |
| `App\Support\Currency` | `USD` (scale 2) / `LBP` (scale 0) — one LBP minor unit is one lira, because piastres have been meaningless for decades. |
| `Money::convertTo()` | Takes an explicit `lbpPerUsd`; there is no ambient rate it can pick up by accident. |

### Transaction currency was already recorded

This part of §3.6 was **already satisfied** before this session:

| Table | Column | Meaning |
|---|---|---|
| `employees` | `base_salary_currency` | The currency the contract was agreed in |
| `payroll_lines` | `contracted_salary_currency` | As-contracted, kept alongside the LBP computation |
| `invoices` | `currency` | The invoice is denominated in one currency; that is the tax document |
| `vat_transactions` | `amount_currency` | As transacted, with LBP amounts for the return |

Each of `payroll_runs` and `invoices` also snapshots the `lbp_per_usd` it used,
so a finalised run or an issued invoice reproduces its own figures years later
rather than being recomputed at today's rate. That pattern was right, and the
work below extends it rather than replacing it.

### What was missing

1. **No rate history of any kind.** The rate lived in one mutable float,
   `tenants.data → settings.exchange_rate`, set once by
   `tenant:create --rate` and displayed read-only on the dashboard. There was
   **no manual-entry screen** to change it and no record of what it was on any
   given day.
2. **No MoF-prescribed rate. At all.** The system knew exactly one rate. §3.6's
   entire distinction — market rate for the books, MoF rate for filings — had
   no representation in the schema, so the question "which rate applies here?"
   could not be asked, let alone answered wrongly.
3. **No revaluation reporting.** No view of any rate delta, and no unrealized
   gain/loss on balances as the rate moves.
4. **No general ledger to hang any of this on.** Worth stating plainly: this
   product has **no chart of accounts and no journal entries**. It is
   document-centric — invoices, credit notes, payroll runs, VAT transactions.
   "Dual-currency ledger" therefore had no ledger to be dual-currency *in*.

### Honest read of the before-state

The claim "genuine dual-currency accounting" (§1, §5) was **half true** at
`4ac697f`. The hard half — never losing the transaction's own currency, never
combining currencies silently, never recomputing a filed figure at a new rate —
was done properly. The regulatory half — that Lebanon has *two* official
answers to "what is a dollar worth today", and they are used for different
purposes — was entirely absent.

---

## After: what this session added

### The two rates are now one central, dated, independent pair

`exchange_rates` (**central** database, `App\Models\ExchangeRate`):

| Column | |
|---|---|
| `rate_date` | Unique. One row per day. |
| `market_rate_lbp_per_usd` | What the market says. The business's own books. |
| `mof_rate_lbp_per_usd` | What the Ministry prescribes. Filings only. |
| `source` | Where the figures came from — free text, required. |
| `verified_at` / `verified_by` | The same human gate NSSF and VAT rates carry. |

**Central, not per-tenant**, matching `nssf_rate_schedules` and
`vat_rate_schedules`: both figures are national facts rather than business
decisions. 250 businesses should not each retype the government's number, and
they must not be able to disagree about what it was.

`tenants.settings.exchange_rate` is **untouched** and still means what it
meant: the rate this business chooses to book at. That is a genuinely
different thing from either national figure, it is already wired through
payroll and invoicing, and changing it was not worth the regression risk.

The two rates are stored as two independent columns with no derivation between
them — writing one cannot move the other, and a test pins that.

### `RateBasis` — which rate, for which purpose

`App\Support\RateBasis` is an enum with two cases, `Market` and `Mof`, and it
is the thing that makes the §3.6 rule expressible in code rather than in a
comment. `ExchangeRate::rateFor(RateBasis)` is the only way to get a number
out, so "which rate is this?" has to be answered explicitly at every call site.

### Filing figures now snapshot the MoF rate

`payroll_runs.mof_rate_lbp_per_usd` and `invoices.mof_rate_lbp_per_usd` are new,
nullable, and populated at run/issue time from the day's verified rate. Both
are additive columns — no existing figure changed, and every pre-existing test
still passes.

A document therefore now carries **both** rates it was created under: the one
its books were kept at, and the one its filing must use.

### Who enters what — a deliberate deviation from the brief

The brief asked for "daily manual entry of both rates" on a screen. Built
literally, that screen would sit inside the tenant app, where **any business
owner could edit the rate every other business files at.** Both figures are
national: one is the market quote, the other is prescribed by the Ministry.
Neither is a per-business value, and a tenant-facing form over shared national
data is not a warning to write, it is a hole not to open.

So entry is split by who legitimately owns the number:

| | Entered by | How |
|---|---|---|
| Market rate | The operator | `php artisan exchange:rate --market=…` |
| Ministry rate | The operator, then verified | `php artisan exchange:rate --mof=…`, then `--verify=<id> --by="…"` |
| **The business's booking rate** | **The business owner** | **Settings → Exchange rates, in the app** |

That is the same shape as `nssf:schedule` and `vat:schedule`, which are also
national law entered once centrally. Changing a figure **clears any existing
verification**, so a filing never rests on a signature given for a different
number — there is a test for that.

If the intent really was per-tenant rate entry, this is the decision to
revisit; it is one migration and one controller, not a rewrite.

### Screens

- **Settings → Exchange rates** — today's national pair with the gap between
  them and the verification state, the last 30 days of history, and the form
  for the business's own booking rate (owner-only).
- **Revaluation** — outstanding USD invoices valued at both rates, the movement
  per invoice since it was issued, and 14 days of rate history, so the gap is a
  figure rather than a feeling.

### Still not built, and deliberately

- **A general ledger.** Revaluation here reports over documents, not over
  account balances, because there are no account balances. Real unrealized
  gain/loss on a trial balance needs a chart of accounts first — a much larger
  piece of work than this session, and one that should not be smuggled in
  under "dual currency".
- **Withholding tax.** §3.6's April-2024 rule (USD/EUR withholding filed in the
  currency withheld) is **not implemented** — there is no withholding-tax
  module at all. The rate machinery this session adds is what such a module
  would need, but the module itself does not exist. Do not read this document
  as saying withholding is handled.
- **The scheduled snapshot job.** §8 asks for a scheduled job *"with manual
  fallback for outages"*. What exists is the manual fallback and the table it
  writes to — not the job. A deliberate order of work rather than an oversight:
  a scraped rate that silently changes a filing figure is worse than a blank
  field, and the verification gate had to exist before anything automatic could
  write here. The job is a small addition on top of this.
