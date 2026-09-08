# Payroll & NSSF

> **The system ships with no NSSF figures and will not invent any.**
> Read [§1](#1-the-figures-are-data-not-code) before using this in anger.

---

## 1. The figures are data, not code

No contribution rate or ceiling is hard-coded anywhere. They live in
`nssf_rate_schedules` (central database), effective-dated, and payroll
**refuses to run** when no schedule covers the period being paid.

That is deliberate. A payroll that computes confidently from stale or guessed
figures is worse than one that refuses: it produces numbers that look
authoritative, get declared to the NSSF, and are wrong.

### What I was confident about, and what I was not

| | Confidence | Why |
|---|---|---|
| **Rate structure** — 8% / 3% sickness & maternity, 6% family allowances, 8.5% end-of-service | High | These statutory splits have been stable in Lebanese law for decades. They are pre-filled as **command defaults** in `nssf:schedule`. |
| **Ceilings** | **None** | Decrees have moved them repeatedly since 2019. There is **no default and never will be** — the command requires them explicitly. |

**Before the first real pay run:** confirm both the rates and the ceilings
against the decree in force, and record where they came from in `--source`.
Even the pre-filled rates deserve a second look; they are a convenience, not an
assertion.

### Verification gate

A schedule is unverified until someone signs off on it:

```
php artisan nssf:schedule --verify=<id> --by="Your name"
```

Until then you can **draft** a run and read the numbers, but you **cannot
finalise** one. The payroll screen carries a visible warning while a run rests
on unverified rates. Verification is a human claim — the system only records
that someone made it.

---

## 2. The rules the engine implements

Three branches, and the differences between them matter:

| Branch | Employer | Employee | Ceiling |
|---|---|---|---|
| Sickness & maternity | yes | **yes** | its own ceiling |
| Family allowances | yes | no | a **different**, lower ceiling |
| End of service (EOSI) | yes | no | **none — the full salary** |

Two things this gets right that are easy to get wrong:

- **The two ceilings are different amounts.** Using one for both branches is a
  quiet, plausible-looking error. `NssfCalculatorTest` pins it.
- **EOSI is uncapped.** Applying a ceiling to it under-contributes for every
  employee above that ceiling — the classic Lebanese payroll bug. There is a
  test that asserts the *wrong* figure is not produced.

Whether an employee has dependents does **not** change the contribution. It
decides the allowance the NSSF pays *back*, which is a separate flow (see §6).

Undeclared staff are modelled explicitly (`is_nssf_registered = false`): they
appear on the payroll at full gross with zero contributions on both sides.
This is common enough in Lebanese SMEs that supporting it openly is safer than
having someone fake it by zeroing the rates.

---

## 3. Money

Integer minor units throughout — **never floats**. `App\Payroll\Money`.

- LBP is scale 0 (one minor unit = one lira; piastres are dead).
- USD is scale 2 (cents).
- Rates are integer **basis points**: `850` is 8.5%.
- Rounding is half-up, applied once, in exactly one place
  (`Money::divideRoundHalfUp`). `intdiv` truncates, which would shave a lira off
  every contribution.

Adding LBP to USD throws. There is no implicit conversion anywhere.

### Dual currency

Salaries are stored **in the currency they were agreed in** — post-crisis,
Lebanese salaries are commonly set in USD — and converted to LBP at the payroll
run's stored rate, because NSSF is declared in lira.

Amounts are stored in LBP plus the run's `lbp_per_usd`. The USD view is
*derived*, never stored twice: two stored copies of the same figure eventually
disagree, and then nobody knows which one is the ledger.

---

## 4. Runs are immutable once finalised

A run **snapshots** the rates it used onto itself. It does not merely reference
the central schedule row, because that row can later be corrected — and a
finalised March must keep computing March's numbers forever.

- Re-running a **draft** month rebuilds it (fix a salary, run again).
- A **finalised** month refuses both re-runs and re-finalising.
- Correcting the central schedule afterwards does not touch any existing run.

Lines are written in one transaction. A half-written pay run is worse than one
that failed outright — which is also why tenant databases are pinned to InnoDB.

---

## 5. Using it

```bash
# 1. Load the rates in force (ceilings from the decree — no defaults)
php artisan nssf:schedule --from=2026-01-01 \
  --sickness-ceiling=<LBP> --family-ceiling=<LBP> \
  --source="Decree ___ dated ___"

# 2. Sign off on them
php artisan nssf:schedule --verify=1 --by="Your name"

# 3. Draft a month, then finalise
php artisan payroll:run <subdomain> 2026 3 --rate=89500
php artisan payroll:run <subdomain> 2026 3 --rate=89500 --finalise

# List schedules (also shows which are unverified)
php artisan nssf:schedule
```

Adding a schedule automatically closes the previous open-ended one the day
before, so periods never overlap.

Employees are managed in the app: **Employees** in the top nav. Payroll can
also be drafted and finalised from the **Payroll** screen — the CLI is for
automation, not the only way in.

### Who can do what

| | View payroll & employees | Add/edit employees, draft, finalise |
|---|---|---|
| Owner | yes | yes |
| Accountant | yes | yes |
| Staff | yes | **no** |

Accountant seats are a Pro-plan feature, and doing this is what the seat is
for. Staff being read-only is an ASSUMPTION, not a stated requirement —
salary data is the most sensitive thing in the product. Change it in
`AppServiceProvider` (`manage-payroll` gate) if staff need to maintain the list.

---

## 6. Not built yet

Deliberate omissions, roughly in the order they matter:

- **Family allowance benefit.** The amount NSSF pays back per spouse and child.
  Dependents are already stored; the per-dependent amounts are more
  regulatory data, and would go in the same effective-dated schedule.
- **Part-month pro-rating.** Someone who joins or leaves mid-month currently
  gets a full month's line. `Employee::activeDuring` includes them; the
  proration itself is not implemented.
- **EOSI liability vs. contributions.** The 8.5% paid to the NSSF is tracked.
  Lebanese labour law entitles an employee to roughly one month per year of
  service, and where that exceeds what the fund holds the employer tops it up.
  That gap is **not** modelled — `serviceMonthsAt()` exists as the raw material.
- **The declaration form itself.** Totals are computed; the NSSF's actual
  submission format is not generated.
- **Salaries split across currencies.** One salary is currently in one
  currency. Part-USD/part-LBP contracts are real and would need a second pair
  of columns.
- **Overtime, bonuses, deductions, leave.** No pay elements beyond base salary.

---

## 7. Tests

`NssfCalculatorTest` and `MoneyTest` use **fixture** rates and prove the
*engine*: which ceilings apply to which branch, that EOSI is uncapped, exact
rounding, and that the books balance (`gross + employer = total cost`,
`gross − employee = net`) across a range of salaries including zero and
1.2 billion lira. They stay valid whatever figures a real schedule carries.

`PayrollRunTest` covers the integration: refusing to run without a schedule,
using the rules in force *during the period paid* rather than today's,
snapshot immutability, tenant isolation, and the finalise gate.
