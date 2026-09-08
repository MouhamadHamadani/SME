<?php

use App\Models\User;
use App\Models\WhatsAppMessage;
use Illuminate\Support\Facades\Http;

/*
 | Connecting a business's WhatsApp number from the browser, so nobody needs
 | shell access to `whatsapp:connect`. Every test fakes HTTP — no real message
 | is sent and no real credential is checked.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    $this->tenant = makeTenant('acme', ['name' => 'Acme Trading']);
    makeTenantUser($this->tenant, ['email' => 'owner@acme.test', 'role' => 'owner', 'locale' => 'en']);
});

function asSettingsUser(string $role = 'owner'): User
{
    tenancy()->initialize(test()->tenant);

    $user = $role === 'owner'
        ? User::where('role', 'owner')->sole()
        : User::create([
            'name' => ucfirst($role), 'email' => $role.'@acme.test',
            'password' => 'password-12345', 'role' => $role, 'locale' => 'en',
        ]);

    test()->actingAs($user);

    return $user;
}

/** What Meta answers for a working phone number id. */
function fakeVerified(string $number = '+961 3 123 456', string $name = 'Acme Trading'): void
{
    Http::fake(['*' => Http::response([
        'display_phone_number' => $number,
        'verified_name' => $name,
        'id' => 'PHONE_ID_9',
    ])]);
}

// -- Who can reach it -------------------------------------------------------

it('requires sign-in', function () {
    $this->get(tenantUrl('acme', '/settings/whatsapp'))
        ->assertRedirect(tenantUrl('acme', '/login'));
});

it('shows the owner an unconnected business', function () {
    asSettingsUser();

    $this->get(tenantUrl('acme', '/settings/whatsapp'))
        ->assertOk()
        ->assertSee('No number is connected');
});

it('refuses an accountant', function () {
    // Narrower than manage-payroll on purpose: the token can message customers
    // AS the business, so changing it is the owner's decision.
    asSettingsUser('accountant');

    $this->get(tenantUrl('acme', '/settings/whatsapp'))->assertForbidden();

    $this->post(tenantUrl('acme', '/settings/whatsapp'), [
        'phone_number_id' => '1234567890',
        'access_token' => str_repeat('t', 40),
    ])->assertForbidden();
});

// -- Connecting -------------------------------------------------------------

it('checks the credentials with Meta before storing them', function () {
    config(['whatsapp.driver' => 'cloud']);
    fakeVerified();
    asSettingsUser();

    $this->post(tenantUrl('acme', '/settings/whatsapp'), [
        'phone_number_id' => '1234567890',
        'access_token' => 'EAAG-a-permanent-system-user-token',
    ])->assertRedirect(tenantUrl('acme', '/settings/whatsapp'));

    $tenant = $this->tenant->fresh();

    expect($tenant->hasWhatsApp())->toBeTrue()
        ->and($tenant->wa_phone_number_id)->toBe('1234567890')
        // Meta is the authority on how the number displays.
        ->and($tenant->wa_phone_number)->toBe('+961 3 123 456')
        ->and($tenant->whatsappCredentials()['access_token'])->toBe('EAAG-a-permanent-system-user-token');

    // Read-only check, not a message.
    Http::assertSent(fn ($r) => $r->method() === 'GET' && str_contains($r->url(), '/1234567890'));
});

it('never stores a token Meta rejected', function () {
    // Otherwise the first sign of a bad paste is a customer's invoice failing.
    config(['whatsapp.driver' => 'cloud']);
    Http::fake(['*' => Http::response([
        'error' => ['message' => 'Invalid OAuth access token.'],
    ], 401)]);
    asSettingsUser();

    $this->from(tenantUrl('acme', '/settings/whatsapp'))
        ->post(tenantUrl('acme', '/settings/whatsapp'), [
            'phone_number_id' => '1234567890',
            'access_token' => 'expired-token-pasted-by-mistake',
        ])
        ->assertRedirect(tenantUrl('acme', '/settings/whatsapp'))
        // Meta's own wording; ours would be a guess about which step is missing.
        ->assertSessionHasErrors(['access_token' => 'WhatsApp rejected the message: Invalid OAuth access token.']);

    expect($this->tenant->fresh()->hasWhatsApp())->toBeFalse();
});

it('does not flash the token back into the session', function () {
    config(['whatsapp.driver' => 'cloud']);
    asSettingsUser();

    $this->from(tenantUrl('acme', '/settings/whatsapp'))
        ->post(tenantUrl('acme', '/settings/whatsapp'), [
            'phone_number_id' => 'not-numeric',
            'access_token' => 'a-real-bearer-credential-40-chars-long',
        ])
        ->assertInvalid(['phone_number_id']);

    // A bearer credential must not sit in the session waiting to be rendered.
    expect(session('_old_input'))->not->toHaveKey('access_token');

    Http::assertNothingSent();
});

it('saves without calling Meta while the app is in test mode', function () {
    config(['whatsapp.driver' => 'log']);
    asSettingsUser();

    $this->post(tenantUrl('acme', '/settings/whatsapp'), [
        'phone_number_id' => '1234567890',
        'access_token' => 'a-token-for-later-when-we-go-live',
    ])->assertRedirect();

    expect($this->tenant->fresh()->hasWhatsApp())->toBeTrue();

    // preventStrayRequests would have failed the test if anything went out.
    Http::assertNothingSent();
});

it('says plainly that nothing is sent in test mode', function () {
    config(['whatsapp.driver' => 'log']);
    asSettingsUser();

    $this->get(tenantUrl('acme', '/settings/whatsapp'))
        ->assertOk()
        ->assertSee('This app is in test mode.');
});

// -- After connecting -------------------------------------------------------

it('shows what is connected without ever rendering the token', function () {
    config(['whatsapp.driver' => 'cloud']);
    $this->tenant->connectWhatsApp('1234567890', 'super-secret-token', '+961 3 123 456');
    asSettingsUser();

    $response = $this->get(tenantUrl('acme', '/settings/whatsapp'))->assertOk();

    $response->assertSee('1234567890')
        ->assertSee('+961 3 123 456')
        ->assertSee('Stored, encrypted')
        ->assertDontSee('super-secret-token');
});

it('re-checks a stored token and refreshes the number', function () {
    // Tokens get revoked without anyone telling us. The alternative to a
    // button is hearing about it from a customer.
    config(['whatsapp.driver' => 'cloud']);
    $this->tenant->connectWhatsApp('1234567890', 'stored-token', '+961 3 000 000');
    fakeVerified('+961 3 123 456', 'Acme Trading');
    asSettingsUser();

    $this->from(tenantUrl('acme', '/settings/whatsapp'))
        ->post(tenantUrl('acme', '/settings/whatsapp/test'))
        ->assertRedirect(tenantUrl('acme', '/settings/whatsapp'))
        ->assertSessionHas('status', 'Working. Meta reports +961 3 123 456, sending as Acme Trading.');

    expect($this->tenant->fresh()->wa_phone_number)->toBe('+961 3 123 456');

    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer stored-token'));
});

it('reports a revoked token instead of pretending the connection works', function () {
    config(['whatsapp.driver' => 'cloud']);
    $this->tenant->connectWhatsApp('1234567890', 'revoked-token');
    Http::fake(['*' => Http::response(['error' => ['message' => 'Session has expired.']], 401)]);
    asSettingsUser();

    $this->from(tenantUrl('acme', '/settings/whatsapp'))
        ->post(tenantUrl('acme', '/settings/whatsapp/test'))
        ->assertSessionHasErrors('access_token');
});

it('disconnects without losing the delivery history', function () {
    $this->tenant->connectWhatsApp('1234567890', 'stored-token', '+961 3 123 456');

    $this->tenant->run(fn () => WhatsAppMessage::create([
        'recipient' => '9613123456', 'template' => 'invoice_notification', 'language' => 'ar',
        'status' => 'sent', 'provider_message_id' => 'wamid.ABC', 'sent_at' => now(),
    ]));

    asSettingsUser();

    $this->delete(tenantUrl('acme', '/settings/whatsapp'))->assertRedirect();

    $tenant = $this->tenant->fresh();

    expect($tenant->hasWhatsApp())->toBeFalse()
        ->and($tenant->wa_phone_number_id)->toBeNull()
        ->and($tenant->settings)->not->toHaveKey('whatsapp_access_token');

    // "Did the customer get it?" still has to be answerable afterwards.
    $this->tenant->run(fn () => expect(WhatsAppMessage::count())->toBe(1));
});

// -- Validation and isolation ----------------------------------------------

it('rejects the number itself pasted into the id field', function () {
    // Meta's phone number id is numeric; "+961 3 123 456" is the number.
    config(['whatsapp.driver' => 'cloud']);
    asSettingsUser();

    $this->from(tenantUrl('acme', '/settings/whatsapp'))
        ->post(tenantUrl('acme', '/settings/whatsapp'), [
            'phone_number_id' => '+961 3 123 456',
            'access_token' => str_repeat('t', 40),
        ])
        ->assertInvalid(['phone_number_id']);

    Http::assertNothingSent();
});

it('connects one business without touching another', function () {
    config(['whatsapp.driver' => 'cloud']);
    $globex = makeTenant('globex');
    fakeVerified();
    asSettingsUser();

    $this->post(tenantUrl('acme', '/settings/whatsapp'), [
        'phone_number_id' => '1234567890',
        'access_token' => 'acme-token',
    ])->assertRedirect();

    expect($globex->fresh()->hasWhatsApp())->toBeFalse();
});
