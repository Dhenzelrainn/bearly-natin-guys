<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up(): void
    {
        $cityCodes = $this->existingLogisticsCityCodes();

        Schema::table('logistics_profiles', function (Blueprint $table): void {
            $table->string('psgc_city_code', 20)->nullable();
        });

        foreach ($cityCodes as $profileId => $cityCode) {
            DB::table('logistics_profiles')
                ->where('id', $profileId)
                ->update(['psgc_city_code' => $cityCode]);
        }

        Schema::table('logistics_profiles', function (Blueprint $table): void {
            $table->unique('psgc_city_code', 'logistics_profiles_psgc_city_code_unique');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->string('psgc_city_code', 20)->nullable();
        });

        Schema::table('shipments', function (Blueprint $table): void {
            $table->foreignId('origin_logistics_profile_id')
                ->nullable()
                ->constrained('logistics_profiles')
                ->restrictOnDelete();
            $table->string('destination_city_code', 20)->nullable();
            $table->json('destination_address_snapshot')->nullable();
        });

        DB::table('orders')
            ->whereNotNull('shipping_address_id')
            ->orderBy('id')
            ->chunkById(500, function ($orders): void {
                $addressIds = $orders->pluck('shipping_address_id')->filter()->unique()->values();
                $codes = DB::table('addresses')
                    ->whereIn('id', $addressIds)
                    ->whereNotNull('psgc_city_code')
                    ->pluck('psgc_city_code', 'id');

                foreach ($orders as $order) {
                    $cityCode = $codes->get($order->shipping_address_id);

                    if (filled($cityCode)) {
                        DB::table('orders')
                            ->where('id', $order->id)
                            ->update(['psgc_city_code' => trim((string) $cityCode)]);
                    }
                }
            });

        DB::table('shipments')
            ->orderBy('id')
            ->chunkById(500, function ($shipments): void {
                foreach ($shipments as $shipment) {
                    $cityCode = null;

                    if ($shipment->purpose === 'return') {
                        $address = $shipment->destination_address_id
                            ? DB::table('addresses')
                                ->where('id', $shipment->destination_address_id)
                                ->first()
                            : null;
                        $cityCode = $address->psgc_city_code ?? null;

                        if ($address) {
                            DB::table('shipments')
                                ->where('id', $shipment->id)
                                ->update([
                                    'destination_address_snapshot' => json_encode([
                                        'recipient_name' => $address->recipient_name,
                                        'phone' => $address->phone,
                                        'house_number' => $address->house_number,
                                        'street' => $address->street,
                                        'barangay' => $address->barangay,
                                        'city_municipality' => $address->city_municipality,
                                        'province' => $address->province,
                                        'postal_code' => $address->postal_code,
                                        'psgc_city_code' => $address->psgc_city_code,
                                    ], JSON_THROW_ON_ERROR),
                                ]);
                        }
                    } else {
                        $orderId = DB::table('seller_orders')
                            ->where('id', $shipment->seller_order_id)
                            ->value('order_id');

                        $cityCode = $orderId
                            ? DB::table('orders')->where('id', $orderId)->value('psgc_city_code')
                            : null;
                    }

                    DB::table('shipments')
                        ->where('id', $shipment->id)
                        ->update([
                            'origin_logistics_profile_id' => $shipment->logistics_profile_id,
                            'destination_city_code' => filled($cityCode)
                                ? trim((string) $cityCode)
                                : null,
                        ]);
                }
            }, 'id');

        Schema::create('shipment_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('from_logistics_profile_id')->constrained('logistics_profiles')->restrictOnDelete();
            $table->foreignId('to_logistics_profile_id')->constrained('logistics_profiles')->restrictOnDelete();
            $table->foreignId('from_sorting_center_id')->constrained('sorting_centers')->restrictOnDelete();
            $table->foreignId('to_sorting_center_id')->nullable()->constrained('sorting_centers')->restrictOnDelete();
            $table->string('status', 20)->default('sent');
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at');
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->index(['from_logistics_profile_id', 'status', 'sent_at'], 'shipment_transfers_from_status_sent_index');
            $table->index(['to_logistics_profile_id', 'status', 'sent_at'], 'shipment_transfers_to_status_sent_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_transfers');

        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('origin_logistics_profile_id');
            $table->dropColumn(['destination_city_code', 'destination_address_snapshot']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('psgc_city_code');
        });

        Schema::table('logistics_profiles', function (Blueprint $table): void {
            $table->dropUnique('logistics_profiles_psgc_city_code_unique');
            $table->dropColumn('psgc_city_code');
        });
    }

    /** @return array<int, string> */
    private function existingLogisticsCityCodes(): array
    {
        $codes = [];
        $missing = [];

        foreach (DB::table('logistics_profiles')->orderBy('id')->get(['id', 'user_id']) as $profile) {
            $code = DB::table('addresses')
                ->where('user_id', $profile->user_id)
                ->whereRaw('LOWER(label) = ?', ['sorting center'])
                ->whereNotNull('psgc_city_code')
                ->orderByDesc('id')
                ->value('psgc_city_code');

            if (! filled($code)) {
                $centerAddressId = DB::table('sorting_centers')
                    ->where('logistics_profile_id', $profile->id)
                    ->orderBy('id')
                    ->value('address_id');

                $code = $centerAddressId
                    ? DB::table('addresses')
                        ->where('id', $centerAddressId)
                        ->value('psgc_city_code')
                    : null;
            }

            $code = trim((string) $code);

            if (! preg_match('/^[0-9]{6,10}$/D', $code)) {
                $missing[] = (int) $profile->id;

                continue;
            }

            $codes[(int) $profile->id] = $code;
        }

        $duplicates = collect($codes)
            ->mapToGroups(fn (string $code, int $id): array => [$code => $id])
            ->filter(fn ($profileIds): bool => $profileIds->count() > 1)
            ->map(fn ($profileIds): array => $profileIds->all())
            ->all();

        if ($missing !== [] || $duplicates !== []) {
            $details = [];

            if ($missing !== []) {
                $details[] = 'profiles requiring a verified PSGC city code: '.implode(', ', $missing);
            }

            foreach ($duplicates as $cityCode => $profileIds) {
                $details[] = "city {$cityCode} is claimed by Logistics profile IDs ".implode(', ', $profileIds);
            }

            throw new RuntimeException(
                'City-scoped Logistics migration stopped before schema changes. Resolve '.implode('; ', $details).'. No profiles or shipments were changed.'
            );
        }

        return $codes;
    }
};
