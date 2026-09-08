# Running the app, and signing in as a tenant

The confusing part of this codebase is that it serves **two different sites
from one deploy**, split by hostname:

| URL | What it is | Auth |
|---|---|---|
| `localhost:8000` | Public marketing site (English) | none |
| `localhost:8000/ar` | Public marketing site (Arabic) | none |
| `acme.localhost:8000` | The **app**, for the business `acme` | sign-in required |

There is **no login link on the marketing site**, and there never will be —
it is the public front door. You reach a business's app at *its own subdomain*.

---

## 1. Start it

```bash
composer install && npm install
npm run build
php artisan migrate
php artisan serve
```

`php artisan serve` listens on `localhost:8000`.

> **Why `*.localhost` works with no setup.** Browsers resolve any `*.localhost`
> name to 127.0.0.1 (RFC 6761), so `acme.localhost:8000` reaches your dev
> server without touching the hosts file and without admin rights. This is why
> `APP_URL` defaults to `http://localhost`.
>
> `APP_URL` is what decides the subdomain base — see
> `config('tenancy.app_domain')`. Point it at a real domain for staging or
> production and the same code works unchanged.

---

## 2. Create a business to sign in to

There is **no signup page**. Self-serve billing is Phase 2, so during early
access the founder provisions each business by hand:

```bash
php artisan tenant:create "Arz Clinic" arzclinic \
  --plan=pro \
  --owner-name="Rima Khoury" \
  --owner-email=rima@arzclinic.lb \
  --rate=89500
```

| Argument | Meaning |
|---|---|
| `"Arz Clinic"` | Display name, shown in the app header |
| `arzclinic` | **Subdomain only** — no dots, no `http://` |
| `--plan` | `starter` \| `growth` \| `pro` (matches the published pricing) |
| `--rate` | Opening LBP/USD rate, used as the payroll default |
| `--wa` | WhatsApp sender number (optional) |
| `--owner-password` | Omit it and one is **generated and printed once** |

The command creates the tenant, **its own database**, its domain, and the
owner account. Output looks like:

```
INFO  Created "Arz Clinic" (pro).

Tenant ID ....... 780681f3-322f-42ec-bad4-c182710a28ed
Database ........ tenant780681f3-322f-42ec-bad4-c182710a28ed
Sign in at ...... arzclinic.localhost
Owner ........... rima@arzclinic.lb

WARN  Generated password (shown once): xY7$kQ2mNp4wLz8v
```

**Copy the generated password now** — it is hashed on write and cannot be
recovered. Lost it? Reset it (see §5).

---

## 3. Sign in

Open:

```
http://arzclinic.localhost:8000
```

You will be redirected to `/login` **on that subdomain**, showing the
business's own name. Sign in with the owner email and password.

The app opens in **Arabic by default** (the tenant's `settings.language`).
Switch with the language toggle in the header — it saves to your user, so it
persists.

### Where things are

| Nav | Path | What |
|---|---|---|
| Dashboard | `/` | Plan, exchange rate, WhatsApp sender |
| Payroll | `/payroll` | NSSF contributions, draft and finalise a month |
| Invoices | `/invoices` | Create, issue, mark paid, void |
| Customers | `/customers` | Who you invoice |
| — | on an invoice | Send on WhatsApp (log driver by default — see WHATSAPP.md) |
| VAT | `/vat` | Quarterly return, draft and file |
| Employees | `/employees` | Add, edit, end employment |

---

## 4. Before payroll or VAT will compute anything

Both modules **refuse to run without rates**, by design — see
[PAYROLL.md §1](PAYROLL.md) and [VAT.md §1](VAT.md). Load and verify them once:

```bash
# NSSF — ceilings from the decree in force; there are no defaults
php artisan nssf:schedule --from=2026-01-01 \
  --sickness-ceiling=<LBP> --family-ceiling=<LBP> --source="Decree ___"
php artisan nssf:schedule --verify=1 --by="Your name"

# VAT — threshold from the official source
php artisan vat:schedule --from=2018-01-01 \
  --threshold=<LBP> --source="___"
php artisan vat:schedule --verify=1 --by="Your name"
```

Until a schedule is **verified**, you can draft and read the numbers but not
finalise or file. That gate is deliberate.

### Exchange rates

Lebanon has two rates and they are used for different things — the market rate
for the business's own books, the Ministry-prescribed rate for anything filed
(see [DUAL-CURRENCY-STATUS.md](DUAL-CURRENCY-STATUS.md)). Both are national, so
they are entered centrally by the operator rather than by each business:

```bash
php artisan exchange:rate --date=2026-09-08 --market=<LBP> --mof=<LBP> \
  --source="BDL circular / parallel market close"
php artisan exchange:rate --verify=1 --by="Your name"
php artisan exchange:rate --list
```

Same gate as the others: **nothing may be filed against an unverified Ministry
rate.** Changing a figure clears its verification.

A business sets its own booking rate in the app, under Settings → Exchange
rates. That is a different number and it is theirs to set.

### End-of-service estimates

```bash
php artisan eosi:accrue arzclinic            # today
php artisan eosi:accrue arzclinic --date=2026-12-31
```

§9 calls this a *running* estimate, so it needs running — monthly or at year
end. Each row records the formula version that produced it.

---

## 5. Common situations

**Lost the owner password.** There is no password-reset flow yet. Reset it
directly, inside the tenant's database:

```bash
php artisan tinker
```
```php
$t = App\Models\Tenant::whereHas('domains', fn ($q) => $q->where('domain', 'arzclinic'))->sole();
$t->run(fn () => App\Models\User::where('email', 'rima@arzclinic.lb')->sole()->update(['password' => 'a-new-long-password']));
```

**Add a second user to a business** (staff can read payroll; accountants can
change it):

```php
$t->run(fn () => App\Models\User::create([
    'name' => 'Karim', 'email' => 'karim@arzclinic.lb',
    'password' => 'a-long-enough-password', 'role' => 'accountant', 'locale' => 'ar',
]));
```

**List the businesses you have created:**

```php
App\Models\Tenant::with('domains')->get()->each(fn ($t) => print($t->name.' -> '.$t->domains->pluck('domain')->implode(',').PHP_EOL));
```

**"Tenant could not be identified" / 404 on the subdomain.** The subdomain has
no tenant. Check the spelling against the list above; `arzclinic.localhost` and
`arz-clinic.localhost` are different businesses.

**The marketing site appears at the subdomain.** It should not — that was a
real bug and there is a regression test for it. If you see it, route order in
`bootstrap/app.php` has been changed; read the comment there.

**Sign-in says the details do not match, but you are sure they do.** Users live
in *their own business's* database. Credentials valid at `acme` genuinely do
not exist at `globex`. Check which subdomain you are on.

---

## 6. Running the tests

```bash
./vendor/bin/pest
```

Tests use SQLite in memory and create real per-tenant SQLite files, cleaned up
afterwards. They do not touch your MySQL databases.

### The MySQL run

SQLite forgives things MySQL does not — date comparison, strict mode,
collation, storage engine — and two real defects here were exactly that
divergence. Before a release, run the same suite against MySQL:

```bash
mysql -u root -e "CREATE DATABASE sme_test_central CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
./vendor/bin/pest -c phpunit.mysql.xml
```

This one **does** create and drop real databases (`sme_test_central` and a
`tenant…` database per test), so point it at a local MySQL you do not mind it
churning. It is much slower than the SQLite run — a pre-release gate, not the
inner loop.
