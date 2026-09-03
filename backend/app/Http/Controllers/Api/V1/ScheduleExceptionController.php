<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Scheduling\MaterializeScheduleOccurrences;
use App\Actions\Scheduling\RefreshOfferingVisibility;
use App\Http\Controllers\Controller;
use App\Models\ScheduleException;
use App\Models\ServiceOffering;
use App\Services\AuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScheduleExceptionController extends Controller
{
    public function __construct(private readonly AuditRecorder $audit, private readonly MaterializeScheduleOccurrences $materialize, private readonly RefreshOfferingVisibility $visibility) {}

    public function index(ServiceOffering $serviceOffering): JsonResponse
    {
        return response()->json(['data' => $serviceOffering->scheduleExceptions()->orderBy('date')->get()]);
    }

    public function store(Request $request, ServiceOffering $serviceOffering): JsonResponse
    {
        return $this->save($request, $serviceOffering, new ScheduleException);
    }

    public function update(Request $request, ServiceOffering $serviceOffering, ScheduleException $scheduleException): JsonResponse
    {
        abort_unless($scheduleException->service_offering_id === $serviceOffering->id, 404);

        return $this->save($request, $serviceOffering, $scheduleException);
    }

    private function save(Request $request, ServiceOffering $offering, ScheduleException $exception): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'starts_at' => ['nullable', 'required_with:ends_at', 'date_format:H:i'],
            'ends_at' => ['nullable', 'required_with:starts_at', 'date_format:H:i', 'after:starts_at'],
            'reason' => ['required', 'string', 'min:3', 'max:255'], 'blocked' => ['required', 'boolean'],
        ]);
        $created = ! $exception->exists;
        DB::transaction(function () use ($request, $offering, $exception, $data): void {
            ServiceOffering::query()->whereKey($offering->id)->lockForUpdate()->firstOrFail();
            abort_unless($offering->unit->operational_enabled, 422, 'Unidade fora da operação.');
            $before = $exception->exists ? $exception->attributesToArray() : null;
            $exception->fill([...$data, 'service_offering_id' => $offering->id, 'created_by' => $exception->created_by ?? $request->user()->id])->save();
            $this->materialize->handle($offering);
            $this->visibility->handle($offering);
            $this->audit->record($request, $exception, $before ? 'updated' : 'created', $before, $exception->attributesToArray());
        }, 3);

        return response()->json(['data' => $exception->fresh()], $created ? 201 : 200);
    }

    public function destroy(Request $request, ServiceOffering $serviceOffering, ScheduleException $scheduleException): JsonResponse
    {
        abort_unless($scheduleException->service_offering_id === $serviceOffering->id, 404);
        DB::transaction(function () use ($request, $serviceOffering, $scheduleException): void {
            ServiceOffering::query()->whereKey($serviceOffering->id)->lockForUpdate()->firstOrFail();
            $before = $scheduleException->attributesToArray();
            $scheduleException->update(['blocked' => false]);
            $this->materialize->handle($serviceOffering);
            $this->visibility->handle($serviceOffering);
            $this->audit->record($request, $scheduleException, 'unblocked', $before, $scheduleException->attributesToArray());
        });

        return response()->json(['data' => $scheduleException->fresh()]);
    }
}
