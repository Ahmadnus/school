<?php

return [
    'attention' => [
        'attendance_pending' => [
            'title' => ':count شعب بلا تسجيل حضور اليوم',
            'body' => 'لم يُقدَّم الحضور بعد لهذه الشعب.',
        ],
        'absence_threshold' => [
            'title' => ':count طلاب عند حدّ الغياب',
            'body' => 'بلغت أيام الغياب بلا عذر الحدّ المضبوط في إعدادات المدرسة.',
        ],
        'excuses_pending' => [
            'title' => ':count أعذار غياب بانتظار المراجعة',
            'body' => 'قبول العذر يحوّل الغياب إلى غياب بعذر.',
        ],
        'posts_pending_approval' => [
            'title' => ':count منشورات بانتظار الموافقة',
            'body' => 'منشورات من أنواع تتطلّب الموافقة قبل النشر.',
        ],
        'fees_overdue' => [
            'title' => ':count أقساط متأخّرة',
            'body' => 'تجاوزت تاريخ الاستحقاق ولم تُسدَّد بالكامل.',
        ],
        'conversations_awaiting_reply' => [
            'title' => ':count محادثات بانتظار الردّ',
            'body' => 'كتب وليّ أمر قبل أكثر من يوم ولم يردّ أحد.',
        ],
        'follow_ups_due' => [
            'title' => ':count متابعات مستحقّة',
            'body' => 'محادثات موعد متابعتها اليوم أو قبله.',
        ],
        'schedule_conflicts' => [
            'title' => ':count تعارضات في الجدول',
            'body' => 'معلم أو غرفة أو شعبة محجوزة مرتين في الوقت نفسه.',
        ],
        'data_health_issues' => [
            'title' => ':count مشكلات بيانات للمراجعة',
            'body' => 'سجلات ناقصة أو غير متّسقة قد تسبّب مشكلات لاحقاً.',
        ],
        'setup_incomplete' => [
            'title' => 'إعداد المدرسة ينقصه :count%',
            'body' => 'أكمل الخطوات المتبقية كي تعمل كل الميزات كما يجب.',
        ],
    ],
    'class_health' => [
        'status' => ['healthy' => 'جيّدة', 'attention' => 'تحتاج انتباهاً', 'review' => 'يُنصح بالمراجعة'],
        'low_attendance' => 'نسبة الحضور :rate% خلال آخر ٣٠ يوماً.',
        'attendance_slipping' => 'تراجعت نسبة الحضور إلى :rate% خلال آخر ٣٠ يوماً.',
        'many_unexcused' => ':count غياباً بلا عذر خلال ٣٠ يوماً.',
        'behavior_activity' => ':count سجلات سلوك مفتوحة خلال ٣٠ يوماً.',
        'frequent_lateness' => ':count حالات تأخّر خلال ٣٠ يوماً.',
    ],
    'data_health' => [
        'no_current_year' => 'لا سنة أكاديمية حالية',
        'no_current_term' => 'لا فصل دراسي حالي',
        'students_without_guardian' => 'طلاب بلا أيّ وليّ أمر',
        'students_not_enrolled' => 'طلاب نشطون غير مسجَّلين هذه السنة',
        'sections_without_teacher' => 'شعب بلا معلم مسنَد',
        'subjects_without_assessments' => 'مواد بلا تقييمات هذا الفصل',
        'guardians_without_phone' => 'أولياء أمور بلا رقم هاتف',
        'students_missing_birth_date' => 'طلاب بلا تاريخ ميلاد',
        'duplicate_students' => 'طلاب بأسماء متطابقة (تكرار محتمل)',
    ],
    'setup' => [
        'school_info' => 'اسم المدرسة وهاتفها وعملتها',
        'academic_year' => 'السنة الأكاديمية الحالية',
        'terms' => 'فصول السنة',
        'grades_sections' => 'الصفوف والشعب',
        'subjects' => 'مواد الفصل الحالي',
        'staff' => 'معلم واحد على الأقل',
        'students' => 'الطلاب',
        'guardians' => 'أولياء أمور مرتبطون بالطلاب',
        'fee_types' => 'أنواع الأقساط',
        'notifications' => 'إعدادات الإشعارات',
        'roll_call' => 'أول تسجيل حضور مُقدَّم',
    ],
];
