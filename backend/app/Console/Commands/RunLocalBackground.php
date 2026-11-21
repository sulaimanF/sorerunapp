<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Notifications\LocalMailScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class RunLocalBackground extends Command
{
    protected $signature = 'dev:background {action=start : start, status, stop, restart, pause-worker, resume-worker}';

    protected $description = 'Supervise the local synthetic mail worker and scheduler in this terminal';

    private string $directory;

    public function handle(LocalMailScope $scope): int
    {
        $this->directory = storage_path('framework/local-background');
        if (! is_dir($this->directory)) {
            mkdir($this->directory, 0700, true);
        }
        $action = $this->argument('action');
        if (! in_array($action, ['start', 'status', 'stop', 'restart', 'pause-worker', 'resume-worker'], true)) {
            return self::INVALID;
        }
        if (! $scope->active()) {
            $this->error('This launcher is available only in APP_ENV=local.');

            return self::FAILURE;
        }
        $lock = fopen($this->directory.'/supervisor.lock', 'c+');
        if ($action !== 'start') {
            $running = ! flock($lock, LOCK_EX | LOCK_NB);
            if (! $running) {
                flock($lock, LOCK_UN);
            }
            fclose($lock);
            if ($action === 'status') {
                $state = $this->readJson($this->directory.'/state.json');
                $this->line(json_encode(['running' => $running, 'state' => $state,
                    'jobs_by_queue' => DB::table('jobs')->select('queue')->selectRaw('COUNT(*) AS total')->groupBy('queue')->get(),
                    'failed_jobs' => DB::table('failed_jobs')->count(),
                    'recent_deliveries' => DB::table('notification_deliveries')->latest('id')->limit(8)->get(['id', 'type', 'status', 'attempt_count', 'last_error_code']),
                ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }
            if (! $running) {
                $this->error('No launcher is running. Start composer dev in a dedicated terminal.');

                return self::FAILURE;
            }
            file_put_contents($this->directory.'/control.json', json_encode(['action' => $action, 'id' => bin2hex(random_bytes(8))]), LOCK_EX);
            $this->info('Requested '.$action.'. Check dev:status for acknowledgement.');

            return self::SUCCESS;
        }
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            $this->error('The project background launcher is already running.');

            return self::FAILURE;
        }
        $worker = null;
        $scheduler = null;
        $stopping = false;
        $workerEnabled = true;
        $restart = false;
        $ack = null;
        $crashes = 0;
        $generation = 1;
        $lastScheduledMinute = null;
        try {
            $scope->assertReady();
            file_put_contents($this->directory.'/control.json', '{}', LOCK_EX);
            if (PHP_OS_FAMILY === 'Windows') {
                sapi_windows_set_ctrl_handler(function () use (&$stopping, &$workerEnabled): bool {
                    $stopping = true;
                    $workerEnabled = false;

                    return true;
                });
            }
            $this->info('Local MySQL sorerunapp / Mailpit 127.0.0.1:1025 / queue mail-local / synthetic @example.test only.');
            $this->info('Keep this terminal open. Ctrl+C or composer dev:stop stops owned children.');
            $this->record('supervisor.started');
            while (true) {
                $control = $this->readJson($this->directory.'/control.json');
                if (is_array($control) && isset($control['id']) && $control['id'] !== $ack) {
                    $ack = $control['id'];
                    match ($control['action']) {
                        'stop' => [$stopping = true, $workerEnabled = false],
                        'pause-worker' => $workerEnabled = false,
                        'resume-worker' => $workerEnabled = true,
                        'restart' => [$restart = true, $workerEnabled = false],
                    };
                    $this->record('control.'.$control['action']);
                }
                if ($worker !== null && ! $worker->isRunning()) {
                    if ($worker->getExitCode() !== 0) {
                        $crashes++;
                        $this->record('worker.failed exit='.(int) $worker->getExitCode());
                    }
                    $worker = null;
                }
                if ($scheduler !== null && ! $scheduler->isRunning()) {
                    $code = $scheduler->getExitCode();
                    $this->record('scheduler.finished exit='.(int) $code);
                    $scheduler = null;
                    if ($code !== 0) {
                        throw new RuntimeException('Scheduled run failed. Inspect local-scheduler.log and Laravel log.');
                    }
                }
                if (($stopping || $restart) && $worker === null && $scheduler === null) {
                    if ($stopping) {
                        break;
                    }
                    $scope->assertReady();
                    $workerEnabled = true;
                    $restart = false;
                    $crashes = 0;
                    $generation++;
                    $this->record('supervisor.restarted generation='.$generation);
                }
                if ($crashes >= 5) {
                    throw new RuntimeException('Five worker failures; inspect local-background.log and failed jobs before restarting.');
                }
                $minute = gmdate('YmdHi');
                if (! $stopping && ! $restart && $scheduler === null && $minute !== $lastScheduledMinute) {
                    // The same Laravel scheduler as cron, once per minute; no permanent OS task.
                    $lastScheduledMinute = $minute;
                    $scheduler = $this->child(['schedule:run']);
                    $this->record('scheduler.started pid='.$scheduler->getPid().' minute='.$minute);
                }
                if ($workerEnabled && ! $stopping && ! $restart && $worker === null) {
                    // One standard Laravel job per child: reload code automatically and drain safely on stop.
                    $worker = $this->child(['queue:work', 'database', '--queue='.LocalMailScope::QUEUE, '--once', '--sleep=1', '--tries=1', '--timeout=60']);
                }
                file_put_contents($this->directory.'/state.json', json_encode([
                    'supervisor_pid' => getmypid(), 'worker_pid' => $worker?->getPid(), 'scheduler_pid' => $scheduler?->getPid(),
                    'worker_enabled' => $workerEnabled, 'restarting' => $restart, 'stopping' => $stopping,
                    'acknowledged_control' => $ack, 'heartbeat_utc' => gmdate('c'), 'queue' => LocalMailScope::QUEUE,
                    'generation' => $generation, 'last_scheduled_minute' => $lastScheduledMinute,
                ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
                usleep(250000);
            }
        } catch (Throwable $error) {
            $this->error($error::class === RuntimeException::class ? $error->getMessage() : 'Background startup/process failed: '.$error::class.'. No secret output is printed.');
            $this->record('supervisor.failed class='.$error::class);

            return self::FAILURE;
        } finally {
            $this->stopChild($worker);
            $this->stopChild($scheduler);
            file_put_contents($this->directory.'/state.json', json_encode(['stopped_at_utc' => gmdate('c')]), LOCK_EX);
            flock($lock, LOCK_UN);
            fclose($lock);
            $this->record('supervisor.stopped');
        }

        return self::SUCCESS;
    }

    private function child(array $arguments): Process
    {
        $process = new Process([PHP_BINARY, base_path('artisan'), ...$arguments], base_path());
        $process->setTimeout(null);
        // Windows temporary output pipes can deadlock during nested process shutdown.
        // Technical delivery events go to Laravel's log; schedule output has its own file.
        $process->disableOutput();
        if (PHP_OS_FAMILY === 'Windows') {
            $process->setOptions(['create_process_group' => true]);
        }
        $process->start();

        return $process;
    }

    private function stopChild(?Process &$process): void
    {
        if ($process === null) {
            return;
        }
        if ($process->isRunning() && PHP_OS_FAMILY === 'Windows') {
            // PID comes only from our Process object, never a system-wide process-name kill.
            $stop = new Process(['taskkill', '/PID', (string) $process->getPid(), '/T', '/F']);
            $stop->disableOutput();
            $stop->setTimeout(10);
            $stop->run();
        }
        $process->stop(2);
        $process = null;
    }

    private function record(string $event): void
    {
        file_put_contents(storage_path('logs/local-background.log'), gmdate('c').' '.$event.PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $stream = fopen($path, 'r');
        flock($stream, LOCK_SH);
        $contents = stream_get_contents($stream);
        flock($stream, LOCK_UN);
        fclose($stream);

        return json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    }
}
