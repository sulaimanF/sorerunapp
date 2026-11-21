<?php

declare(strict_types=1);

namespace App\Http\Requests\Administration;

final class DeleteAdminAccountRequest extends AdminAccountRequest
{
    public function rules(): array
    {
        return [];
    }
}
