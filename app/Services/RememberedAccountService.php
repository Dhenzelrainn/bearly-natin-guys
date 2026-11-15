<?php

namespace App\Services;

use App\Models\RememberedAccount;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;

class RememberedAccountService
{
    public const COOKIE_NAME = 'bearly_recent_accounts';

    public const MAX_ACCOUNTS = 6;

    public const TTL_DAYS = 30;

    /**
     * @return Collection<int, array{id: int, name: string, email: string, initials: string}>
     */
    public function accounts(Request $request): Collection
    {
        [, $resolved] = $this->resolveEntries($request);

        return collect($resolved)
            ->sortByDesc(fn (array $item): int => $item['record']->last_used_at?->getTimestamp()
                ?? $item['record']->created_at?->getTimestamp()
                ?? 0)
            ->values()
            ->map(function (array $item, int $index): array {
                $user = $item['record']->user;
                $name = trim((string) ($user->name ?: trim(($user->first_name ?? '').' '.($user->last_name ?? ''))));
                $name = $name !== '' ? $name : 'Bearly account';
                $parts = preg_split('/\s+/', $name) ?: [];
                $initials = collect($parts)
                    ->filter()
                    ->take(2)
                    ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
                    ->implode('');

                return [
                    'id' => (int) $user->id,
                    'name' => $name,
                    'email' => (string) $user->email,
                    'initials' => $initials !== '' ? $initials : 'B',
                    'recommended' => $index === 0,
                ];
            });
    }

    public function remember(Request $request, User $user): void
    {
        [$entries] = $this->resolveEntries($request);
        $existing = collect($entries)->firstWhere('user_id', (int) $user->id);
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = now()->addDays(self::TTL_DAYS);

        $entries = DB::transaction(function () use ($entries, $existing, $expiresAt, $request, $token, $tokenHash, $user): array {
            $record = null;

            if ($existing) {
                $record = RememberedAccount::query()
                    ->where('user_id', $user->id)
                    ->where('token_hash', hash('sha256', $existing['token']))
                    ->lockForUpdate()
                    ->first();
            }

            if ($record) {
                $record->forceFill([
                    'token_hash' => $tokenHash,
                    'expires_at' => $expiresAt,
                    'last_used_at' => now(),
                ])->save();
            } else {
                RememberedAccount::query()->create([
                    'user_id' => $user->id,
                    'token_hash' => $tokenHash,
                    'expires_at' => $expiresAt,
                    'last_used_at' => now(),
                ]);
            }

            $entries = array_values(array_filter(
                $entries,
                fn (array $entry): bool => $entry['user_id'] !== (int) $user->id,
            ));
            array_unshift($entries, [
                'user_id' => (int) $user->id,
                'token' => $token,
            ]);

            $evicted = array_slice($entries, self::MAX_ACCOUNTS);
            $entries = array_slice($entries, 0, self::MAX_ACCOUNTS);

            foreach ($evicted as $entry) {
                RememberedAccount::query()
                    ->where('user_id', $entry['user_id'])
                    ->where('token_hash', hash('sha256', $entry['token']))
                    ->delete();
            }

            return $entries;
        }, attempts: 3);

        $this->queueCookie($request, $entries);
    }

    public function authenticate(Request $request, int $userId): ?User
    {
        [$entries] = $this->resolveEntries($request);
        $entry = collect($entries)->firstWhere('user_id', $userId);

        if (! $entry) {
            return null;
        }

        $oldTokenHash = hash('sha256', $entry['token']);
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = now()->addDays(self::TTL_DAYS);

        $user = DB::transaction(function () use ($expiresAt, $oldTokenHash, $tokenHash, $userId): ?User {
            $record = RememberedAccount::query()
                ->where('user_id', $userId)
                ->where('token_hash', $oldTokenHash)
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (! $record) {
                return null;
            }

            $user = User::query()->whereKey($userId)->lockForUpdate()->first();

            if (! $user || $user->status !== 'active') {
                $record->delete();

                return null;
            }

            $record->forceFill([
                'token_hash' => $tokenHash,
                'expires_at' => $expiresAt,
                'last_used_at' => now(),
            ])->save();

            return $user;
        }, attempts: 3);

        if (! $user) {
            $entries = array_values(array_filter(
                $entries,
                fn (array $candidate): bool => $candidate['user_id'] !== $userId,
            ));
            $this->queueCookie($request, $entries);

            return null;
        }

        foreach ($entries as &$candidate) {
            if ($candidate['user_id'] === $userId) {
                $candidate['token'] = $token;
                break;
            }
        }
        unset($candidate);

        $this->queueCookie($request, $entries);

        return $user;
    }

    public function forgetAccount(Request $request, int $userId): bool
    {
        [$entries] = $this->resolveEntries($request);
        $entry = collect($entries)->firstWhere('user_id', $userId);

        if (! $entry) {
            return false;
        }

        $deleted = RememberedAccount::query()
            ->where('user_id', $userId)
            ->where('token_hash', hash('sha256', $entry['token']))
            ->delete();

        $entries = array_values(array_filter(
            $entries,
            fn (array $candidate): bool => $candidate['user_id'] !== $userId,
        ));
        $this->queueCookie($request, $entries);

        return $deleted > 0;
    }

    public function forgetUser(Request $request, int $userId): void
    {
        $this->forgetAccount($request, $userId);
    }

    public function clear(Request $request): int
    {
        $entries = $this->readEntries($request);
        $deleted = 0;

        foreach ($entries as $entry) {
            $deleted += RememberedAccount::query()
                ->where('user_id', $entry['user_id'])
                ->where('token_hash', hash('sha256', $entry['token']))
                ->delete();
        }

        $this->queueCookie($request, []);

        return $deleted;
    }

    /**
     * @return array{0: array<int, array{user_id: int, token: string}>, 1: array<int, array{entry: array{user_id: int, token: string}, record: RememberedAccount}>}
     */
    private function resolveEntries(Request $request): array
    {
        $rawCookie = $request->cookie(self::COOKIE_NAME);
        $entries = $this->readEntries($request);

        if ($entries === []) {
            if (is_string($rawCookie) && $rawCookie !== '') {
                $this->queueCookie($request, []);
            }

            return [[], []];
        }

        $records = RememberedAccount::query()
            ->with('user')
            ->whereIn('token_hash', array_map(fn (array $entry): string => hash('sha256', $entry['token']), $entries))
            ->where('expires_at', '>', now())
            ->get()
            ->keyBy('token_hash');

        $validEntries = [];
        $resolved = [];

        foreach ($entries as $entry) {
            $hash = hash('sha256', $entry['token']);
            $record = $records->get($hash);

            if (
                ! $record
                || (int) $record->user_id !== $entry['user_id']
                || ! $record->user
                || $record->user->status !== 'active'
            ) {
                if ($record) {
                    $record->delete();
                }

                continue;
            }

            $validEntries[] = $entry;
            $resolved[] = [
                'entry' => $entry,
                'record' => $record,
            ];
        }

        if ($validEntries !== $entries) {
            $this->queueCookie($request, $validEntries);
        }

        return [$validEntries, $resolved];
    }

    /**
     * @return array<int, array{user_id: int, token: string}>
     */
    private function readEntries(Request $request): array
    {
        $value = $request->cookie(self::COOKIE_NAME);

        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        if (! is_array($decoded)) {
            return [];
        }

        return collect($decoded)
            ->filter(fn (mixed $entry): bool => is_array($entry)
                && isset($entry['user_id'], $entry['token'])
                && filter_var($entry['user_id'], FILTER_VALIDATE_INT) !== false
                && (int) $entry['user_id'] > 0
                && is_string($entry['token'])
                && preg_match('/^[a-f0-9]{64}$/D', $entry['token']) === 1)
            ->map(fn (array $entry): array => [
                'user_id' => (int) $entry['user_id'],
                'token' => $entry['token'],
            ])
            ->unique('user_id')
            ->take(self::MAX_ACCOUNTS)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{user_id: int, token: string}>  $entries
     */
    private function queueCookie(Request $request, array $entries): void
    {
        if ($entries === []) {
            Cookie::queue(cookie(
                self::COOKIE_NAME,
                '',
                -1,
                (string) config('session.path', '/'),
                config('session.domain'),
                $request->isSecure(),
                true,
                false,
                'lax',
            ));

            return;
        }

        Cookie::queue(cookie(
            self::COOKIE_NAME,
            json_encode($entries, JSON_THROW_ON_ERROR),
            self::TTL_DAYS * 24 * 60,
            (string) config('session.path', '/'),
            config('session.domain'),
            $request->isSecure(),
            true,
            false,
            'lax',
        ));
    }
}
