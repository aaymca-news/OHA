<?php

namespace App\Enums;

/**
 * Where a version of the report or ODP came from: a file a user chose, or the
 * ODP's linked Google Drive document, read by the platform after someone changed it.
 */
enum DocumentSource: string
{
    case Upload = 'upload';
    case GoogleDrive = 'google_drive';
    /** Made by the platform: the previous version with fixes typed here written in. */
    case Platform = 'platform';
}
