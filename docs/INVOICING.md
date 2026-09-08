# Invoicing

Invoices are the tax document, and they are what **feeds the VAT return**.
This module closes the biggest gap listed in [VAT.md](VAT.md) §5.

---

## 1. Invoicing writes; VAT reads

An issued invoice creates `vat_transactions` rows. The VAT return does not know
invoices exist — it keeps reading one source, and invoicing feeds it.

**One transaction per VAT TREATMENT on the invoice**, not one per invoice.

This matters more than it looks. A clinic invoices standard-rated consultations
alongside exempt medical procedures on the same document. Reporting that under
a single "dominant" treatment puts the exempt amount into **taxable turnover**,
overstating it by exactly the exempt lines — on a return somebody signs. A
25,000,000 invoice with 9,000,000 exempt was reporting 25,000,000 taxable
instead of 16,000,000. There is a regression test.

The invoice's own `net_lbp` / `vat_lbp` are the **sum of the transactions it
wrote**, not a separate conversion of its totals. Each group converts and
rounds independently, so re-converting the whole could differ by a lira — and
an invoice that disagrees with what it declared is the contradiction this
module exists to prevent.

---

## 2. Numbering

- A **draft has no number.** Numbers are assigned at issue.
- The series is **gap-free**: an abandoned draft never consumed one.
- Numbers are **never reused**, including by a voided invoice. A hole in a tax
  invoice series is a question from an inspector; a duplicate is a problem.
- Allocation takes `lockForUpdate` so two people issuing at the same instant
  cannot read the same maximum, and a unique index on `number` is the second
  line of defence — a race fails loudly rather than duplicating.

Reference format is `INV-{year}-{0000}`.

---

## 3. Credit notes

**Void before the period is filed; credit note after.**

That is the whole distinction. Voiding deletes the invoice's VAT transactions,
which is only safe while the period is still a draft. Once a return is filed,
voiding is refused — altering a declared period silently is not an option. A
credit note corrects it instead, and **the correction lands in the period the
CREDIT NOTE is issued**, not the invoice's.

| | Void | Credit note |
|---|---|---|
| Works after the VAT return is filed | no | **yes** |
| Effect on the original period | erases it | none |
| Effect on the current period | none | reduces output VAT |
| Document produced | none | a numbered tax document |

### How it is stored

Credit notes live in the **`invoices` table**, separated by `document_type`.
They are structurally the same document — customer, dated lines, currency and
rate, VAT snapshot, a number, immutability — and a parallel table would
duplicate all of it and let the two drift.

The safety comes from **global scopes**: `Invoice` is pinned to
`document_type = 'invoice'` and `CreditNote` to `'credit_note'`, so no existing
query has to remember the filter. Forgetting one would show a credit note on
the invoice list or double-count it in a total, which is precisely the failure
this arrangement rules out.

> Two consequences of the shared table, both handled and both easy to trip
> over again: relation foreign keys are named **explicitly** (`invoice_id`),
> because Eloquent would infer `credit_note_id` from the subclass; and
> `InvoiceLine::invoice()` **drops** the document-type scope, since a line
> legitimately belongs to either document.

### Rules

- Its own number series, `CN-{year}-{0000}`, starting at 1 independently of
  invoices.
- Only an **issued or paid** invoice can be credited. A draft should be edited;
  a void one is already reversed.
- **Cannot credit more than the invoice is worth.** Only *issued* credit notes
  count toward the total — letting drafts reserve headroom would allow two of
  them to each look affordable while together they over-credit.
- Amounts are stored **positive** on the document (it reads "credit note for
  5,000,000") and written **negative** to `vat_transactions`, so the return's
  existing sums reduce output VAT with no parallel code path. `vat_transactions`
  and the return totals are signed columns for this reason.
- One negative transaction **per treatment**, mirroring invoices — correcting
  the total while corrupting the taxable/exempt split is still a wrong return.
- A credit note is neither paid nor voided. If it was wrong, raise another
  document.

### Full and partial

Two routes onto the same action:

| | |
|---|---|
| `POST /invoices/{id}/credit/full` | One click from the invoice. Copies every line as-is. |
| `GET  /invoices/{id}/credit` | The form, **pre-filled with the invoice's lines**. |
| `POST /invoices/{id}/credit` | Issues from the submitted lines. |

The form pre-fills rather than starting blank, so a full credit is one click
away and a partial one is an *edit* — reduce a quantity or price, or remove
lines you are not crediting. Each row shows what the original invoice line was,
so the reduction can be judged against it.

The remaining balance is **stated before the form**, not only enforced on
submit. If you exceed it anyway the error names the exact figure left
("At most 13,320,000 LBP remains uncredited") and keeps what you typed.

> The ceiling is enforced in `IssueCreditNote`, after the lines are priced —
> only then is the total known. The whole issue runs in one transaction, so a
> rejected credit leaves nothing behind.

Arriving at the form with nothing left to credit redirects back to the invoice
rather than 404ing: the button that got you there is gone by then, so it is a
stale link, not an error worth a page.

---

## 4. Immutability

| State | Editable | Notes |
|---|---|---|
| `draft` | yes | No number, not on any VAT return |
| `issued` | **no** | Tax record. Void or credit-note it |
| `paid` | **no** | |
| `void` | **no** | Number kept, VAT transactions removed |

Voiding an invoice deletes the VAT transactions it wrote, so the return stops
counting it — **unless** any of them is already on a **filed** return, in
which case voiding is refused. Altering a declared period silently is not an
option; that needs a credit note.

---

## 5. Money and quantities

Everything integer, via `App\Support\Money`:

- Amounts are in the invoice's own currency; the invoice is the tax document,
  so a USD invoice shows USD VAT. Conversion to LBP happens once, at the stored
  `lbp_per_usd`, for the VAT return.
- **Quantities are thousandths** (`2.5` → `2500`), so `2.5 × 1,333` has one
  unambiguous answer. More than 3 decimals is rejected, not rounded away.
- **VAT is computed per line and summed**, never applied to the subtotal. Three
  lines of 5 lira at 11% are 1 + 1 + 1 = 3, where 11% of the 15 subtotal would
  be 2. The printed invoice must agree with itself.
- A USD invoice **requires** an exchange rate before it can be issued.

---

## 6. Who can do what

Same `manage-payroll` gate as payroll and VAT: **owner and accountant** can
create, issue, void and mark paid. **Staff can read.** See
[PAYROLL.md](PAYROLL.md) for the reasoning and how to change it.

---

## 7. Using it

**Invoices** and **Customers** in the top nav.

1. Add a customer (their VAT number goes on the invoice — without it a B2B
   buyer cannot reclaim the input VAT).
2. New invoice → pick customer, currency, add lines, save draft.
3. Issue it. That is when the number is allocated and the VAT transactions are
   written. It cannot be edited afterwards.

Issuing **refuses without a verified VAT rate schedule**, like everything else
that touches a regulatory figure — see [VAT.md §1](VAT.md).

---

## 8. Not built yet

- **WhatsApp delivery.** The marketing site promises it and the customer's
  number is captured, but sending needs the Meta Cloud API and a verified
  business number. Nothing is wired.
- **PDF / print.** The invoice view is HTML only. No PDF, no print stylesheet.
- **Payments.** `paid_at` is a flag, not a payment record — no partial
  payments, no allocation, no receipts.
- **Recurring invoices, quotes, delivery notes.**
- **Per-line discounts and invoice-level discounts.**
- **Purchase-side invoices.** Input VAT still comes from `vat_transactions`
  rows created outside this module.

---

## 9. Tests

`InvoicingTest` — line arithmetic and rounding, per-line VAT summation,
sequential gap-free numbering, no number on drafts, no reuse after voiding,
immutability, rate snapshotting, the VAT hand-off including the
**mixed-treatment split**, USD conversion, invoice/transaction agreement, void
behaviour and the filed-return guard, tenant isolation.

`InvoiceScreenTest` — quantity parsing, validation messages, issuing from the
screen, the read-only redirect for issued invoices, mark-paid and void, the
role gate, customer management, and Arabic RTL.

`CreditNoteTest` — the period-shifting property (a filed return stays
untouched while the credit lands in the next quarter), negative transactions,
the separate number series, global-scope isolation, over-crediting limits,
drafts not reserving headroom, what cannot be credited, per-treatment reversal,
USD, and tenant isolation.

`CreditNoteScreenTest` — crediting from the invoice screen, the linked view,
credit notes staying off the invoice list, the role gate, and Arabic RTL.

`PartialCreditNoteTest` — the pre-filled form, partial amounts, a second
credit up to the remaining balance, the over-credit refusal leaving nothing
behind, per-line validation, mixed treatments credited partially, the stale
link redirect, the role gate, and Arabic RTL.
