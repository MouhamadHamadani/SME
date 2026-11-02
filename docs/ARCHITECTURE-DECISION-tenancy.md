# ADR: one database per tenant, versus `tenant_id` scoping

**Status:** **DECIDED — keep DB-per-tenant.** Confirmed 2 November 2026 by the founder, after re-reviewing cost against the actual hosting setup (self-managed MySQL on a Hetzner server, not AWS RDS — see the note under `AWS RDS cost` below). The two hardening follow-ups in the Recommendation section are adopted as near-term work, not open questions.
**Date:** 8 September 2026 · **Commit under review:** `4ac697f`
**Author:** written at the request of the founder, who noticed the deviation.

> **This document changes nothing.** No tenancy code, migration or config was
> modified while writing it. The recommendation at the end is a recommendation.

> **Source.** `docs/knowledge-base.md` appeared in the repository partway
> through this session, and §8 and §10 have since been read directly. Both
> confirm the figures used below — §10 gives *"First $10,000 MRR: ~200–250
> customers … 12–18 months"*, and §8 says of multi-tenancy:
>
> > given this is a compliance product handling salary and financial data,
> > tenant isolation deserves real design attention up front — [documented]
> > Laravel multi-tenancy pitfalls (silent tenant-scope bugs from `orWhere()`
> > precedence errors, queue workers losing tenant context on background jobs)
> > … apply directly here, arguably with higher stakes given the data involved
>
> That is the strongest argument in the file for what was built, and it was
> written before the build. It does not settle the cost question below.

---

## Context

The plan on record was a **single database with `tenant_id` columns**, chosen
for lower cost and operational simplicity at MVP scale.

What was built is **one database per business** (`stancl/tenancy` v3, resolved
by subdomain). That is a real deviation from the plan, made during the second
commit of the build, and it now underpins eight modules.

---

## 1. Was the reasoning recorded?

Yes — in three places, all written at the time rather than reconstructed now.

**Commit `7118447`, "Add multi-tenant app foundation":**

> stancl/tenancy v3: one database per business, resolved by subdomain.
> Separation at the database level rather than a tenant_id column, so a
> missing scope cannot leak one business's payroll into another's.

**`docs/ARCHITECTURE.md`, "Tenancy":**

> For this product that is a compliance decision, not a scaling one: a
> business's payroll and employee records are separated at the database level,
> so a missing `where tenant_id = ?` cannot leak one business's salaries into
> another's. It also makes "export or delete everything for this business" one
> operation.

**`database/migrations/2019_09_15_000010_create_tenants_table.php`:**

> Each tenant gets its OWN DATABASE rather than a tenant_id column on shared
> tables. For this product that is a compliance decision, not a scaling one […]

So the deviation was deliberate and documented, on a single stated ground:
**isolation of salary and financial data.** What was *not* recorded is any
costing of the alternative, or acknowledgement that it contradicted the plan.
That omission is the actual failure here — the decision was defensible, but it
was taken unilaterally and filed as a technical note rather than raised as a
change to the plan.

---

## 2. What it actually buys — and what it does not

### Removed structurally

- **Cross-tenant reads from a forgotten scope.** There is no `tenant_id` to
  forget. §8's `orWhere()` precedence trap — where
  `->where(...)->orWhere(...)` around a global scope silently widens the
  query — cannot return another business's rows, because those rows are not in
  the table being queried. This is the strongest argument for the current
  design and it is a genuine structural elimination, not a mitigation.
- **Queue jobs losing tenant context.** `QueueTenancyBootstrapper` is enabled
  (`config/tenancy.php:55`), so jobs carry tenant context. More importantly,
  when context *is* genuinely absent the failure mode inverts: the query runs
  against the central connection, where `employees` does not exist, and throws
  `SQLSTATE[42S02]` at the first query. Under `tenant_id` scoping the same bug
  returns **every tenant's rows**, silently. Loud wrong beats quiet wrong when
  the payload is salaries.
- **Offboarding and export.** "Give me everything you hold on my business" and
  "delete it" are each one operation on one database, not a fan-out across 13
  tables with a `WHERE` clause that has to be right every time.
- **Per-tenant restore.** One business's backup can be restored without
  row-level surgery on a shared table.

### Not removed — and worth being blunt about

- **Initialising the wrong tenant.** If resolution picks the wrong subdomain,
  or code calls `tenancy()->initialize($wrong)`, every query goes to the wrong
  database and looks entirely normal. This is the same severity as a missing
  scope, and database separation does nothing about it. The mitigation is
  identical either way: tests. `TenancyTest` covers it.
- **The isolation is arranged by the application, not enforced by MySQL.**
  `PermissionControlledMySQLDatabaseManager` is commented out
  (`config/tenancy.php:91`), so **one MySQL user holds grants on every tenant
  database.** Nothing at the SQL layer refuses a cross-database query; the app
  simply does not issue one. Calling this "isolation at the database level" is
  true of the schema and not of the permissions. Enabling per-tenant DB users
  would close that gap and is a config change, not an architecture change.
- **Central tables.** `leads`, `nssf_rate_schedules` and `vat_rate_schedules`
  are shared by design. Any future central table holding per-tenant data
  reintroduces exactly the scoping problem this design avoids elsewhere.
- **Ordinary application bugs.** Nothing here prevents showing the right
  tenant's data to the wrong *user* within that tenant.

**Net:** it eliminates one specific, high-consequence, easy-to-cause bug class
and leaves a smaller, harder-to-cause one in place.

---

## 3. What it costs at the §10 target (200–250 customers)

All figures below are **estimates**, labelled as such, from the repository's
actual configuration. Nothing here has been load-tested.

### Database and table count

| | At 250 customers |
|---|---|
| Databases | 251 (250 tenant + 1 central) |
| Tenant migrations, each | 16 (`database/migrations/tenant/`) |
| Tenant tables total | ~3,500 |
| Central tables | 10 |

### Schema migrations — the real operational cost

`php artisan tenants:migrate` iterates tenants **sequentially in one process**.
There is **no CI pipeline and no deploy script in this repository** — I checked
for `.github/workflows`, `Envoy`, `Dockerfile` and `deploy*`, and found none.
So today this is a human running a command and watching it.

At an estimated 1–3 seconds per tenant for a small `ALTER`:

- **250 tenants ≈ 4–13 minutes** of sequential migration.
- For that entire window, tenants are at **mixed schema versions** — the ones
  already migrated and the ones not yet.
- If it fails at tenant 137, there is nothing that reports which tenant failed,
  nothing that resumes from there, and nothing that put the app in maintenance
  mode first.

This is the genuine weakness of the current model, and it is **an automation
gap, not an architectural one**. Under single-DB, the same change is one
`ALTER TABLE` — but on a table with 250 tenants' rows in it, which at this data
volume is also fast, and which is atomic. Single-DB wins here, clearly.

### Connections

Not a constraint at this scale. Laravel opens the central connection plus the
active tenant's connection per request, and `DatabaseTenancyBootstrapper`
purges and reconnects on switch. Concurrency is bounded by **PHP-FPM worker
count**, not tenant count — so MySQL `max_connections` (default 151) is sized
against roughly `workers × 2`, not against 250. A 250-tenant estate on 20
workers needs ~40 connections.

The real resource cost is metadata, not connections: ~3,500 tables means
raising `table_open_cache` and `innodb_open_files`, and a larger InnoDB data
dictionary. Measurable, not alarming, at this size.

### AWS RDS cost

**Essentially identical between the two models at this customer count.** RDS
bills for instance class, allocated storage, IOPS and backup storage. It does
not bill per database or per schema. 250 small databases on one instance and
one database with 250 tenants' rows in it carry the same instance bill.

> **Correction, 2 November 2026.** This product is not hosted on AWS RDS. It
> runs on a self-managed MySQL instance on a Hetzner server. The analysis
> below was written against RDS pricing because that was the plan on record
> at the time; the conclusion still holds and is actually stronger on
> Hetzner. A flat-rate server bills for CPU/RAM/disk, not per database, per
> instance class, or per IOPS tier — so the “essentially identical”
> finding below becomes “identical”: 250 schemas versus one schema with 250
> tenants' rows cost the same box the same money either way. The RDS figures
> are left in place as the worked example; treat “RDS” below as standing in
> for “whatever MySQL host this runs on.”

Rough estimate for either model at 250 customers of this size:
**`db.t4g.medium`, Multi-AZ, ~$120–160/month** including storage and backups
— *2026 list-price estimate, verify against current AWS pricing before
budgeting.* Against $10,000 MRR that is under 2% of revenue in both models.

Where the models genuinely diverge on cost:

- **Backup/restore granularity** favours DB-per-tenant (restore one business).
- **Cross-tenant analytics** favours single-DB (one `GROUP BY` versus a
  fan-out across 250 databases — relevant if you ever want "average payroll
  size across all customers", which today would need `tenants:run`).
- **Connection-pooling products** (RDS Proxy) are priced per vCPU and are
  awkward with per-tenant connections; not needed at this scale.

**Conclusion on cost: the original plan's stated premise — that single-DB is
cheaper at MVP scale — does not hold.** The saving is approximately zero. Its
real advantage is operational simplicity, which is a different argument and a
better one.

---

## 4. What reverting would cost

Files and call sites that encode the DB-per-tenant assumption, counted from the
repository at `4ac697f`:

| Area | What | Scale |
|---|---|---|
| Migrations | `database/migrations/tenant/*` — every table needs `tenant_id`, a composite index, and merging into the central set | **16 files** |
| Models | Tenant-scoped models each need a global scope, and inherit the `orWhere()` trap | **11 of 15** |
| Uniqueness | Invoice numbering, user email, VAT return periods all become **composite** uniques. The `lockForUpdate()` numbering path needs re-verifying under one shared table with 250× the contention | 3+ constraints |
| Provisioning | `tenant:create`, `TenancyServiceProvider` job pipeline | 2 files |
| Routing | `bootstrap/app.php` route order, `routes/tenant.php` domain constraint, `TenantRouteParameter` middleware | 3 files |
| Config | `config/tenancy.php`; session/cache/queue pinning becomes unnecessary | 4 files |
| Tests | `tenant->run()` / `tenancy()->initialize()` call sites | **189 across 17 files** |
| Helpers | `tests/Pest.php` — `makeTenant`, `makeTenantUser`, per-tenant teardown | 1 file |

**Estimate: 3–5 focused days**, plus a data migration for any live tenant, plus
re-verification of all 285 tests. That is a rewrite of the data layer of eight
shipped modules, and it grows with every module added after this one.

It also **adds** a permanent bug class that currently cannot occur, in a
product whose whole positioning is trustworthiness with salary and tax data.

---

## Consequences

**Keeping it:**
- Cross-tenant leakage via a missed scope stays structurally impossible.
- Schema changes stay a fan-out that is currently manual and unmonitored.
- Cross-tenant reporting requires `tenants:run`, not a query.
- The RDBMS still does not enforce the isolation — one credential, all databases.

**Reverting:**
- 3–5 days of rework across eight modules, with no cost saving to show for it.
- Schema changes become one atomic `ALTER`.
- A missing `where tenant_id = ?` becomes a permanent, live risk on salary data.

---

## Recommendation: **keep it, and close the automation gap**

Plainly:

1. **The reason to revert has been costed and does not hold.** Single-DB is not
   meaningfully cheaper at 250 customers — the difference is roughly zero
   dollars on one RDS instance.
2. **It removes a real bug class** for a product holding payroll data, and
   reverting means deliberately reintroducing it.
3. **The revert costs 3–5 days now** and more later, against no saving.
4. **The genuine weakness is operational, not architectural**, and it is
   cheaper to fix than the architecture is to change.

Three things should be done regardless of this decision, none of them large:

- **Automate `tenants:migrate`** in a deploy script: maintenance mode, per-tenant
  success/failure logging, resume-from-failure, and a post-run assertion that
  every tenant is at the same migration batch. This is the actual risk at 250
  customers — roughly a day of work.
- **Enable per-tenant database users**
  (`PermissionControlledMySQLDatabaseManager`) so MySQL enforces the isolation
  the architecture claims, rather than the application merely arranging it.
- **Revisit at ~1,000 tenants**, or the first time the estate outgrows a single
  RDS instance — at which point per-tenant databases become an advantage
  (shardable by tenant) rather than a cost.

**What I am not recommending:** that this decision stands because it is already
built. It should stand because it is the better trade for *this* product. If
the priority is operational simplicity above data-isolation guarantees — a
legitimate call for a two-person company — then revert now, at 3–5 days, rather
than at 10 days after the next three modules.

**Decision: keep it.** Confirmed by the founder on 2 November 2026. The two
hardening items above — automating `tenants:migrate` and enabling
per-tenant database users via `PermissionControlledMySQLDatabaseManager` —
are adopted as the follow-up work, not left open. They do not block anything
already shipped; they close the two real operational gaps this document
identified.

---

## Addendum, 2 November 2026: both hardening items closed

Built on top of `ba01808`. Details, limitations and the deploy runbook:
**[TENANCY-HARDENING-STATUS.md](TENANCY-HARDENING-STATUS.md)**.

- **Migration fan-out — closed.** `php artisan deploy:migrate` migrates central
  and then each tenant inside maintenance mode, logs every tenant, stops at the
  first failure, resumes by re-running (finished tenants are skipped), and
  fails unless every tenant ends on the same applied migrations. A bare
  `migrate` that leaves any tenant behind now exits non-zero.
- **MySQL-enforced isolation — closed.** Every tenant database has its own
  MySQL user granted on that schema only, created with the tenant and dropped
  with it. A test connects as one tenant's user and is refused another
  tenant's schema by MySQL itself. Existing tenants move over with
  `php artisan tenants:provision-db-users`.

The "Consequences" above that read *"manual and unmonitored"* and *"one
credential, all databases"* describe the state before this addendum.
