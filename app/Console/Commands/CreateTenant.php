<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Provision a business.
 *
 * This is how onboarding actually works right now: there is no self-serve
 * signup, because there is no self-serve billing (Whish/OMT is Phase 2, §6).
 * During early access the founder onboards each business by hand from a row in
 * the `leads` table — this command is that step.
 */
class CreateTenant extends Command
{
    protected $signature = 'tenant:create
        {name : Business name, e.g. "Haddad Grill"}
        {subdomain : Subdomain only, e.g. "haddadgrill"}
        {--plan=starter : starter|growth|pro}
        {--owner-name= : Owner full name}
        {--owner-email= : Owner email (their login)}
        {--owner-password= : Leave blank to generate one}
        {--wa= : WhatsApp sender number for invoice delivery}
        {--rate= : Opening LBP/USD exchange rate}';

    protected $description = 'Create a business, its database, and its owner account';

    public function handle(): int
    {
        try {
            $input = $this->validated();
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->components->error($message);
                }
            }

            return self::FAILURE;
        }

        $password = $input['owner_password'] ?: Str::password(16);
        $generated = ! $input['owner_password'];

        $tenant = Tenant::create([
            'name' => $input['name'],
            'plan' => $input['plan'],
            'wa_phone_number' => $input['wa'],
            'settings' => [
                'exchange_rate' => $input['rate'] === null ? null : (float) $input['rate'],
                'currency' => 'USD',
                'language' => 'ar',
            ],
        ]);

        $tenant->domains()->create(['domain' => $input['subdomain']]);

        // Runs inside the tenant's own database.
        $tenant->run(function () use ($input, $password) {
            User::create([
                'name' => $input['owner_name'],
                'email' => $input['owner_email'],
                'password' => $password,
                'role' => 'owner',
                'locale' => 'ar',
            ]);
        });

        $this->components->info("Created \"{$tenant->name}\" ({$tenant->plan})");
        $this->components->twoColumnDetail('Tenant ID', $tenant->id);
        $this->components->twoColumnDetail('Database', $tenant->database()->getName());
        $this->components->twoColumnDetail('Sign in at', $input['subdomain'].'.'.config('tenancy.app_domain'));
        $this->components->twoColumnDetail('Owner', $input['owner_email']);

        if ($generated) {
            $this->newLine();
            $this->components->warn("Generated password (shown once): {$password}");
        }

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function validated(): array
    {
        $data = [
            'name' => $this->argument('name'),
            'subdomain' => Str::lower(trim((string) $this->argument('subdomain'))),
            'plan' => $this->option('plan'),
            'owner_name' => $this->option('owner-name') ?: $this->ask('Owner name'),
            'owner_email' => Str::lower(trim((string) ($this->option('owner-email') ?: $this->ask('Owner email')))),
            'owner_password' => $this->option('owner-password'),
            'wa' => $this->option('wa'),
            'rate' => $this->option('rate'),
        ];

        return validator($data, [
            'name' => ['required', 'string', 'max:160'],
            // Subdomain only — no dots, no scheme. A label per RFC 1123.
            'subdomain' => [
                'required', 'string', 'max:63',
                'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/',
                Rule::unique('domains', 'domain'),
            ],
            'plan' => ['required', Rule::in(Tenant::PLANS)],
            'owner_name' => ['required', 'string', 'max:120'],
            'owner_email' => ['required', 'email', 'max:190'],
            'owner_password' => ['nullable', 'string', 'min:12'],
            'wa' => ['nullable', 'string', 'max:40'],
            'rate' => ['nullable', 'numeric', 'gt:0'],
        ], [
            'subdomain.regex' => 'The subdomain must be a single label: lowercase letters, digits and hyphens only.',
        ])->validate();
    }
}
