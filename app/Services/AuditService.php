<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditService
{
    public function record(
        string $action,
        string $module,
        ?Model $subject = null,
        ?string $description = null,
        array $oldValues = [],
        array $newValues = [],
        string $severity = 'info',
        ?Request $request = null,
        ?User $actor = null,
    ): AuditLog {
        $request ??= request();

        return AuditLog::query()->create([
            'actor_user_id' => ($actor ?? $request->user())?->id,
            'action' => $action,
            'module' => $module,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'description' => $description,
            'old_values' => $oldValues ?: null,
            'new_values' => $newValues ?: null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'severity' => $severity,
            'created_at' => now(),
        ]);
    }

    /**
     * Record a successful sign-in or sign-out with an explicit actor.
     * Mobile login requests are unauthenticated, and web logout clears
     * authentication immediately after this call, so the actor cannot be
     * derived from the request's authenticated user.
     */
    public function recordAuthentication(
        User $user,
        string $event,
        string $channel,
        ?Request $request = null,
    ): AuditLog {
        $request ??= request();

        $device = $this->deviceContext($request->userAgent());

        return $this->record(
            action: $event === 'signed_out' ? 'auth.signed_out' : 'auth.signed_in',
            module: 'Authentication',
            subject: $user,
            description: $this->authenticationDescription($event, $channel),
            newValues: [
                'event' => $event === 'signed_out' ? 'signed_out' : 'signed_in',
                'channel' => $channel,
                'role' => (string) $user->role,
                'device' => $device['device'],
                'browser' => $device['browser'],
                'os' => $device['os'],
                'location' => $this->resolveApproximateLocation($request->ip()),
            ],
            severity: 'info',
            actor: $user,
            request: $request,
        );
    }

    private function authenticationDescription(string $event, string $channel): string
    {
        if ($event === 'signed_out') {
            return $channel === 'mobile_api'
                ? 'Signed out of the mobile app; the active API token was revoked.'
                : 'Signed out of the web session.';
        }

        return match ($channel) {
            'google' => 'Successful sign-in with Google.',
            'mobile_api' => 'Successful mobile app sign-in; an API token was issued.',
            default => 'Successful sign-in with email and password.',
        };
    }

    /**
     * Approximate device details parsed from the User-Agent header. These are
     * informational only: clients can spoof their User-Agent, so this is not
     * verified physical-device identity.
     *
     * @return array{device: string, browser: string, os: string}
     */
    private function deviceContext(?string $userAgent): array
    {
        $agent = trim((string) $userAgent);

        return [
            'device' => $this->deviceType($agent),
            'browser' => $this->browserName($agent),
            'os' => $this->operatingSystem($agent),
        ];
    }

    private function deviceType(string $agent): string
    {
        if ($agent === '') {
            return 'Unknown';
        }

        $lower = strtolower($agent);

        if (
            str_contains($lower, 'ipad')
            || str_contains($lower, 'tablet')
            || (str_contains($lower, 'android') && ! str_contains($lower, 'mobile'))
        ) {
            return 'Tablet';
        }

        if (
            str_contains($lower, 'mobile')
            || str_contains($lower, 'iphone')
            || str_contains($lower, 'ipod')
            || str_contains($lower, 'windows phone')
        ) {
            return 'Mobile';
        }

        if (
            str_contains($lower, 'windows')
            || str_contains($lower, 'macintosh')
            || str_contains($lower, 'linux')
            || str_contains($lower, 'cros')
        ) {
            return 'Desktop';
        }

        return 'Unknown';
    }

    private function browserName(string $agent): string
    {
        if ($agent === '') {
            return 'Unknown';
        }

        $patterns = [
            'Edge' => '/(?:Edg|EdgA|EdgiOS)\/(\d+(?:\.\d+)?)/',
            'Opera' => '/OPR\/(\d+(?:\.\d+)?)/',
            'Samsung Internet' => '/SamsungBrowser\/(\d+(?:\.\d+)?)/',
            'Chrome' => '/(?:Chrome|CriOS)\/(\d+(?:\.\d+)?)/',
            'Firefox' => '/(?:Firefox|FxiOS)\/(\d+(?:\.\d+)?)/',
            'Internet Explorer' => '/(?:MSIE |rv:)(\d+(?:\.\d+)?)/',
            'Safari' => '/Version\/(\d+(?:\.\d+)?).*Safari/',
            'curl' => '/curl\/(\d+(?:\.\d+)?)/',
            'OkHttp' => '/okhttp\/(\d+(?:\.\d+)?)/i',
            'Dart' => '/Dart\/(\d+(?:\.\d+)?)/',
        ];

        foreach ($patterns as $name => $pattern) {
            if (preg_match($pattern, $agent, $matches) === 1) {
                $major = (int) explode('.', $matches[1])[0];

                return $major > 0 ? "{$name} {$major}" : $name;
            }
        }

        return 'Unknown';
    }

    private function operatingSystem(string $agent): string
    {
        if ($agent === '') {
            return 'Unknown';
        }

        if (
            preg_match('/iPhone|iPad|iPod/', $agent) === 1
            && preg_match('/OS (\d+)[._](\d+)/', $agent, $matches) === 1
        ) {
            return 'iOS '.(int) $matches[1].'.'.(int) $matches[2];
        }

        if (preg_match('/Android (\d+(?:\.\d+)?)/', $agent, $matches) === 1) {
            return 'Android '.$matches[1];
        }

        $windowsVersions = [
            '10.0' => 'Windows 10/11',
            '6.3' => 'Windows 8.1',
            '6.2' => 'Windows 8',
            '6.1' => 'Windows 7',
            '6.0' => 'Windows Vista',
            '5.1' => 'Windows XP',
        ];

        if (preg_match('/Windows NT (\d+\.\d+)/', $agent, $matches) === 1) {
            return $windowsVersions[$matches[1]] ?? 'Windows';
        }

        if (str_contains($agent, 'CrOS')) {
            return 'ChromeOS';
        }

        if (str_contains($agent, 'Macintosh')) {
            return 'macOS';
        }

        if (str_contains($agent, 'Linux')) {
            return 'Linux';
        }

        return 'Unknown';
    }

    /**
     * Approximate IP-based location is intentionally not resolved: no GeoIP
     * provider is approved, and raw IP addresses must not be sent to a
     * third-party service without owner approval. The audit row still stores
     * the request IP, and the Audit Logs UI reports the location as
     * unavailable until an approved provider is configured.
     */
    private function resolveApproximateLocation(?string $ip): ?string
    {
        return null;
    }
}
