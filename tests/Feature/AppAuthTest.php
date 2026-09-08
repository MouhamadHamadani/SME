<?php

use App\Models\User;

use function Pest\Laravel\followingRedirects;
use function Pest\Laravel\from;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

beforeEach(function () {
    $this->acme = makeTenant('acme', ['name' => 'Acme Trading']);
    makeTenantUser($this->acme, [
        'name' => 'Rami Haddad',
        'email' => 'rami@acme.test',
        'password' => 'correct-horse-battery',
    ]);
});

it('signs a user in against their own business', function () {
    post(tenantUrl('acme', '/login'), [
        'email' => 'rami@acme.test',
        'password' => 'correct-horse-battery',
    ])->assertRedirect(rtrim(tenantUrl('acme', '/'), '/'));

    tenancy()->initialize($this->acme);
    expect(auth()->check())->toBeTrue()
        ->and(auth()->user()->email)->toBe('rami@acme.test');
});

it('rejects a wrong password', function () {
    from(tenantUrl('acme', '/login'))
        ->post(tenantUrl('acme', '/login'), [
            'email' => 'rami@acme.test',
            'password' => 'wrong-password',
        ])
        ->assertRedirect(tenantUrl('acme', '/login'))
        // The tenant's default language is Arabic, so the failure comes back
        // in Arabic without anyone choosing a locale.
        ->assertInvalid(['email' => __('app.auth.failed', [], 'ar')]);

    expect(auth()->check())->toBeFalse();
});

it('gives the same message for an unknown address as for a wrong password', function () {
    // User enumeration: the response must not reveal whether an address exists
    // at this business. Both paths must produce this identical message.
    $sameMessage = __('app.auth.failed', [], 'ar');

    // Real address, wrong password.
    from(tenantUrl('acme', '/login'))
        ->post(tenantUrl('acme', '/login'), [
            'email' => 'rami@acme.test',
            'password' => 'wrong-password',
        ])->assertInvalid(['email' => $sameMessage]);

    // Address that does not exist here, correct password for someone else.
    from(tenantUrl('acme', '/login'))
        ->post(tenantUrl('acme', '/login'), [
            'email' => 'nobody@acme.test',
            'password' => 'correct-horse-battery',
        ])->assertInvalid(['email' => $sameMessage]);
});

it('will not sign in with another business\'s credentials', function () {
    // The credential is real — at a different business. The guard reads the
    // tenant's own database, so it simply is not there.
    $globex = makeTenant('globex', ['name' => 'Globex']);
    makeTenantUser($globex, ['email' => 'nour@globex.test', 'password' => 'another-password-1']);

    from(tenantUrl('acme', '/login'))
        ->post(tenantUrl('acme', '/login'), [
            'email' => 'nour@globex.test',
            'password' => 'another-password-1',
        ])
        ->assertInvalid(['email']);

    expect(auth()->check())->toBeFalse();
});

it('regenerates the session on sign-in', function () {
    // Session fixation: the id a visitor arrives with must not survive login.
    $this->startSession();
    $before = session()->getId();

    post(tenantUrl('acme', '/login'), [
        'email' => 'rami@acme.test',
        'password' => 'correct-horse-battery',
    ]);

    expect(session()->getId())->not->toBe($before);
});

it('sends a guest to the app login, not the marketing site', function () {
    get(tenantUrl('acme', '/'))->assertRedirect(tenantUrl('acme', '/login'));
});

it('shows the dashboard to a signed-in user', function () {
    tenancy()->initialize($this->acme);
    $user = User::sole();

    $this->actingAs($user)
        ->get(tenantUrl('acme', '/'))
        ->assertOk()
        ->assertSee('Acme Trading', false)
        ->assertSee('Rami Haddad', false);
});

it('signs a user out', function () {
    tenancy()->initialize($this->acme);
    $user = User::sole();

    $this->actingAs($user)
        ->post(tenantUrl('acme', '/logout'))
        ->assertRedirect(tenantUrl('acme', '/login'));

    expect(auth()->check())->toBeFalse();
});

it('rate limits sign-in attempts', function () {
    foreach (range(1, 5) as $i) {
        from(tenantUrl('acme', '/login'))
            ->post(tenantUrl('acme', '/login'), ['email' => 'rami@acme.test', 'password' => 'nope'])
            ->assertRedirect();
    }

    from(tenantUrl('acme', '/login'))
        ->post(tenantUrl('acme', '/login'), ['email' => 'rami@acme.test', 'password' => 'nope'])
        ->assertStatus(429);
});

it('never echoes the submitted password back into the form', function () {
    $response = followingRedirects()
        ->from(tenantUrl('acme', '/login'))
        ->post(tenantUrl('acme', '/login'), [
            'email' => 'rami@acme.test',
            'password' => 'super-secret-value',
        ]);

    $response->assertOk()->assertDontSee('super-secret-value', false);
});

it('renders the app in Arabic with a mirrored layout by default', function () {
    tenancy()->initialize($this->acme);
    $user = User::sole();

    // Tenant default language is 'ar'.
    $this->actingAs($user)
        ->get(tenantUrl('acme', '/'))
        ->assertOk()
        ->assertSee('lang="ar"', false)
        ->assertSee('dir="rtl"', false)
        ->assertSee('لوحة التحكّم', false);
});

it('remembers a language switch for that user', function () {
    tenancy()->initialize($this->acme);
    $user = User::sole();

    $this->actingAs($user)
        ->from(tenantUrl('acme', '/'))
        ->post(tenantUrl('acme', '/locale'), ['locale' => 'en'])
        ->assertRedirect(tenantUrl('acme', '/'));

    $this->actingAs($user)
        ->get(tenantUrl('acme', '/'))
        ->assertOk()
        ->assertSee('lang="en"', false)
        ->assertSee('dir="ltr"', false);

    tenancy()->initialize($this->acme);
    expect(User::sole()->locale)->toBe('en');
});

it('keeps the app out of search results', function () {
    tenancy()->initialize($this->acme);

    $this->actingAs(User::sole())
        ->get(tenantUrl('acme', '/'))
        ->assertSee('name="robots" content="noindex, nofollow"', false);
});
