<?php

namespace App\Demo;

use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Address;

/**
 * The demo site's email, as it leaves: marked "Demo" (subject and a note at the top), and
 * sent only to registered visitors (DemoVisitor), never to anyone in the demo itself (its
 * people at example.org, or the showcase's Super Administrator). In practice the only
 * email the demo sends is a visitor's own "choose a new password" link.
 */
final class DemoMail
{
    public const NOTE = 'This email is from the OHA demo site (demo.oha.africaymca.org), where people try the platform with made-up data. It is not from the live platform.';

    /** @return false|null false: send nothing */
    public function handle(MessageSending $event): ?bool
    {
        $message = $event->message;
        if (! config('oha.demo')) {
            return null;
        }

        // Only to registered visitors.
        $real = fn (array $addresses) => array_values(array_filter($addresses, fn (Address $a) => DemoVisitor::query()->email($a->getAddress())->exists()));
        $to = $real($message->getTo());
        if ($to === []) {
            return false;
        }
        $message->to(...$to)->cc(...$real($message->getCc()))->bcc(...$real($message->getBcc()));

        $message->subject('[Demo] '.$message->getSubject());

        $html = $message->getHtmlBody();
        if (is_string($html)) {
            $note = '<div style="background:#fff4d6;border:1px solid #e0b84c;color:#5c4400;padding:10px 14px;margin:0 0 12px;font-family:Arial,sans-serif;font-size:14px;">'.e(self::NOTE).'</div>';
            $message->html(preg_match('/<body[^>]*>/i', $html) === 1
                ? (string) preg_replace('/(<body[^>]*>)/i', '$1'.$note, $html, 1)
                : $note.$html);
        }
        $text = $message->getTextBody();
        if (is_string($text)) {
            $message->text(self::NOTE."\n\n".$text);
        }

        return null;
    }
}
