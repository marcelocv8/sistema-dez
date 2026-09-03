<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Scheduling\BuildAvailability;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListAvailabilityRequest;
use Illuminate\Http\JsonResponse;

class PublicAvailabilityController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(ListAvailabilityRequest $request, BuildAvailability $availability): JsonResponse
    {
        return response()->json(['data' => $availability->handle($request->validated())]);
    }
}
