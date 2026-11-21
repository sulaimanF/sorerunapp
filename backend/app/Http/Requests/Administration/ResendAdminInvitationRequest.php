<?php

declare(strict_types=1);

namespace App\Http\Requests\Administration;

final class ResendAdminInvitationRequest extends AdminAccountRequest
{
    public function rules(): array
    {
        return [];
    }
}
