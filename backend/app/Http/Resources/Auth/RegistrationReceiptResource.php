<?php

declare(strict_types=1);

namespace App\Http\Resources\Auth;

use App\Support\Http\RequestId;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RegistrationReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'result' => 'registration_received',
            'message' => 'Permintaan registrasi diterima. Jika alamat email dapat digunakan, instruksi verifikasi akan diproses.',
        ];
    }

    public function with(Request $request): array
    {
        return ['meta' => ['request_id' => RequestId::get($request)]];
    }
}
