<?php

namespace App\Enums;

/**
 * Why a report or ODP file is held: a version of the document itself, uploaded (or,
 * for the ODP, taken from Google Drive). Supporting files are evidence (Evidence).
 */
enum DocumentPurpose: string
{
    case Uploaded = 'uploaded';
    /** The ODP as changed in Google Drive after the assessment was closed: noted, never a version. */
    case AfterSigning = 'after_signing';
    /**
     * The ODP revised during an open follow-up (uploaded, or taken from Google Drive): its
     * tasks are read into the plan, counted in that follow-up, with no separate approval.
     */
    case Revision = 'revision';
}
