<?php

/*
 | Marketing site copy — English (source language).
 | Structure is mirrored exactly in lang/ar/marketing.php. If you add a key
 | here, add it there, or the Arabic page will render a raw key.
 */

return [
    'meta' => [
        'title' => 'Compliance Suite — accounting and payroll built for Lebanese SMEs',
        'description' => 'Cloud accounting and payroll with NSSF, end-of-service indemnity, VAT and true LBP/USD dual-currency books. From $19/month. Arabic and English.',
    ],

    'nav' => [
        'features' => 'What it does',
        'compliance' => 'Staying compliant',
        'pricing' => 'Pricing',
        'story' => 'Why we built this',
        'cta' => 'Get early access',
        'switch_language' => 'العربية',
        'switch_language_aria' => 'Switch to Arabic',
        'menu' => 'Menu',
        'skip_to_content' => 'Skip to content',
    ],

    'hero' => [
        'eyebrow' => 'Built for Lebanese SMEs',
        'heading' => 'Accounting and payroll that already knows the Lebanese rules.',
        'subheading' => 'NSSF contributions, end-of-service indemnity, VAT returns, and genuine LBP/USD dual-currency books — one system, in Arabic and English.',
        'price_label' => 'From',
        'price_amount' => '$19',
        'price_period' => '/month',
        'price_note' => 'A real published price. No sales call, no demo request, no quote.',
        'cta_primary' => 'Get early access',
        'cta_secondary' => 'See all pricing',
        'photo_slot' => 'Photography slot: Lebanese restaurant counter, owner at the till',
    ],

    'pain' => [
        'heading' => 'You are running a real business on a spreadsheet.',
        'lede' => 'Almost every SME we spoke to was doing one of these four things. Most were doing all four.',
        'items' => [
            [
                'title' => 'Payroll recalculated by hand, every month',
                'body' => 'NSSF contributions and end-of-service indemnity worked out on a calculator at the end of each month. One transposed digit, and you are the one explaining it to an inspector.',
            ],
            [
                'title' => 'Two currencies, one spreadsheet',
                'body' => 'The rate moved again. There is a USD column and an LBP column and nothing reconciles, so the real numbers live in your head instead of your books.',
            ],
            [
                'title' => '$5,000–$15,000 for a system nobody maintains',
                'body' => 'You paid a developer to build something custom. He left the country. Now nothing gets updated when the rules change, and no one else can open the code.',
            ],
            [
                'title' => 'VAT season is a fire drill',
                'body' => 'Every quarter, the same scramble to rebuild the return out of receipts, invoices, and memory — and the quiet hope that nothing was missed.',
            ],
        ],
    ],

    'features' => [
        'heading' => 'The combination nobody else sells you.',
        'lede' => 'Each of these exists somewhere. Together, self-serve, and in Arabic — nowhere.',
        'items' => [
            [
                'title' => 'NSSF payroll, calculated — not configured',
                'tag' => 'International products do not know this exists',
                'body' => 'Sickness and maternity, family allowances, and end-of-service contributions computed against the rates in force. Ceilings, brackets, and the employer/employee split are built in, not something you type in.',
            ],
            [
                'title' => 'End-of-service indemnity that accrues',
                'tag' => 'International products do not know this exists',
                'body' => 'EOSI accrues per employee, every month, so your real liability is a number you can look up rather than a surprise at the end.',
            ],
            [
                'title' => 'VAT returns assembled from your actual books',
                'tag' => 'Available locally — behind a sales call',
                'body' => 'Your invoices already carry their VAT treatment. The quarterly return builds itself from them instead of from a shoebox of receipts.',
            ],
            [
                'title' => 'True dual currency, not a converted total',
                'tag' => 'Usually a workaround, not a feature',
                'body' => 'Every transaction stores both LBP and USD at the rate on the day it happened. Report in either currency, with no re-keying and no second spreadsheet.',
            ],
            [
                'title' => 'Invoices delivered over WhatsApp',
                'tag' => 'Usually an email attachment, if that',
                'body' => 'Where your customers already are. Send the invoice and get the confirmation inside the app they have open all day.',
            ],
            [
                'title' => 'Arabic and English, equally',
                'tag' => 'Usually an English product with translated labels',
                'body' => 'Not an English system with Arabic labels bolted on. Full right-to-left layout, both languages, the same product either way.',
            ],
        ],
    ],

    'compliance' => [
        'eyebrow' => 'The core promise',
        'heading' => 'When the rules change, the software changes. Before you notice.',
        'lede' => 'NSSF rates move. VAT thresholds move. The exchange rate moves daily. Most of what goes wrong in Lebanese SME accounting is not bad arithmetic — it is arithmetic that was correct last year.',
        'items' => [
            [
                'title' => 'We track the changes so you do not have to',
                'body' => 'Contribution rates, ceilings, and VAT rules are versioned in the system, each with the date it took effect.',
            ],
            [
                'title' => 'Past periods keep their own rules',
                'body' => 'Run a report for March and you get March\'s rules — not today\'s applied backwards over a year of records.',
            ],
            [
                'title' => 'Every filing period has a plain status',
                'body' => 'Filed, due soon, or overdue. Colour-coded so a deadline is never something you have to go looking for.',
            ],
        ],
        'status_demo_label' => 'Filing status, at a glance',
        'status_filed' => 'Filed',
        'status_due_soon' => 'Due in 6 days',
        'status_overdue' => 'Overdue',
        'status_filed_context' => 'VAT — Q1',
        'status_due_soon_context' => 'NSSF — March',
        'status_overdue_context' => 'NSSF — February',
        'disclaimer' => 'Independent software. Not affiliated with, or endorsed by, the National Social Security Fund or the Ministry of Finance.',
    ],

    'pricing' => [
        'heading' => 'Published prices. No sales call.',
        'lede' => 'Every alternative in this category makes you request a quote first. Here are the numbers.',
        'per_month' => '/month',
        'popular' => 'Most chosen',
        'cta' => 'Get early access',
        'footnote' => 'Prices in USD, per month. Local payment methods (Whish, OMT) arrive with billing at launch. During early access there is nothing to pay and nothing to install.',
        'tiers' => [
            'starter' => [
                'name' => 'Starter',
                'price' => '$19',
                'summary' => 'For an owner-operated business that just needs the books to be right.',
                'features' => [
                    'Up to 5 users',
                    'Invoicing and quotes',
                    'VAT-ready bookkeeping',
                    'LBP/USD dual currency',
                    'WhatsApp invoice delivery',
                    'Arabic and English',
                ],
            ],
            'growth' => [
                'name' => 'Growth',
                'price' => '$49',
                'summary' => 'Once you have staff on the books and NSSF is a monthly reality.',
                'features' => [
                    'Everything in Starter',
                    'Up to 20 employees',
                    'NSSF payroll',
                    'End-of-service indemnity accrual',
                    'Payslips and payroll reports',
                ],
            ],
            'pro' => [
                'name' => 'Pro',
                'price' => '$99',
                'summary' => 'More than one location, and an accountant who needs their own way in.',
                'features' => [
                    'Everything in Growth',
                    'Multi-branch',
                    'Multi-currency reporting',
                    'Accountant seats',
                    'Priority support',
                ],
            ],
        ],
    ],

    'story' => [
        'eyebrow' => 'Why we built this',
        'heading' => 'We kept having the same conversation.',
        'body' => [
            'I have spent years building software for businesses here — restaurants, clinics, distributors, small retailers. The conversation almost always ended the same way. The owner would open a laptop, show me a spreadsheet held together by habit, and say some version of: I know it is not right, but nothing out there is built for us.',
            'They were not wrong. The international products do not know what the NSSF is. The local ones will not tell you what they cost until you sit through a sales call, and half of them will not open properly in Arabic. So people either keep the spreadsheet, or pay five figures for a custom system that stops being maintained the moment the developer moves on.',
            'So we are building the thing that kept not existing: NSSF, end-of-service, VAT, and real dual-currency accounting in one product, in both languages, at a price printed on the website. It is not finished yet. If it sounds like it was built for the way you actually work, tell us now — the first group of businesses shapes what ships.',
        ],
        'signature' => '— Founder, Compliance Suite',
        'photo_slot' => 'Photography slot: founder portrait, or a Lebanese clinic front desk',
    ],

    'form' => [
        'eyebrow' => 'Early access',
        'heading' => 'Be in the first group.',
        'lede' => 'We are onboarding a small number of businesses before public launch. Leave your details and we will reach you on WhatsApp — there is nothing to pay and nothing to install.',
        'name' => 'Your name',
        'business_name' => 'Business name',
        'phone' => 'Phone / WhatsApp number',
        'phone_hint' => 'This is how we will reach you.',
        'email' => 'Email',
        'business_type' => 'Type of business',
        'business_type_placeholder' => 'Choose one',
        'business_types' => [
            'restaurant_retail' => 'Restaurant or retail',
            'clinic_salon' => 'Clinic or salon',
            'distributor' => 'Distributor or wholesaler',
            'other' => 'Something else',
        ],
        'currently_using' => 'What are you using now?',
        'currently_using_optional' => 'Optional',
        'currently_using_placeholder' => 'Prefer not to say',
        'currently_using_options' => [
            'excel' => 'Excel or Google Sheets',
            'paper' => 'Paper and a calculator',
            'custom_system' => 'A custom-built system',
            'other_software' => 'Other accounting software',
        ],
        'submit' => 'Request early access',
        'privacy' => 'We will only use these details to contact you about early access. No newsletter, no third parties.',
        'honeypot_label' => 'Leave this field empty',
        'thanks_heading' => 'Thank you — we have your details.',
        'thanks_body' => 'We will contact you on WhatsApp within two working days. Nothing to pay and nothing to install in the meantime.',
        'thanks_secondary' => 'While you wait: the pricing above is exactly what you will be charged at launch. No quote, no negotiation.',
        'error_heading' => 'A few details need fixing.',
    ],

    'footer' => [
        'tagline' => 'Accounting and payroll built for Lebanese SMEs.',
        'disclaimer' => 'Independent software. Not affiliated with, or endorsed by, the National Social Security Fund or the Ministry of Finance.',
        'rights' => 'Pre-launch. Product name and branding are not final.',
    ],
];
