<?php

/*
 | Only the rules the lead-capture form actually uses. Laravel falls back to
 | the raw key for anything missing, so keep this in step with LeadRequest.
 | Arabic strings here need the same native review as lang/ar/marketing.php.
 */

return [
    'required' => 'حقل :attribute مطلوب.',
    'string' => 'حقل :attribute يجب أن يكون نصاً.',
    'max' => [
        'string' => 'حقل :attribute يجب ألا يتجاوز :max حرفاً.',
    ],
    'email' => 'حقل :attribute يجب أن يكون بريداً إلكترونياً صالحاً.',
    'in' => 'قيمة :attribute المختارة غير صالحة.',

    'attributes' => [
        'name' => 'الاسم',
        'business_name' => 'اسم العمل',
        'phone' => 'رقم الهاتف',
        'email' => 'البريد الإلكتروني',
        'business_type' => 'نوع العمل',
        'currently_using' => 'ما تستخدمه حالياً',
    ],
];
