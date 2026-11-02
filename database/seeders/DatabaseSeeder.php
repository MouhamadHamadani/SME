<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * There is deliberately nothing to seed.
 *
 * This used to be the stock Laravel scaffold, which creates a `User`. That
 * breaks here in two different ways, because this application's shape is not
 * the one the scaffold assumes:
 *
 *   `db:seed` / `migrate:refresh --seed` runs on the CENTRAL connection, where
 *   there is no `users` table at all — users are per-tenant (see
 *   database/migrations/tenant/0001_01_01_000000_create_users_table.php). The
 *   failure read "Table 'sme_central.users' doesn't exist", which points at the
 *   schema rather than at the seeder that asked for it.
 *
 *   `tenants:seed` is worse: config/tenancy.php aims it at this class, so it
 *   would have succeeded — inserting a fake "Test User" into a real business's
 *   database, with a known email and a factory password.
 *
 * Nothing belongs here instead. The two kinds of data this system needs are
 * both entered deliberately and neither is a fixture:
 *
 *   - Regulatory figures (NSSF, VAT, LBP/USD rates) are CENTRAL and are typed
 *     in against a source document by an operator — `nssf:schedule`,
 *     `vat:schedule`, `exchange:rate`. None ship, on purpose: a payroll or a
 *     return computed from a seeded guess looks authoritative and gets
 *     declared. See docs/PAYROLL.md and docs/VAT.md.
 *   - A business and its owner come from `tenant:create`, which provisions the
 *     tenant database and its first user together.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('Nothing to seed. Use `tenant:create` for a business, and `nssf:schedule` / `vat:schedule` / `exchange:rate` for regulatory figures.');
    }
}
