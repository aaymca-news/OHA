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
    | Stage 2, ODP follow-up: how many follow-ups a cycle has, after the assessment is closed.
    | 3, as in the ODP template. Their dates are agreed with each movement, not fixed (decided
    | 12 Nov 2026).
    */
    'follow_ups' => (int) env('OHA_FOLLOW_UPS', 3),

    /*
    | The demo site (demo.oha.africaymca.org): the same platform, for trying it with made-up
    | data. On, it shows a "Demo" banner and offers one-click sign-in for each role;
    | oha:demo-reset starts it again by hand. Never on the live site.
    */
    'demo' => (bool) env('OHA_DEMO', false),

    /*
    | On the demo site, each visitor registers and gets a demo of their own (a
    | "sandbox"): their own tables, in their own PostgreSQL schema, and their own files.
    | What one visitor does, nobody else sees. A demo unused for sandbox_days is deleted,
    | with its visitor's account; at most sandbox_max exist (the least recently used goes).
    */
    'demo_sandboxes' => (bool) env('OHA_DEMO_SANDBOXES', true),
    // One shared demo: every visitor works in the showcase itself (no private copies), so
    // what anyone does, everyone sees. Off: each visitor works in a private copy.
    'demo_shared' => (bool) env('OHA_DEMO_SHARED', true),
    'demo_sandbox_days' => (int) env('OHA_DEMO_SANDBOX_DAYS', 30),
    'demo_sandbox_max' => (int) env('OHA_DEMO_SANDBOX_MAX', 200),
    // The showcase's files (null: the platform's usual folder, storage/app/private/oha).
    'demo_showcase_folder' => env('OHA_DEMO_SHOWCASE_FOLDER'),
    // The demo's movements go by "Sample YMCA 1" to "Sample YMCA 23", never their real
    // names (App\Demo\DemoMovements). Off only in the tests written with the real names.
    'demo_sample_names' => (bool) env('OHA_DEMO_SAMPLE_NAMES', true),

    /*
    | Whether the demo really sends email, through the site's mail settings: only the
    | visitor's own "choose a new password" link, marked "Demo" (the demo people are at
    | example.org and are never emailed). Off, mail is only written to the log.
    */
    'demo_mail' => (bool) env('OHA_DEMO_MAIL', false),

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
