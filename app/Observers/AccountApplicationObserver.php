<?php

namespace App\Observers;

use App\Models\AccountApplication;
use App\Services\ApplicationDocumentVerificationService;

class AccountApplicationObserver
{
    public function saved(
        AccountApplication $application
    ): void {
        if (! in_array(
            $application->status,
            ['approved', 'rejected'],
            true
        )) {
            return;
        }

        app(
            ApplicationDocumentVerificationService::class
        )->sync($application);
    }
}
