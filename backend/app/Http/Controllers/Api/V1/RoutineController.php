<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RoutineTask;
use App\Services\AuditRecorder;
use App\Services\RoutineGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RoutineController extends Controller
{
    public function __construct(private readonly AuditRecorder $audit, private readonly RoutineGenerator $generator) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'scope' => ['nullable', Rule::in(['mine', 'all'])],
        ]);
        $date = $data['date'] ?? CarbonImmutable::today(config('sistema_dez.timezone'))->toDateString();
        $scopeAll = ($data['scope'] ?? 'mine') === 'all' && $request->user()->hasPermission('routine.manage');
        abort_unless(($data['scope'] ?? 'mine') === 'all' || $scopeAll || ($data['scope'] ?? 'mine') === 'mine', 403);

        $tasks = RoutineTask::query()->with(['assignee:id,name', 'doneBy:id,name'])
            ->whereDate('due_date', $date)
            ->when(! $scopeAll, fn ($query) => $query->where(fn ($inner) => $inner
                ->whereNull('assigned_to')->orWhere('assigned_to', $request->user()->id)))
            ->orderBy('type')->orderBy('title')->get();

        return response()->json(['data' => [
            'date' => $date,
            'scope' => $scopeAll ? 'all' : 'mine',
            'tasks' => $tasks->map(fn (RoutineTask $task): array => $this->serialize($task))->all(),
        ]]);
    }

    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = CarbonImmutable::parse($data['date'] ?? CarbonImmutable::today(config('sistema_dez.timezone'))->toDateString());
        $created = $this->generator->generate($date);

        return response()->json(['data' => ['created' => $created, 'date' => $date->toDateString()]], 201);
    }

    public function tick(Request $request, RoutineTask $routineTask): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        $task = DB::transaction(function () use ($request, $routineTask, $data): RoutineTask {
            $task = RoutineTask::query()->lockForUpdate()->findOrFail($routineTask->id);
            if ($task->status !== 'PENDENTE') {
                throw ValidationException::withMessages(['task' => 'Esta rotina já foi concluída.']);
            }
            $before = $task->attributesToArray();
            $task->forceFill([
                'status' => 'FEITO',
                'note' => $data['note'] ?? null,
                'done_by' => $request->user()->id,
                'done_at' => now(),
            ])->save();
            $this->audit->record($request, $task, 'routine.ticked', $before, $task->attributesToArray());

            return $task;
        }, 3);

        return response()->json(['data' => $this->serialize($task)]);
    }

    public function reopen(Request $request, RoutineTask $routineTask): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:1000']]);

        $task = DB::transaction(function () use ($request, $routineTask, $data): RoutineTask {
            $task = RoutineTask::query()->lockForUpdate()->findOrFail($routineTask->id);
            if ($task->status !== 'FEITO') {
                throw ValidationException::withMessages(['task' => 'Somente rotinas concluídas podem ser reabertas.']);
            }
            $before = $task->attributesToArray();
            $task->forceFill(['status' => 'PENDENTE', 'note' => $data['note'], 'done_by' => null, 'done_at' => null])->save();
            $this->audit->record($request, $task, 'routine.reopened', $before, $task->attributesToArray());

            return $task;
        }, 3);

        return response()->json(['data' => $this->serialize($task)]);
    }

    /** @return array<string, mixed> */
    private function serialize(RoutineTask $task): array
    {
        return [
            'id' => $task->id,
            'type' => $task->type,
            'title' => $task->title,
            'detail' => $task->detail,
            'ref_type' => $task->ref_type,
            'ref_id' => $task->ref_id,
            'assigned_to' => $task->assigned_to,
            'assignee_name' => $task->assignee?->name,
            'due_date' => $task->due_date instanceof CarbonImmutable ? $task->due_date->toDateString() : (string) $task->due_date,
            'status' => $task->status,
            'note' => $task->note,
            'done_by' => $task->done_by,
            'done_by_name' => $task->doneBy?->name,
            'done_at' => $task->done_at?->toIso8601String(),
        ];
    }
}
