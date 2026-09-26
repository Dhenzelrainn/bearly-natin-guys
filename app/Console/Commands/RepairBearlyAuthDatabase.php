<?php

namespace App\Console\Commands;

use App\Models\Address;
use App\Models\AccountApplication;
use App\Services\AddressNormalizationService;
use App\Services\ApplicationDocumentVerificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairBearlyAuthDatabase extends Command
{
    protected $signature = 'bearly:repair-auth-db';

    protected $description =
        'Repair Bearly normalized address and application document consistency';

    public function handle(
        AddressNormalizationService $addresses,
        ApplicationDocumentVerificationService $documents
    ): int {
        $addressUpdates = 0;
        $documentUpdates = 0;

        DB::transaction(function () use (
            $addresses,
            $documents,
            &$addressUpdates,
            &$documentUpdates
        ): void {
            Address::query()
                ->with('user')
                ->where(function ($query) {
                    $query
                        ->whereNull('postal_code')
                        ->orWhere('postal_code', '')
                        ->orWhereNull('psgc_city_code')
                        ->orWhere('psgc_city_code', '');
                })
                ->orderBy('id')
                ->chunkById(
                    100,
                    function ($rows) use (
                        $addresses,
                        &$addressUpdates
                    ): void {
                        foreach ($rows as $address) {
                            if ($addresses->enrich($address)) {
                                $address->saveQuietly();
                                $addressUpdates++;
                            }
                        }
                    }
                );

            AccountApplication::query()
                ->whereIn(
                    'status',
                    ['approved', 'rejected']
                )
                ->orderBy('id')
                ->chunkById(
                    100,
                    function ($applications) use (
                        $documents,
                        &$documentUpdates
                    ): void {
                        foreach ($applications as $application) {
                            $documentUpdates +=
                                $documents->sync($application);
                        }
                    }
                );
        });

        $this->info(
            'Auth DB repair complete. ' .
            "Addresses updated: {$addressUpdates}; " .
            "Documents updated: {$documentUpdates}."
        );

        return self::SUCCESS;
    }
}
