<?php

use App\Models\Lead;

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\from;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

function validLead(array $overrides = []): array
{
    return array_merge([
        'name' => 'Rami Haddad',
        'business_name' => 'Haddad Grill',
        'phone' => '+961 3 123 456',
        'email' => 'rami@haddadgrill.com',
        'business_type' => 'restaurant_retail',
        'currently_using' => 'excel',
        'locale' => 'en',
        'website' => '',
    ], $overrides);
}

it('stores a valid submission', function () {
    post('/early-access', validLead())
        ->assertRedirect(route('marketing.home').'#early-access')
        ->assertSessionHas('lead_submitted');

    assertDatabaseHas('leads', [
        'name' => 'Rami Haddad',
        'business_name' => 'Haddad Grill',
        'phone' => '+961 3 123 456',
        'email' => 'rami@haddadgrill.com',
        'business_type' => 'restaurant_retail',
        'currently_using' => 'excel',
        'locale' => 'en',
    ]);
});

it('stores a submission with the optional field left blank', function () {
    post('/early-access', validLead(['currently_using' => '']))->assertSessionHasNoErrors();

    expect(Lead::sole()->currently_using)->toBeNull();
});

it('records the locale the form was filled in', function () {
    post('/early-access', validLead(['locale' => 'ar']))
        ->assertRedirect(route('marketing.home.ar').'#early-access');

    expect(Lead::sole()->locale)->toBe('ar');
});

it('silently drops a submission that fills the honeypot', function () {
    $response = post('/early-access', validLead(['website' => 'https://spam.example']));

    // Silently: the bot gets the same redirect a person would, with no
    // validation errors to learn from — but nothing is written.
    $response->assertRedirect(route('marketing.home').'#early-access')
        ->assertSessionHasNoErrors();

    assertDatabaseCount('leads', 0);
});

it('drops a honeypot submission even when the rest of the payload is junk', function () {
    post('/early-access', [
        'website' => 'x',
        'locale' => 'en',
    ])->assertSessionHasNoErrors();

    assertDatabaseCount('leads', 0);
});

it('requires every mandatory field', function () {
    from('/')
        ->post('/early-access', ['locale' => 'en', 'website' => ''])
        ->assertRedirect('/')
        ->assertSessionHasErrors(['name', 'business_name', 'phone', 'email', 'business_type']);

    assertDatabaseCount('leads', 0);
});

it('does not require the optional currently-using field', function () {
    from('/')
        ->post('/early-access', ['locale' => 'en', 'website' => ''])
        ->assertSessionDoesntHaveErrors('currently_using');
});

it('rejects an invalid email and an unknown business type', function () {
    from('/')
        ->post('/early-access', validLead(['email' => 'not-an-email', 'business_type' => 'nuclear_reactor']))
        ->assertSessionHasErrors(['email', 'business_type']);

    assertDatabaseCount('leads', 0);
});

it('returns validation messages in English when submitted from the English page', function () {
    from('/')
        ->post('/early-access', ['locale' => 'en', 'website' => ''])
        ->assertInvalid([
            'name' => 'required',
            'email' => 'required',
        ]);
});

it('returns validation messages in Arabic when submitted from the Arabic page', function () {
    from('/ar')
        ->post('/early-access', ['locale' => 'ar', 'website' => ''])
        // Arabic message, using the Arabic attribute name rather than the raw
        // column name — that is what lang/ar/validation.php `attributes` is for.
        ->assertInvalid([
            'name' => 'حقل الاسم مطلوب.',
            'business_type' => 'حقل نوع العمل مطلوب.',
        ]);
});

it('shows a rendered bilingual thank-you state, not a bare redirect', function () {
    post('/early-access', validLead());

    get('/')->assertOk()
        ->assertSee('Thank you — we have your details.')
        ->assertDontSee('name="business_type"', false);

    post('/early-access', validLead(['locale' => 'ar', 'email' => 'second@example.com']));

    get('/ar')->assertOk()->assertSee('شكراً لك — وصلتنا بياناتك.', false);
});

it('rate limits the endpoint', function () {
    foreach (range(1, 10) as $i) {
        post('/early-access', validLead(['email' => "lead{$i}@example.com"]))->assertRedirect();
    }

    post('/early-access', validLead(['email' => 'eleventh@example.com']))->assertStatus(429);

    assertDatabaseCount('leads', 10);
});
