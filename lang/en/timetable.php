<?php

return [
    'status' => [
        'analyzed' => 'Analyzed',
        'generated' => 'Generated',
        'infeasible' => 'Not possible',
    ],

    /**
     * Why a timetable is impossible.
     *
     * Every message names who caused it, how much is missing, and what to
     * change. "Could not generate the timetable" says none of those, so an
     * administrator can do nothing with it.
     */
    'conflict' => [
        'school_has_no_working_days' => 'School working hours are not set yet. Set the working days and each day\'s hours first.',

        'nothing_to_schedule' => 'There are no teaching assignments in this term, so there is nothing to schedule. '
            .'Assign subjects to teachers first.',

        'assignment_has_no_lessons' => ':subject for section :section (:teacher) has no weekly lesson count. '
            .'Set the lesson count on the assignment, or on the subject.',

        'section_has_no_window' => 'Section :section has no hours on any day the school is open. '
            .'Set the section\'s hours, or widen the school\'s.',

        'teacher_has_no_availability' => ':teacher has not set their availability yet, so their lessons cannot be scheduled. '
            .'Ask them to set their hours.',

        'assignment_has_no_valid_slot' => 'No valid placement for :subject (:minutes minutes) in section :section with :teacher: '
            .'their availability does not overlap the section\'s hours long enough for a whole lesson.',

        'teacher_capacity_exceeded' => ':teacher needs :required_lessons lessons but their availability only fits '
            .':available_lessons. Widen their availability, or move some assignments to another teacher.',

        'section_capacity_exceeded' => 'Section :section needs :required_lessons lessons but its hours only fit '
            .':available_lessons. Widen the section\'s hours, or reduce the lessons required.',

        'mutually_exclusive_assignment' => 'Could not place :missing of :required :subject lessons for section :section (:teacher): '
            .'the placements available to it are the same ones other lessons need. '
            .'Widen the teacher\'s availability or the section\'s hours.',

        'search_budget_exhausted' => 'The constraints are too tangled to resolve within one request — :placed of :required '
            .'lessons were placed. Widen the school hours or teacher availability a little and retry.',

        // --- Single-placement conflicts: shown on manual edits.
        'lesson_has_no_duration' => 'The lesson must end after it starts.',
        'school_closed_that_day' => 'The school is closed that day.',
        'section_closed_that_day' => 'This section has no hours that day.',
        'outside_school_hours' => 'The lesson falls outside school hours (:window).',
        'outside_section_hours' => 'The lesson falls outside the section\'s hours (:window).',
        'teacher_not_assigned' => 'This subject for this section is not assigned to this teacher.',
        'teacher_unavailable' => 'The teacher is not available at that time.',
        'teacher_double_booked' => ':teacher already has a lesson at that time (:section, :time).',
        'section_double_booked' => 'Section :section already has a lesson at that time (:subject, :time).',
    ],

    'messages' => [
        'lesson_minutes_saved' => 'Lesson length set to :minutes minutes.',
        'grid_misaligned' => 'Availability boundaries must fall on :minutes-minute steps.',
        'move_rejected' => 'The lesson cannot move there — see the conflict.',
        'no_term' => 'No term is set up yet. Create an academic year and a term first.',
        'analyzed' => 'Timetable analyzed.',
        'generated' => 'Timetable generated: :count lessons.',
        'infeasible' => 'No valid timetable is possible with these constraints. See the conflict report.',
        'availability_saved' => 'Availability saved.',
        'availability_cleared' => 'That day\'s availability was cleared.',
        'hours_saved' => 'School hours saved.',
        'hours_cleared' => 'That day is now closed.',
        'assignment_updated' => 'Assignment saved.',
        'slot_moved' => 'Lesson moved.',
        'no_run' => 'No timetable has been generated for this term yet.',
        'cleared' => 'The generated timetable was deleted. Manually built lessons were not touched.',
    ],
];
