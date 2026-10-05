<?php

namespace App\Oha;

use RuntimeException;

/**
 * The uploaded file could not be opened as an Excel workbook.
 */
final class UnreadableForm extends RuntimeException {}
