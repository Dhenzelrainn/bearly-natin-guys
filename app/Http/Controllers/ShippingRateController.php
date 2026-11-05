<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ShippingRateController extends Controller
{
    public function index(): View
    {
        return view('admin.shipping-rates', [
            'baseFee' => $this->moneyInput((int) (SystemSetting::query()
                ->where('key', 'shipping.base_fee_minor')
                ->value('value')
                ?? SystemSetting::query()->where('key', 'shipping.seller_order_fee_minor')->value('value')
                ?? config('shipping.default_base_fee_minor', 5000))),
            'zoneSurchargePerLevel' => $this->moneyInput((int) (SystemSetting::query()
                ->where('key', 'shipping.zone_surcharge_per_level_minor')
                ->value('value') ?? config('shipping.default_zone_surcharge_per_level_minor', 500))),
            'volumetricDivisor' => (int) (SystemSetting::query()
                ->where('key', 'shipping.volumetric_divisor')
                ->value('value') ?? config('shipping.default_volumetric_divisor', 5000)),
            'pickupRegularRate' => $this->moneyInput($this->pickupRateMinor('rider.pickup_rate_regular_minor')),
            'pickupBulkyRate' => $this->moneyInput($this->pickupRateMinor('rider.pickup_rate_bulky_minor')),
            'pickupBulkyClass' => $this->pickupBulkyClass(),
            'pickupRegularClasses' => implode(', ', $this->pickupRegularClasses()),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'base_fee' => ['required', 'string', 'regex:/^\d{1,7}(?:\.\d{1,2})?$/'],
            'zone_surcharge_per_level' => ['required', 'string', 'regex:/^\d{1,7}(?:\.\d{1,2})?$/'],
            'volumetric_divisor' => ['required', 'integer', 'min:1', 'max:1000000'],
            'pickup_regular_rate' => ['required', 'string', 'regex:/^\d{1,7}(?:\.\d{1,2})?$/'],
            'pickup_bulky_rate' => ['required', 'string', 'regex:/^\d{1,7}(?:\.\d{1,2})?$/'],
            'pickup_regular_classes' => ['required', 'string', 'max:500'],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ], [
            'base_fee.regex' => 'Enter a valid peso amount with up to two decimal places.',
            'zone_surcharge_per_level.regex' => 'Enter a valid peso amount with up to two decimal places.',
            'pickup_regular_rate.regex' => 'Enter a valid pickup rate with up to two decimal places.',
            'pickup_bulky_rate.regex' => 'Enter a valid bulky pickup rate with up to two decimal places.',
        ]);

        $baseFeeMinor = $this->moneyToMinor($data['base_fee']);
        $surchargePerLevelMinor = $this->moneyToMinor($data['zone_surcharge_per_level']);
        $pickupRegularMinor = $this->moneyToMinor($data['pickup_regular_rate']);
        $pickupBulkyMinor = $this->moneyToMinor($data['pickup_bulky_rate']);
        $pickupRegularClasses = $this->parsePickupRegularClasses($data['pickup_regular_classes']);

        if ($pickupRegularMinor < 1 || $pickupBulkyMinor < 1) {
            throw ValidationException::withMessages([
                'pickup_regular_rate' => 'Pickup rates must be greater than zero.',
            ]);
        }

        if ($pickupRegularClasses === []) {
            throw ValidationException::withMessages([
                'pickup_regular_classes' => 'Add at least one recognized non-bulky parcel size class.',
            ]);
        }
        if ($baseFeeMinor < 1) {
            throw ValidationException::withMessages(['base_fee' => 'The base shipping fee must be greater than zero.']);
        }
        if ($baseFeeMinor + (4 * $surchargePerLevelMinor) > 999_999_999) {
            throw ValidationException::withMessages([
                'zone_surcharge_per_level' => 'The highest zone fee must remain within the supported shipping amount.',
            ]);
        }

        DB::transaction(function () use (
            $request,
            $data,
            $baseFeeMinor,
            $surchargePerLevelMinor,
            $pickupRegularMinor,
            $pickupBulkyMinor,
            $pickupRegularClasses,
        ): void {
            $oldValues = [
                'volumetric_divisor' => (int) (SystemSetting::query()
                    ->where('key', 'shipping.volumetric_divisor')
                    ->lockForUpdate()
                    ->value('value') ?? config('shipping.default_volumetric_divisor', 5000)),
                'base_fee_minor' => $this->effectiveBaseFeeMinor(true),
                'zone_surcharge_per_level_minor' => (int) (SystemSetting::query()
                    ->where('key', 'shipping.zone_surcharge_per_level_minor')
                    ->lockForUpdate()
                    ->value('value') ?? config('shipping.default_zone_surcharge_per_level_minor', 500)),
                'pickup_rate_regular_minor' => $this->pickupRateMinor('rider.pickup_rate_regular_minor'),
                'pickup_rate_bulky_minor' => $this->pickupRateMinor('rider.pickup_rate_bulky_minor'),
                'pickup_regular_size_classes' => $this->pickupRegularClasses(),
            ];
            $newValues = [
                'volumetric_divisor' => (int) $data['volumetric_divisor'],
                'base_fee_minor' => $baseFeeMinor,
                'zone_surcharge_per_level_minor' => $surchargePerLevelMinor,
                'pickup_rate_regular_minor' => $pickupRegularMinor,
                'pickup_rate_bulky_minor' => $pickupBulkyMinor,
                'pickup_regular_size_classes' => $pickupRegularClasses,
            ];

            SystemSetting::query()->updateOrCreate(
                ['key' => 'shipping.volumetric_divisor'],
                [
                    'value' => $newValues['volumetric_divisor'],
                    'value_type' => 'integer',
                    'is_public' => false,
                    'updated_by' => $request->user()->id,
                ],
            );
            SystemSetting::query()->updateOrCreate(
                ['key' => 'shipping.base_fee_minor'],
                [
                    'value' => $newValues['base_fee_minor'],
                    'value_type' => 'integer',
                    'is_public' => false,
                    'updated_by' => $request->user()->id,
                ],
            );
            SystemSetting::query()->updateOrCreate(
                ['key' => 'shipping.zone_surcharge_per_level_minor'],
                [
                    'value' => $newValues['zone_surcharge_per_level_minor'],
                    'value_type' => 'integer',
                    'is_public' => false,
                    'updated_by' => $request->user()->id,
                ],
            );
            SystemSetting::query()->updateOrCreate(
                ['key' => 'rider.pickup_rate_regular_minor'],
                [
                    'value' => $newValues['pickup_rate_regular_minor'],
                    'value_type' => 'integer',
                    'is_public' => false,
                    'updated_by' => $request->user()->id,
                ],
            );
            SystemSetting::query()->updateOrCreate(
                ['key' => 'rider.pickup_rate_bulky_minor'],
                [
                    'value' => $newValues['pickup_rate_bulky_minor'],
                    'value_type' => 'integer',
                    'is_public' => false,
                    'updated_by' => $request->user()->id,
                ],
            );
            SystemSetting::query()->updateOrCreate(
                ['key' => 'rider.pickup_regular_size_classes'],
                [
                    'value' => $newValues['pickup_regular_size_classes'],
                    'value_type' => 'json',
                    'is_public' => false,
                    'updated_by' => $request->user()->id,
                ],
            );

            DB::table('audit_logs')->insert([
                'actor_user_id' => $request->user()->id,
                'action' => 'shipping_rates.updated',
                'module' => 'shipping',
                'auditable_type' => SystemSetting::class,
                'auditable_id' => null,
                'description' => 'Admin updated Bearly shipping configuration: '.trim((string) $data['reason']),
                'old_values' => json_encode($oldValues, JSON_THROW_ON_ERROR),
                'new_values' => json_encode($newValues, JSON_THROW_ON_ERROR),
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 1000),
                'severity' => 'info',
                'created_at' => now(),
            ]);
        });

        return redirect()->route('admin.shipping-rates')->with('success', 'Bearly shipping settings were saved.');
    }

    private function moneyToMinor(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private function moneyInput(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }

    private function effectiveBaseFeeMinor(bool $lock = false): int
    {
        $setting = SystemSetting::query()->where('key', 'shipping.base_fee_minor');
        if ($lock) {
            $setting->lockForUpdate();
        }
        $stored = $setting->value('value');

        if ($stored === null) {
            $legacy = SystemSetting::query()->where('key', 'shipping.seller_order_fee_minor');
            if ($lock) {
                $legacy->lockForUpdate();
            }
            $stored = $legacy->value('value');
        }

        return (int) ($stored ?? config('shipping.default_base_fee_minor', 5000));
    }

    private function pickupBulkyClass(): string
    {
        return (string) config('bearly_rider_compensation.pickup_bulky_size_class', 'bulky');
    }

    private function pickupRateMinor(string $key): int
    {
        $fallback = $key === 'rider.pickup_rate_bulky_minor'
            ? config('bearly_rider_compensation.pickup_rate_bulky_minor', 3000)
            : config('bearly_rider_compensation.pickup_rate_regular_minor', 1500);

        $stored = SystemSetting::query()
            ->where('key', $key)
            ->value('value');

        return $stored === null ? (int) $fallback : (int) $stored;
    }

    /** @return list<string> */
    private function pickupRegularClasses(): array
    {
        $stored = SystemSetting::query()
            ->where('key', 'rider.pickup_regular_size_classes')
            ->value('value');

        if (is_string($stored)) {
            $decoded = json_decode($stored, true);
            $stored = is_array($decoded) ? $decoded : null;
        }

        $classes = is_array($stored)
            ? $stored
            : (array) config('bearly_rider_compensation.pickup_regular_size_classes', []);

        return collect($classes)
            ->map(fn ($value): string => strtolower(trim((string) $value)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function parsePickupRegularClasses(string $value): array
    {
        $classes = collect(explode(',', $value))
            ->map(fn (string $class): string => strtolower(trim($class)))
            ->filter()
            ->unique()
            ->values();

        $invalid = $classes->first(
            fn (string $class): bool => preg_match('/^[a-z0-9][a-z0-9_-]{0,19}$/', $class) !== 1,
        );

        if ($invalid !== null) {
            throw ValidationException::withMessages([
                'pickup_regular_classes' => "The size class \"{$invalid}\" is not a valid lowercase class of up to 20 characters.",
            ]);
        }

        if ($classes->contains(strtolower($this->pickupBulkyClass()))) {
            throw ValidationException::withMessages([
                'pickup_regular_classes' => 'The bulky class is paid at the bulky rate and cannot be listed as a regular class.',
            ]);
        }

        return $classes->all();
    }
}
