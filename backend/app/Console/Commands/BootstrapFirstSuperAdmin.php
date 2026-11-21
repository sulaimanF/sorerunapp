<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Administration\BootstrapSuperAdmin;
use App\Exceptions\ApiProblem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class BootstrapFirstSuperAdmin extends Command
{
    protected $signature = 'admin:bootstrap-super {--user-id= : Explicit existing user ID} {--email= : Exact account email}';

    protected $description = 'One-time audited Super Admin assignment to an explicitly selected verified account';

    public function handle(BootstrapSuperAdmin $action): int
    {
        $id = filter_var($this->option('user-id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $email = $this->option('email');
        if ($id === false || ! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('ID akun dan email eksplisit wajib diberikan.');

            return self::INVALID;
        }
        $email = mb_strtolower(trim($email), 'UTF-8');
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql') {
            $this->error('Bootstrap membutuhkan MySQL 8.4.');

            return self::FAILURE;
        }
        $actual = $connection->selectOne('SELECT DATABASE() AS db_name, VERSION() AS version');
        if (! str_starts_with($actual->version, '8.4.')) {
            $this->error('Bootstrap membutuhkan MySQL 8.4.');

            return self::FAILURE;
        }
        $this->line('Environment: '.app()->environment().'; host: '.$connection->getConfig('host').'; database aktual: '.$actual->db_name);
        $this->line('Target ID: '.$id.'; email: '.$email);
        if (! $this->input->isInteractive() || ! $this->confirm('Target dan backup telah diperiksa. Tetapkan Super Admin pertama dan cabut seluruh sesi akun ini?', false)) {
            $this->warn('Dibatalkan tanpa perubahan.');

            return self::FAILURE;
        }
        try {
            $action->handle($id, $email, (string) Str::uuid());
        } catch (ApiProblem $problem) {
            $this->error($problem->getMessage());

            return self::FAILURE;
        }
        $this->info('Super Admin ditetapkan dan audit tersimpan. Pengguna harus login kembali.');

        return self::SUCCESS;
    }
}
