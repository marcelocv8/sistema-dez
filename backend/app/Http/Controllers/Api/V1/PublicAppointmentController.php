<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Scheduling\BookAppointment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePublicAppointmentRequest;
use App\Http\Resources\Api\V1\PublicAppointmentResource;
use Illuminate\Http\JsonResponse;

class PublicAppointmentController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(StorePublicAppointmentRequest $request, BookAppointment $booking): JsonResponse
    {
        return (new PublicAppointmentResource($booking->handle($request->validated())))
            ->response()
            ->setStatusCode(201)->withHeaders(['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
    }
}
