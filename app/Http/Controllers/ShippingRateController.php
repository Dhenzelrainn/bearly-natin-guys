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
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'base_fee' => ['required', 'string', 'regex:/^\d{1,7}(?:\.\d{1,2})?$/'],
            'zone_surcharge_per_level' => ['required', 'string', 'regex:/^\d{1,7}(?:\.\d{1,2})?$/'],
            'volumetric_divisor' => ['required', 'integer', 'min:1', 'max:1000000'],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ], [
            'base_fee.regex' => 'Enter a valid peso amount with up to two decimal places.',
            'zone_surcharge_per_level.regex' => 'Enter a valid peso amount with up to two decimal places.',
        ]);

        $baseFeeMinor = $this->moneyToMinor($data['base_fee']);
        $surchargePerLevelMinor = $this->moneyToMinor($data['zone_surcharge_per_level']);
        if ($baseFeeMinor < 1) {
            throw ValidationException::withMessages(['base_fee' => 'The base shipping fee must be greater than zero.']);
        }
        if ($baseFeeMinor + (4 * $surchargePerLevelMinor) > 999_999_999) {
            throw ValidationException::withMessages([
                'zone_surcharge_per_level' => 'The highest zone fee must remain within the supported shipping amount.',
            ]);
        }

        DB::transaction(function () use ($request, $data, $baseFeeMinor, $surchargePerLevelMinor): void {
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
            ];
            $newValues = [
                'volumetric_divisor' => (int) $data['volumetric_divisor'],
                'base_fee_minor' => $baseFeeMinor,
                'zone_surcharge_per_level_minor' => $surchargePerLevelMinor,
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
}
