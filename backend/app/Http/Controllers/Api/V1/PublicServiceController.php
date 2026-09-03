<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListPublicServicesRequest;
use App\Http\Resources\Api\V1\PublicServiceResource;
use App\Models\Procedure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PublicServiceController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(ListPublicServicesRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $offeringFilter = function ($query) use ($filters): void {
            $query->publiclyAvailable()
                ->when($filters['unit_id'] ?? null, fn (Builder $query, string $id) => $query->where('unit_id', $id))
                ->when($filters['agreement_id'] ?? null, fn (Builder $query, string $id) => $query->where('agreement_id', $id))
                ->when($filters['modality'] ?? null, fn (Builder $query, string $modality) => $query->where('modality', $modality));
        };

        $procedures = Procedure::query()
            ->where('active', true)
            ->where('online_enabled', true)
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('type', $type))
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->whereLike('name', "%{$search}%"))
            ->whereHas('serviceOfferings', $offeringFilter)
            ->with(['serviceOfferings' => fn ($query) => $offeringFilter($query->with(['unit', 'professional', 'agreement']))])
            ->orderBy('name')
            ->get();

        return PublicServiceResource::collection($procedures);
    }
}
