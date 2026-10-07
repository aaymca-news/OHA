<?php

namespace App\Support\Google;

use App\Exceptions\WorkflowRuleBroken;

/**
 * Reads the Google Drive file ID out of a link as people copy it: from the address
 * bar of Google Sheets or Docs, from "Share → Copy link", or an older "open?id=" link.
 * A folder, Slides, Forms or a link to anything else is refused with what to do instead.
 */
final class DriveLink
{
    private const ID = '[A-Za-z0-9_-]{20,}';

    /** @return string the file ID */
    public static function fileId(string $url): string
    {
        $url = trim($url);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        if (! in_array($host, ['docs.google.com', 'drive.google.com'], true) || ! str_starts_with(strtolower($url), 'https://')) {
            throw new WorkflowRuleBroken('Paste the link to the ODP in Google Drive: it starts with https://docs.google.com/ or https://drive.google.com/.');
        }
        if (preg_match('#/(?:drive/)?(?:u/\d+/)?folders/#', $path) === 1) {
            throw new WorkflowRuleBroken('This is a link to a folder. Open the ODP document itself and copy its link.');
        }
        if (preg_match('#^/(presentation|forms)/#', $path, $m) === 1) {
            throw new WorkflowRuleBroken('This is a link to Google '.['presentation' => 'Slides', 'forms' => 'Forms'][$m[1]].'. The ODP is a spreadsheet or a document: link the Google Sheet, Google Doc, or the Excel or Word file in Drive.');
        }

        if (preg_match('#^/(?:document/(?:u/\d+/)?d|spreadsheets/(?:u/\d+/)?d|file/(?:u/\d+/)?d)/('.self::ID.')#', $path, $m) === 1) {
            return $m[1];
        }
        if (in_array($path, ['/open', '/uc'], true) && is_string($query['id'] ?? null) && preg_match('#^'.self::ID.'$#', $query['id']) === 1) {
            return $query['id'];
        }

        throw new WorkflowRuleBroken('This link does not point to a file. In Google Drive, open the ODP and copy the link from Share → Copy link.');
    }
}
