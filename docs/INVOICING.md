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

## 3. Immutability

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

## 4. Money and quantities

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

## 5. Who can do what

Same `manage-payroll` gate as payroll and VAT: **owner and accountant** can
create, issue, void and mark paid. **Staff can read.** See
[PAYROLL.md](PAYROLL.md) for the reasoning and how to change it.

---

## 6. Using it

**Invoices** and **Customers** in the top nav.

1. Add a customer (their VAT number goes on the invoice — without it a B2B
   buyer cannot reclaim the input VAT).
2. New invoice → pick customer, currency, add lines, save draft.
3. Issue it. That is when the number is allocated and the VAT transactions are
   written. It cannot be edited afterwards.

Issuing **refuses without a verified VAT rate schedule**, like everything else
that touches a regulatory figure — see [VAT.md §1](VAT.md).

---

## 7. Not built yet

- **Credit notes.** The one real gap. Voiding works only while the period is
  open; once a return is filed, correcting an invoice needs a credit note, and
  there is none. `VatCalculator` rejects negative amounts, so this is a
  deliberate hole rather than a half-built one.
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

## 8. Tests

`InvoicingTest` — line arithmetic and rounding, per-line VAT summation,
sequential gap-free numbering, no number on drafts, no reuse after voiding,
immutability, rate snapshotting, the VAT hand-off including the
**mixed-treatment split**, USD conversion, invoice/transaction agreement, void
behaviour and the filed-return guard, tenant isolation.

`InvoiceScreenTest` — quantity parsing, validation messages, issuing from the
screen, the read-only redirect for issued invoices, mark-paid and void, the
role gate, customer management, and Arabic RTL.
