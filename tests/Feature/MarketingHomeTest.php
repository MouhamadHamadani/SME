<?php

use function Pest\Laravel\get;

it('renders the homepage in English, left to right', function () {
    get('/')
        ->assertOk()
        ->assertSee('lang="en"', false)
        ->assertSee('dir="ltr"', false)
        ->assertSee('Accounting and payroll that already knows the Lebanese rules.');
});

it('renders the homepage in Arabic, right to left', function () {
    get('/ar')
        ->assertOk()
        ->assertSee('lang="ar"', false)
        ->assertSee('dir="rtl"', false)
        ->assertSee('محاسبة ورواتب تعرف القوانين اللبنانية سلفاً.', false);
});

it('shows the starting price in the hero, before the pricing table', function () {
    $html = get('/')->assertOk()->getContent();

    // The hero price must come before both the pricing section and the lead
    // form: the whole positioning is that you see a real number without
    // scrolling past a wall of copy or filling anything in.
    $heroPrice = strpos($html, 'id="hero-price"');
    $pricingSection = strpos($html, 'id="pricing"');
    $leadForm = strpos($html, 'id="early-access"');

    expect($heroPrice)->not->toBeFalse()
        ->and($heroPrice)->toBeLessThan($pricingSection)
        ->and($heroPrice)->toBeLessThan($leadForm);

    expect($html)->toContain('$19');
});

it('renders all six brief sections in order', function () {
    $html = get('/')->assertOk()->getContent();

    $sections = ['id="pain"', 'id="features"', 'id="compliance"', 'id="pricing"', 'id="story"', 'id="early-access"'];

    $positions = array_map(function (string $needle) use ($html) {
        $at = strpos($html, $needle);
        expect($at)->not->toBeFalse("missing section: {$needle}");

        return $at;
    }, $sections);

    expect($positions)->toBe(collect($positions)->sort()->values()->all());
});

it('renders all six sections in Arabic too', function () {
    $html = get('/ar')->assertOk()->getContent();

    foreach (['id="pain"', 'id="features"', 'id="compliance"', 'id="pricing"', 'id="story"', 'id="early-access"'] as $needle) {
        expect($html)->toContain($needle);
    }
});

it('publishes the three pricing tiers from the knowledge base', function () {
    $html = get('/')->assertOk()->getContent();

    foreach ([
        ['Starter', '$19', 'Up to 5 users'],
        ['Growth', '$49', 'Up to 20 employees'],
        ['Pro', '$99', 'Multi-branch'],
    ] as [$name, $price, $feature]) {
        expect($html)->toContain($name)->toContain($price)->toContain($feature);
    }

    expect($html)->toContain('NSSF payroll')
        ->toContain('Accountant seats')
        ->toContain('Multi-currency reporting');
});

it('publishes the same three prices in Arabic', function () {
    $html = get('/ar')->assertOk()->getContent();

    foreach (['19$', '49$', '99$'] as $price) {
        expect($html)->toContain($price);
    }
});

it('uses logical padding and margin utilities so the layout mirrors under RTL', function () {
    $html = get('/ar')->assertOk()->getContent();

    // pl-/pr-/ml-/mr- are physical: they would pin content to one side and
    // break the mirror. The brief requires ps-/pe-/ms-/me- throughout.
    expect($html)->not->toMatch('/class="[^"]*\b(pl|pr|ml|mr)-\d/');
});

it('pairs the gold CTA with dark text, never light', function () {
    // #C9971F is a 2.4:1 text colour on the offwhite ground and fails AA, so
    // gold only ever appears as a fill under warm-charcoal (5.7:1).
    $html = get('/')->assertOk()->getContent();

    expect($html)->toContain('bg-warm-gold text-warm-charcoal')
        ->and($html)->not->toContain('bg-warm-gold text-warm-offwhite')
        ->and($html)->not->toContain('bg-warm-gold text-white');
});

it('parks nothing at a negative offset, which would break the RTL page', function () {
    // Regression: the honeypot was hidden with `left:-9999px`. Under RTL a
    // large negative offset overflows in the scrollable direction — it
    // stretched the Arabic document to ~10,000px wide and pushed every section
    // off-screen. Hide with clipping (sr-only), never with negative offsets.
    $html = get('/ar')->assertOk()->getContent();

    expect($html)->not->toMatch('/(left|right|inset|margin[a-z-]*)\s*:\s*-\d{3,}/i');
});

it('carries hreflang links for both locales', function () {
    get('/')
        ->assertOk()
        ->assertSee('hreflang="en"', false)
        ->assertSee('hreflang="ar"', false)
        ->assertSee('hreflang="x-default"', false);
});

it('references NSSF and the Ministry of Finance in words only, with no official imagery', function () {
    $html = get('/')->assertOk()->getContent();

    expect($html)->toContain('Not affiliated with, or endorsed by');

    // No government seal/logo asset may be referenced anywhere. Pending a legal
    // check that has not happened yet — see docs/CONTENT-TODO.md.
    expect($html)->not->toMatch('/(nssf|mof|ministry)[^"]*\.(png|jpe?g|svg|webp)/i');
});
