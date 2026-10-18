<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Data\Auth\LoginMemberData;
use App\Enums\AccountStatus;
use App\Exceptions\ApiProblem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use SensitiveParameter;

class AuthenticateMember
{
    private static ?string $dummyHash = null;

    public function handle(#[SensitiveParameter] LoginMemberData $data): User
    {
        $user = User::query()->where('email', $data->email)->first();
        if ($user === null) {
            if (self::$dummyHash === null || Hash::needsRehash(self::$dummyHash)) {
                self::$dummyHash = Hash::make(Str::random(32));
            }
            Hash::check($data->password, self::$dummyHash);
            throw $this->invalidCredentials();
        }

        // Expensive credential verification happens before the short row lock.
        $checkedHash = $user->password;
        if (! Hash::check($data->password, $checkedHash)) {
            throw $this->invalidCredentials();
        }

        return DB::transaction(function () use ($user, $checkedHash, $data): User {
            $current = User::query()->whereKey($user->id)->lockForUpdate()->first();
            if ($current === null || $current->status !== AccountStatus::Enabled
                || ! hash_equals($checkedHash, $current->password)) {
                throw $this->invalidCredentials();
            }

            // Keep the configured hashing cost current after valid authentication.
            if (Hash::needsRehash($current->password)) {
                $current->password = Hash::make($data->password);
                $current->save();
            }

            return $current;
        }, attempts: 3);
    }

    private function invalidCredentials(): ApiProblem
    {
        return new ApiProblem('INVALID_CREDENTIALS', 'Email atau password tidak sesuai.', 401);
    }
}
