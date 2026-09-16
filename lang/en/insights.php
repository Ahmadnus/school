<?php

return [
    'attention' => [
        'attendance_pending' => [
            'title' => ':count classes have no roll call today',
            'body' => 'Attendance has not been submitted yet for these classes.',
        ],
        'absence_threshold' => [
            'title' => ':count students are at the absence limit',
            'body' => 'Unexcused absences reached the threshold set in school settings.',
        ],
        'excuses_pending' => [
            'title' => ':count absence excuses await review',
            'body' => 'Accepting an excuse turns the absence into an excused one.',
        ],
        'posts_pending_approval' => [
            'title' => ':count posts await approval',
            'body' => 'Posts of types that require approval before publishing.',
        ],
        'fees_overdue' => [
            'title' => ':count installments are overdue',
            'body' => 'Past their due date and not fully paid.',
        ],
        'conversations_awaiting_reply' => [
            'title' => ':count conversations await a reply',
            'body' => 'A guardian wrote more than a day ago and nobody has answered.',
        ],
        'follow_ups_due' => [
            'title' => ':count follow-ups are due',
            'body' => 'Conversations marked for follow-up on or before today.',
        ],
        'schedule_conflicts' => [
            'title' => ':count timetable conflicts',
            'body' => 'A teacher, room or class is booked twice at the same time.',
        ],
        'data_health_issues' => [
            'title' => ':count data issues to review',
            'body' => 'Missing or inconsistent records that may cause problems later.',
        ],
        'setup_incomplete' => [
            'title' => 'School setup is :count% away from complete',
            'body' => 'Finish the remaining steps so every feature works as intended.',
        ],
    ],
    'class_health' => [
        'status' => ['healthy' => 'Healthy', 'attention' => 'Needs attention', 'review' => 'Review recommended'],
        'low_attendance' => 'Attendance is :rate% over the last 30 days.',
        'attendance_slipping' => 'Attendance slipped to :rate% over the last 30 days.',
        'many_unexcused' => ':count unexcused absences in 30 days.',
        'behavior_activity' => ':count open behaviour records in 30 days.',
        'frequent_lateness' => ':count late arrivals in 30 days.',
    ],
    'data_health' => [
        'no_current_year' => 'No current academic year is set',
        'no_current_term' => 'No current term is set',
        'students_without_guardian' => 'Students without any guardian',
        'students_not_enrolled' => 'Active students not enrolled this year',
        'sections_without_teacher' => 'Classes with no teacher assigned',
        'subjects_without_assessments' => 'Subjects with no assessments this term',
        'guardians_without_phone' => 'Guardians without a phone number',
        'students_missing_birth_date' => 'Students missing a birth date',
        'duplicate_students' => 'Students with an identical name (possible duplicates)',
    ],
    'setup' => [
        'school_info' => 'School name, phone and currency',
        'academic_year' => 'Current academic year',
        'terms' => 'Terms of the year',
        'grades_sections' => 'Grades and classes',
        'subjects' => 'Subjects of the current term',
        'staff' => 'At least one teacher',
        'students' => 'Students',
        'guardians' => 'Guardians linked to students',
        'fee_types' => 'Fee types',
        'notifications' => 'Notification settings',
        'roll_call' => 'First submitted roll call',
    ],
];
