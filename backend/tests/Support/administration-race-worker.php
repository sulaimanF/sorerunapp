<?php

declare(strict_types=1);

use App\Actions\Administration\AcceptAdminInvitation;
use App\Actions\Administration\AcceptFirstAccountActivation;
use App\Actions\Administration\BootstrapSuperAdmin;
use App\Actions\Administration\ChangeAdministrativePassword;
use App\Actions\Administration\CreateAdminAccount;
use App\Actions\Administration\DeleteAdminAccount;
use App\Actions\Administration\IssueFirstAccountActivation;
use App\Actions\Administration\ResendAdminInvitation;
use App\Actions\Auth\ResetMemberPassword;
use App\Exceptions\ApiProblem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AdministrationFixtures;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    (new class
    {
        use AdministrationFixtures;

        public function check(): void
        {
            $this->requireAdministrationDatabase();
        }
    })->check();
    if (! preg_match('/\A[a-f0-9]{32}\z/D', $input['run'] ?? '') || ! in_array($input['worker'] ?? null, [1, 2], true)) {
        throw new RuntimeException('Invalid race context.');
    }
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $directory = storage_path('framework/testing/administration-race-'.$input['run']);
    if (! is_dir($directory)) {
        throw new RuntimeException('Missing barrier.');
    }
    $wait = static function (string $file): void {
        $deadline = microtime(true) + 25;
        while (! is_file($file)) {
            clearstatcache();
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Race barrier expired.');
            }
            usleep(10000);
        }
    };
    $connection = DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
    file_put_contents($directory.'/ready-'.$input['worker'], 'ready');
    $wait($directory.'/go');
    $task = $input['task'];
    $operation = static function () use ($task): array {
        $request = (string) Str::uuid();

        return match ($task['operation']) {
            'issue-first' => ['code' => 'issued', 'delivery_id' => app(IssueFirstAccountActivation::class)->handle($task['target'], $task['email'], $request)],
            'accept-first' => (static function () use ($task, $request): array {
                app(AcceptFirstAccountActivation::class)->handle($task['proof'], $request);

                return ['code' => 'activated'];
            })(),
            'create' => ['code' => 'created', 'user_id' => app(CreateAdminAccount::class)->handle($task['actor'], 1, $task['data'], $request)['user_id']],
            'password' => (static function () use ($task, $request): array {
                app(ChangeAdministrativePassword::class)->handle($task['actor'], 1, $task['data'], $request, $task['target'] ?? null);

                return ['code' => 'password_changed'];
            })(),
            'delete' => (static function () use ($task, $request): array {
                app(DeleteAdminAccount::class)->handle($task['actor'], 1, $task['target'], $request);

                return ['code' => 'deleted'];
            })(),
            'accept' => (static function () use ($task, $request): array {
                app(AcceptAdminInvitation::class)->handle($task['proof'], $request);

                return ['code' => 'accepted'];
            })(),
            'resend' => ['code' => 'resent', 'user_id' => app(ResendAdminInvitation::class)->handle($task['actor'], 1, $task['target'], $request)['user_id']],
            'reset' => (static function () use ($task, $request): array {
                app(ResetMemberPassword::class)->handle($task['proof'], $request);

                return ['code' => 'reset'];
            })(),
            'bootstrap' => (static function () use ($task, $request): array {
                app(BootstrapSuperAdmin::class)->handle($task['target'], $task['email'], $request);

                return ['code' => 'bootstrapped'];
            })(),
            'revoke' => DB::transaction(static function () use ($task): array {
                $actor = User::query()->whereKey($task['actor'])->lockForUpdate()->firstOrFail();
                $actor->roles()->detach();

                return ['code' => 'revoked'];
            }),
            default => throw new RuntimeException('Unknown race operation.'),
        };
    };
    try {
        if ($input['worker'] === 1 && (isset($task['hold_user']) || isset($task['hold_users']))) {
            $result = DB::transaction(function () use ($task, $directory, $wait, $operation): array {
                User::query()->whereIn('id', $task['hold_users'] ?? [$task['hold_user']])->orderBy('id')->lockForUpdate()->get();
                file_put_contents($directory.'/held', 'held');
                $wait($directory.'/entering');
                usleep(200000);

                return $operation();
            });
        } else {
            if ($input['worker'] === 2 && ($task['wait_held'] ?? false)) {
                $wait($directory.'/held');
                file_put_contents($directory.'/entering', 'entering');
            }
            $result = $operation();
        }
    } catch (ApiProblem $problem) {
        $result = ['code' => $problem->errorCode];
    } catch (AuthorizationException) {
        $result = ['code' => 'FORBIDDEN'];
    }
    echo json_encode([...$result, 'connection' => $connection], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    // No raw exception/proof/SQL bindings in worker output.
    fwrite(STDERR, 'Administrative race failed: '.get_class($exception));
    exit(1);
}
