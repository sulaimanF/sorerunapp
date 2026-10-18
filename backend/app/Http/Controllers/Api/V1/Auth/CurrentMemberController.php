<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\Auth\BrowserSessionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrentMemberController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return (new BrowserSessionResource($request->user('web')))->response()->header('Cache-Control', 'no-store');
    }
}
