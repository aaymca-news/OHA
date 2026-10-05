<?php

namespace App\Enums;

/**
 * How serious a finding from the OHA form check is.
 */
enum FindingSeverity: string
{
    case Error = 'error';
    case Missing = 'missing';
    case Warning = 'warning';
}
