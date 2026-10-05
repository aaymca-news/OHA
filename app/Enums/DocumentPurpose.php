<?php

namespace App\Enums;

/**
 * Why a report or ODP file is held: a version of the document itself, uploaded
 * (or, for the ODP, taken from Google Drive), or a file supplied for reference.
 */
enum DocumentPurpose: string
{
    case Reference = 'reference';
    case Uploaded = 'uploaded';
}
