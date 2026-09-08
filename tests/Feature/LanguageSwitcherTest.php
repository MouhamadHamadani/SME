<?php

use function Pest\Laravel\get;

it('offers a switcher to the other language on each page', function () {
    get('/')
        ->assertOk()
        ->assertSee('href="'.route('marketing.home.ar').'"', false)
        ->assertSee('العربية', false);

    get('/ar')
        ->assertOk()
        ->assertSee('href="'.route('marketing.home').'"', false)
        ->assertSee('>English<', false);
});

it('persists the chosen locale across a page load', function () {
    // Locale lives in the URL rather than the session, so following the
    // switcher — and every load after it — stays in that language, and a
    // shared link carries its own language with it.
    get('/ar')->assertOk()->assertSee('dir="rtl"', false);
    get('/ar')->assertOk()->assertSee('dir="rtl"', false);

    get('/')->assertOk()->assertSee('dir="ltr"', false);
});

it('keeps the whole page in one language', function () {
    $html = get('/ar')->assertOk()->getContent();

    // Section headings from every one of the six sections, in Arabic.
    foreach ([
        'محاسبة ورواتب تعرف القوانين اللبنانية سلفاً.',
        'أنت تدير عملاً حقيقياً على ملف Excel.',
        'التركيبة التي لا يبيعها لك أحد.',
        'حين تتغيّر القوانين، يتغيّر النظام. قبل أن تنتبه أنت.',
        'أسعار معلنة. بلا مكالمة مبيعات.',
        'كان الحديث نفسه يتكرّر في كل مرة.',
        'كن ضمن المجموعة الأولى.',
    ] as $heading) {
        expect($html)->toContain($heading);
    }

    // No untranslated English stub left behind in the Arabic page.
    expect($html)->not->toContain('Get early access')
        ->and($html)->not->toContain('Published prices');
});

it('sets the html lang and dir attributes to match the locale', function () {
    get('/')->assertOk()->assertSee('<html lang="en" dir="ltr"', false);
    get('/ar')->assertOk()->assertSee('<html lang="ar" dir="rtl"', false);
});
