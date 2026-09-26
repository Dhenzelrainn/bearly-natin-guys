<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AdminSettingController extends Controller
{
    public const DEFAULTS = [
        'marketplace_name' => 'Bearly',
        'commission_rate' => 10.0,
        'marketplace_description' => 'Bearly is an e-commerce marketplace connecting Buyers, Sellers, Logistics Centers, and Riders.',
        'platform_active' => true,
        'maintenance_mode' => false,
        'registration_buyer' => true,
        'registration_seller' => true,
        'registration_logistics' => true,
        'registration_rider' => true,
        'cancellation_hours' => 24,
        'settlement_days' => 7,
    ];

    public function index(Request $request): View
    {
        $stored = $this->storedSettings();

        return view('admin.system.settings', [
            'admin' => $this->adminSummary($request),
            'topNotifications' => [],
            'settings' => array_replace(self::DEFAULTS, $stored),
        ]);
    }

    public function update(Request $request, AuditService $audit): RedirectResponse
    {
        $validated = $request->validate([
            'marketplace_name' => ['required', 'string', 'max:100'],
            'commission_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'marketplace_description' => ['required', 'string', 'max:1000'],
            'platform_active' => ['required', 'boolean'],
            'maintenance_mode' => ['required', 'boolean'],
            'registration_buyer' => ['required', 'boolean'],
            'registration_seller' => ['required', 'boolean'],
            'registration_logistics' => ['required', 'boolean'],
            'registration_rider' => ['required', 'boolean'],
            'cancellation_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'settlement_days' => ['required', 'integer', 'in:7,14,30'],
        ]);

        $validated = $this->normalize($validated);
        $old = array_replace(self::DEFAULTS, $this->storedSettings());

        DB::transaction(function () use ($request, $validated, $old, $audit): void {
            foreach ($validated as $key => $value) {
                SystemSetting::query()->updateOrCreate(['key' => $key], [
                    'value' => $value,
                    'value_type' => $this->typeOf($value),
                    'is_public' => false,
                    'updated_by' => $request->user()->id,
                ]);
            }

            $changedKeys = array_keys(array_filter($validated, fn ($value, $key) => $old[$key] !== $value, ARRAY_FILTER_USE_BOTH));
            if ($changedKeys !== []) {
                $audit->record('settings.updated', 'system_management', null,
                    'Platform settings were updated.',
                    array_intersect_key($old, array_flip($changedKeys)),
                    array_intersect_key($validated, array_flip($changedKeys)),
                    in_array('maintenance_mode', $changedKeys, true) ? 'warning' : 'info', $request);
            }
        }, 3);

        return back()->with('success', 'Platform settings were saved.');
    }

    public function reset(Request $request, AuditService $audit): RedirectResponse
    {
        $old = array_replace(self::DEFAULTS, $this->storedSettings());
        DB::transaction(function () use ($request, $audit, $old): void {
            SystemSetting::query()->delete();
            $audit->record('settings.reset', 'system_management', null,
                'Platform settings were restored to their defaults.', $old, self::DEFAULTS, 'warning', $request);
        }, 3);

        return back()->with('success', 'Platform settings were reset.');
    }

    private function typeOf(mixed $value): string
    {
        return match (true) { is_bool($value) => 'boolean', is_int($value) => 'integer', is_float($value) => 'decimal', default => 'string' };
    }

    private function storedSettings(): array
    {
        return SystemSetting::query()->get()->mapWithKeys(fn (SystemSetting $setting) => [$setting->key => $setting->value])->all();
    }

    private function normalize(array $values): array
    {
        foreach (['platform_active','maintenance_mode','registration_buyer','registration_seller','registration_logistics','registration_rider'] as $key) {
            $values[$key] = filter_var($values[$key], FILTER_VALIDATE_BOOL);
        }
        $values['commission_rate'] = (float) $values['commission_rate'];
        $values['cancellation_hours'] = (int) $values['cancellation_hours'];
        $values['settlement_days'] = (int) $values['settlement_days'];

        return $values;
    }

    private function adminSummary(Request $request): array
    {
        $user = $request->user();
        return ['name' => $user->name, 'role' => 'Admin', 'email' => $user->email,
            'initials' => collect(explode(' ', $user->name))->map(fn ($part) => strtoupper(substr($part, 0, 1)))->take(2)->implode('')];
    }
}
