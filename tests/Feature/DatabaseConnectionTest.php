<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('runs the test suite against the PostgreSQL test database', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toBe('oha_testing')
        ->and(Schema::hasTable('users'))->toBeTrue();
});
