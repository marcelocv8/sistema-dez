<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\AttendanceEvent;
use App\Services\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class AttendanceController extends Controller
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);
        $from = CarbonImmutable::createFromFormat('Y-m-d', $data['from'], config('sistema_dez.timezone'))->startOfDay();
        $to = CarbonImmutable::createFromFormat('Y-m-d', $data['to'], config('sistema_dez.timezone'))->endOfDay();
        abort_if($from->diffInDays($to) > 92, 422, 'Consulte no máximo 93 dias.');

        $events = AttendanceEvent::query()->with('user:id,name,role')
            ->when($data['user_id'] ?? null, fn ($query, $id) => $query->where('user_id', $id))
            ->whereBetween('logged_in_at', [$from->utc(), $to->utc()])
            ->orderBy('logged_in_at')->get();

        $exitHour = AttendanceEvent::exitHour();
        $grouped = $events->groupBy(fn (AttendanceEvent $event): string => $event->user_id.'|'.$event->service_date);
        $rows = $grouped->map(fn ($dayEvents) => $this->serializeDay($dayEvents, $exitHour))->sortByDesc('date')->values();

        return response()->json(['data' => [
            'exit_hour' => $exitHour,
            'days' => $rows->all(),
        ]]);
    }

    public function export(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);
        $from = CarbonImmutable::createFromFormat('Y-m-d', $data['from'], config('sistema_dez.timezone'))->startOfDay();
        $to = CarbonImmutable::createFromFormat('Y-m-d', $data['to'], config('sistema_dez.timezone'))->endOfDay();
        abort_if($from->diffInDays($to) > 92, 422, 'Consulte no máximo 93 dias.');

        $events = AttendanceEvent::query()->with('user:id,name')
            ->when($data['user_id'] ?? null, fn ($query, $id) => $query->where('user_id', $id))
            ->whereBetween('logged_in_at', [$from->utc(), $to->utc()])
            ->orderBy('logged_in_at')->get();
        $exitHour = AttendanceEvent::exitHour();

        $lines = ['data;usuario;perfis;entradas;primeira_entrada;ultima_entrada;relogins;relogin_apos_saida'];
        foreach ($events->groupBy(fn (AttendanceEvent $event): string => $event->user_id.'|'.$event->service_date) as $dayEvents) {
            $row = $this->serializeDay($dayEvents, $exitHour);
            $lines[] = implode(';', [
                $row['date'], $row['user_name'], implode('/', $row['roles']), count($row['logins']),
                $row['first_login'] ?? '', $row['last_login'] ?? '', $row['relogins'], $row['relogin_after_exit'] ? 'sim' : 'nao',
            ]);
        }

        return response()->json(['data' => ['filename' => "ponto-{$data['from']}_{$data['to']}.csv", 'csv' => implode("\n", $lines)]]);
    }

    public function showExitHour(): JsonResponse
    {
        return response()->json(['data' => ['exit_hour' => AttendanceEvent::exitHour()]]);
    }

    public function updateExitHour(Request $request): JsonResponse
    {
        $data = $request->validate(['exit_hour' => ['required', 'date_format:H:i']]);
        $before = AttendanceEvent::exitHour();
        AppSetting::set('attendance.exit_hour', $data['exit_hour'], $request->user()->id);
        $this->audit->record($request, new AppSetting, 'attendance.exit_hour_updated', ['value' => $before], ['value' => $data['exit_hour']]);

        return response()->json(['data' => ['exit_hour' => $data['exit_hour']]]);
    }

    /** @param  Collection<int, AttendanceEvent>  $dayEvents */
    private function serializeDay($dayEvents, string $exitHour): array
    {
        $first = $dayEvents->first();
        $user = $first->user;
        $logins = $dayEvents->map(fn (AttendanceEvent $event): string => $event->logged_in_at->setTimezone(config('sistema_dez.timezone'))->format('H:i'))->values()->all();
        // Relogin após a saída: um novo login depois do horário fechado, além da primeira entrada.
        $reloginAfterExit = count($logins) > 1 && (bool) collect(array_slice($logins, 1))->first(fn (string $login): bool => $login > $exitHour);

        return [
            'user_id' => (int) $first->user_id,
            'user_name' => $user?->name,
            'roles' => [$user?->role?->label()],
            'date' => $first->service_date->toDateString(),
            'logins' => $logins,
            'first_login' => $logins[0] ?? null,
            'last_login' => $logins === [] ? null : $logins[count($logins) - 1],
            'relogins' => max(0, count($logins) - 1),
            'relogin_after_exit' => $reloginAfterExit,
            'exit_hour' => $exitHour,
        ];
    }
}
