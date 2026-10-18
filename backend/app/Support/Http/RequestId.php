<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class RequestId
{
    public static function get(Request $request): string
    {
        $id = $request->attributes->get('sorerunapp.request_id');

        if (! is_string($id) || ! Str::isUuid($id)) {
            $id = (string) Str::uuid();
            $request->attributes->set('sorerunapp.request_id', $id);
        }

        return $id;
    }
}
