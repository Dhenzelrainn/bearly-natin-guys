<?php

namespace App\Services;

use App\Models\RegistrationDraft;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

class RegistrationDraftService
{
    public const COOKIE_NAME = 'bearly_registration_draft';

    public const TTL_DAYS = 7;

    private const FLOWS = [
        'buyer-seller' => [
            'max_step' => 4,
            'fields' => [
                'role', 'first_name', 'last_name', 'middle_initial', 'sex', 'email',
                'phone_country', 'contact_number', 'birthday', 'region', 'region_code', 'province', 'city',
                'barangay', 'street_name', 'house_number', 'postal_code', 'city_code',
                'postal_manual', 'business_name', 'business_category_id',
            ],
        ],
        'rider' => [
            'max_step' => 3,
            'fields' => [
                'logistics_partner', 'first_name', 'middle_initial', 'last_name', 'sex',
                'email', 'contact_number', 'birthday', 'region', 'region_code', 'province', 'municipality',
                'barangay', 'street', 'house_number', 'postal_code', 'city_code',
                'postal_manual', 'work_type', 'vehicle_type', 'vehicle_model', 'plate_number',
            ],
        ],
        'logistics' => [
            'max_step' => 3,
            'fields' => [
                'first_name', 'middle_initial', 'last_name', 'sex', 'birthday', 'email',
                'contact_number', 'business_name', 'region', 'region_code', 'province', 'municipality', 'barangay',
                'street', 'house_number', 'postal_code', 'city_code', 'postal_manual',
            ],
        ],
    ];

    /**
     * Prepare a browser-scoped draft for a guest registration form.
     *
     * @return array{fields: array<string, scalar>, step: int, has_draft: bool}
     */
    public function forPage(Request $request, string $flow): array
    {
        $definition = $this->definition($flow);
        $hash = $this->browserTokenHash($request);
        $draft = RegistrationDraft::query()
            ->where('browser_token_hash', $hash)
            ->where('flow', $flow)
            ->first();

        if ($draft?->expires_at->isPast()) {
            $draft->delete();
            $draft = null;
        } elseif ($draft && $this->emailAlreadyRegistered(
            is_array($draft->payload) ? ($draft->payload['email'] ?? null) : null
        )) {
            $draft->delete();
            $draft = null;
        } elseif ($draft) {
            $draft->forceFill(['expires_at' => now()->addDays(self::TTL_DAYS)])->save();
        }

        $payload = $draft?->payload ?? [];
        $fields = collect($payload)
            ->only($definition['fields'])
            ->all();

        return [
            'fields' => $fields,
            'step' => min($definition['max_step'], max(1, (int) ($draft?->step ?? 1))),
            'has_draft' => $draft !== null,
        ];
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function save(Request $request, string $flow, array $fields, int $step): ?RegistrationDraft
    {
        $definition = $this->definition($flow);
        $allowed = array_fill_keys($definition['fields'], true);
        $safeFields = [];

        foreach ($fields as $name => $value) {
            if (! is_string($name) || ! isset($allowed[$name]) || ! is_scalar($value)) {
                continue;
            }

            $value = (string) $value;
            if (mb_strlen($value) > 300) {
                continue;
            }

            $safeFields[$name] = $value;
        }

        $browserTokenHash = $this->browserTokenHash($request);

        return Cache::lock($this->lockKey($browserTokenHash, $flow), 10)
            ->block(5, function () use ($safeFields, $definition, $browserTokenHash, $flow, $step): ?RegistrationDraft {
                if ($this->emailAlreadyRegistered($safeFields['email'] ?? null)) {
                    RegistrationDraft::query()
                        ->where('browser_token_hash', $browserTokenHash)
                        ->where('flow', $flow)
                        ->delete();

                    return null;
                }

                $draft = RegistrationDraft::query()->firstOrNew([
                    'browser_token_hash' => $browserTokenHash,
                    'flow' => $flow,
                ]);
                $draft->forceFill([
                    'payload' => $safeFields,
                    'step' => min($definition['max_step'], max(1, $step)),
                    'expires_at' => now()->addDays(self::TTL_DAYS),
                ])->save();

                return $draft;
            });
    }

    public function forget(Request $request, string $flow): void
    {
        $this->definition($flow);

        $browserTokenHash = $this->browserTokenHash($request);

        Cache::lock($this->lockKey($browserTokenHash, $flow), 10)
            ->block(5, fn () => RegistrationDraft::query()
                ->where('browser_token_hash', $browserTokenHash)
                ->where('flow', $flow)
                ->delete());
    }

    public function pruneExpired(): int
    {
        return RegistrationDraft::query()
            ->where('expires_at', '<=', now())
            ->delete();
    }

    /** @return array{max_step: int, fields: array<int, string>} */
    private function definition(string $flow): array
    {
        abort_unless(isset(self::FLOWS[$flow]), 404);

        return self::FLOWS[$flow];
    }

    private function browserTokenHash(Request $request): string
    {
        $token = $request->cookie(self::COOKIE_NAME);

        if (! is_string($token) || ! preg_match('/^[A-Za-z0-9]{64}$/D', $token)) {
            $token = Str::random(64);
        }

        // Refresh the browser capability whenever the draft is viewed or saved.
        // This makes the seven-day window inactivity-based instead of expiring
        // seven days after the first page visit.
        Cookie::queue(cookie(
            self::COOKIE_NAME,
            $token,
            self::TTL_DAYS * 24 * 60,
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            'lax',
        ));

        return hash('sha256', $token);
    }

    private function lockKey(string $browserTokenHash, string $flow): string
    {
        return 'registration-draft:'.$flow.':'.$browserTokenHash.':lock';
    }

    private function emailAlreadyRegistered(mixed $email): bool
    {
        if (! is_string($email)) {
            return false;
        }

        $email = strtolower(trim($email));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        return User::withTrashed()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->exists();
    }
}
