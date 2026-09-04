<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListCatalogRequest;
use App\Http\Requests\Api\V1\SaveCatalogRequest;
use App\Models\Agreement;
use App\Models\Procedure;
use App\Models\Professional;
use App\Models\Unit;
use App\Services\AuditRecorder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class CatalogController extends Controller
{
    /** @var array<string, class-string<Model>> */
    private const MODELS = [
        'units' => Unit::class,
        'agreements' => Agreement::class,
        'procedures' => Procedure::class,
        'professionals' => Professional::class,
    ];

    public function __construct(private readonly AuditRecorder $audit) {}

    public function index(ListCatalogRequest $request, string $resource): JsonResponse
    {
        $model = $this->modelClass($resource);
        $query = $model::query()->orderBy('name')->orderBy('id');

        if ($request->filled('q')) {
            $term = Str::lower(Str::ascii(trim((string) $request->string('q'))));
            $query->where(function (Builder $query) use ($resource, $term): void {
                $nameColumn = $resource === 'units' ? 'name' : 'search_name';
                $query->whereRaw("lower({$nameColumn}) like ?", ['%'.$term.'%'])
                    ->orWhereLike('legacy_id', '%'.$term.'%', caseSensitive: false);
            });
        }
        if ($request->has('active')) {
            $query->where('active', $request->boolean('active'));
        }

        $page = $query->paginate($request->integer('per_page', 25));

        return response()->json([
            'data' => collect($page->items())->map(fn (Model $item) => $this->serialize($resource, $item))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(string $resource, string $id): JsonResponse
    {
        $item = $this->find($resource, $id);

        return response()->json(['data' => $this->serialize($resource, $item)]);
    }

    public function store(SaveCatalogRequest $request, string $resource): JsonResponse
    {
        $model = $this->modelClass($resource);
        $attributes = $this->attributes($resource, $request->validated());

        $item = DB::transaction(function () use ($request, $resource, $model, $attributes): Model {
            $this->maintainDefaultAgreement($resource, $attributes);
            $item = $model::create($attributes);
            $this->audit->record($request, $item, 'created', null, $item->attributesToArray());

            return $item;
        });

        return response()->json(['data' => $this->serialize($resource, $item)], Response::HTTP_CREATED);
    }

    public function update(SaveCatalogRequest $request, string $resource, string $id): JsonResponse
    {
        $item = $this->find($resource, $id);
        $attributes = $this->attributes($resource, $request->validated());

        DB::transaction(function () use ($request, $resource, $item, $attributes): void {
            $before = $item->attributesToArray();
            $this->guardDefaultAgreement($resource, $item, $attributes);
            $this->maintainDefaultAgreement($resource, $attributes, $item->getKey());
            $item->update($attributes);
            $this->audit->record($request, $item, 'updated', $before, $item->fresh()->attributesToArray());
        });

        return response()->json(['data' => $this->serialize($resource, $item->fresh())]);
    }

    public function destroy(Request $request, string $resource, string $id): JsonResponse
    {
        $item = $this->find($resource, $id);
        $this->guardDefaultAgreement($resource, $item, ['is_default' => false, 'active' => false]);
        $before = $item->attributesToArray();
        $item->update(['active' => false]);
        $this->audit->record($request, $item, 'deactivated', $before, $item->fresh()->attributesToArray());

        return response()->json(['data' => $this->serialize($resource, $item->fresh())]);
    }

    /** @return class-string<Model> */
    private function modelClass(string $resource): string
    {
        abort_unless(isset(self::MODELS[$resource]), Response::HTTP_NOT_FOUND);

        return self::MODELS[$resource];
    }

    private function find(string $resource, string $id): Model
    {
        return $this->modelClass($resource)::query()->findOrFail($id);
    }

    /** @param array<string, mixed> $attributes @return array<string, mixed> */
    private function attributes(string $resource, array $attributes): array
    {
        $attributes['name'] = trim((string) $attributes['name']);
        if ($resource !== 'units') {
            $attributes['search_name'] = Str::lower(Str::ascii($attributes['name']));
            $attributes['classification_status'] ??= 'MANUAL';
        }

        return $attributes;
    }

    /** @param array<string, mixed> $attributes */
    private function maintainDefaultAgreement(string $resource, array $attributes, mixed $except = null): void
    {
        if ($resource !== 'agreements' || ! ($attributes['is_default'] ?? false)) {
            return;
        }

        if (! ($attributes['active'] ?? true)) {
            throw ValidationException::withMessages(['active' => 'O convênio padrão precisa permanecer ativo.']);
        }

        Agreement::query()->when($except, fn (Builder $query) => $query->whereKeyNot($except))->update(['is_default' => false]);
    }

    /** @param array<string, mixed> $attributes */
    private function guardDefaultAgreement(string $resource, Model $item, array $attributes): void
    {
        if ($resource !== 'agreements' || ! $item->getAttribute('is_default')) {
            return;
        }

        if (($attributes['is_default'] ?? true) && ($attributes['active'] ?? true)) {
            return;
        }

        throw ValidationException::withMessages(['is_default' => 'Defina outro convênio padrão antes de alterar ou desativar este registro.']);
    }

    /** @return array<string, mixed> */
    private function serialize(string $resource, Model $item): array
    {
        $fields = match ($resource) {
            'units' => ['id', 'legacy_id', 'name', 'timezone', 'cnpj', 'active', 'operational_enabled', 'created_at', 'updated_at'],
            'agreements' => ['id', 'legacy_id', 'name', 'type', 'is_default', 'online_enabled', 'requires_authorization', 'active', 'classification_status', 'created_at', 'updated_at'],
            'procedures' => ['id', 'legacy_id', 'name', 'type', 'group_name', 'tuss_code', 'duration_minutes', 'active', 'legacy', 'online_enabled', 'classification_status', 'created_at', 'updated_at'],
            'professionals' => ['id', 'legacy_id', 'name', 'council_acronym', 'council_number', 'commission_type', 'commission_value', 'user_id', 'active', 'online_enabled', 'classification_status', 'created_at', 'updated_at'],
        };

        return collect($item->only($fields))->map(fn (mixed $value) => $value instanceof \BackedEnum ? $value->value : $value)->all();
    }
}
