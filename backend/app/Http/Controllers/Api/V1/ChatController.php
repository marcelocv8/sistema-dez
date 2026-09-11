<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Patient;
use App\Models\User;
use App\Services\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ChatController extends Controller
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function conversations(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $conversations = ChatConversation::query()
            ->whereHas('participants', fn ($query) => $query->whereKey($userId))
            ->with(['participants:id,name,role'])
            ->orderByDesc('updated_at')->limit(100)->get();
        $unread = ChatConversation::unreadCountsFor($userId, $conversations->modelKeys());

        return response()->json(['data' => $conversations->map(fn (ChatConversation $conversation): array => [
            'id' => $conversation->id,
            'type' => $conversation->type,
            'title' => $conversation->title,
            'participants' => $conversation->participants->map(fn (User $user): array => [
                'id' => (int) $user->id, 'name' => $user->name, 'role' => $user->role?->value,
            ])->all(),
            'last_message_at' => $conversation->updated_at?->toIso8601String(),
            'unread' => $unread[$conversation->id] ?? 0,
        ])->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['DIRECT', 'GROUP'])],
            'title' => ['nullable', 'string', 'max:120', 'required_if:type,GROUP'],
            'participant_ids' => ['required', 'array', 'min:1', 'max:30'],
            'participant_ids.*' => ['required', 'integer', 'exists:users,id', 'distinct'],
        ]);

        $userId = (int) $request->user()->id;
        $participantIds = array_values(array_unique([...array_map(intval(...), $data['participant_ids']), $userId]));

        $conversation = DB::transaction(function () use ($request, $data, $userId, $participantIds): ChatConversation {
            if ($data['type'] === 'DIRECT') {
                if (count($participantIds) !== 2) {
                    throw ValidationException::withMessages(['participant_ids' => 'Conversa 1:1 exige exatamente um participante além de você.']);
                }
                $existing = ChatConversation::query()
                    ->where('type', 'DIRECT')
                    ->has('participants', '=', 2)
                    ->whereHas('participants', fn ($query) => $query->whereKey($participantIds[0]))
                    ->whereHas('participants', fn ($query) => $query->whereKey($participantIds[1]))
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }
            $conversation = ChatConversation::query()->create([
                'type' => $data['type'],
                'title' => $data['type'] === 'GROUP' ? $data['title'] : null,
                'created_by' => $userId,
            ]);
            foreach ($participantIds as $participantId) {
                DB::table('chat_participants')->insert([
                    'id' => (string) Str::uuid(), 'chat_conversation_id' => $conversation->id, 'user_id' => $participantId,
                    'last_read_at' => null, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $this->audit->record($request, $conversation, 'chat.conversation_created', null, $conversation->attributesToArray());

            return $conversation;
        }, 3);

        return response()->json(['data' => ['id' => $conversation->id, 'type' => $conversation->type, 'title' => $conversation->title]], 201);
    }

    public function messages(Request $request, ChatConversation $chatConversation): JsonResponse
    {
        $userId = (int) $request->user()->id;
        abort_unless($chatConversation->participants()->whereKey($userId)->exists(), 403, 'Você não participa desta conversa.');
        $data = $request->validate(['after' => ['nullable', 'date'], 'before_id' => ['nullable', 'uuid']]);

        $before = isset($data['before_id']) ? $chatConversation->messages()->findOrFail($data['before_id']) : null;
        $messages = $chatConversation->messages()->with(['author:id,name', 'patient:id,name'])
            ->when($before, fn ($query) => $query->where(fn ($q) => $q
                ->where('created_at', '<', $before->getRawOriginal('created_at'))
                ->orWhere(fn ($q) => $q->where('created_at', $before->getRawOriginal('created_at'))->where('id', '<', $before->id))))
            ->when($data['after'] ?? null, fn ($query, $after) => $query->where('created_at', '>', CarbonImmutable::parse($after)))
            ->reorder()->orderBy('created_at', isset($data['after']) ? 'asc' : 'desc')
            ->orderBy('id', isset($data['after']) ? 'asc' : 'desc')->limit(200)->get();
        if (! isset($data['after'])) {
            $messages = $messages->reverse()->values();
        }

        return response()->json(['data' => $messages->map(fn (ChatMessage $message): array => [
            'id' => $message->id,
            'author' => ['id' => (int) $message->user_id, 'name' => $message->author?->name],
            'body' => $message->body,
            'created_at' => $message->created_at?->toISOString(),
            'patient' => $message->patient ? ['id' => $message->patient->id, 'name' => $message->patient->name] : null,
            'mine' => (int) $message->user_id === $userId,
        ])->all()]);
    }

    public function sendMessage(Request $request, ChatConversation $chatConversation): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:2000'],
            'patient_id' => ['nullable', 'uuid', 'exists:patients,id'],
            'appointment_id' => ['nullable', 'uuid', 'exists:appointments,id'],
        ]);
        $userId = (int) $request->user()->id;
        $participantIds = $chatConversation->participants()->pluck('users.id')->all();
        abort_unless(in_array($userId, $participantIds, true), 403, 'Você não participa desta conversa.');

        $message = DB::transaction(function () use ($data, $userId, $chatConversation): ChatMessage {
            $message = $chatConversation->messages()->create([
                'user_id' => $userId,
                'body' => $data['body'],
                'patient_id' => $data['patient_id'] ?? null,
                'appointment_id' => $data['appointment_id'] ?? null,
                'created_at' => now()->format('Y-m-d H:i:s.u'),
            ]);
            $chatConversation->participants()->updateExistingPivot($userId, ['last_read_at' => now()->format('Y-m-d H:i:s.u')]);
            $chatConversation->touch();

            return $message;
        }, 3);

        return response()->json(['data' => [
            'id' => $message->id, 'created_at' => $message->created_at?->toISOString(),
            'author' => ['id' => $userId, 'name' => $request->user()->name], 'body' => $message->body,
            'patient' => $message->patient?->only(['id', 'name']), 'mine' => true,
        ]], 201);
    }

    public function markRead(Request $request, ChatConversation $chatConversation): JsonResponse
    {
        $userId = (int) $request->user()->id;
        abort_unless($chatConversation->participants()->whereKey($userId)->exists(), 403, 'Você não participa desta conversa.');
        $chatConversation->participants()->updateExistingPivot($userId, ['last_read_at' => now()->format('Y-m-d H:i:s.u')]);

        return response()->json(['data' => ['ok' => true]]);
    }

    public function unread(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $conversationIds = ChatConversation::query()
            ->whereHas('participants', fn ($query) => $query->whereKey($userId))
            ->pluck('id');
        $total = array_sum(ChatConversation::unreadCountsFor($userId, $conversationIds));

        return response()->json(['data' => ['unread' => (int) $total]]);
    }

    public function participants(Request $request): JsonResponse
    {
        $users = User::query()->where('active', true)->whereKeyNot((int) $request->user()->id)
            ->orderBy('name')->get(['id', 'name', 'role']);

        return response()->json(['data' => $users->map(fn (User $user): array => [
            'id' => (int) $user->id, 'name' => $user->name, 'role' => $user->role?->value, 'role_label' => $user->role?->label(),
        ])->all()]);
    }

    public function lookupPatient(Request $request): JsonResponse
    {
        $request->merge(['cpf' => preg_replace('/\D/', '', (string) $request->input('cpf'))]);
        $data = $request->validate(['cpf' => ['required', 'digits:11']]);
        $hash = hash_hmac('sha256', $data['cpf'], (string) config('sistema_dez.identity_pepper'));
        $patient = Patient::query()->where('cpf_hash', $hash)->first();

        return response()->json(['data' => $patient?->only(['id', 'name'])]);
    }
}
