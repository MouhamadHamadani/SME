# VAT

> **The system ships with no VAT figures and will not invent any.**
> Read [§1](#1-the-figures-are-data-not-code) before filing anything.

Same contract as [PAYROLL.md](PAYROLL.md), because it is the same problem:
regulatory numbers that move, on a return somebody signs.

---

## 1. The figures are data, not code

Rates and filing rules live in `vat_rate_schedules` (central database),
effective-dated. A return **refuses to build** when no schedule covers the
quarter being reported.

| | Confidence | Why |
|---|---|---|
| **Standard rate — 11%** | High | In force since 2018. Pre-filled as a command **default** (`--rate=1100`). |
| **Registration threshold** | **None** | Moved with the currency collapse. **No default** — the command requires it. |
| **Filing deadline** | Low | Defaults to 20 days after the quarter ends. **Confirm it** — the screen counts down against this. |

A schedule is unverified until a human signs off:

```bash
php artisan vat:schedule --verify=1 --by="Your name"
```

Until then you can **draft** a return and read it, but not **file** it.

---

## 2. What the engine gets right

### Net vs gross

Lebanese businesses quote both ways — some price net and add VAT, others show
a VAT-inclusive shelf price. Both are supported, as **separate methods**:

```php
$calc->fromNet($amount, $treatment, $bps);    // VAT added on top
$calc->fromGross($amount, $treatment, $bps);  // VAT extracted from within
```

At 11%, the VAT inside a gross of 1,110,000 is **110,000** — not 11% of
1,110,000 (122,100). Applying the rate to a gross figure is the classic VAT
error and it inflates every line, so the two directions are deliberately not
one method with a flag.

### Zero-rated vs exempt

Both charge the customer nothing. They are not the same thing:

| | Charges VAT | Counts as taxable turnover | Input VAT on related purchases |
|---|---|---|---|
| **Standard** | yes | yes | recoverable |
| **Zero-rated** (exports) | no — 0% | **yes** | **recoverable** |
| **Exempt** (healthcare, education, most financial services) | no | **no** | **not** recoverable |

Treating an exempt supply as zero-rated overstates taxable turnover and
over-recovers input VAT. There is a test for it.

### Credit carry-forward

A quarter where input VAT exceeds output VAT produces a **credit**, not a
negative payment. That credit carries into the next quarter — but **only from a
finalised return**. A draft can still change, and building on a moving figure
would silently alter the next period every time the previous one was rebuilt.

### Period boundaries

Transactions are matched by an inclusive datetime range, not a date-string
`between`. That is not pedantry: the column stores `2026-03-31 00:00:00`, and
string-comparing it against `"2026-03-31"` sorts it *after* the upper bound —
which **silently dropped every sale made on the last day of the quarter**.
Caught by test, fixed in `VatTransaction::scopeInPeriod`.

---

## 3. Immutability

A return snapshots the rate and deadline it used, so correcting the central
schedule later cannot rewrite a filed quarter. On filing, its transactions are
stamped with the return id so they cannot be claimed twice.

- Re-drafting an open quarter rebuilds it.
- A filed quarter refuses both rebuild and re-filing.

---

## 4. Using it

```bash
# 1. Load the rules in force (threshold from the official source)
php artisan vat:schedule --from=2018-01-01 --threshold=<LBP> --source="___"

# 2. Sign off
php artisan vat:schedule --verify=1 --by="Your name"

# 3. Draft a quarter, then file
php artisan vat:return <subdomain> 2026 1
php artisan vat:return <subdomain> 2026 1 --file

# List schedules
php artisan vat:schedule
```

Or from the app: **VAT** in the top nav. Drafting and filing are gated on
`manage-payroll` (owner + accountant); staff can read.

---

## 5. Not built yet

- **Transaction entry screen.** Sales and purchases are created in code or by
  import. The VAT screen is read-only over them. This is the biggest gap —
  and it mostly disappears when invoicing lands, because an invoice should
  *write* a `vat_transactions` row rather than the return learning to read two
  sources.
- **Partial exemption / apportionment.** A business making both taxable and
  exempt supplies must apportion its input VAT. Not modelled — `is_recoverable`
  is a manual flag per purchase so the system never silently over-recovers.
- **Credit notes and corrections.** Negative amounts are rejected. Adjusting a
  filed period needs a documented correction mechanism.
- **The declaration form itself.** Totals are computed; the Ministry's actual
  submission format is not generated.
- **Registration threshold monitoring.** The threshold is stored but nothing
  warns a business approaching it.
- **Filing in order.** `VatException::priorPeriodOpen` exists for it but is not
  yet enforced — because carry-forward only reads finalised returns, filing out
  of order understates rather than corrupts. Worth closing before launch.

---

## 6. Tests

`VatCalculatorTest` uses a **fixture** 11% and proves the engine: net vs gross,
round-tripping, the zero-rated/exempt distinction, half-up rounding, and that
`net + vat === gross` across a wide range including zero. It stays valid
whatever rate a real schedule carries.

`VatReturnTest` covers the integration: refusing without a schedule, turnover
classification, unrecoverable input, credit positions, carry-forward (including
across a year boundary and *not* from drafts), period boundaries, snapshot
immutability, the filing gate, transaction locking, and tenant isolation.
