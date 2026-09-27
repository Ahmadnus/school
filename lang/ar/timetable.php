<?php

return [
    'status' => [
        'analyzed' => 'مُحلَّل',
        'generated' => 'مولَّد',
        'infeasible' => 'غير ممكن',
    ],

    /**
     * أسباب الاستحالة.
     *
     * كلّ رسالة تقول ثلاثة أشياء: **من** سبّب، و**كم** ينقصه، و**ما** الذي
     * يغيّره. «تعذّر توليد الجدول» لا تقول شيئاً منها، فلا يستطيع المدير أن
     * يفعل بها شيئاً.
     */
    'conflict' => [
        'school_has_no_working_days' => 'لم يُضبط دوام المعهد بعد. اضبط أيّام الدوام وساعات كل يوم أوّلاً.',

        'nothing_to_schedule' => 'لا إسنادات تدريس في هذا الفصل، فلا شيء يُجدوَل. أسنِد المواد إلى الأساتذة أوّلاً.',

        'assignment_has_no_lessons' => 'إسناد :subject للشعبة :section (:teacher) بلا عدد حصص أسبوعيّة. '
            .'اكتب عدد الحصص على الإسناد، أو على المادة.',

        'section_has_no_window' => 'الشعبة :section لا دوام لها في أيّ يومٍ يفتح فيه المعهد. '
            .'اضبط دوام الشعبة، أو وسّع دوام المعهد.',

        'teacher_has_no_availability' => 'الأستاذ :teacher لم يضبط تفرّغه بعد، فلا يمكن جدولة حصصه. '
            .'اطلب منه ضبط أوقاته.',

        'assignment_has_no_valid_slot' => 'لا موضع صالح لحصّة :subject (:minutes دقيقة) للشعبة :section مع الأستاذ :teacher: '
            .'تفرّغه لا يتقاطع مع دوام الشعبة بما يكفي لحصّةٍ كاملة.',

        'teacher_capacity_exceeded' => 'الأستاذ :teacher يحتاج :required_lessons حصّة، وتفرّغه لا يتّسع إلاّ لـ:available_lessons. '
            .'وسّع تفرّغه، أو انقل بعض إسناداته إلى أستاذ آخر.',

        'section_capacity_exceeded' => 'الشعبة :section تحتاج :required_lessons حصّة، ودوامها لا يتّسع إلاّ لـ:available_lessons. '
            .'وسّع دوام الشعبة، أو أنقص عدد الحصص المطلوبة.',

        'mutually_exclusive_assignment' => 'تعذّر وضع :missing من :required حصص :subject للشعبة :section (:teacher): '
            .'المواضع المتاحة له هي نفسها التي تحتاجها حصص أخرى. '
            .'وسّع تفرّغ الأستاذ أو دوام الشعبة.',

        'search_budget_exhausted' => 'القيود متشابكة أكثر مما يُحسَم في وقت الطلب — وُضعت :placed حصّة من :required. '
            .'وسّع دوام المعهد أو تفرّغ الأساتذة قليلاً ثم أعد المحاولة.',

        // --- تعارضات موضعٍ واحد: تُعرَض عند التعديل اليدويّ.
        'lesson_has_no_duration' => 'وقت انتهاء الحصّة يجب أن يكون بعد وقت بدئها.',
        'school_closed_that_day' => 'المعهد مغلق في هذا اليوم.',
        'section_closed_that_day' => 'لا دوام لهذه الشعبة في هذا اليوم.',
        'outside_school_hours' => 'الحصّة خارج دوام المعهد (:window).',
        'outside_section_hours' => 'الحصّة خارج دوام الشعبة (:window).',
        'teacher_not_assigned' => 'هذه المادة لهذه الشعبة غير مُسندة إلى هذا الأستاذ.',
        'teacher_unavailable' => 'الأستاذ غير متفرّغ في هذا الوقت.',
        'teacher_double_booked' => 'الأستاذ :teacher عنده حصّة أخرى في هذا الوقت (:section، :time).',
        'section_double_booked' => 'الشعبة :section عندها حصّة أخرى في هذا الوقت (:subject، :time).',
    ],

    'messages' => [
        'lesson_minutes_saved' => 'تمّ ضبط طول الحصّة على :minutes دقيقة.',
        'grid_misaligned' => 'حدود التفرّغ يجب أن تكون على مضاعفات :minutes دقيقة.',
        'move_rejected' => 'لا يمكن نقل الحصّة إلى هذا الموضع — انظر التعارض.',
        'no_term' => 'لا فصل دراسي مضبوط بعد. أنشئ سنة دراسية وفصلاً أوّلاً.',
        'analyzed' => 'تمّ تحليل الجدول.',
        'generated' => 'تمّ توليد الجدول: :count حصّة.',
        'infeasible' => 'الجدول غير ممكن بهذه القيود. راجع تقرير التعارض.',
        'availability_saved' => 'تمّ حفظ التفرّغ.',
        'availability_cleared' => 'تمّ إفراغ تفرّغ هذا اليوم.',
        'hours_saved' => 'تمّ حفظ دوام المعهد.',
        'hours_cleared' => 'تمّ إلغاء دوام هذا اليوم — صار مغلقاً.',
        'assignment_updated' => 'تمّ حفظ الإسناد.',
        'slot_moved' => 'تمّ نقل الحصّة.',
        'no_run' => 'لا جدول مولَّد بعد لهذا الفصل.',
        'cleared' => 'تمّ حذف الجدول المولَّد. الحصص التي بُنيت يدويّاً لم تُمَسّ.',
    ],
];
