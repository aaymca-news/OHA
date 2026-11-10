<?php

namespace App\Demo;

/**
 * The demo site's sign-in accounts, one per role, offered as one-click buttons on its
 * sign-in page. Invented people at example.org (a domain that never receives mail).
 */
final class DemoPeople
{
    /** The movement whose Board Chairperson and General Secretary the demo signs in as. */
    public const MOVEMENT = 'ghana';

    /** @var array<string, array{email: string, label: string, explains: string}> role key => account */
    public const ACCOUNTS = [
        'admin' => ['email' => 'demo.admin@example.org', 'label' => 'Administrator', 'explains' => 'Approves, assigns assessors, sees every movement'],
        'assessor' => ['email' => 'demo.assessor@example.org', 'label' => 'AAYMCA assessor', 'explains' => 'Runs assessments and the ODP follow-up for the movements assigned'],
        'chair' => ['email' => 'demo.chair@example.org', 'label' => 'Board Chairperson', 'explains' => 'Signs the ODP, follows the movement’s progress'],
        'ngs' => ['email' => 'demo.ngs@example.org', 'label' => 'National General Secretary', 'explains' => 'Adds evidence, reports progress on the ODP'],
    ];
}
