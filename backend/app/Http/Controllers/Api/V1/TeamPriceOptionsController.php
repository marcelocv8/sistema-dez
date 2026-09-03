<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Agreement;
use App\Models\Procedure;
use Illuminate\Http\JsonResponse;

class TeamPriceOptionsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $agreements = Agreement::query()
            ->where('active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'legacy_id', 'name', 'is_default']);

        $groups = Procedure::query()
            ->where('active', true)
            ->whereNotNull('group_name')
            ->select(['type', 'group_name'])
            ->distinct()
            ->orderBy('type')
            ->orderBy('group_name')
            ->get()
            ->groupBy(fn (Procedure $procedure): string => $procedure->type->value)
            ->map(fn ($items): array => $items->pluck('group_name')->values()->all());

        return new JsonResponse(['data' => [
            'agreements' => $agreements,
            'groups' => [
                'CONSULTATION' => $groups->get('CONSULTATION', []),
                'EXAM' => $groups->get('EXAM', []),
            ],
        ]]);
    }
}
