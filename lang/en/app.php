<?php

/*
 | Authenticated app copy — English.
 | Mirrored in lang/ar/app.php. The Arabic side needs the same native review
 | as the marketing copy — see docs/CONTENT-TODO.md §2.
 */

return [
    'auth' => [
        'title' => 'Sign in',
        'heading' => 'Sign in to :business',
        'email' => 'Email',
        'password' => 'Password',
        'remember' => 'Keep me signed in',
        'submit' => 'Sign in',
        'failed' => 'Those details do not match an account at this business.',
        'no_account' => 'No account yet? Your business owner sets these up.',
    ],

    'nav' => [
        'dashboard' => 'Dashboard',
        'switch_language' => 'العربية',
        'switch_language_aria' => 'Switch to Arabic',
        'sign_out' => 'Sign out',
        'signed_in_as' => 'Signed in as :name',
        'skip_to_content' => 'Skip to content',
    ],

    'plans' => [
        'starter' => 'Starter',
        'growth' => 'Growth',
        'pro' => 'Pro',
    ],

    'dashboard' => [
        'heading' => 'Good to see you, :name',
        'lede' => 'This is :business on the :plan plan.',
        'exchange_rate' => 'LBP/USD rate',
        'exchange_rate_unset' => 'Not set yet',
        'plan_label' => 'Plan',
        'whatsapp' => 'WhatsApp sender',
        'whatsapp_unset' => 'Not connected',
        'coming' => 'What lands next',
        'coming_lede' => 'The foundation is in place — tenancy, sign-in, and the shared design system. These are the modules that build on it.',
        'modules' => [
            'Invoicing and quotes, with VAT treatment carried on every line',
            'NSSF payroll — contributions calculated against the rates in force',
            'End-of-service indemnity, accruing per employee per month',
            'Dual-currency ledger storing LBP and USD at the rate on the day',
            'Quarterly VAT returns assembled from the books',
            'WhatsApp invoice delivery',
        ],
    ],
];
