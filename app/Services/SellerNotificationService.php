<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

class SellerNotificationService
{
    /**
     * Recent database notifications shaped for the Seller layout popover.
     *
     * @return array<int, array{title: string, time: string, type: string}>
     */
    public function recentFor(?User $user, int $limit = 5): array
    {
        if (! $user) {
            return [];
        }

        return $user->notifications()
            ->latest()
            ->limit($limit)
            ->get()
            ->map(function (DatabaseNotification $notification): array {
                $data = is_array($notification->data) ? $notification->data : [];
                $status = strtolower((string) ($data['status'] ?? ''));

                return [
                    'title' => (string) ($data['message'] ?? $data['title'] ?? 'Bearly notification'),
                    'time' => $notification->created_at?->diffForHumans() ?? '',
                    'type' => match (true) {
                        in_array($status, ['completed', 'approved', 'paid'], true) => 'success',
                        in_array($status, ['rejected', 'failed'], true) => 'danger',
                        default => 'warning',
                    },
                ];
            })
            ->values()
            ->all();
    }
}
