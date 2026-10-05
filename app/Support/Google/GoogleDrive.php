<?php

namespace App\Support\Google;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Reads ODP documents from Google Drive, as AAYMCA's service account. Read-only:
 * the platform never changes anything in Drive. A document is readable when it sits
 * in a Shared Drive the service account belongs to, or is shared with its address.
 *
 * Signs in with a short-lived token (OAuth 2.0 JWT bearer grant), signed with the
 * service account's private key and kept in the cache until shortly before it expires.
 */
class GoogleDrive
{
    private const SCOPE = 'https://www.googleapis.com/auth/drive.readonly';

    private const API = 'https://www.googleapis.com/drive/v3/files/';

    private const FIELDS = 'id,name,mimeType,version,modifiedTime,trashed,lastModifyingUser(displayName,emailAddress)';

    /** Whether AAYMCA's service account key is in place. */
    public function configured(): bool
    {
        return $this->credentials() !== null;
    }

    /** The address to share a document with, so the platform can read it. */
    public function serviceAccountEmail(): ?string
    {
        return $this->credentials()['client_email'] ?? null;
    }

    public function file(string $fileId): DriveFile
    {
        $response = $this->get(self::API.rawurlencode($fileId), ['fields' => self::FIELDS, 'supportsAllDrives' => 'true']);
        $data = $response->json();

        $file = new DriveFile(
            id: (string) $data['id'],
            name: (string) $data['name'],
            mimeType: (string) $data['mimeType'],
            version: (int) $data['version'],
            modifiedAt: CarbonImmutable::parse((string) $data['modifiedTime']),
            editorEmail: $data['lastModifyingUser']['emailAddress'] ?? null,
            editorName: $data['lastModifyingUser']['displayName'] ?? null,
            trashed: (bool) ($data['trashed'] ?? false),
        );

        if ($file->trashed) {
            throw new DriveUnavailable('The ODP document is in the Google Drive bin. Restore it, or link the document that replaced it.');
        }
        if ($file->format() === null) {
            throw new DriveUnavailable('The linked file is not a document: link the ODP as a Google Doc, a Word file or a PDF.');
        }

        return $file;
    }

    /** The document's content: a Google Doc exported as Word, any other file as it is. */
    public function content(DriveFile $file): string
    {
        $response = $file->mimeType === DriveFile::GOOGLE_DOC
            ? $this->get(self::API.rawurlencode($file->id).'/export', ['mimeType' => DriveFile::DOCX])
            : $this->get(self::API.rawurlencode($file->id), ['alt' => 'media', 'supportsAllDrives' => 'true']);

        return $response->body();
    }

    /**
     * @param  array<string, string>  $query
     */
    private function get(string $url, array $query): Response
    {
        try {
            $response = $this->client()->get($url, $query);
        } catch (ConnectionException) {
            throw new DriveUnavailable('Google Drive could not be reached. The platform tries again on its next check.');
        }

        if ($response->successful()) {
            return $response;
        }

        $reason = $response->json('error.errors.0.reason') ?? $response->json('error.status');
        throw new DriveUnavailable(match (true) {
            in_array($response->status(), [403, 404], true) && $reason === 'exportSizeLimitExceeded' => 'The ODP is too large for Google to export as Word (over 10 MB). Remove large pictures, or upload it by hand.',
            in_array($response->status(), [403, 404], true) => 'The platform cannot open this document in Google Drive. Keep it in the OHA shared drive, or share it with '.$this->serviceAccountEmail().' (Viewer is enough).',
            $response->status() === 401 => 'Google refused the platform’s sign-in. An Administrator should check the service account key.',
            $response->status() === 429 || $response->serverError() => 'Google Drive is busy. The platform tries again on its next check.',
            default => 'Google Drive answered with an error ('.$response->status().').',
        });
    }

    private function client(): PendingRequest
    {
        return Http::withToken($this->token())->timeout(60)->retry(2, 500, throw: false);
    }

    private function token(): string
    {
        $credentials = $this->credentials() ?? throw new DriveUnavailable('The platform is not connected to Google Drive yet.');

        return Cache::remember('google-drive-token:'.$credentials['client_email'], now()->addMinutes(50), function () use ($credentials): string {
            $now = time();
            $segments = [
                self::base64url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
                self::base64url((string) json_encode([
                    'iss' => $credentials['client_email'],
                    'scope' => self::SCOPE,
                    'aud' => $credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token',
                    'iat' => $now,
                    'exp' => $now + 3600,
                ])),
            ];
            if (! openssl_sign(implode('.', $segments), $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new DriveUnavailable('The service account key could not be used. An Administrator should check it.');
            }

            try {
                $response = Http::asForm()->timeout(30)->post($credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token', [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => implode('.', [...$segments, self::base64url($signature)]),
                ]);
            } catch (ConnectionException) {
                throw new DriveUnavailable('Google could not be reached to sign in. The platform tries again on its next check.');
            }

            if (! $response->successful() || ! is_string($response->json('access_token'))) {
                throw new DriveUnavailable('Google refused the platform’s sign-in. An Administrator should check the service account key.');
            }

            return $response->json('access_token');
        });
    }

    /**
     * @return array{client_email: string, private_key: string, token_uri?: string}|null
     */
    protected function credentials(): ?array
    {
        $path = config('oha.drive.credentials');
        if (! is_string($path) || $path === '' || ! is_readable($path)) {
            return null;
        }

        $key = json_decode((string) file_get_contents($path), true);

        return is_array($key) && is_string($key['client_email'] ?? null) && is_string($key['private_key'] ?? null) ? $key : null;
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
