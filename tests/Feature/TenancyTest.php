<?php

use App\Models\Employee;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;

use function Pest\Laravel\artisan;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

it('gives each business its own database', function () {
    $acme = makeTenant('acme');
    $globex = makeTenant('globex');

    expect($acme->database()->getName())->not->toBe($globex->database()->getName());
});

it('keeps one business\'s users out of another\'s database', function () {
    $acme = makeTenant('acme');
    $globex = makeTenant('globex');

    makeTenantUser($acme, ['email' => 'rami@acme.test', 'name' => 'Rami']);
    makeTenantUser($globex, ['email' => 'nour@globex.test', 'name' => 'Nour']);

    $acme->run(function () {
        expect(User::pluck('email')->all())->toBe(['rami@acme.test']);
    });

    $globex->run(function () {
        expect(User::pluck('email')->all())->toBe(['nour@globex.test']);
    });
});

it('lets the same email be a user at two different businesses', function () {
    // The unique index on users.email is scoped to one tenant database, so an
    // accountant serving two clients is not a collision.
    $acme = makeTenant('acme');
    $globex = makeTenant('globex');

    makeTenantUser($acme, ['email' => 'accountant@example.com', 'role' => 'accountant']);
    makeTenantUser($globex, ['email' => 'accountant@example.com', 'role' => 'accountant']);

    $acme->run(fn () => expect(User::where('email', 'accountant@example.com')->count())->toBe(1));
    $globex->run(fn () => expect(User::where('email', 'accountant@example.com')->count())->toBe(1));
});

it('resolves the tenant from the subdomain', function () {
    makeTenant('acme', ['name' => 'Acme Trading']);

    get(tenantUrl('acme', '/login'))
        ->assertOk()
        ->assertSee('Acme Trading', false);
});

it('404s an unknown subdomain', function () {
    get(tenantUrl('nobody', '/login'))->assertNotFound();
});

it('does not expose the app on a central domain', function () {
    makeTenant('acme');

    // PreventAccessFromCentralDomains: /login is a tenant route only.
    get('http://sme.test/login')->assertNotFound();
});

it('does not let the marketing homepage shadow the app on a tenant subdomain', function () {
    // Regression. The marketing routes are unconstrained and the tenant routes
    // are subdomain-constrained, so registration ORDER decides which matches
    // `acme.sme.test/`. stancl registers tenant routes inside app->booted() —
    // dead last — which would serve the marketing page here. Route order is
    // owned by bootstrap/app.php precisely to prevent this.
    $tenant = makeTenant('acme', ['name' => 'Acme Trading']);
    makeTenantUser($tenant, ['email' => 'owner@acme.test']);

    // Unauthenticated, so the app redirects to its own login rather than
    // rendering the public marketing homepage.
    get(tenantUrl('acme', '/'))
        ->assertRedirect(tenantUrl('acme', '/login'))
        ->assertDontSee('Accounting and payroll that already knows');
});

it('keeps the marketing site public and outside tenancy', function () {
    makeTenant('acme');

    get('http://sme.test/')->assertOk()->assertSee('$19');
    get('http://sme.test/ar')->assertOk()->assertSee('dir="rtl"', false);

    // And it still works with no tenant in the world at all.
    Tenant::all()->each->delete();
    get('http://sme.test/')->assertOk();
});

it('still accepts marketing leads with tenancy installed', function () {
    post('http://sme.test/early-access', [
        'name' => 'Rima Khoury',
        'business_name' => 'Arz Clinic',
        'phone' => '+961 71 555 111',
        'email' => 'rima@arzclinic.lb',
        'business_type' => 'clinic_salon',
        'currently_using' => 'paper',
        'locale' => 'en',
        'website' => '',
    ])->assertRedirect();

    // Leads are central and pre-tenant — no tenant_id, no tenant database.
    expect(Lead::count())->toBe(1);
});

describe('tenant:create', function () {
    it('provisions a business, its database and its owner', function () {
        artisan('tenant:create', [
            'name' => 'Haddad Grill',
            'subdomain' => 'haddadgrill',
            '--plan' => 'growth',
            '--owner-name' => 'Rami Haddad',
            '--owner-email' => 'rami@haddadgrill.com',
            '--owner-password' => 'correct-horse-battery',
            '--wa' => '+96170123456',
            '--rate' => '89500',
        ])->assertSuccessful();

        $tenant = Tenant::firstOrFail();

        expect($tenant->name)->toBe('Haddad Grill')
            ->and($tenant->plan)->toBe('growth')
            ->and($tenant->wa_phone_number)->toBe('+96170123456')
            ->and($tenant->settings['exchange_rate'])->toBe(89500.0)
            ->and($tenant->domains->pluck('domain')->all())->toBe(['haddadgrill']);

        $tenant->run(function () {
            $owner = User::sole();
            expect($owner->email)->toBe('rami@haddadgrill.com')
                ->and($owner->role)->toBe('owner')
                ->and($owner->password)->not->toBe('correct-horse-battery');
        });
    });

    it('rejects a subdomain that is not a single label', function () {
        artisan('tenant:create', [
            'name' => 'Bad',
            'subdomain' => 'not.a.label',
            '--owner-name' => 'X',
            '--owner-email' => 'x@example.com',
        ])->assertFailed();

        expect(Tenant::count())->toBe(0);
    });

    it('rejects a duplicate subdomain', function () {
        makeTenant('acme');

        artisan('tenant:create', [
            'name' => 'Acme Two',
            'subdomain' => 'acme',
            '--owner-name' => 'X',
            '--owner-email' => 'x@example.com',
        ])->assertFailed();

        expect(Tenant::count())->toBe(1);
    });

    it('rejects an unknown plan', function () {
        artisan('tenant:create', [
            'name' => 'Acme',
            'subdomain' => 'acme',
            '--plan' => 'enterprise',
            '--owner-name' => 'X',
            '--owner-email' => 'x@example.com',
        ])->assertFailed();
    });
});

it('binds a route model rather than passing the tenant subdomain positionally', function () {
    /*
     | Regression, and a nasty one: tenant routes sit under
     | Route::domain('{tenant}.…'), so `tenant` is the route's FIRST parameter.
     | Laravel passes route parameters to controllers positionally, so a
     | controller declaring `edit(Employee $employee)` received the string
     | "acme" — even though implicit binding had resolved the Employee.
     |
     | Every future route that takes a model would hit this. TenantRouteParameter
     | forgets the parameter once tenancy is initialized.
     */
    $tenant = makeTenant('acme');
    makeTenantUser($tenant, ['email' => 'owner@acme.test', 'role' => 'owner']);
    $tenant->run(fn () => Employee::factory()->create(['name' => 'Bound Correctly']));

    tenancy()->initialize($tenant);
    $employee = Employee::sole();

    test()->actingAs(User::where('role', 'owner')->sole())
        ->get(tenantUrl('acme', '/employees/'.$employee->id))
        ->assertOk()
        ->assertSee('Bound Correctly');
});

it('still generates tenant URLs after forgetting the parameter', function () {
    // Forgetting `tenant` must not break route(); URL::defaults covers it.
    $tenant = makeTenant('acme');
    makeTenantUser($tenant, ['email' => 'owner@acme.test', 'role' => 'owner']);

    tenancy()->initialize($tenant);

    test()->actingAs(User::sole())
        ->get(tenantUrl('acme', '/employees'))
        ->assertOk()
        // Links in the page carry the subdomain rather than throwing
        // UrlGenerationException for a missing parameter.
        ->assertSee(tenantUrl('acme', '/employees/new'), false);
});
