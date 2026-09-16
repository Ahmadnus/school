<?php

return [
    'level' => [
        'none' => 'No signals',
        'follow_up' => 'Follow-up recommended',
        'attention' => 'Needs attention',
    ],
    'attendance_drop' => [
        'title' => 'Attendance dropped',
        'detail' => 'Attendance fell from :from% to :to% over the last 30 days.',
    ],
    'unexcused_absences' => [
        'title' => 'Unexcused absences',
        'detail' => ':count unexcused absences this year (school limit: :threshold).',
    ],
    'repeated_lateness' => [
        'title' => 'Repeated lateness',
        'detail' => 'Late :count times in the last 30 days.',
    ],
    'behavior_incidents' => [
        'title' => 'Open behavior records',
        'detail' => ':count open records in the last 60 days.',
    ],
    'overdue_fees' => [
        'title' => 'Overdue installments',
        'detail' => ':count installment(s) past their due date.',
    ],
    'academic_decline' => [
        'title' => 'Scores declining',
        'detail' => 'Recent average :to% compared with :from% before.',
    ],
];
