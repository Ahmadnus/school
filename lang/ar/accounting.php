<?php

return [
    'defaults' => [
        'expense' => [
            'rent' => 'إيجار', 'salaries' => 'رواتب', 'utilities' => 'كهرباء وماء وإنترنت',
            'supplies' => 'قرطاسية ولوازم', 'maintenance' => 'صيانة', 'marketing' => 'إعلان وتسويق',
            'other' => 'مصاريف أخرى',
        ],
        'income' => [
            'donations' => 'تبرعات', 'activities' => 'أنشطة ورحلات', 'books' => 'مبيعات كتب',
            'other' => 'إيرادات أخرى',
        ],
    ],
    'entry_created' => 'سُجّل القيد.',
    'entry_voided' => 'أُلغي القيد.',
    'already_voided' => 'القيد ملغى مسبقاً.',
    'category_saved' => 'حُفظ التصنيف.',
];
