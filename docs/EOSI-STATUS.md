# End-of-service indemnity — status

Audit taken at commit `4ac697f`, **before** anything in this session was
written, then extended with what the session added.

> **Source.** `docs/knowledge-base.md` appeared in the repository partway
> through this session and §3.2 was read directly from it. The implementation
> below matches it — including the band boundaries — and §3.7 rates the
> EOSI seniority-coefficient formula **high confidence (multiple consistent
> sources)**.
>
> Two things §3.2 says that the code reflects and a reader should not lose:
> **NSSF, not the employer, formally calculates the payout** — everything here
> is the employer's own estimate for planning and for answering an employee,
> not an authoritative figure. And **Law 319 may replace the lump-sum model
> entirely**, which is why the calculation sits behind an interface.

---

## Before: what already existed

**The employer's contribution. Not the employee's entitlement.**

That distinction is the whole audit. The two are different numbers, computed
differently, and the codebase had exactly one of them.

### What existed

| | |
|---|---|
| `NssfCalculator` | The **8.5% employer contribution** to the fund, correctly uncapped on full salary — with a comment noting that capping it is the classic Lebanese payroll bug. |
| `payroll_lines.eosi_base_lbp` / `eosi_employer_lbp` | That contribution, per employee, per month. |
| `nssf_rate_schedules.eosi_employer_bps` | The rate, effective-dated and verification-gated like the rest. |
| `Employee::serviceMonthsAt()` | Whole months of service — with the docblock "the basis for indemnity", i.e. raw material deliberately left unused. |

### What was missing — everything else

1. **No accrual ledger.** §9's "running estimate per employee,
   formula-versioned" did not exist in any form. No table, no model.
2. **No seniority coefficient.** The 50 / 65 / 85 / 100% table appeared nowhere
   in the codebase. Searching for `seniority`, `coefficient` and `indemnit`
   returned only the NSSF contribution and that one docblock.
3. **No 12-month average salary.** Nothing averaged anything; the contribution
   is computed on the current month's figure.
4. **No bonus or overtime data at all.** `grep` for `bonus` and `overtime`
   across `app/` and `database/` returned nothing. The §3.2 carve-out could not
   have been implemented even in principle, because the payroll has no pay
   elements beyond base salary — so "paid consistently for three years" was
   unanswerable from the data held.
5. **No strategy interface.** The Law 319 mitigation §3.2 and §11 both call for
   was absent. There was nothing to swap, because there was no calculation.
6. **No screen.**

### Honest read of the before-state

The build report's "deliberately not built" list said *"the end-of-service
top-up above what the fund holds"*, which implies the accrual underneath it
existed. **It did not.** The system knew what the business had *paid in*; it
had no view at all of what the employee was *owed*. For the clinic and salon
persona §4 names — high turnover, EOSI accuracy as the thing they care about —
that is the gap between the product's pitch and the product.

---

## After: what this session added

### The calculation, behind an interface

`App\Payroll\Eosi\EosiStrategy` — `name()`, `version()`, `estimate()`. Pure by
contract: no database, no clock, no config, so an estimate can be reproduced
years later instead of recomputed under whatever law is current.

`LumpSumStrategy` implements it:

```
indemnity = average taxable salary over the last 12 months
          x years of service
          x seniority coefficient
```

| Years of enrolment | Coefficient |
|---|---|
| under 5 | 50% |
| 5 to under 10 | 65% |
| 10 to under 20 | 85% |
| 20 and over | 100% |

Boundaries are **inclusive at the lower end** — five years exactly is 65%, not
50%. Tests pin 4-vs-5, 9-vs-10 and 19-vs-20 in both directions, because that
step is worth fifteen per cent of someone's final payment.

The three terms are combined in **one division**
(`average x coefficient x months / 120000`) rather than three sequential
percentage operations, so the result rounds once. `Money::scaleBy()` was added
for it and routes through the same single rounding helper as everything else —
it is not a second rounding site.

### The Law 319 mitigation, concretely

The binding lives in one line of `AppServiceProvider`:

```php
$this->app->bind(EosiStrategy::class, LumpSumStrategy::class);
```

A `MonthlyPensionStrategy` replaces that line and nothing else — not the
accrual action, not the command, not the screen. A test proves it, by binding
an anonymous alternative strategy and asserting the stored accrual carries
*its* name and version.

### The three-year carve-out, and the data it needs

`employee_pay_elements` is new: dated bonus and overtime records, in the
currency they were actually paid in. It exists **for EOSI** — the carve-out
cannot be applied to data nobody recorded.

A kind qualifies when it appears in **each** of the three 12-month windows
ending at the as-of date. One large bonus in a good year is not consistency; a
modest one every year is. A gap in any window disqualifies the kind outright
rather than counting the years around it. Qualifying elements from the last 12
months are then spread across those 12 months, which is what makes them part of
an *average monthly* salary rather than a lump added on top.

**Deliberate boundary:** pay elements do **not** feed the monthly payroll run
or its NSSF contributions. Wiring them into payroll proper would change every
existing run's figures and is out of this session's scope
([PAYROLL.md §6](PAYROLL.md)).

### The ledger

`eosi_accruals` — one row per employee per as-of date, carrying the strategy,
the formula version, the coefficient, the 12-month average, the estimate, and
an `inputs` JSON holding the working. An estimate a person cannot audit is one
they cannot defend to a departing employee.

Re-running a date **corrects** that day's row rather than appending a second
answer. Older rows keep their own strategy and version, so a figure computed
under today's law keeps saying what it said after the law changes — the screen
marks such rows "computed under an earlier formula".

### Screens and command

- **Employees → any employee → End of service.** Readable by any signed-in
  user; recording an estimate and adding pay elements are gated on
  `manage-payroll`, like every other write to salary data.
- `php artisan eosi:accrue <business> [--date=]` — freezes the estimate for
  every current employee. §9 calls it a running estimate, which means it has to
  be run.

### Two clocks, not one

This is the correction the knowledge base forced. §3.2 writes the coefficient
bands in **years of NSSF enrolment**, and §9 lists "NSSF enrollment date" on
Employee explicitly, *"needed for the EOSI seniority coefficient"*.

I had first built this with a single clock — enrolment assumed equal to service
— and flagged it as an assumption. Reading §9 turned that from a defensible
assumption into a known gap, so `employees.nssf_enrolled_on` now exists and the
two terms are kept apart:

| Term | Clock |
|---|---|
| Years-of-service multiplier | `started_on` → as-of |
| Seniority coefficient | `nssf_enrolled_on` → as-of |

They coincide for someone declared from day one, which is why the column is
nullable and null reads as "declared from the start date". They diverge for
everyone declared late — common enough in Lebanese SMEs that conflating them
would quietly overstate the coefficient. Ten years of service with four years
of enrolment now pays 50% of ten years, not 85%; a test pins exactly that.

An **undeclared** employee has no enrolment years, so they sit in the lowest
band — but their service multiplier is untouched, because the employer still
owes those years. Every accrual records `enrolment_assumed_from_start_date` so
a reader can tell which rows rested on the fallback.

### Still not built

- **The top-up above what the fund holds.** Both numbers now exist — the
  contribution on `payroll_lines`, the entitlement on `eosi_accruals` — so the
  comparison is finally possible. It is not implemented.
- **Payment on departure.** Ending an employee does not settle or close the
  accrual.
- **Part-month service.** `serviceMonthsAt()` counts whole months.
- **Pay elements in payroll.** See the boundary above.
- **Contract type.** §9 lists it on Employee; it is not modelled, and it may
  matter for indemnity treatment.
- **A quarterly reminder to re-check Law 319.** §3.2 asks for one in the team's
  calendar. That is a calendar entry, not code, and nobody has made it.
