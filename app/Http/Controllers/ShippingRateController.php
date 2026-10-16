<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ShippingRateController extends Controller
{
    public function index(): View
    {
        return view('admin.shipping-rates', [
            'sellerOrderFee' => $this->moneyInput((int) (SystemSetting::query()
                ->where('key', 'shipping.seller_order_fee_minor')
                ->value('value') ?? config('shipping.default_seller_order_fee_minor', 5000))),
            'volumetricDivisor' => (int) (SystemSetting::query()
                ->where('key', 'shipping.volumetric_divisor')
                ->value('value') ?? config('shipping.default_volumetric_divisor', 5000)),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'seller_order_fee' => ['required', 'string', 'regex:/^\d{1,7}(?:\.\d{1,2})?$/'],
            'volumetric_divisor' => ['required', 'integer', 'min:1', 'max:1000000'],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ], [
            'seller_order_fee.regex' => 'Enter a valid peso amount with up to two decimal places.',
        ]);

        DB::transaction(function () use ($request, $data): void {
            $oldValues = [
                'volumetric_divisor' => (int) (SystemSetting::query()
                    ->where('key', 'shipping.volumetric_divisor')
                    ->lockForUpdate()
                    ->value('value') ?? config('shipping.default_volumetric_divisor', 5000)),
                'seller_order_fee_minor' => (int) (SystemSetting::query()
                    ->where('key', 'shipping.seller_order_fee_minor')
                    ->lockForUpdate()
                    ->value('value') ?? config('shipping.default_seller_order_fee_minor', 5000)),
            ];
            $newValues = [
                'volumetric_divisor' => (int) $data['volumetric_divisor'],
                'seller_order_fee_minor' => $this->moneyToMinor($data['seller_order_fee']),
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
                ['key' => 'shipping.seller_order_fee_minor'],
                [
                    'value' => $newValues['seller_order_fee_minor'],
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
}
