<?php

return [
    'level' => [
        'none' => 'لا إشارات',
        'follow_up' => 'يحتاج متابعة',
        'attention' => 'يحتاج انتباهاً',
    ],
    'attendance_drop' => [
        'title' => 'تراجع الحضور',
        'detail' => 'انخفضت نسبة الحضور من :from٪ إلى :to٪ خلال آخر ٣٠ يوماً.',
    ],
    'unexcused_absences' => [
        'title' => 'غياب بلا عذر',
        'detail' => ':count أيام غياب بلا عذر هذه السنة (حدّ المدرسة: :threshold).',
    ],
    'repeated_lateness' => [
        'title' => 'تأخّر متكرّر',
        'detail' => 'تأخّر :count مرات خلال آخر ٣٠ يوماً.',
    ],
    'behavior_incidents' => [
        'title' => 'سجلات سلوك مفتوحة',
        'detail' => ':count سجلات مفتوحة خلال آخر ٦٠ يوماً.',
    ],
    'overdue_fees' => [
        'title' => 'أقساط متأخرة',
        'detail' => ':count قسط/أقساط تجاوزت تاريخ الاستحقاق.',
    ],
    'academic_decline' => [
        'title' => 'تراجع في الدرجات',
        'detail' => 'متوسط الدرجات الأخيرة :to٪ مقابل :from٪ سابقاً.',
    ],
];
