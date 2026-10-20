<?php

use App\Enums\AccountStatus;
use App\Models\Conversation;
use App\Models\User;
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

Broadcast::channel('announcements.{role}', function (User $user, string $role): bool {
    return $user->status === AccountStatus::Active->value
        && in_array($role, ['buyer', 'seller', 'logistics', 'rider'], true)
        && $user->hasRole($role);
});
