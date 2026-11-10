<?php

return [

    /*
    | Where OHA forms, reports and ODPs are kept. A private disk: files are only
    | ever handed out by the download routes, never served directly.
    */
    'disk' => env('OHA_DISK', 'oha'),

    /* Largest OHA form accepted, in kilobytes. The 2026 form is about 250 KB. */
    'max_upload_kb' => (int) env('OHA_MAX_UPLOAD_KB', 20480),

    /* Largest profile photo accepted, in kilobytes (it is cropped and shrunk after). */
    'max_photo_kb' => (int) env('OHA_MAX_PHOTO_KB', 5120),

    /*
    | When something counts as "due soon", in days: an assessment deadline (Timelines'
    | gates view), and an ODP task (its "soon" state on Timelines and the dashboards).
    */
    'due_soon_days' => [
        'deadlines' => 7,
        'odp_tasks' => 30,
    ],

    /*
    | How long the sidebar's badges are kept before they are counted again, in seconds.
    | Any change recorded in the audit trail counts them again at once. 0: every page.
    */
    'badge_cache_seconds' => (int) env('OHA_BADGE_CACHE_SECONDS', 60),

    /*
    | Stage 2, ODP follow-up: how many follow-up meetings a year, evenly spread from the
    | signing of the ODP. 3, as in the ODP template (decided 10 Nov 2026): every 4 months.
    */
    'follow_ups_per_year' => (int) env('OHA_FOLLOW_UPS_PER_YEAR', 3),

    /*
    | The demo site (demo.oha.africaymca.org): the same platform, for trying it with made-up
    | data. On, it shows a "Demo" banner and offers one-click sign-in for each role;
    | oha:demo-reset starts it again by hand. Never on the live site.
    */
    'demo' => (bool) env('OHA_DEMO', false),

    /*
    | On the demo site, each visitor gets a private copy (a "sandbox"): their own tables,
    | in their own PostgreSQL schema, and their own files, made from scratch when they
    | click "Start your own demo". What one visitor does, nobody else sees. A copy unused
    | for sandbox_days is deleted; at most sandbox_max exist (the least recently used goes).
    */
    'demo_sandboxes' => (bool) env('OHA_DEMO_SANDBOXES', true),
    'demo_sandbox_days' => (int) env('OHA_DEMO_SANDBOX_DAYS', 14),
    'demo_sandbox_max' => (int) env('OHA_DEMO_SANDBOX_MAX', 200),

    /*
    | Whether the demo really sends email (invitations and the other notices to people a
    | visitor invites), marked "Demo", through the site's mail settings. Off, mail is only
    | written to the log. Never to the demo people (example.org). Invitations are limited
    | each day: per private demo and per internet address.
    */
    'demo_mail' => (bool) env('OHA_DEMO_MAIL', false),
    'demo_invites_per_demo' => (int) env('OHA_DEMO_INVITES_PER_DEMO', 10),
    'demo_invites_per_address' => (int) env('OHA_DEMO_INVITES_PER_ADDRESS', 20),

    /* How many rows a long list shows per page (assessments, users, audit, notifications). */
    'per_page' => (int) env('OHA_PER_PAGE', 25),

    /*
    | Google Drive, where staff write the ODP together. The platform reads each linked
    | ODP document as a Google service account: a JSON key from AAYMCA's Google Cloud
    | project, kept outside the public folder. Without a key, the ODP is uploaded by hand
    | only. The platform only reads: it never changes anything in Google Drive.
    |
    | quiet_minutes: a document still being typed in is left until nobody has changed it
    | for this long, so one sitting of edits becomes one version, not dozens.
    */
    'drive' => [
        'credentials' => env('GOOGLE_SERVICE_ACCOUNT_JSON'),
        // Optional: an africaymca.org account the service account acts as (domain-wide
        // delegation), for when Workspace does not allow sharing with outside addresses.
        'act_as' => env('GOOGLE_DRIVE_ACT_AS'),
        'quiet_minutes' => (int) env('OHA_DRIVE_QUIET_MINUTES', 10),
    ],

    /* The blank OHA form staff send to a movement. */
    'blank_form' => resource_path('templates/YMCA-OHA-Form-2026-v1.0-BLANK.xlsx'),

    /*
    | The official report template (Version 5, March 2026) and ODP template, corrected to
    | the form: the report's Financial Stability maximum is 20, not 21, so the scored
    | maxima total 84; the ODP's priorities are numbered 1.1 to 9.3.
    */
    'report_template' => resource_path('templates/YMCA-OHA-Report-Template-2026.docx'),
    'odp_template' => resource_path('templates/YMCA-ODP-Template-2026.xlsx'),

    /*
    | The real form, report and ODP of 11 National Movements, one folder each, loaded
    | locally by NationalMovementsSeeder. Real movement data, so kept outside version
    | control.
    */
    'national_movements' => env('OHA_NATIONAL_MOVEMENTS', base_path('../National Movements')),

    /*
    | The Zambia YMCA 2026 documents, used for local sample data and for tests
    | of the form reader. They hold a real movement's data, so they live outside
    | version control until AAYMCA decides otherwise.
    */
    'zambia' => [
        'form' => env('OHA_ZAMBIA_FORM', base_path('../Zambia Docs/ZAM26 YMCA Annual Organisational Health Assessment Form.xlsx')),
        'report' => env('OHA_ZAMBIA_REPORT', base_path('../Zambia Docs/OHA Analysis Report Zambia YMCA 2026.docx')),
        'odp' => env('OHA_ZAMBIA_ODP', base_path('../Zambia Docs/Organisational Development Plan-Zambia YMCA.xlsx')),
    ],

];
