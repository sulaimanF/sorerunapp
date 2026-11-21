<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Content;

use App\Http\Controllers\Controller;
use App\Http\Resources\Content\PublicLandingPageResource;
use App\Services\Content\ReadLandingPage;
use App\Support\Http\RequestId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PublicLandingPageController extends Controller
{
    public function __invoke(Request $request, ReadLandingPage $pages): JsonResponse
    {
        return (new PublicLandingPageResource($pages->published($request)))->additional(['meta' => ['request_id' => RequestId::get($request)]])
            ->response()->header('Cache-Control', 'no-store');
    }
}
