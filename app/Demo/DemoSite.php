<?php

namespace App\Demo;

/**
 * The demo site's safety lock for commands that change it: config oha.demo is on, and
 * the site's own address says "demo" (or this is a developer's machine or a test), so a
 * demo setting switched on by mistake can never touch the live site.
 */
final class DemoSite
{
    /** Why a demo-only command must not run here, or null when it may. */
    public static function refusal(): ?string
    {
        if (! config('oha.demo')) {
            return 'This is not the demo site (OHA_DEMO is off). Nothing was changed.';
        }
        if (! app()->isLocal() && ! app()->runningUnitTests() && ! str_contains((string) parse_url((string) config('app.url'), PHP_URL_HOST), 'demo')) {
            return 'APP_URL is not the demo site’s address. Nothing was changed.';
        }

        return null;
    }
}
