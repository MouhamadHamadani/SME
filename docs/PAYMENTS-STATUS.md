# Payments and receipts — status

Audit taken at commit `806962a`, **before** anything in this session was
written, then extended with what the session added.

Baseline at the start of the session: **323 tests passing** on SQLite.

> **Scope note, because two words in this repo mean two different things.**
> This document is about an SME recording money **it received from its own
> customers** against invoices it issued. It has nothing to do with this
> product's own subscription billing, which is still the separate, deferred
> "self-serve signup / Whish–OMT billing" item. Nothing here talks to a
> payment gateway. Every payment is a human typing *"I received X, this way,
> on this date"* after the money already moved somewhere else.

---

## Before: what already existed

**A boolean dressed as a timestamp.**

### The whole of it

`invoices.paid_at` (nullable timestamp) plus `invoices.status = 'paid'`, both
written by one method:

```php
// app/Models/Invoice.php
public function markPaid(): void
{
    if ($this->isDraft() || $this->isVoid()) {
        throw InvoicingException::cannotMarkPaid($this->label());
    }

    $this->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
}
```

No amount. No currency. No method. No date the money actually arrived — only
`now()`, the moment somebody clicked. No record of who clicked.

### Every call site

| Where | What it did |
|---|---|
| `InvoiceController::markPaid()` | The controller action, gated on `manage-payroll`. |
| `routes/tenant.php` — `POST /invoices/{invoice}/paid` | Named `app.invoices.mark-paid`. |
| `resources/views/app/invoices/show.blade.php` | A single **Mark as paid** button, shown when the invoice is issued and not void. |
| `CreditNote::markPaid()` | Overrides it to **throw** — a credit note reduces what is owed rather than being paid. Correct, and kept. |
| `InvoicingTest` "marks an issued invoice paid" | Asserted `status = paid`, `paid_at` not null, `statusKey() = filed`. |
| `InvoicingTest` "will not mark a draft paid" | The refusal. |
| `InvoiceScreenTest` "marks paid and voids from the screen" | Marked paid, then **voided the same invoice**. |
| `CreditNoteTest` | Pinned the credit-note refusal. |
| `DualCurrencyTest` "keeps a paid invoice out of the revaluation" | Used it to settle an invoice so the revaluation would drop it. |

One more reader, not a call site but coupled to the same flag:

```php
// app/Http/Controllers/App/ExchangeRateController::revaluation()
$outstanding = Invoice::query()->issued()->whereNull('paid_at') ...
```

The FX revaluation screen decided "outstanding" by that flag, and valued each
invoice at its **full total**. A half-paid invoice was revalued as if none of
it had been collected.

### The blind spots, stated plainly

1. **No amount.** The system could not answer "how much have we actually been
   paid?" for a single invoice, let alone in total.
2. **Partial payment was unrepresentable.** An invoice paid 60% was either
   fully paid or entirely unpaid, and both answers were wrong. This is not an
   edge case in Lebanon — instalments against one invoice are ordinary.
3. **No method.** Cash, cheque, Whish, OMT and a bank transfer were the same
   nothing. Reconciling a cheque that later bounced, or matching an OMT
   reference, had nothing to match against.
4. **No date the money arrived.** `now()` is when the flag was flipped, which
   can be weeks after the payment.
5. **No receipt.** Nothing numbered, nothing printable, nothing to hand over.
6. **No audit trail.** Not who recorded it, not what was recorded, not when.
7. **Void could erase a settled transaction.** `Invoice::void()` guarded a
   filed VAT return and nothing else, so a paid invoice could be voided and
   every trace of the settlement went with it. `InvoiceScreenTest` did
   *exactly that* and passed.
8. **The credit-note ceiling and the payment "ceiling" did not know about each
   other**, because only one of them existed.

### How credit notes were queryable (they already were)

This part was in good shape and the balance calculation is built on it rather
than beside it:

```php
// app/Models/Invoice.php
public function creditedMinor(): int      // sum(total_minor) of ISSUED credit notes
public function creditableMinor(): int    // max(0, total_minor - creditedMinor())
```

`creditNotes()` is a `hasMany(CreditNote::class, 'credited_invoice_id')` — the
foreign key named explicitly, because both documents live in the `invoices`
table and Eloquent would otherwise infer `credit_note_id` from the subclass.
Only `issued`/`paid` credit notes count; a draft must not reserve headroom.

**An invoice's balance therefore has to net both effects in one number**, or a
partially-credited, partially-paid invoice reports an outstanding amount that
is simply false.

### The status vocabulary

`Invoice::statusKey()` mapped onto the shared three-state badge:

| | |
|---|---|
| `paid` | `filed` (green) |
| issued and past `due_on` | `overdue` (red) |
| anything else | `due-soon` (amber) |

**There was no answer for "overdue AND partially paid"** — the combination
could not arise, because partial payment could not be recorded.

### Numbering discipline to reuse, not rebuild

`IssueInvoice::nextNumber()`:

```php
$highest = Invoice::query()->lockForUpdate()->max('number');
return ((int) $highest) + 1;
```

`lockForUpdate` inside the issuing transaction, a unique index on the column as
the second line of defence, numbers never reused. `IssueCreditNote::nextNumber()`
is the same three lines against its own series. Receipts get the same treatment
and not a second mechanism.

---

## After: what this session added

### A real ledger, and a balance derived from it

`payments` (**tenant** database, `App\Models\Payment`):

| Column | |
|---|---|
| `invoice_id` | Required. One payment, one invoice. |
| `receipt_number` | Sequential, gap-free, unique. `RCT-{year}-{0000}` is derived from it. |
| `amount_minor` + `currency` | Integer minor units, via `App\Support\Money`. |
| `lbp_per_usd` | The **market** rate the day the money arrived. Null for LBP. |
| `method` | `App\Support\PaymentMethod` — cash, cheque, whish, omt, bank_transfer, other. |
| `reference` | Cheque number, Whish/OMT transaction ID, bank reference. |
| `notes` | Free text. |
| `received_on` | When the money arrived, not when it was typed in. |
| `recorded_by` | The user who logged it. |

`method` is a **string column with a PHP enum cast**, not a database enum, and
that is deliberate: Lebanon gains payment rails faster than this schema should
change, so adding one is a case in `PaymentMethod` rather than a migration.
`currency` stays a database enum, matching `invoices` and `vat_transactions`.

The rate is the **market** rate via the existing
`ExchangeRate::rateFor(RateBasis::Market)` — no second rate mechanism, and
`RateBasis` is passed explicitly as it is everywhere else. A receipt is a
commercial document, not a filing, so the Ministry rate is not the one to
snapshot here.

### The invoice now derives its money state

| | |
|---|---|
| `payments()` | `hasMany`, foreign key named explicitly like the other two. |
| `amountPaid()` | Sum of payments, in the invoice's currency. |
| `balance()` | `total − credit notes − payments`. Never negative. |
| `isFullyPaid()` | A payment exists **and** the balance is zero. |
| `isPartiallyPaid()` | A payment exists **and** the balance is not zero. |

`balance()` nets **both** effects in one number. An invoice partly credited and
partly paid owes neither the uncredited part nor the unpaid part but the
overlap, and either half reported alone is simply a wrong figure. There is a
test walking an invoice through a credit note and two payments and pinning the
ceiling at the netted balance, not the invoice total.

`isFullyPaid()` asks for a payment to exist rather than only checking the
balance, because a **fully credited** invoice also has a zero balance and
nobody paid it. Those are different facts and the code says so; there is a test.

### `markPaid()` is now a wrapper, not a flag

```php
public function markPaid(PaymentMethod $method = PaymentMethod::Other, ?User $by = null): Payment
{
    // ... same draft/void refusal as before
    return app(RecordPayment::class)->record($this, $this->balance(), $method, by: $by);
}
```

`status` and `paid_at` survive as a **cache** of what the payments say —
`scopeIssued()`, `creditedMinor()` and the void guard all read `status`, and
rewriting those was not worth the regression risk. `RecordPayment` is the only
code that writes them, and only with a committed `Payment` row beside it. **No
path marks an invoice paid without a payment behind it.**

The **`POST /invoices/{invoice}/paid` route and its button are gone.** Marking
an invoice paid without recording what arrived, how and when is the gap this
session closes; leaving the door open beside the new one would have reopened it.
`markPaid()` itself stays as a programmatic convenience, which is why its
default method is the deliberately vague `other`.

### Business rules, each with a test

- **Only an issued invoice takes a payment.** Not a draft, not a void one, not
  a credit note — the same shape as `IssueCreditNote::assertCreditable()`.
- **The ceiling is the netted balance**, stated on screen *before* the form is
  submitted and named exactly on refusal ("At most 510,000 LBP remains
  outstanding"), mirroring the over-crediting ceiling. The whole record runs in
  one transaction, so a rejected payment leaves nothing behind — not a row, and
  not a consumed receipt number. Pinned.
- **A currency mismatch is refused, never converted.** Converting would pick an
  exchange rate nobody agreed to and bury it in a receipt.
- **Voiding an invoice with any payment is refused.** One lira is enough: it is
  a settled transaction, and voiding would erase the payment history along with
  the VAT. The correction is a credit note — the same answer this module
  already gives once a VAT period is filed.

### Receipt numbering

`RecordPayment::nextNumber()` is the same three lines as
`IssueInvoice::nextNumber()` against its own series: `lockForUpdate` on the max,
a unique index as the second line of defence, numbers never reused. Not a
shared abstraction over two callers — the duplication is three lines and the
alternative is an indirection nobody reading either one wants.

One ordering detail worth keeping: the number is allocated **before** the
balance is read. That lock is what serialises concurrent payments, so the
balance check runs against settled state. Reading the balance first would let
two simultaneous payments each see the same room and together overpay.

### The status combination that had no answer

**Overdue and partly paid resolves to `overdue`.** A part payment does not cure
lateness, and amber on an invoice a customer is behind on is the wrong signal
to the person chasing it. Green stays reserved for a zero balance.

The partial settlement is not lost — it moves into the label. The badge now has
four states over three colours, so the label is its own method,
`Invoice::statusLabel()`, rather than a ternary repeated in every view:

| | `statusKey()` | `statusLabel()` |
|---|---|---|
| settled | `filed` | Paid |
| overdue, partly paid | `overdue` | **Overdue · partly paid** |
| overdue | `overdue` | Overdue |
| partly paid | `due-soon` | Partly paid |
| otherwise | `due-soon` | Issued |

That replaced the inline conditional the invoice list and the invoice view each
carried their own copy of.

### Screens

- **Record payment** (`/invoices/{id}/payments/new`) — the balance and its
  arithmetic stated first, then amount, currency (defaulted to the invoice's),
  method, date (defaulted to today, native `<input type="date">`), reference and
  notes. `manage-payroll`, like the rest of invoicing.
- **Payment history** on the invoice — each row showing method, amount, date,
  reference and who recorded it, with paid-to-date and the balance beneath.
- **Receipt** (`/receipts/{payment}`) — numbered, read-only, one payment. A USD
  receipt also states its lira value and the rate used. HTML only.
- **Outstanding balances** (`/outstanding`) — issued invoices with a balance,
  soonest due first, totalled **per currency** (a single total across LBP and
  USD is a number that means nothing; `Money::plus()` would throw rather than
  produce it). Not an aging report: no buckets, no statements.

### One thing corrected along the way

The **FX revaluation screen** decided "outstanding" by `whereNull('paid_at')`
and valued each invoice at its **full total**. With payments in the system that
overstates the exposure by exactly what has already been banked, so it now
rejects zero-balance invoices and revalues `balance()`, which nets credit notes
too. Its existing tests were unaffected — with no payments, balance is total.

### Forward compatibility: one payment, several invoices

Not built. The schema does not make it painful:

- `Payment` is **its own entity** with its own currency, rate, method and
  receipt number, not columns bolted onto `invoices`.
- **`RecordPayment` is the only code that decides which invoice a payment
  affects.** Nothing else reaches for `payment.invoice_id`; everything asks the
  invoice for its balance.

A future `payment_allocations` pivot is therefore an **additive** migration —
every existing payment becomes the trivial one-allocation case — plus a new
body for one method. Not a rewrite.

---

## Deliberately out of scope, and still out

- **The general ledger / chart of accounts.** Unchanged from
  [DUAL-CURRENCY-STATUS.md](DUAL-CURRENCY-STATUS.md). These payments are
  documents, not journal entries, and there is still nothing to post them to.
- **One payment across several invoices.** See above.
- **Mixed-currency partial payment.** Paying part of a USD invoice in LBP cash
  is real in this market and is **refused today**, not converted. Supporting it
  properly means recording the amount tendered, the amount credited and the rate
  agreed between the two parties as three separate facts — which is a design
  decision, not a column. Refusing is the honest interim answer; silently
  converting at some rate would not be.
- **Refunds and payment reversal.** A payment cannot currently be undone. If one
  is recorded wrongly there is no correcting document for it, which is a real
  gap and the most likely next request after multi-invoice allocation.
  Deliberate: a reversal is a document of its own, with its own number and its
  own rules, and bolting a delete button onto a receipt would have been worse
  than not having one.
- **PDF and print.** Receipts are HTML only, exactly like invoices — see
  [INVOICING.md](INVOICING.md) §8. A pre-existing gap that covers both
  documents, tracked once rather than half-closed for one of them.
- **Any Whish, OMT or bank API.** Nothing in this session contacts a payment
  provider. The method enum labels how an SME's customer paid; it is not an
  integration. This product's own subscription billing remains a separate,
  deferred item.
- **Aging buckets, statements, customer-level balances.** The outstanding list
  answers "who owes us money" and stops there.

---

## Tests

`PaymentTest` (14) — partial payment and the balance; two payments in different
methods settling an invoice; the overpayment refusal naming the exact balance
and leaving no row and no consumed number; the zero-balance refusal; the
currency-mismatch refusal; the market-rate snapshot on a USD payment and null on
LBP; a credit note netted into the balance alongside payments, including the
ceiling; a fully credited invoice not being called paid; the void refusal; the
draft / void / credit-note refusals; zero and negative amounts; sequential
gap-free receipt numbers across invoices including after a refusal; the
overdue-and-partly-paid resolution in both key and label; and tenant isolation
with an independent receipt series per business.

`PaymentScreenTest` (12) — the balance stated before the form; recording a part
payment and seeing it in the history with who recorded it; the overpayment
refusal keeping what was typed; the currency refusal; the stale-link redirect
once nothing is outstanding; the numbered receipt; the outstanding list emptying
as an invoice is settled; the overdue-partly-paid badge on that list; the role
gate both ways (staff read balances, history and receipts but cannot record;
accountant can); sign-in required; and Arabic RTL with logical properties only.

Updated rather than deleted:

- `InvoicingTest` "marks an issued invoice paid" now asserts the `Payment` row,
  the amount paid and the zero balance behind the flag.
- `InvoiceScreenTest` "marks paid and voids from the screen" became **two**
  tests: settling through the new form (and confirming the void is now refused),
  and voiding an *unpaid* invoice.

Housekeeping the session forced, worth naming: `draftInvoice()`,
`issuedInvoice()`, `seedIssuedInvoice()` and `signInAs()` were declared inside
individual test files, so any suite using them silently depended on Pest's file
load order. They moved to `tests/Pest.php` beside the other shared fixtures, and
`InvoiceScreenTest::asUser()` — byte-for-byte identical to `signInAs()` — is
gone.

**350 tests passing** (323 before this session), on SQLite and on real MySQL.
