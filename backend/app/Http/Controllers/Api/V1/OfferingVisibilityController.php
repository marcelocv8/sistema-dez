<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Scheduling\RefreshOfferingVisibility;
use App\Http\Controllers\Controller;
use App\Models\ServiceOffering;
use App\Services\AuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OfferingVisibilityController extends Controller
{
    public function update(Request $request, ServiceOffering $serviceOffering, RefreshOfferingVisibility $visibility, AuditRecorder $audit): JsonResponse
    {
        $data = $request->validate(['paused' => ['required', 'boolean']]);

        $offering = DB::transaction(function () use ($request, $serviceOffering, $data, $visibility, $audit): ServiceOffering {
            $offering = ServiceOffering::query()->lockForUpdate()->findOrFail($serviceOffering->id);
            $before = $offering->attributesToArray();
            $offering->forceFill(['online_paused' => $data['paused']])->save();
            $offering = $visibility->handle($offering);
            $audit->record($request, $offering, $data['paused'] ? 'visibility.paused' : 'visibility.resumed', $before, $offering->attributesToArray());

            return $offering;
        }, 3);

        return response()->json(['data' => $offering->load(['unit', 'procedure', 'professional', 'agreement'])]);
    }
}
