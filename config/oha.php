<?php

return [

    /*
    | Where OHA forms, reports and ODPs are kept. A private disk: files are only
    | ever handed out by the download routes, never served directly.
    */
    'disk' => env('OHA_DISK', 'oha'),

    /* Largest OHA form accepted, in kilobytes. The 2026 form is about 250 KB. */
    'max_upload_kb' => (int) env('OHA_MAX_UPLOAD_KB', 20480),

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
