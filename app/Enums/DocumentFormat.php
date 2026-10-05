<?php

namespace App\Enums;

/**
 * File format of a downloadable document.
 */
enum DocumentFormat: string
{
    case Docx = 'docx';
    case Pdf = 'pdf';
    case Xlsx = 'xlsx';
}
