<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ImportIssueResolutionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ResolveImportIssueRequest;
use App\Models\StgImportIssue;
use App\Models\StgImportRun;
use App\Services\AuditRecorder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ImportIssueController extends Controller
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::in(ImportIssueResolutionStatus::values())],
            'entity_type' => ['nullable', 'string', 'max:50'],
            'code' => ['nullable', 'string', 'max:80'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $latestRunId = StgImportRun::query()->latest('started_at')->value('id');
        $query = StgImportIssue::query()->where('import_run_id', $latestRunId)
            ->when($request->filled('status'), fn (Builder $query) => $query->where('resolution_status', $request->string('status')))
            ->when($request->filled('entity_type'), fn (Builder $query) => $query->where('entity_type', $request->string('entity_type')))
            ->when($request->filled('code'), fn (Builder $query) => $query->where('code', $request->string('code')))
            ->orderBy('code')->orderBy('entity_type')->orderBy('id');
        $page = $query->paginate($request->integer('per_page', 25));

        return response()->json([
            'data' => collect($page->items())->map($this->serialize(...))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'import_run_id' => $latestRunId],
        ]);
    }

    public function update(ResolveImportIssueRequest $request, StgImportIssue $importIssue): JsonResponse
    {
        $before = $importIssue->attributesToArray();
        $status = $request->string('resolution_status')->toString();
        $importIssue->update([
            'resolution_status' => $status,
            'resolution_note' => $request->input('resolution_note'),
            'resolved_by' => $status === 'OPEN' ? null : $request->user()->getAuthIdentifier(),
            'resolved_at' => $status === 'OPEN' ? null : now(),
        ]);
        $this->audit->record($request, $importIssue, 'resolution_updated', $before, $importIssue->fresh()->attributesToArray());

        return response()->json(['data' => $this->serialize($importIssue->fresh())]);
    }

    /** @return array<string, mixed> */
    private function serialize(StgImportIssue $issue): array
    {
        return $issue->only(['id', 'import_run_id', 'code', 'entity_type', 'legacy_id', 'source_file', 'payload', 'resolution_status', 'resolution_note', 'resolved_by', 'resolved_at', 'created_at']);
    }
}
