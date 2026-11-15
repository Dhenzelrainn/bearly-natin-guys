<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserNotificationPreference;
use Illuminate\Validation\ValidationException;

class NotificationPreferenceService
{
    /**
     * Resolve every registry event that applies to the user's role(s), with
     * stored preferences merged over the registry defaults.
     *
     * @return array<string, array{key: string, label: string, description: string, group: string, mandatory: bool, in_app: bool, email: bool}>
     */
    public function forUser(User $user): array
    {
        $roles = $this->rolesFor($user);

        $saved = UserNotificationPreference::query()
            ->where('user_id', $user->id)
            ->get()
            ->keyBy('event_key');

        $events = [];

        foreach (config('notifications.events', []) as $key => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            if (! array_intersect((array) ($definition['audience'] ?? []), $roles)) {
                continue;
            }

            $mandatory = (bool) ($definition['mandatory'] ?? false);
            $defaults = (array) ($definition['defaults'] ?? []);
            $stored = $saved->get($key);

            $events[$key] = [
                'key' => $key,
                'label' => (string) ($definition['label'] ?? $key),
                'description' => (string) ($definition['description'] ?? ''),
                'group' => (string) ($definition['group'] ?? 'general'),
                'mandatory' => $mandatory,
                'in_app' => $mandatory || ($stored ? $stored->in_app : (bool) ($defaults['in_app'] ?? true)),
                'email' => $stored ? $stored->email : (bool) ($defaults['email'] ?? false),
            ];
        }

        return $events;
    }

    /**
     * Resolve the delivery channels for one event. Mandatory events always
     * keep the in-app channel; unknown events fall back to in-app only so a
     * delivery is never dropped silently.
     *
     * @return array{in_app: bool, email: bool}
     */
    public function channelsFor(User $user, string $eventKey): array
    {
        $definition = config("notifications.events.{$eventKey}");

        if (! is_array($definition)) {
            return ['in_app' => true, 'email' => false];
        }

        $mandatory = (bool) ($definition['mandatory'] ?? false);
        $defaults = (array) ($definition['defaults'] ?? []);

        $stored = UserNotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('event_key', $eventKey)
            ->first();

        return [
            'in_app' => $mandatory || ($stored ? $stored->in_app : (bool) ($defaults['in_app'] ?? true)),
            'email' => $stored ? $stored->email : (bool) ($defaults['email'] ?? false),
        ];
    }

    /**
     * Persist preferences for events the user is allowed to receive. Mandatory
     * events keep the in-app channel enabled regardless of submitted values.
     *
     * @param  array<string, array{in_app: bool, email: bool}>  $preferences
     */
    public function updateForUser(User $user, array $preferences): void
    {
        $allowed = $this->forUser($user);

        foreach ($preferences as $key => $values) {
            if (! isset($allowed[$key])) {
                throw ValidationException::withMessages([
                    'preferences' => "Unknown notification event [{$key}] for this account.",
                ]);
            }

            $mandatory = (bool) $allowed[$key]['mandatory'];

            UserNotificationPreference::query()->updateOrCreate(
                ['user_id' => $user->id, 'event_key' => $key],
                [
                    'in_app' => $mandatory ? true : (bool) ($values['in_app'] ?? true),
                    'email' => (bool) ($values['email'] ?? false),
                ],
            );
        }
    }

    /**
     * @return list<string>
     */
    private function rolesFor(User $user): array
    {
        $roles = [];

        if (is_string($user->role) && $user->role !== '') {
            $roles[] = $user->role;
        }

        foreach ($user->roles()->pluck('name') as $name) {
            $roles[] = (string) $name;
        }

        return array_values(array_unique($roles));
    }
}
