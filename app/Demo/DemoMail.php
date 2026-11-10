<?php

namespace App\Demo;

use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Address;

/**
 * The demo site's email, as it leaves: marked "Demo" (subject and a note at the top),
 * never sent to the demo people (example.org, which receives no mail), and every link to
 * the site opens the private demo it came from, in whichever browser it is clicked (by
 * way of the demo's private link), so an invited person carries on in that demo.
 */
final class DemoMail
{
    public const NOTE = 'This email is from the OHA demo site (demo.oha.africaymca.org), where people try the platform with made-up data. It is not from the live platform.';

    /** Paths left as they are: the demo's own links, and images and files the email shows. */
    private const KEEP = ['/demo/', '/images/', '/build/', '/storage/', '/favicon'];

    /** @return false|null false: send nothing */
    public function handle(MessageSending $event): ?bool
    {
        $message = $event->message;
        if (! config('oha.demo')) {
            return null;
        }

        // Never to the demo people's invented addresses.
        $real = fn (array $addresses) => array_values(array_filter($addresses, fn (Address $a) => ! str_ends_with(strtolower($a->getAddress()), '@example.org')));
        $to = $real($message->getTo());
        if ($to === []) {
            return false;
        }
        $message->to(...$to)->cc(...$real($message->getCc()))->bcc(...$real($message->getBcc()));

        $message->subject('[Demo] '.$message->getSubject());

        $html = $message->getHtmlBody();
        if (is_string($html)) {
            $html = $this->links($html, html: true);
            $note = '<div style="background:#fff4d6;border:1px solid #e0b84c;color:#5c4400;padding:10px 14px;margin:0 0 12px;font-family:Arial,sans-serif;font-size:14px;">'.e(self::NOTE).'</div>';
            $html = preg_match('/<body[^>]*>/i', $html) === 1
                ? (string) preg_replace('/(<body[^>]*>)/i', '$1'.$note, $html, 1)
                : $note.$html;
            $message->html($html);
        }
        $text = $message->getTextBody();
        if (is_string($text)) {
            $message->text(self::NOTE."\n\n".$this->links($text, html: false));
        }

        return null;
    }

    /** Each link to the site, through the private demo's own link (when there is a demo). */
    private function links(string $body, bool $html): string
    {
        $key = Sandboxes::current();
        $base = rtrim((string) config('app.url'), '/');
        if ($key === null || $base === '') {
            return $body;
        }

        return (string) preg_replace_callback('#'.preg_quote($base, '#').'(/[^\s"\'<>]*)?#', function (array $m) use ($key, $html): string {
            $path = $html ? html_entity_decode($m[1] ?? '', ENT_QUOTES | ENT_HTML5) : ($m[1] ?? '');
            $path = $path === '' ? '/' : $path;
            foreach (self::KEEP as $keep) {
                if (str_starts_with($path, $keep)) {
                    return $m[0];
                }
            }
            $url = route('demo.resume', ['key' => $key, 'next' => $path]);

            return $html ? e($url) : $url;
        }, $body);
    }
}
