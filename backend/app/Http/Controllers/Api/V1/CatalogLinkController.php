<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SaveCatalogLinkRequest;
use App\Models\Agreement;
use App\Models\CatalogLink;
use App\Models\Procedure;
use App\Models\Professional;
use App\Models\Unit;
use App\Services\AuditRecorder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CatalogLinkController extends Controller
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = CatalogLink::query()->with(['unit', 'procedure', 'professional', 'agreement'])
            ->when($request->has('active'), fn (Builder $query) => $query->where('active', $request->boolean('active')))
            ->when($request->filled('q'), function (Builder $query) use ($request): void {
                $term = '%'.trim((string) $request->string('q')).'%';
                $query->where(function (Builder $query) use ($term): void {
                    $query->whereHas('procedure', fn (Builder $query) => $query->where('name', 'ilike', $term))
                        ->orWhereHas('professional', fn (Builder $query) => $query->where('name', 'ilike', $term));
                });
            })
            ->orderByDesc('updated_at');
        $page = $query->paginate($request->integer('per_page', 25));

        return response()->json([
            'data' => collect($page->items())->map($this->serialize(...))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function store(SaveCatalogLinkRequest $request): JsonResponse
    {
        $link = CatalogLink::create($request->safe()->except('relationship'));
        $this->audit->record($request, $link, 'created', null, $link->attributesToArray());

        return response()->json(['data' => $this->serialize($link->load(['unit', 'procedure', 'professional', 'agreement']))], Response::HTTP_CREATED);
    }

    public function update(SaveCatalogLinkRequest $request, CatalogLink $catalogLink): JsonResponse
    {
        $before = $catalogLink->attributesToArray();
        $catalogLink->update($request->safe()->except('relationship'));
        $this->audit->record($request, $catalogLink, 'updated', $before, $catalogLink->fresh()->attributesToArray());

        return response()->json(['data' => $this->serialize($catalogLink->fresh()->load(['unit', 'procedure', 'professional', 'agreement']))]);
    }

    public function destroy(Request $request, CatalogLink $catalogLink): JsonResponse
    {
        $before = $catalogLink->attributesToArray();
        $catalogLink->update(['active' => false]);
        $this->audit->record($request, $catalogLink, 'deactivated', $before, $catalogLink->fresh()->attributesToArray());

        return response()->json(['data' => $this->serialize($catalogLink->fresh()->load(['unit', 'procedure', 'professional', 'agreement']))]);
    }

    public function options(): JsonResponse
    {
        $option = fn (Builder $query) => $query->where('active', true)->orderBy('name')->get(['id', 'name', 'legacy_id']);

        return response()->json(['data' => [
            'units' => $option(Unit::query()),
            'procedures' => $option(Procedure::query()),
            'professionals' => $option(Professional::query()),
            'agreements' => $option(Agreement::query()),
        ]]);
    }

    /** @return array<string, mixed> */
    private function serialize(CatalogLink $link): array
    {
        $entity = fn ($model) => $model ? ['id' => $model->id, 'name' => $model->name, 'legacy_id' => $model->legacy_id] : null;

        return [
            'id' => $link->id,
            'unit' => $entity($link->unit),
            'procedure' => $entity($link->procedure),
            'professional' => $entity($link->professional),
            'agreement' => $entity($link->agreement),
            'active' => $link->active,
            'updated_at' => $link->updated_at,
        ];
    }
}
