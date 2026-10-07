<?php

use App\Support\Google\DriveFile;
use App\Support\Google\DriveUnavailable;
use App\Support\Google\GoogleDrive;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * The Google Drive client signs in as the service account and only reads. Google
 * itself is replaced by Http::fake; the key is generated for the test.
 */

beforeEach(function () {
    // PHP on Windows needs to be told where its OpenSSL configuration is to make a key.
    $cnf = collect([getenv('OPENSSL_CONF'), dirname(PHP_BINARY).'/extras/ssl/openssl.cnf'])->first(fn ($p) => is_string($p) && is_file($p));
    $options = $cnf !== null ? ['config' => $cnf] : [];
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + $options);
    openssl_pkey_export($key, $pem, null, $options);
    $this->keyFile = tempnam(sys_get_temp_dir(), 'sa');
    file_put_contents($this->keyFile, json_encode([
        'type' => 'service_account',
        'client_email' => 'oha-platform@aaymca-oha.iam.gserviceaccount.com',
        'private_key' => $pem,
        'token_uri' => 'https://oauth2.googleapis.com/token',
    ]));
    config(['oha.drive.credentials' => $this->keyFile]);
    Cache::flush();
});

afterEach(fn () => @unlink($this->keyFile));

it('is not connected without a key', function () {
    config(['oha.drive.credentials' => null]);

    expect(app(GoogleDrive::class)->configured())->toBeFalse()
        ->and(fn () => app(GoogleDrive::class)->file('1ZaMbIaOdP2026xYzAbCdEfGhIjKlMnOpQr'))->toThrow(DriveUnavailable::class, 'not connected');
});

it('signs in with a signed token, reads the file and exports a Google Doc as Word', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3599]),
        'www.googleapis.com/drive/v3/files/abc12345678901234567890/export*' => Http::response('PK word bytes'),
        'www.googleapis.com/drive/v3/files/abc12345678901234567890*' => Http::response([
            'id' => 'abc12345678901234567890', 'name' => 'Zambia ODP 2026', 'mimeType' => DriveFile::GOOGLE_DOC,
            'version' => '57', 'modifiedTime' => '2026-10-05T09:30:00.000Z', 'trashed' => false,
            'lastModifyingUser' => ['displayName' => 'Tendai Moyo', 'emailAddress' => 'tendai.moyo@africaymca.org'],
        ]),
    ]);

    $drive = app(GoogleDrive::class);
    $file = $drive->file('abc12345678901234567890');

    expect($drive->configured())->toBeTrue()
        ->and($file->version)->toBe(57)
        ->and($file->editorEmail)->toBe('tendai.moyo@africaymca.org')
        ->and($file->fileName())->toBe('Zambia ODP 2026.docx')
        ->and($drive->content($file))->toBe('PK word bytes');

    Http::assertSent(function (Request $r) {
        if ($r->url() !== 'https://oauth2.googleapis.com/token') {
            return false;
        }
        [$header, $claims] = array_map(fn ($s) => json_decode(base64_decode(strtr($s, '-_', '+/')), true), array_slice(explode('.', $r['assertion']), 0, 2));

        return $r['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
            && $header['alg'] === 'RS256'
            && $claims['scope'] === 'https://www.googleapis.com/auth/drive.readonly'
            && $claims['iss'] === 'oha-platform@aaymca-oha.iam.gserviceaccount.com';
    });
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/export') && $r['mimeType'] === DriveFile::DOCX && $r->hasHeader('Authorization', 'Bearer ya29.test'));
    // Read-only: nothing but GET to Drive.
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/drive/') && $r->method() !== 'GET');
});

it('exports a Google Sheet as an Excel workbook', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test']),
        'www.googleapis.com/drive/v3/files/sheet12345678901234567890/export*' => Http::response('PK excel bytes'),
        'www.googleapis.com/drive/v3/files/sheet12345678901234567890*' => Http::response([
            'id' => 'sheet12345678901234567890', 'name' => 'TOGO YMCA Organisational Development Plan', 'mimeType' => DriveFile::GOOGLE_SHEET,
            'version' => '9', 'modifiedTime' => '2026-10-07T09:30:00Z', 'trashed' => false,
        ]),
    ]);
    $drive = app(GoogleDrive::class);
    $file = $drive->file('sheet12345678901234567890');

    expect($file->format())->toBe('xlsx')
        ->and($file->fileName())->toBe('TOGO YMCA Organisational Development Plan.xlsx')
        ->and($drive->content($file))->toBe('PK excel bytes');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/export') && $r['mimeType'] === DriveFile::XLSX);
});

it('says what to do when the document is not shared with the platform, or is in the bin', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test']),
        'www.googleapis.com/drive/v3/files/notshared123456789012345*' => Http::response(['error' => ['code' => 404, 'errors' => [['reason' => 'notFound']]]], 404),
        'www.googleapis.com/drive/v3/files/inthebin1234567890123456*' => Http::response([
            'id' => 'inthebin1234567890123456', 'name' => 'Old ODP', 'mimeType' => DriveFile::GOOGLE_DOC,
            'version' => '3', 'modifiedTime' => '2026-10-01T09:30:00Z', 'trashed' => true,
        ]),
    ]);
    $drive = app(GoogleDrive::class);

    expect(fn () => $drive->file('notshared123456789012345'))->toThrow(DriveUnavailable::class, 'share it with oha-platform@aaymca-oha.iam.gserviceaccount.com')
        ->and(fn () => $drive->file('inthebin1234567890123456'))->toThrow(DriveUnavailable::class, 'Google Drive bin');
});
