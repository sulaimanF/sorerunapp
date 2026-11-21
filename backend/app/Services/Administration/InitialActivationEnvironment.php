<?php

declare(strict_types=1);

namespace App\Services\Administration;

use App\Services\Notifications\LocalMailScope;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class InitialActivationEnvironment
{
    public function assertReady(): void
    {
        $db = DB::connection();
        $actual = $db->selectOne('SELECT DATABASE() AS name, VERSION() AS version');
        $expected = app()->environment('local') ? 'sorerunapp' : 'sorerunapp_stage11_test';
        if (! app()->environment(['local', 'testing']) || app()->configurationIsCached()
            || config('database.default') !== 'mysql' || $db->getDriverName() !== 'mysql' || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true)
            || (int) $db->getConfig('port') !== 3306 || $actual->name !== $expected
            || $db->getDatabaseName() !== $expected || ! str_starts_with($actual->version, '8.4.')
            || config('queue.connections.database.driver') !== 'database'
            || config('queue.connections.database.table') !== 'jobs'
            || (app()->environment('local') && config('queue.default') !== 'database')
            || ! in_array(config('queue.connections.database.connection'), [null, 'mysql'], true)) {
            throw new RuntimeException('Initial activation requires uncached local sorerunapp or isolated Stage11 MySQL 8.4 tests.');
        }
        foreach (['app.url', 'sorerunapp.frontend_url'] as $key) {
            $url = parse_url((string) config($key));
            if (! is_array($url) || ! in_array($url['host'] ?? '', ['127.0.0.1', 'localhost'], true)) {
                throw new RuntimeException('Initial activation requires loopback application origins.');
            }
        }
        if (! (app()->environment('testing') && config('mail.default') === 'array')) {
            app(LocalMailScope::class)->assertTransport();
        }
    }
}
