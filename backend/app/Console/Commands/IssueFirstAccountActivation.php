<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Administration\IssueFirstAccountActivation as IssueActivation;
use App\Exceptions\ApiProblem;
use App\Services\Administration\InitialActivationEnvironment;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;

final class IssueFirstAccountActivation extends Command
{
    protected $signature = 'admin:activate-first-account {--user-id= : Explicit existing candidate ID} {--email= : Exact candidate email}';

    protected $description = 'Issue a role-free initial activation proof, locally through the outbox (no bootstrap)';

    public function handle(IssueActivation $action, InitialActivationEnvironment $environment): int
    {
        $id = filter_var($this->option('user-id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $email = $this->option('email');
        if ($id === false || ! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('ID akun dan email eksplisit wajib diberikan.');

            return self::INVALID;
        }
        try {
            $environment->assertReady();
            $this->line('Environment: '.app()->environment().'; target ID: '.$id.'; email: '.mb_strtolower(trim($email), 'UTF-8'));
            if (! $this->input->isInteractive() || ! $this->confirm('Target dan backup telah diperiksa. Terbitkan undangan aktivasi tanpa memberikan role?', false)) {
                $this->warn('Dibatalkan tanpa perubahan.');

                return self::FAILURE;
            }
            $deliveryId = $action->handle($id, mb_strtolower(trim($email), 'UTF-8'), (string) Str::uuid());
        } catch (ApiProblem|RuntimeException $problem) {
            $this->error($problem instanceof ApiProblem ? $problem->getMessage() : 'Prasyarat environment aktivasi awal tidak terpenuhi. Periksa konfigurasi lokal.');

            return self::FAILURE;
        }
        $this->info('Undangan diterima outbox (delivery ID '.$deliveryId.'). Email belum diklaim terkirim. Tidak ada role yang diberikan.');
        $this->line('Proses hanya antrean aktivasi melalui admin:deliver-first-activation dengan ID/email yang sama.');

        return self::SUCCESS;
    }
}
