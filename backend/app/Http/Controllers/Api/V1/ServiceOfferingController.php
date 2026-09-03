<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Scheduling\MaterializeScheduleOccurrences;
use App\Actions\Scheduling\RefreshOfferingVisibility;
use App\Enums\BookingMode;
use App\Enums\Modality;
use App\Http\Controllers\Controller;
use App\Models\CatalogLink;
use App\Models\ProcedurePrice;
use App\Models\ServiceOffering;
use App\Models\Unit;
use App\Services\AuditRecorder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ServiceOfferingController extends Controller
{
    public function __construct(private readonly AuditRecorder $audit, private readonly MaterializeScheduleOccurrences $materialize, private readonly RefreshOfferingVisibility $visibility) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['unit_id' => ['nullable', 'uuid'], 'professional_id' => ['nullable', 'uuid']]);
        $offerings = ServiceOffering::query()->with(['unit', 'procedure', 'professional', 'agreement', 'scheduleWindows', 'scheduleExceptions'])
            ->whereHas('unit', fn ($query) => $query->where('operational_enabled', true))
            ->when($request->unit_id, fn ($query, $id) => $query->where('unit_id', $id))
            ->when($request->professional_id, fn ($query, $id) => $query->where('professional_id', $id))
            ->orderBy('created_at')->get();

        return response()->json(['data' => $offerings]);
    }

    public function options(Request $request): JsonResponse
    {
        $request->validate(['catalog_link_id' => ['nullable', 'uuid']]);
        $links = CatalogLink::query()->where('active', true)->with(['unit', 'procedure', 'professional', 'agreement'])
            ->whereHas('unit', fn ($query) => $query->where('active', true)->where('operational_enabled', true))
            ->whereHas('procedure', fn ($query) => $query->where('active', true))
            ->whereHas('professional', fn ($query) => $query->where('active', true))
            ->whereHas('agreement', fn ($query) => $query->where('active', true))->get();
        $selected = $links->firstWhere('id', $request->catalog_link_id);
        $prices = $selected ? $this->pricesFor($selected)->orderBy('nature')->orderByDesc('valid_from')->get() : [];

        return response()->json(['data' => [
            'units' => Unit::query()->where('active', true)->where('operational_enabled', true)->orderBy('name')->get(),
            'links' => $links, 'prices' => $prices,
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->save($request, new ServiceOffering);
    }

    public function update(Request $request, ServiceOffering $serviceOffering): JsonResponse
    {
        return $this->save($request, $serviceOffering);
    }

    private function save(Request $request, ServiceOffering $offering): JsonResponse
    {
        $data = $request->validate([
            'catalog_link_id' => ['required', 'uuid'], 'procedure_price_id' => ['required', 'uuid'],
            'booking_mode' => ['required', Rule::enum(BookingMode::class)], 'modality' => ['required', Rule::enum(Modality::class)],
            'duration_override_minutes' => ['nullable', 'integer', 'min:1', 'max:480'],
            'daily_capacity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'arrival_block_minutes' => ['required', 'integer', 'min:1', 'max:480'],
            'arrival_block_capacity' => ['required', 'integer', 'min:1', 'max:1000'],
            'active' => ['required', 'boolean'],
        ]);
        $created = ! $offering->exists;
        $offering = DB::transaction(function () use ($request, $data, $offering, $created): ServiceOffering {
            $link = CatalogLink::query()->with(['unit', 'procedure', 'professional', 'agreement'])->lockForUpdate()->findOrFail($data['catalog_link_id']);
            if (! $link->active || ! $link->unit->active || ! $link->unit->operational_enabled || ! $link->procedure->active
                || ! $link->professional?->active || ! $link->agreement->active) {
                throw ValidationException::withMessages(['catalog_link_id' => 'O vínculo e suas entidades precisam estar ativos em uma unidade operacional.']);
            }
            $price = $this->pricesFor($link)->whereKey($data['procedure_price_id'])->first();
            if (! $price) {
                throw ValidationException::withMessages(['procedure_price_id' => 'Escolha um preço vigente e válido compatível com o vínculo.']);
            }
            if ($offering->exists) {
                $offering = ServiceOffering::query()->lockForUpdate()->findOrFail($offering->id);
                if ($offering->catalog_link_id !== $link->id) {
                    throw ValidationException::withMessages(['catalog_link_id' => 'Crie outra oferta para um vínculo diferente.']);
                }
            }
            $before = $offering->exists ? $offering->attributesToArray() : null;
            $offering->fill([...$data, 'unit_id' => $link->unit_id, 'procedure_id' => $link->procedure_id,
                'professional_id' => $link->professional_id, 'agreement_id' => $link->agreement_id,
                'price' => $price->amount])->save();
            if ($created) {
                $offering->forceFill(['online_paused' => false])->save();
            }
            $this->materialize->handle($offering);
            $offering = $this->visibility->handle($offering);
            $this->audit->record($request, $offering, $before ? 'updated' : 'created', $before, $offering->attributesToArray());

            return $offering;
        }, 3);

        return response()->json(['data' => $offering->load(['unit', 'procedure', 'professional', 'agreement', 'scheduleWindows', 'scheduleExceptions'])], $created ? 201 : 200);
    }

    public function destroy(Request $request, ServiceOffering $serviceOffering): JsonResponse
    {
        DB::transaction(function () use ($request, $serviceOffering): void {
            $offering = ServiceOffering::query()->lockForUpdate()->findOrFail($serviceOffering->id);
            $before = $offering->attributesToArray();
            $offering->update(['active' => false, 'online_enabled' => false]);
            $this->materialize->handle($offering);
            $this->audit->record($request, $offering, 'deactivated', $before, $offering->attributesToArray());
        });

        return response()->json(['data' => $serviceOffering->fresh()]);
    }

    private function pricesFor(CatalogLink $link): Builder
    {
        return ProcedurePrice::query()->where('procedure_id', $link->procedure_id)->where('agreement_id', $link->agreement_id)
            ->where(fn ($query) => $query->whereNull('unit_id')->orWhere('unit_id', $link->unit_id))
            ->where('status', 'VALID')->where(fn ($query) => $query->whereNull('valid_from')->orWhere('valid_from', '<=', today()->toDateString()))
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>=', today()->toDateString()));
    }
}
