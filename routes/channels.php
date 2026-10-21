<?php

use App\Enums\AccountStatus;
use App\Models\Conversation;
use App\Models\User;
use App\Services\ConversationService;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('users.{userId}', function (User $user, int $userId): bool {
    return (int) $user->id === $userId;
});

Broadcast::channel('conversations.{conversation}', function (User $user, Conversation $conversation): bool {
    return $conversation->participants()
        ->whereKey($user->id)
        ->exists();
});

/*
 * Presence is conversation-scoped so subscribers only see the participants
 * of a thread they can already open. The service applies the same seller
 * Store check used by the seller message endpoints and returns only the
 * minimum identity fields consumed by the online indicator.
 */
Broadcast::channel('conversation-presence.{conversation}', function (User $user, Conversation $conversation): array|bool {
    return app(ConversationService::class)->presenceMemberFor($conversation, $user) ?? false;
});

Broadcast::channel('announcements.{role}', function (User $user, string $role): bool {
    return $user->status === AccountStatus::Active->value
        && in_array($role, ['buyer', 'seller', 'logistics', 'rider'], true)
        && $user->hasRole($role);
});
