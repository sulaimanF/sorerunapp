<?php

declare(strict_types=1);

use App\Services\Administration\InitialActivationEnvironment;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

function activationSnapshot(string $connection = 'mysql', bool $omitPurpose = false): array
{
    $db = DB::connection($connection);
    $result = [];
    foreach ($db->select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'") as $table) {
        $name = array_values((array) $table)[0];
        if (! preg_match('/\A[a-z0-9_]+\z/D', $name)) {
            throw new RuntimeException('Unexpected table.');
        }
        $rows = array_map(static function ($row) use ($name, $omitPurpose): string {
            $data = (array) $row;
            if ($omitPurpose && $name === 'admin_invitations') {
                unset($data['purpose']);
            }

            return serialize($data);
        }, $db->table($name)->get()->all());
        sort($rows, SORT_STRING);
        $result[$name] = ['count' => count($rows), 'sha256' => hash('sha256', serialize($rows))];
    }
    ksort($result);

    return $result;
}

function activationMysql(string $program, array $arguments, ?string $input = null): string
{
    $binary = 'C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/'.$program.'.exe';
    if (! is_file($binary)) {
        throw new RuntimeException('Reviewed MySQL binary unavailable.');
    }
    $db = DB::connection();
    $process = new Process([$binary, '--no-defaults', '--host='.$db->getConfig('host'), '--port=3306', '--user='.$db->getConfig('username'), ...$arguments], base_path(), ['MYSQL_PWD' => (string) $db->getConfig('password')]);
    $process->setTimeout(180);
    if ($input !== null) {
        $process->setInput($input);
    }
    $process->run();
    if (! $process->isSuccessful()) {
        throw new RuntimeException('MySQL utility failed; sensitive output withheld.');
    }

    return $process->getOutput();
}

function activationRestoreProof(array $manifest, string $directory): void
{
    $dump = Crypt::decryptString(file_get_contents($directory.'/'.$manifest['backup']));
    if (! hash_equals($manifest['dump_sha256'], hash('sha256', $dump)) || preg_match('/^\s*(?:USE\s|CREATE\s+DATABASE)/mi', $dump)) {
        throw new RuntimeException('Invalid backup.');
    }
    config(['database.connections.activation_restore' => array_replace(DB::connection()->getConfig(), ['database' => $manifest['restore_database'], 'url' => null])]);
    DB::purge('activation_restore');
    DB::connection('activation_restore')->statement("SET SESSION time_zone = '+00:00'");
    if (activationSnapshot('activation_restore') !== $manifest['snapshot']) {
        throw new RuntimeException('Restored data mismatch.');
    }
    foreach ($manifest['media'] as $media) {
        if (basename($media['backup']) !== $media['backup'] || ! hash_equals($media['sha256'], hash('sha256', Crypt::decryptString(file_get_contents($directory.'/'.$media['backup']))))) {
            throw new RuntimeException('Media backup mismatch.');
        }
    }
}

$operation = $argv[1] ?? 'inspect';
try {
    app(InitialActivationEnvironment::class)->assertReady();
    if (! app()->environment('local')) {
        throw new RuntimeException('This operational script accepts only local sorerunapp.');
    }
    DB::statement("SET SESSION time_zone = '+00:00'");
    $directory = storage_path('app/private/stage11-activation');
    if ($operation === 'backup') {
        if (! app()->isDownForMaintenance()) {
            throw new RuntimeException('Maintenance required.');
        }
        foreach (['ROUTINES' => 'ROUTINE_SCHEMA', 'EVENTS' => 'EVENT_SCHEMA', 'TRIGGERS' => 'TRIGGER_SCHEMA'] as $table => $column) {
            if (DB::table('information_schema.'.$table)->where($column, 'sorerunapp')->exists()) {
                throw new RuntimeException('Executable database objects require review.');
            }
        }
        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            throw new RuntimeException('Backup directory unavailable.');
        }
        $before = activationSnapshot();
        $dump = activationMysql('mysqldump', ['--single-transaction', '--quick', '--skip-lock-tables', '--no-tablespaces', '--hex-blob', '--set-gtid-purged=OFF', '--routines', '--events', '--triggers', '--skip-comments', 'sorerunapp']);
        if (preg_match('/^\s*(?:USE\s|CREATE\s+DATABASE)/mi', $dump)) {
            throw new RuntimeException('Dump must not select a database.');
        }
        $run = gmdate('Ymd_His').'_'.bin2hex(random_bytes(4));
        $backup = 'sorerunapp_'.$run.'.sql.enc';
        file_put_contents($directory.'/'.$backup, Crypt::encryptString($dump), LOCK_EX);
        $restore = 'sorerunapp_activation_restore_'.$run;
        DB::statement('CREATE DATABASE `'.$restore.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        activationMysql('mysql', ['--database='.$restore, '--binary-mode'], Crypt::decryptString(file_get_contents($directory.'/'.$backup)));
        $media = [];
        $paths = [];
        foreach (DB::table('landing_page_media')->get(['path']) as $row) {
            $paths[] = ['landing', $row->path];
        }
        foreach (DB::table('member_profiles')->whereNotNull('photo_path')->get(['photo_path']) as $row) {
            $paths[] = ['local', $row->photo_path];
        }
        foreach ($paths as [$disk, $path]) {
            if (! is_string($path) || str_contains($path, '..') || str_contains($path, '\\') || str_starts_with($path, '/')) {
                throw new RuntimeException('Unexpected media path.');
            }
            $bytes = Storage::disk($disk)->get($path);
            if (! is_string($bytes)) {
                throw new RuntimeException('Referenced media missing.');
            }
            $file = 'media_'.$run.'_'.count($media).'.enc';
            file_put_contents($directory.'/'.$file, Crypt::encryptString($bytes), LOCK_EX);
            $media[] = ['backup' => $file, 'disk' => $disk, 'path' => $path, 'sha256' => hash('sha256', $bytes)];
        }
        $manifest = ['source' => 'sorerunapp', 'backup' => $backup, 'restore_database' => $restore, 'snapshot' => $before, 'dump_sha256' => hash('sha256', $dump), 'media' => $media, 'created_at_utc' => gmdate('c')];
        activationRestoreProof($manifest, $directory);
        if (activationSnapshot() !== $before) {
            throw new RuntimeException('Source changed during backup.');
        }
        file_put_contents($directory.'/manifest_'.$run.'.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
        echo json_encode(['run' => $run, 'backup' => $backup, 'restore_database' => $restore, 'tables_verified' => count($before), 'media_verified' => count($media)], JSON_THROW_ON_ERROR).PHP_EOL;
    } elseif (in_array($operation, ['verify', 'apply-activation'], true)) {
        $run = $argv[2] ?? '';
        if (! preg_match('/\A[0-9]{8}_[0-9]{6}_[a-f0-9]{8}\z/D', $run)) {
            throw new RuntimeException('Invalid backup run.');
        }
        $manifest = json_decode(file_get_contents($directory.'/manifest_'.$run.'.json'), true, flags: JSON_THROW_ON_ERROR);
        if ($manifest['source'] !== 'sorerunapp' || $manifest['backup'] !== 'sorerunapp_'.$run.'.sql.enc' || $manifest['restore_database'] !== 'sorerunapp_activation_restore_'.$run) {
            throw new RuntimeException('Unexpected manifest.');
        }
        activationRestoreProof($manifest, $directory);
        if ($operation === 'apply-activation') {
            if (! app()->isDownForMaintenance() || activationSnapshot() !== $manifest['snapshot']) {
                throw new RuntimeException('Maintenance and unchanged verified backup required.');
            }
            $name = '2026_11_21_180000_support_first_account_activation';
            $pending = array_values(array_diff(array_map(fn ($file) => basename($file, '.php'), glob(database_path('migrations/*.php'))), DB::table('migrations')->pluck('migration')->all()));
            if ($pending !== [$name]) {
                throw new RuntimeException('Unexpected pending migration/prerequisite.');
            }
            if (Artisan::call('migrate', ['--path' => 'database/migrations/'.$name.'.php', '--no-interaction' => true]) !== 0) {
                throw new RuntimeException('Migration failed; inspect schema before retry.');
            }
            $after = activationSnapshot(omitPurpose: true);
            foreach ($manifest['snapshot'] as $table => $state) {
                if ($table !== 'migrations' && ($after[$table] ?? null) !== $state) {
                    throw new RuntimeException('Unexpected data change.');
                }
            }
            $checks = DB::table('information_schema.TABLE_CONSTRAINTS')->where('CONSTRAINT_SCHEMA', 'sorerunapp')
                ->where('TABLE_NAME', 'admin_invitations')->whereIn('CONSTRAINT_NAME', ['chk_invitation_purpose', 'chk_invitation_issuer'])->where('ENFORCED', 'YES')->count();
            if ($checks !== 2) {
                throw new RuntimeException('Invitation constraints missing.');
            }
            echo json_encode(['migration' => $name, 'batch' => DB::table('migrations')->where('migration', $name)->value('batch'), 'existing_data_unchanged' => true, 'checks_enforced' => $checks], JSON_THROW_ON_ERROR).PHP_EOL;
        } else {
            echo json_encode(['backup_verified' => true, 'tables_verified' => count($manifest['snapshot']), 'application_modified' => false], JSON_THROW_ON_ERROR).PHP_EOL;
        }
    } elseif ($operation === 'inspect') {
        echo json_encode(['environment' => app()->environment(), 'database' => DB::selectOne('SELECT DATABASE() AS name')->name, 'maintenance' => app()->isDownForMaintenance()], JSON_THROW_ON_ERROR).PHP_EOL;
    } else {
        throw new RuntimeException('Only inspect, backup, verify and apply-activation are supported.');
    }
} catch (Throwable $problem) {
    fwrite(STDERR, 'Local activation operation failed ('.$operation.'); sensitive details withheld.'.PHP_EOL);
    exit(1);
}
