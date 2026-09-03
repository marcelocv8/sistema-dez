<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PriceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListTeamPricesRequest;
use App\Models\Agreement;
use App\Models\ProcedurePrice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class TeamPriceController extends Controller
{
    public function __invoke(ListTeamPricesRequest $request): JsonResponse
    {
        $agreementId = $request->string('agreement_id')->toString();

        if ($agreementId === '') {
            $agreementId = Agreement::query()->where('is_default', true)->where('active', true)->value('id');
        }

        if (! is_string($agreementId) || $agreementId === '') {
            return new JsonResponse(['message' => 'Convênio Particular padrão não configurado.'], 409);
        }

        $rawSearch = Str::of($request->string('q')->toString())->squish()->toString();
        $normalizedSearch = Str::of($rawSearch)->ascii()->upper()->toString();
        $type = $request->string('type')->toString();
        $group = $request->string('group')->toString();

        $prices = ProcedurePrice::query()
            ->with(['procedure:id,legacy_id,name,type,group_name,tuss_code', 'agreement:id,legacy_id,name', 'unit:id,legacy_id,name'])
            ->where('agreement_id', $agreementId)
            ->where('status', PriceStatus::Valid)
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('valid_from')
                ->orWhereDate('valid_from', '<=', today()))
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('valid_until')
                ->orWhereDate('valid_until', '>=', today()))
            ->whereHas('agreement', fn (Builder $query): Builder => $query->where('active', true))
            ->whereHas('procedure', function (Builder $query) use ($normalizedSearch, $rawSearch, $type, $group): void {
                $query->where('active', true)
                    ->where(function (Builder $search) use ($normalizedSearch, $rawSearch): void {
                        $search->where('search_name', 'like', '%'.$normalizedSearch.'%')
                            ->orWhere('tuss_code', 'like', '%'.$rawSearch.'%')
                            ->orWhere('legacy_id', 'like', '%'.$rawSearch.'%');
                    })
                    ->when($type !== '', fn (Builder $filter): Builder => $filter->where('type', $type))
                    ->when($group !== '', fn (Builder $filter): Builder => $filter->where('group_name', $group));
            })
            ->join('procedures', 'procedures.id', '=', 'procedure_prices.procedure_id')
            ->orderBy('procedures.type')
            ->orderBy('procedures.group_name')
            ->orderBy('procedures.name')
            ->orderBy('procedures.legacy_id')
            ->orderBy('procedure_prices.unit_id')
            ->select('procedure_prices.*')
            ->limit(200)
            ->get();

        $types = $prices->groupBy(fn (ProcedurePrice $price): string => $price->procedure->type->value)
            ->map(function ($typePrices, string $type): array {
                $groups = $typePrices->groupBy(fn (ProcedurePrice $price): string => $price->procedure->group_name ?: 'SEM GRUPO')
                    ->map(function ($groupPrices, string $groupName): array {
                        return [
                            'group_name' => $groupName,
                            'items' => $groupPrices->map(fn (ProcedurePrice $price): array => [
                                'id' => $price->id,
                                'procedure' => [
                                    'id' => $price->procedure->id,
                                    'legacy_id' => $price->procedure->legacy_id,
                                    'tuss_code' => $price->procedure->tuss_code,
                                    'name' => $price->procedure->name,
                                ],
                                'agreement' => [
                                    'id' => $price->agreement->id,
                                    'legacy_id' => $price->agreement->legacy_id,
                                    'name' => $price->agreement->name,
                                ],
                                'unit' => $price->unit === null ? null : [
                                    'id' => $price->unit->id,
                                    'legacy_id' => $price->unit->legacy_id,
                                    'name' => $price->unit->name,
                                ],
                                'nature' => $price->nature,
                                'amount' => $price->amount,
                            ])->values(),
                        ];
                    })->values();

                return ['type' => $type, 'groups' => $groups];
            })->values();

        return new JsonResponse(['data' => [
            'agreement_id' => $agreementId,
            'query' => $rawSearch,
            'types' => $types,
        ]]);
    }
}
