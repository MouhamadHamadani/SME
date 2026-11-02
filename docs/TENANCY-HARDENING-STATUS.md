# Tenancy hardening — status

Audit taken at commit `ba01808` on 2 November 2026, **before** anything in this
session was written, then extended with what the session added.

This session executes the two hardening items adopted in
[ARCHITECTURE-DECISION-tenancy.md](ARCHITECTURE-DECISION-tenancy.md) —
automate the tenant migration fan-out, and make MySQL enforce tenant isolation.
It does not reopen the keep-versus-revert decision; that is closed.

---

## Before: what actually ran

### How migrations reached tenant databases

**They did not, after the day a tenant was created.**

| Where | What it ran |
|---|---|
| `CLAUDE.md` command block | `php artisan migrate` — central only |
| `docs/RUNNING.md` §1 | `php artisan migrate` — central only |
| `composer.json` `setup` script | `php artisan migrate --force` — central only |
| `TenancyServiceProvider`, `TenantCreated` pipeline | `Jobs\MigrateDatabase` → `tenants:migrate --tenants=<new id>` — the new tenant only, once |
| Deploy script, CI, Envoy, Dockerfile | none exist |

`tenants:migrate` appeared **nowhere** in the repository outside the vendor
package, its own config comment and the ADR. So the `CLAUDE.md` command block
was not an oversight in the docs — it was an accurate description of reality.
Every tenant was migrated exactly once, at birth. A tenant migration added
after that reached new tenants only, and the existing ones silently stayed on
the old schema until something 500'd.

And `php artisan migrate` gave no hint of it. Its output is "Nothing to
migrate" when a release carries only tenant migrations, which is precisely the
release where tenants are behind.

### What credential tenant connections used

**The central one — `root`, with no password, in every environment the
repository describes.**

- `config/tenancy.php` → `'mysql' => MySQLDatabaseManager::class`.
  `PermissionControlledMySQLDatabaseManager` was present and commented out.
- `template_tenant_connection` is `null`, so a tenant connection is the central
  `mysql` connection with only `database` swapped
  (`DatabaseConfig::connection()`).
- `.env` and `.env.example` both set `DB_USERNAME=root`, `DB_PASSWORD=` empty.
  `phpunit.mysql.xml` does the same.
- The local `root@localhost` holds `ALL PRIVILEGES ON *.* WITH GRANT OPTION`
  plus every dynamic admin privilege (MySQL 8.0.42). Not "broad grants across
  tenant schemas" — the whole server.

The production credential on the Hetzner box is not visible from the
repository. Nothing in the code narrows it, so it is whatever that server's
`.env` says; if it follows `.env.example`, it is root.

### How many tenants exist

**Pre-launch. Zero real tenants that this repository can see.**

- Local `sme_central`: 0 rows in `tenants`. `sme_test_central`: 0.
- One orphan: the local MySQL holds a database
  `tenant962153f1-9e76-4ac1-a18a-afa1cd05e8c8` that no `tenants` row in either
  central database references — almost certainly a test run that died before
  its teardown. Harmless locally; it illustrates that nothing reports an
  orphan.
- Production's count is not visible from here. The product has no self-serve
  signup and the founder provisions each business by hand, so it is at most a
  handful.

That lowers the risk of the credential switch for existing tenants. The
backfill command below is still written for a live estate.

### What the installed package ships

`stancl/tenancy` **v3.10.1** (`composer.lock`). Read from vendor source, not
from documentation:

| Piece | Behaviour |
|---|---|
| `PermissionControlledMySQLDatabaseManager` | Exists. `createDatabase()` → `CREATE DATABASE`, then `CREATE USER \`u\`@\`%\` IDENTIFIED BY '…'` and `GRANT <18 privileges> ON \`db\`.* TO \`u\`@\`%\``. `deleteDatabase()` → `DROP DATABASE`, then `DROP USER IF EXISTS 'u'`. |
| Where credentials live | `DatabaseConfig::makeCredentials()` writes `tenancy_db_username` and `tenancy_db_password` into the tenant's `data` JSON column in the central `tenants` table. `tenantConfig()` merges every `tenancy_db_*` attribute over the template connection, so a stored username/password replaces the central one. |
| Generated names | Username `Str::random(16)`; password `Hash::make(Str::random(32))` (a bcrypt string, used as the plaintext password). |
| `virtualcolumn` | Handles `encrypted` casts on `data` keys without re-encrypting on load. |

Limitations found in that source, each decided below rather than shipped
silently:

1. **The password is stored in clear text** in `tenants.data`. The WhatsApp
   access token in the same row is encrypted; this would not be.
2. **A failed `CREATE USER` writes the password to the log.** The password is
   interpolated into the SQL, and `QueryException`'s message carries the SQL
   (`QueryException::formatMessage`), which Laravel then logs and prints.
3. **Deleting a tenant with no stored username runs `DROP USER IF EXISTS ''`**
   — the anonymous user — instead of nothing. Every existing tenant is in that
   state until it is backfilled.
4. **The user is dropped only after `DROP DATABASE` succeeds.** If the database
   is already gone, the throw skips the user. MySQL does not remove grants when
   a database is dropped, so that user keeps a grant on the name and inherits
   any future database created under it.
5. **Random usernames make a half-finished provisioning unrecoverable.** A
   crash between `CREATE USER` and saving the tenant leaves a user nobody can
   identify, holding a grant on a live tenant schema.
6. **Host is `%`.** The user may connect from any host that can reach MySQL.
7. **The grant set is schema-scoped but not minimal** — it includes `DROP`,
   `EVENT`, `TRIGGER`, `CREATE ROUTINE`. Tenant migrations run as the tenant
   user (`tenants:migrate` initialises tenancy first), so DDL privileges are
   needed; none of them reach another schema.

---

## After: what this session added

### 1. `php artisan deploy:migrate` — the deploy step

`app/Console/Commands/DeployMigrate.php`. In order:

1. **Maintenance mode on** — unless the app is already down, in which case it
   is left down at the end too (the deploy script, or a previous failed run,
   owns bringing it back).
2. **Central** — `migrate --force`.
3. **Each tenant, oldest first** (`created_at`, then id) — computes that
   tenant's pending migrations; if none, skips it; otherwise
   `tenants:migrate --tenants=<id> --force`. Never bare `migrate` against a
   tenant schema.
4. **Per-tenant logging** — `deploy:migrate: tenant migrated` (with the
   migration names) or `deploy:migrate: tenant failed` (with the error) to the
   default log, and a line per tenant on the console. A summary line at the
   end of the fan-out.
5. **Stops at the first failure.** If migration *n* breaks tenant 137 it will
   very likely break 138 too; carrying on only widens the mixed estate. The
   app stays in maintenance mode — the estate is on two schemas — and the
   command exits 1.
6. **Post-run assertion** — central has nothing pending, every tenant has
   nothing pending, and every tenant holds the **same set** of applied
   migrations. Any difference exits 1, names the tenants, and leaves the app
   down.
7. **Maintenance mode off** — only if this run turned it on.

**Resuming is re-running.** A tenant with nothing pending is skipped without
running anything, and the order is fixed, so after a failure at tenant 137 the
next run passes over 1–136 and starts at 137. There is no state file to go
stale or be forgotten on another server. Granularity is one migration: a
tenant that applied migration 1 of 3 before failing resumes at 2. (A single
migration that dies half-way through its own DDL is MySQL's problem, not this
command's — MySQL DDL is not transactional. Keep tenant migrations to one
change each.)

**"Same migration batch", interpreted.** The ADR asked for every tenant to be
"at the same migration batch". Batch numbers are not comparable across
databases — a tenant created last week has every migration in batch 1, one
created in September has them spread over several — so comparing them would
fail a healthy estate forever. The assertion compares the **set of applied
migration names** instead, which is what "on the same version" actually means.
Central is checked for nothing pending; its migration list is a different one
and is not compared with the tenants'.

### 2. A bare `migrate` can no longer pass for a deploy

`TenancyServiceProvider::guardBareMigrate()` listens for `MigrationsEnded` and
`NoPendingMigrations`. When a central `migrate` finishes — outside tenant
context, not under `--pretend`, not inside `deploy:migrate` — it checks every
tenant, and if any is behind it **throws**, so the command exits non-zero:

```
`migrate` migrated the central database only. 3 tenant database(s) are still
behind (…). Run `php artisan deploy:migrate`.
```

That closes the failure mode at the command that causes it, including the
worst case: a release carrying only tenant migrations, where `migrate` used to
print "Nothing to migrate" and exit 0. A deploy script that runs `migrate` by
mistake now stops. Central's own migrations have already run when it throws;
nothing is rolled back, and `deploy:migrate` picks up from there.

It costs one `tenants` query per `migrate` when there are no tenants, and one
read of each tenant's `migrations` table when there are.

There is no "refuse if out of sync going in" preflight on `deploy:migrate`. An
estate that is out of sync going in is exactly the resume-after-failure case,
and refusing it would stop the command finishing its own job.

### 3. Per-tenant MySQL users

`config/tenancy.php` now maps `mysql` **and** `mariadb` to
`App\Tenancy\ScopedMySQLDatabaseManager`, a subclass of stancl's
`PermissionControlledMySQLDatabaseManager`. Creating a tenant (`tenant:create`,
or anything else that fires `TenantCreated`) now runs, through the existing
`Jobs\CreateDatabase`:

```
CREATE DATABASE `tenant<uuid>` …
CREATE USER `t_<30 hex of the uuid>`@`%` IDENTIFIED BY '<generated>'
GRANT <stancl's 18 privileges> ON `tenant<uuid>`.* TO `t_…`@`%`
```

From then on the tenant's connection logs in as that user. Its grants are
`USAGE ON *.*` plus that one schema — `TenantDatabaseUserTest` asserts the
`SHOW GRANTS` output. Migrations run as the tenant user too
(`tenants:migrate` initialises tenancy first), which is why the grant set
includes DDL.

**Where the credential lives:** where stancl puts it — `tenancy_db_username`
and `tenancy_db_password` in the tenant's `data` column in central `tenants`.
No parallel secrets store.

#### The vendor limitations, decided

| # | Limitation | Decision |
|---|---|---|
| 1 | Password stored in clear text | **Patched.** `tenancy_db_password` carries an `encrypted` cast on `Tenant` — the same treatment as the WhatsApp token in the same row; `virtualcolumn` supports encrypted casts on `data` keys. Both credential keys are in `$hidden`, so a Tenant serialised by mistake does not carry them. |
| 2 | Failed `CREATE USER` logs the password | **Patched.** `createUser()` catches the `QueryException` and rethrows a `RuntimeException` naming the user, the database and the MySQL error code — no statement, and no chained exception carrying it. Tested by forcing error 1396. |
| 3 | `DROP USER IF EXISTS ''` for a tenant with no user | **Patched.** `deleteUser()` does nothing without a username, and names the host explicitly. |
| 4 | User skipped if `DROP DATABASE` throws | **Patched.** `deleteDatabase()` drops the user **first**: a grant that outlives its schema is the security gap; a schema that outlives its user is reachable only by the central credential. Tested by dropping the database by hand first. |
| 5 | Random usernames make a crash unrecoverable | **Patched.** Usernames are `t_` plus the first 30 hex characters of the tenant UUID (MySQL's limit is 32). A provisioning run that died part-way finds and replaces its own leftover, and `mysql.user` can be read against `tenants`. |
| 6 | Host `%` | **Accepted, documented.** The user can log in from any host that can reach MySQL. The boundary for that is the network: on the Hetzner box MySQL should listen on `127.0.0.1` or a private interface only (`bind-address`). Pinning the host means knowing the app's address at provisioning time — not worth a config value while app and database share one machine. Revisit if they split. |
| 7 | Grants schema-scoped but not minimal | **Accepted, documented.** Includes `DROP`, `ALTER`, `EVENT`, `TRIGGER`, `CREATE ROUTINE`. All are confined to the tenant's own schema; none reaches another tenant, which is the property this work exists for. Trimming them would need a separate migration credential — out of proportion now. |

Two operational requirements follow:

- **The central credential must hold `CREATE USER` and `GRANT OPTION`** — it
  now creates and drops users. Root has both. This session did not change the
  central credential's scope (out of scope by instruction).
- **`APP_KEY` now guards database access.** Rotate it only through
  `APP_PREVIOUS_KEYS`. Rotated without, every stored password becomes
  undecryptable and every tenant loses its database. Recovery: set the key,
  run `php artisan tenants:provision-db-users`, which re-provisions any tenant
  whose stored credential no longer connects.

MariaDB is mapped to the same manager so a MariaDB install does not quietly
stay on the shared credential, but it is **untested** — the suite runs on
MySQL 8.0.42.

### 4. Existing tenants: `php artisan tenants:provision-db-users`

`app/Console/Commands/ProvisionTenantDatabaseUsers.php`. Per tenant, oldest
first:

- **Own user exists and connects** → `already scoped`, untouched.
- **Otherwise** → on a *copy* of the tenant: drop any leftover user of the
  derived name, `CREATE USER` + `GRANT`, then log in as it and read the
  tenant's own `migrations` table. Only when that works is the real tenant row
  updated — one `UPDATE` of `tenants`. If it does not work, the new user is
  dropped and the tenant stays on its old credential.
- **One tenant failing does not stop the others**; the command exits 1 and
  says which. Re-run to retry just those.

Why it is safe on a live estate: the stored credential never points at a user
that does not exist. A request already running keeps the connection it has
(MySQL does not kill existing sessions on `DROP USER` either); the next request
reads the tenant row — tenant resolution is uncached here
(`DomainTenantResolver::$shouldCache` is false) — and gets the new credential.
The central credential is never revoked, so no tenant is ever without a
working one.

One narrow window, in the repair path only: a tenant whose *stored* user exists
but cannot connect is dropped and recreated, and new connections in the
milliseconds before the row is saved fail. They were already failing — that is
why it is being repaired.

On SQLite there are no users; every tenant is reported `skipped` and the
command succeeds.

### 5. Deploy runbook

```bash
php artisan down                    # optional — deploy:migrate does it if you don't
git pull && composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan deploy:migrate          # central + every tenant; exits 1 on any problem
php artisan config:cache && php artisan route:cache
php artisan up                      # only if you ran `down` yourself
```

If `deploy:migrate` exits 1: read the tenant id and error it printed (also in
`storage/logs/laravel.log`, lines starting `deploy:migrate:`), fix the cause,
run `php artisan deploy:migrate` again — finished tenants are skipped — then
`php artisan up`.

**Once, on the first deploy that carries this change:**

```bash
php artisan deploy:migrate
php artisan tenants:provision-db-users
```

Then check on the server that only the expected users hold tenant grants:

```sql
SELECT user, host FROM mysql.user WHERE user LIKE 't\_%';
SELECT DISTINCT grantee, table_schema FROM information_schema.schema_privileges
  WHERE table_schema LIKE 'tenant%';
```

Every `t_…` user should match one row in `tenants`, and each tenant schema
should list exactly one `t_…` grantee.

---

## One thing fixed along the way

The baseline was **349/350 on both drivers**, not 350. `EosiTest` "shows the
estimate and its working to any signed-in user" asserted `8,500.00` — exactly
120 months of service from `2016-09-01` — against the real clock, so it went
red on 1 October 2026 when the clock reached 121. Not a product defect: the
test now freezes the date (`travelTo('2026-09-08')`). Unrelated to tenancy;
kept as its own change.

---

## Deliberately out of scope

- **The tenancy model.** DB-per-tenant stays; that decision is closed.
- **Credential rotation** — no UI, no schedule. Provisioning is one-time;
  `tenants:provision-db-users` repairs a credential that stopped working, which
  is the only rotation-adjacent thing it does.
- **The central credential's scope.** Untouched. It must now hold
  `CREATE USER` and `GRANT OPTION`, which root does.
- **The single-MySQL-instance question.** The ADR's ~1,000-tenant revisit
  point stands.
- **Orphan detection.** Nothing reports a `tenant…` database or `t_…` user that
  no `tenants` row references (the local orphan above is one). The SQL in §5
  answers it by hand; a command is easy to add when the estate makes it worth
  it.

---

## Tests

`DeployMigrateTest` — **runs on SQLite and MySQL.** A probe tenant migration is
added after the tenants exist, so each test starts one migration behind.

- migrates every tenant, inside maintenance mode, logging each; app back up
- a simulated failure at the second of three tenants: exit 1, the third never
  attempted, app left down, failure logged; the re-run skips the first tenant
  (the probe ran for it exactly once in total) and finishes the other two
- a tenant holding a migration the others do not fails the post-run assertion
- no tenants at all succeeds
- a bare `migrate` with every tenant current exits 0; with a tenant behind it
  throws, naming `deploy:migrate`

`TenantDatabaseUserTest` — **MYSQL ONLY** (`./vendor/bin/pest -c
phpunit.mysql.xml`). On SQLite nine of its tests are reported **skipped**,
because SQLite has no users or grants; the file header says so.

- a new tenant gets its own user and the app's tenant connection logs in as it
  (`current_user()`)
- `SHOW GRANTS` for that user is `USAGE ON *.*` plus its own schema, nothing
  else
- **connecting as tenant A's user, MySQL itself refuses a read of tenant B's
  table** (error 1142/1044), with a positive control on A's own schema, and
  B's schema is absent from `SHOW DATABASES`
- the password is encrypted in `tenants.data`, and neither credential key
  appears in `toArray()`
- a `CREATE USER` broken by a quote — where MySQL's own error echoes the
  password — surfaces with the error code only, no statement, no chained
  exception
- deleting a tenant drops its user; so does deleting one whose database was
  already dropped by hand
- `tenants:provision-db-users` moves a pre-existing (root-connected) tenant onto
  its own user, its data still readable; a second run reports `already scoped`
  and changes nothing — same username, same password, one user
- a leftover user from a run that died before saving is replaced

Two run on both drivers: the config maps `mysql` and `mariadb` to the scoped
manager, and `tenants:provision-db-users` leaves an already-scoped tenant's row
byte-for-byte unchanged.

### Results, 2 November 2026

| | Before (baseline) | After |
|---|---|---|
| SQLite | 349 / 350 (EOSI clock) | **366 tests: 357 passed, 9 skipped, 0 failed** |
| MySQL 8.0.42 | 349 / 350 (EOSI clock) | **366 / 366 passed** |

Also run by hand against the local dev database through the real CLI:
`tenant:create` provisioned a `t_…` user; `deploy:migrate` exited 0 and
brought the app back up; `tenants:provision-db-users` reported
`already scoped`; with one row removed from the tenant's `migrations` table a
bare `migrate` exited **1** with the `deploy:migrate` message, and 0 once the
row was restored; deleting the tenant dropped both its database and its user.
`storage/logs/laravel.log` contains no `IDENTIFIED BY` statement from any of
these runs.
