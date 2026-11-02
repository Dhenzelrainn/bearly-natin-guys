<?php

namespace App\Console\Commands;

use App\Services\RegistrationDraftService;
use App\Services\SellerProductDraftService;
use Illuminate\Console\Command;

class PruneExpiredDrafts extends Command
{
    protected $signature = 'drafts:prune-expired';

    protected $description = 'Delete expired drafts and reconcile Seller temporary product files.';

    public function handle(
        RegistrationDraftService $registrationDrafts,
        SellerProductDraftService $sellerProductDrafts,
    ): int {
        $registrationCount = $registrationDrafts->pruneExpired();
        $sellerProductCount = $sellerProductDrafts->pruneExpired();
        $orphanFileCount = $sellerProductDrafts->reconcileOrphanedUploads();
        $cleanupFailures = $sellerProductDrafts->cleanupFailureCount();

        $this->info("Pruned {$registrationCount} registration draft(s) and {$sellerProductCount} Seller product draft(s).");

        if ($orphanFileCount > 0) {
            $this->info("Reconciled {$orphanFileCount} orphaned Seller product draft file(s).");
        }

        if ($cleanupFailures > 0) {
            $this->error("{$cleanupFailures} temporary Seller image cleanup or orphan-file reconciliation failure(s) require retry.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
