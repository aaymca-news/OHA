<?php

namespace App\Enums;

/**
 * Why a report or ODP file is held: a version of the document itself, uploaded (or,
 * for the ODP, taken from Google Drive). Supporting files are evidence (Evidence).
 */
enum DocumentPurpose: string
{
    case Uploaded = 'uploaded';
    /** The signed ODP as changed in Google Drive afterwards: noted, never a version. */
    case AfterSigning = 'after_signing';
}
