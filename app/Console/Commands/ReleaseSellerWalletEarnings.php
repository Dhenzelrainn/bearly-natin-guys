<?php

namespace App\Console\Commands;

use App\Services\SellerWalletService;
use Illuminate\Console\Command;

class ReleaseSellerWalletEarnings extends Command
{
    protected $signature = 'seller-wallet:release-eligible-earnings';

    protected $description = 'Post eligible Seller earnings to wallets after COD and return-window checks.';

    public function handle(SellerWalletService $wallets): int
    {
        $count = $wallets->refreshAllSellers();
        $this->info("Refreshed eligible Seller wallet earnings for {$count} Seller profiles.");

        return self::SUCCESS;
    }
}
