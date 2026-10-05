<?php

use Illuminate\Support\Facades\Mail;

/*
 * The cPanel mail server's certificate names the server, not africaymca.org. The
 * certificate check stays on unless MAIL_VERIFY_PEER=false turns it off.
 */

function smtpStreamOptions(): array
{
    config(['mail.mailers.smtp.host' => 'mail.example.org', 'mail.mailers.smtp.port' => 465]);
    app()->forgetInstance('mail.manager');
    Mail::clearResolvedInstances();

    return Mail::mailer('smtp')->getSymfonyTransport()->getStream()->getStreamOptions();
}

it('checks the mail server’s certificate by default', function () {
    expect(smtpStreamOptions()['ssl']['verify_peer'] ?? true)->toBeTrue();
});

it('skips the certificate check only when told to', function () {
    config(['mail.mailers.smtp.verify_peer' => false]);

    expect(smtpStreamOptions()['ssl'])->toMatchArray(['verify_peer' => false, 'verify_peer_name' => false]);
});
