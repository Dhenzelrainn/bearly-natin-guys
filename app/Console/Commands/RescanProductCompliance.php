<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\ComplianceScanner;
use Illuminate\Console\Command;

class RescanProductCompliance extends Command
{
    protected $signature = 'products:compliance-rescan
        {--apply : Persist scan results, violations, Seller warnings, and product holds}
        {--product=* : Limit the scan to one or more product IDs}';

    protected $description = 'Safely review existing non-archived products against active compliance rules.';

    public function handle(ComplianceScanner $scanner): int
    {
        $query = Product::query()
            ->whereNull('removed_at')
            ->where('product_status', '<>', 'archived')
            ->orderBy('id');

        $requestedProductIds = (array) $this->option('product');
        $productIds = array_values(array_filter(
            $requestedProductIds,
            fn ($id): bool => is_scalar($id) && ctype_digit((string) $id) && (int) $id > 0,
        ));

        if ($requestedProductIds !== [] && count($productIds) !== count($requestedProductIds)) {
            $this->error('Every --product value must be a positive product ID. No products were scanned.');

            return self::INVALID;
        }

        $productIds = array_values(array_unique(array_map('intval', $productIds)));

        if ($productIds !== []) {
            $query->whereIn('id', $productIds);
        }

        if (! $this->option('apply')) {
            $this->info($query->count().' eligible products found. No records changed; pass --apply to persist scans.');

            return self::SUCCESS;
        }

        $scanned = 0;
        $blocked = 0;
        $held = 0;

        $query->chunkById(100, function ($products) use ($scanner, &$scanned, &$blocked, &$held): void {
            foreach ($products as $product) {
                $check = $scanner->scan($product, 'manual');
                $scanned++;

                if ($check->result === 'blocked') {
                    $blocked++;
                } elseif ($check->result === 'review_required') {
                    $held++;
                }
            }
        });

        $this->info("Scanned {$scanned} products: {$blocked} blocked, {$held} held for review.");

        return self::SUCCESS;
    }
}
