<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'entity_type' => ['nullable', 'string', 'max:255'],
            'action' => ['nullable', 'string', 'max:80'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = AuditLog::query()
            ->when($request->filled('entity_type'), fn (Builder $query) => $query->where('entity_type', $request->string('entity_type')))
            ->when($request->filled('action'), fn (Builder $query) => $query->where('action', $request->string('action')))
            ->latest('created_at')->paginate($request->integer('per_page', 50));

        return response()->json([
            'data' => $page->items(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }
}
