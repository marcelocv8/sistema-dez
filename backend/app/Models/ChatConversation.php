<?php

namespace App\Models;

use Database\Factories\ChatConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['type', 'title', 'unit_id', 'created_by'])]
class ChatConversation extends Model
{
    /** @use HasFactory<ChatConversationFactory> */
    use HasFactory, HasUuids;

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'chat_participants', 'chat_conversation_id', 'user_id')
            ->withPivot(['last_read_at', 'created_at']);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class)->orderBy('created_at');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function unreadCountFor(int $userId): int
    {
        return self::unreadCountsFor($userId, [$this->id])[$this->id] ?? 0;
    }

    /**
     * Unread counts for several conversations in a single grouped query,
     * keyed by conversation id. Replaces the previous per-conversation
     * (one pivot lookup + one count) round-trip.
     *
     * @param  iterable<int, string>  $conversationIds
     * @return array<string, int>
     */
    public static function unreadCountsFor(int $userId, iterable $conversationIds): array
    {
        $ids = collect($conversationIds)->all();
        if ($ids === []) {
            return [];
        }

        return ChatMessage::query()
            ->join('chat_participants', function ($join) use ($userId): void {
                $join->on('chat_participants.chat_conversation_id', '=', 'chat_messages.chat_conversation_id')
                    ->where('chat_participants.user_id', '=', $userId);
            })
            ->whereIn('chat_messages.chat_conversation_id', $ids)
            ->where('chat_messages.user_id', '!=', $userId)
            ->where(fn ($query) => $query->whereNull('chat_participants.last_read_at')
                ->orWhereColumn('chat_messages.created_at', '>', 'chat_participants.last_read_at'))
            ->groupBy('chat_messages.chat_conversation_id')
            ->selectRaw('chat_messages.chat_conversation_id as conversation_id, count(*) as aggregate')
            ->pluck('aggregate', 'conversation_id')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }
}
