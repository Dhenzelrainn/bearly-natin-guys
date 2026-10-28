<?php

namespace App\Services;

use RuntimeException;

class ProductImageCategoryCheckRetryException extends RuntimeException
{
    public function __construct(public readonly string $failureCode)
    {
        parent::__construct('The advisory product image check will be retried.');
    }
}
