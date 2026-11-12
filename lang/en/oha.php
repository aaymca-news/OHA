<?php

/*
 * The dashboard's wording, kept in one place. Stage 1 is English only; another
 * language is one more copy of this file.
 */

return [
    'app' => [
        'org' => 'Africa Alliance of YMCAs',
        'title' => 'Organizational Health & Development',
        // The Africa Alliance pay-off line, from the brand manual (shown on the sign-in page).
        'payoff' => 'Empowering young people for the African renaissance',
    ],

    'nav' => [
        'dashboard' => 'Dashboard',
        'my_work' => 'My Work',
        'movements' => 'National Movements',
        'assessments' => 'Assessments',
        'timelines' => 'Timelines',
        'journeys' => 'Journeys',
        'users' => 'Users & Roles',
        'our_movement' => 'Our Movement',
        'resources' => 'Resources',
        'notifications' => 'Notifications',
        'profile' => 'My profile',
        'security' => 'Security',
        // The captions over the groups in the menu (AAYMCA staff only).
        'group' => [
            'movements' => 'Movements',
            'library' => 'Library',
            'admin' => 'Administration',
        ],
    ],

    'artefact' => [
        'form' => 'OHA form',
        'report' => 'Report',
        'odp' => 'ODP',
    ],

    // Label, icon and tone for each workflow state. "locked" is derived, never stored.
    'state' => [
        'locked' => ['Locked', 'lock', 'neutral'],
        'not_started' => ['Not started', 'radio_button_unchecked', 'neutral'],
        'rules_failed' => ['Failed the check', 'error', 'critical'],
        'ready' => ['Ready to submit', 'task_alt', 'info'],
        'drafted' => ['Draft', 'edit_note', 'info'],
        'pending_approval' => ['Awaiting approval', 'hourglass_top', 'warning'],
        'rejected' => ['Sent back', 'undo', 'serious'],
        'approved' => ['Approved', 'task_alt', 'good'],
    ],

    'holder' => [
        'assessor' => 'With the assessor',
        'approver' => 'With the Administrators for approval',
        'closer' => 'Approved: ready to close',
        // Not a holder any more: the Board Chairperson's signature is optional and holds nothing up.
        'board' => 'Optional signature by the Board Chairperson',
    ],

    'work' => [
        'title' => 'My Work',
        'subtitle' => 'Everything waiting on you, across the movements you look after.',
        'groups' => [
            'start' => 'To be started',
            'started' => 'Ongoing assessments',
            'done' => 'Assessments done',
            'approve' => 'Awaiting my approval',
            'close' => 'Ready to close',
            'sign' => 'ODP to sign (optional)',
            'gaps' => 'Missing information',
        ],
        // One line under each group's heading: what it holds.
        'about' => [
            'start' => 'Movements you assess with no assessment under way: start one when it is due.',
            'started' => 'Opened and not yet closed, with where each one stands. Once the ODP is approved, close the assessment.',
            'done' => 'Closed and in follow-up: open each follow-up when it is time, hold it, record it and close it.',
            'approve' => 'OHA forms, reports and ODPs submitted for approval.',
            'close' => 'Approved ODPs on movements others assess: closing the assessment sets Baseline 1 and starts the follow-up.',
            'sign' => 'Approved ODPs you may sign on behalf of your board. Optional: nothing waits on your signature.',
            'gaps' => 'Forms that went forward with information still missing.',
        ],
        'empty' => 'Nothing here.',
        'empty_hint' => 'You are all caught up in this group.',
        'due_in' => 'Due in :days days',
        'due_tomorrow' => 'Due tomorrow',
        'due_today' => 'Due today',
        'overdue' => ':days days overdue',
        'overdue_one' => '1 day overdue',
        'no_due' => 'No target date',
    ],

    'severity' => [
        'error' => 'Refused',
        'missing' => 'Missing',
        'warning' => 'For review',
    ],

    'dqa' => [
        'completeness' => ['Completeness', 'Is every required answer there?'],
        'accuracy' => ['Accuracy', 'Is it the right movement, and do the totals agree?'],
        'consistency' => ['Consistency', 'Do related figures add up?'],
        'timeliness' => ['Timeliness', 'Did the form arrive by its target date?'],
        'validity' => ['Validity', 'Are the answers in the form’s own terms?'],
        'traceability' => ['Traceability', 'Did the NGS and the Chair agree the form?'],
        'verdict' => [
            'pass' => 'Pass',
            'warn' => 'Review',
            'gap' => 'Gaps',
            'fail' => 'Fail',
        ],
    ],

    // The same six dimensions, asked of an uploaded report (App\Oha\Report\ReportChecker).
    'dqa_report' => [
        'completeness' => ['Completeness', 'Are all the sections and all nine categories there?'],
        'accuracy' => ['Accuracy', 'Is it the right movement, and does the score agree with the OHA form?'],
        'consistency' => ['Consistency', 'Do its figures agree with each other?'],
        'timeliness' => ['Timeliness', 'Is it for this assessment’s period?'],
        'validity' => ['Validity', 'Could its text and scores be read and checked?'],
        'traceability' => ['Traceability', 'Does it say who wrote it and when?'],
    ],

    'placeholder' => 'Awaiting the approved template.',
];
