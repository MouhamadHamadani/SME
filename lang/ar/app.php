<?php

/*
 | Authenticated app copy — Arabic.
 |
 | STATUS: BEST-EFFORT DRAFT, same caveat as lang/ar/marketing.php.
 | Needs review by a native Lebanese speaker before launch —
 | see docs/CONTENT-TODO.md §2.
 */

return [
    'auth' => [
        'title' => 'تسجيل الدخول',
        'heading' => 'تسجيل الدخول إلى :business',
        'email' => 'البريد الإلكتروني',
        'password' => 'كلمة المرور',
        'remember' => 'أبقني مسجَّلاً',
        'submit' => 'دخول',
        'failed' => 'هذه البيانات لا تطابق أي حساب في هذا العمل.',
        'no_account' => 'لا حساب لديك بعد؟ صاحب العمل هو من ينشئ الحسابات.',
    ],

    'nav' => [
        'dashboard' => 'لوحة التحكّم',
        'switch_language' => 'English',
        'switch_language_aria' => 'التحويل إلى الإنكليزية',
        'sign_out' => 'تسجيل الخروج',
        'signed_in_as' => 'مسجَّل الدخول باسم :name',
        'skip_to_content' => 'انتقل إلى المحتوى',
    ],

    'plans' => [
        'starter' => 'الأساسية',
        'growth' => 'النمو',
        'pro' => 'المتقدّمة',
    ],

    'dashboard' => [
        'heading' => 'أهلاً بك، :name',
        'lede' => 'هذا حساب :business على باقة :plan.',
        'exchange_rate' => 'سعر صرف الليرة مقابل الدولار',
        'exchange_rate_unset' => 'لم يُحدَّد بعد',
        'plan_label' => 'الباقة',
        'whatsapp' => 'رقم واتساب المُرسِل',
        'whatsapp_unset' => 'غير مرتبط',
        'coming' => 'ما سيصل تالياً',
        'coming_lede' => 'الأساس جاهز — الفصل بين الشركات، وتسجيل الدخول، ونظام التصميم المشترك. وهذه هي الوحدات التي تُبنى عليه.',
        'modules' => [
            'الفواتير وعروض الأسعار، مع المعالجة الضريبية على كل بند',
            'رواتب الضمان — اشتراكات تُحتسب وفق النسب النافذة',
            'تعويض نهاية الخدمة، يتراكم لكل أجير شهراً بشهر',
            'دفتر مزدوج العملة يحفظ الليرة والدولار على سعر يومهما',
            'تصاريح الـ TVA الفصلية تتجمّع من الدفاتر',
            'إرسال الفواتير عبر واتساب',
        ],
    ],
];
