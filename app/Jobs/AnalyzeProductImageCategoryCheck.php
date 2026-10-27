<?php

namespace App\Jobs;

use App\Services\ProductImageCategoryCheckRetryException;
use App\Services\ProductImageCategoryCheckService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnalyzeProductImageCategoryCheck implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 75;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly int $checkId) {}

    public function handle(ProductImageCategoryCheckService $checks): void
    {
        $check = $checks->claim($this->checkId);
        if (! $check) {
            return;
        }

        try {
            $checks->analyze($check);
        } catch (ProductImageCategoryCheckRetryException $exception) {
            $checks->releaseForRetry($this->checkId);
            throw $exception;
        } catch (Throwable $exception) {
            $checks->releaseForRetry($this->checkId);
            Log::warning('Product image category check failed unexpectedly.', [
                'check_id' => $this->checkId,
                'exception' => $exception::class,
            ]);

            throw new ProductImageCategoryCheckRetryException('internal_failure');
        }
    }

    public function failed(Throwable $exception): void
    {
        $failureCode = $exception instanceof ProductImageCategoryCheckRetryException
            ? $exception->failureCode
            : 'provider_temporary_failure';

        app(ProductImageCategoryCheckService::class)
            ->markFailedAfterRetries($this->checkId, $failureCode);

        Log::warning('Product image category check exhausted its retry limit.', [
            'check_id' => $this->checkId,
            'failure_code' => $failureCode,
            'exception' => $exception::class,
        ]);
    }
}
