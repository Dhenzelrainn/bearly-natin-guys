<?php

namespace App\Console\Commands;

use App\Services\ReturnRefundService;
use Illuminate\Console\Command;

class EscalateOverdueReturnSellerResponses extends Command
{
    protected $signature = 'returns:escalate-overdue-seller-responses';

    protected $description = 'Escalate return cases to Admin review when the Seller response deadline passes.';

    public function handle(ReturnRefundService $returns): int
    {
        $count = $returns->escalateOverdueSellerResponses();
        $this->info("Escalated {$count} overdue return case(s) to Admin review.");

        return self::SUCCESS;
    }
}
