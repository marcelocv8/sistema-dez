<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SaveTeamUserRequest;
use App\Models\User;
use App\Services\AuditRecorder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class TeamUserController extends Controller
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100'], 'active' => ['nullable', 'boolean']]);
        $users = User::query()
            ->when($request->filled('q'), function (Builder $query) use ($request): void {
                $term = '%'.trim((string) $request->string('q')).'%';
                $query->where(fn (Builder $query) => $query->where('name', 'ilike', $term)->orWhere('email', 'ilike', $term));
            })
            ->when($request->has('active'), fn (Builder $query) => $query->where('active', $request->boolean('active')))
            ->orderBy('name')->get();

        return response()->json(['data' => $users->map($this->serialize(...))->values()]);
    }

    public function store(SaveTeamUserRequest $request): JsonResponse
    {
        $user = User::create($request->validated());
        $this->audit->record($request, $user, 'created', null, $this->auditable($user));

        return response()->json(['data' => $this->serialize($user)], Response::HTTP_CREATED);
    }

    public function update(SaveTeamUserRequest $request, User $teamUser): JsonResponse
    {
        $attributes = $request->validated();
        if (blank($attributes['password'] ?? null)) {
            unset($attributes['password']);
        }
        $this->guardAdminContinuity($request, $teamUser, $attributes);
        $before = $this->auditable($teamUser);
        $teamUser->update($attributes);
        $this->audit->record($request, $teamUser, 'updated', $before, $this->auditable($teamUser->fresh()));

        return response()->json(['data' => $this->serialize($teamUser->fresh())]);
    }

    public function destroy(Request $request, User $teamUser): JsonResponse
    {
        $this->guardAdminContinuity($request, $teamUser, ['active' => false, 'role' => $teamUser->role->value]);
        $before = $this->auditable($teamUser);
        $teamUser->update(['active' => false]);
        $this->audit->record($request, $teamUser, 'deactivated', $before, $this->auditable($teamUser->fresh()));

        return response()->json(['data' => $this->serialize($teamUser->fresh())]);
    }

    /** @param array<string, mixed> $attributes */
    private function guardAdminContinuity(Request $request, User $user, array $attributes): void
    {
        if ($request->user()?->is($user) && (! ($attributes['active'] ?? true) || ($attributes['role'] ?? null) !== UserRole::Admin->value)) {
            throw ValidationException::withMessages(['role' => 'Você não pode remover o próprio acesso administrativo.']);
        }

        if ($user->role === UserRole::Admin && (! ($attributes['active'] ?? true) || ($attributes['role'] ?? null) !== UserRole::Admin->value)) {
            $hasAnotherAdmin = User::query()->whereKeyNot($user->getKey())->where('role', UserRole::Admin->value)->where('active', true)->exists();
            if (! $hasAnotherAdmin) {
                throw ValidationException::withMessages(['role' => 'É obrigatório manter ao menos um administrador ativo.']);
            }
        }
    }

    /** @return array<string, mixed> */
    private function serialize(User $user): array
    {
        return $this->auditable($user) + ['role_label' => $user->role->label(), 'permissions' => $user->role->permissions(), 'updated_at' => $user->updated_at];
    }

    /** @return array<string, mixed> */
    private function auditable(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role->value, 'active' => $user->active];
    }
}
