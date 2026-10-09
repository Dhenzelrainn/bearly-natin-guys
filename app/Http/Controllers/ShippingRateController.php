<?php

namespace App\Http\Controllers;

use App\Models\ShippingRate;
use App\Models\SystemSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ShippingRateController extends Controller
{
    public function index(): View
    {
        $rates = ShippingRate::query()
            ->where('provider_key', (string) config('shipping.provider_key'))
            ->get()
            ->keyBy('route_tier');

        return view('admin.shipping-rates', [
            'tiers' => collect(config('shipping.route_tiers'))->map(function (array $tier, string $key) use ($rates): array {
                $rate = $rates->get($key);

                return [
                    'key' => $key,
                    'label' => $tier['label'],
                    'description' => $tier['description'],
                    'base_fee' => $this->moneyInput((int) ($rate?->first_kg_fee_minor ?? 0)),
                    'additional_fee' => $rate?->additional_kg_surcharge_minor === null
                        ? ''
                        : $this->moneyInput((int) $rate->additional_kg_surcharge_minor),
                ];
            })->values()->all(),
            'volumetricDivisor' => (int) (SystemSetting::query()
                ->where('key', 'shipping.volumetric_divisor')
                ->value('value') ?? config('shipping.default_volumetric_divisor', 5000)),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $rules = [
            'volumetric_divisor' => ['required', 'integer', 'min:1', 'max:1000000'],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ];
        foreach (array_keys((array) config('shipping.route_tiers')) as $tier) {
            $rules["rates.{$tier}.first_kg_fee"] = ['required', 'string', 'regex:/^\d{1,7}(?:\.\d{1,2})?$/'];
            $rules["rates.{$tier}.additional_kg_surcharge"] = ['nullable', 'string', 'regex:/^\d{1,7}(?:\.\d{1,2})?$/'];
        }

        $data = $request->validate($rules, [
            'rates.*.first_kg_fee.regex' => 'Enter a valid peso amount with up to two decimal places.',
            'rates.*.additional_kg_surcharge.regex' => 'Enter a valid peso amount or leave the surcharge blank.',
        ]);

        $provider = (string) config('shipping.provider_key');
        DB::transaction(function () use ($request, $data, $provider): void {
            $oldValues = [
                'volumetric_divisor' => (int) (SystemSetting::query()
                    ->where('key', 'shipping.volumetric_divisor')
                    ->lockForUpdate()
                    ->value('value') ?? config('shipping.default_volumetric_divisor', 5000)),
                'rates' => [],
            ];
            $newValues = [
                'volumetric_divisor' => (int) $data['volumetric_divisor'],
                'rates' => [],
            ];

            foreach (array_keys((array) config('shipping.route_tiers')) as $tier) {
                $rate = ShippingRate::query()
                    ->where('provider_key', $provider)
                    ->where('route_tier', $tier)
                    ->lockForUpdate()
                    ->firstOrFail();
                $oldValues['rates'][$tier] = [
                    'first_kg_fee_minor' => (int) $rate->first_kg_fee_minor,
                    'additional_kg_surcharge_minor' => $rate->additional_kg_surcharge_minor === null
                        ? null
                        : (int) $rate->additional_kg_surcharge_minor,
                ];
                $rate->update([
                    'first_kg_fee_minor' => $this->moneyToMinor($data['rates'][$tier]['first_kg_fee']),
                    'additional_kg_surcharge_minor' => filled($data['rates'][$tier]['additional_kg_surcharge'] ?? null)
                        ? $this->moneyToMinor($data['rates'][$tier]['additional_kg_surcharge'])
                        : null,
                ]);
                $newValues['rates'][$tier] = [
                    'first_kg_fee_minor' => (int) $rate->first_kg_fee_minor,
                    'additional_kg_surcharge_minor' => $rate->additional_kg_surcharge_minor === null
                        ? null
                        : (int) $rate->additional_kg_surcharge_minor,
                ];
            }

            SystemSetting::query()->updateOrCreate(
                ['key' => 'shipping.volumetric_divisor'],
                [
                    'value' => (int) $data['volumetric_divisor'],
                    'value_type' => 'integer',
                    'is_public' => false,
                    'updated_by' => $request->user()->id,
                ],
            );

            DB::table('audit_logs')->insert([
                'actor_user_id' => $request->user()->id,
                'action' => 'shipping_rates.updated',
                'module' => 'shipping',
                'auditable_type' => ShippingRate::class,
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

        return redirect()->route('admin.shipping-rates')->with('success', 'Bearly shipping rates were saved.');
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
}
