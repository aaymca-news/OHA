<?php

namespace App\Support\Google;

use RuntimeException;

/**
 * Google Drive could not give the platform a file. The message says why, in words
 * the staff can act on (share the document, put it back from the bin, and so on).
 */
final class DriveUnavailable extends RuntimeException {}
