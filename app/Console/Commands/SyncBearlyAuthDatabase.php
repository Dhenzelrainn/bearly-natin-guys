<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\RegistrationLifecycleService;
use Illuminate\Console\Command;
use Throwable;

class SyncBearlyAuthDatabase extends Command
{
    protected $signature = 'bearly:sync-auth-db';

    protected $description =
        'Backfill normalized Bearly roles, applications, addresses, and approved profiles from legacy auth columns';

    public function handle(
        RegistrationLifecycleService $lifecycle
    ): int {
        $processed = 0;
        $failed = 0;

        User::query()
            ->orderBy('id')
            ->chunkById(100, function ($users) use (
                $lifecycle,
                &$processed,
                &$failed
            ): void {
                foreach ($users as $user) {
                    try {
                        $lifecycle->backfillUser($user);
                        $processed++;
                    } catch (Throwable $exception) {
                        $failed++;

                        $this->error(
                            "User {$user->id}: {$exception->getMessage()}"
                        );
                    }
                }
            });

        $this->newLine();
        $this->info(
            "Auth DB sync complete. Processed: {$processed}; Failed: {$failed}."
        );

        return $failed === 0
            ? self::SUCCESS
            : self::FAILURE;
    }
}
