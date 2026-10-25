<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductViolation;
use App\Models\User;
use App\Models\ViolationAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductCategoryReviewService
{
    private const REVIEW_ACTION = 'category_review_marked_appropriate';

    private const MODULE = 'Product Compliance';

    private const ACTIONABLE_VIOLATION_STATUSES = [
        'flagged',
        'under_review',
        'correction_requested',
    ];

    private const PER_PAGE = 20;

    public function __construct(
        private readonly AuditService $audit,
    ) {}

    public function paginateQueue(?string $search = null): LengthAwarePaginator
    {
        $reviewedProductIds = $this->unchangedReviewedProductIds();

        $products = Product::query()
            ->visibleToBuyers()
            ->with($this->reviewRelations())
            ->whereDoesntHave('violations', fn (Builder $violations) => $violations
                ->whereIn('status', self::ACTIONABLE_VIOLATION_STATUSES))
            ->when($reviewedProductIds !== [], fn (Builder $query) => $query
                ->whereNotIn('products.id', $reviewedProductIds));

        $search = trim((string) $search);

        if ($search !== '') {
            $like = '%'.$search.'%';

            $products->where(function (Builder $query) use ($like): void {
                $query
                    ->where('products.name', 'like', $like)
                    ->orWhereHas('store', fn (Builder $stores) => $stores->where('name', 'like', $like))
                    ->orWhereHas('store.sellerProfile', fn (Builder $profiles) => $profiles
                        ->where('legal_business_name', 'like', $like))
                    ->orWhereHas('store.sellerProfile.approvedCategory', fn (Builder $categories) => $categories
                        ->where('name', 'like', $like))
                    ->orWhereHas('category', fn (Builder $categories) => $categories->where('name', 'like', $like))
                    ->orWhereHas('marketplaceSubcategory', fn (Builder $subcategories) => $subcategories
                        ->where('name', 'like', $like)
                        ->orWhereHas('marketplaceCategory', fn (Builder $categories) => $categories
                            ->where('name', 'like', $like)));
            });
        }

        return $products
            ->orderBy('products.name')
            ->orderBy('products.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Product $product): array => $this->presentQueueProduct($product));
    }

    public function markCategoryAppropriate(
        Product $product,
        User $actor,
        string $reason,
        Request $request,
    ): void {
        DB::transaction(function () use ($product, $actor, $reason, $request): void {
            $product = $this->lockReviewableProduct($product);

            $snapshot = $this->snapshot($product);
            $fingerprint = $this->fingerprint($snapshot);
            $reviewedAt = now();

            $this->audit->record(
                action: self::REVIEW_ACTION,
                module: self::MODULE,
                subject: $product,
                description: $reason,
                newValues: [
                    'reviewed_by' => (int) $actor->id,
                    'reviewed_at' => $reviewedAt->toIso8601String(),
                    'reason' => $reason,
                    'fingerprint_version' => 1,
                    'fingerprint' => $fingerprint,
                    'snapshot' => $snapshot,
                ],
                severity: 'info',
                request: $request,
                actor: $actor,
            );
        });
    }

    public function flagForCategoryCorrection(
        Product $product,
        User $actor,
        string $reason,
        Request $request,
    ): ProductViolation {
        return DB::transaction(function () use ($product, $actor, $reason, $request): ProductViolation {
            $product = $this->lockReviewableProduct($product);

            $snapshot = $this->snapshot($product);
            $fingerprint = $this->fingerprint($snapshot);
            $now = now();

            $violation = ProductViolation::query()->create([
                'violation_no' => 'VIO-'.Str::upper(Str::random(10)),
                'product_id' => $product->id,
                'seller_profile_id' => $product->store->seller_profile_id,
                'check_id' => $product->latestComplianceCheck?->id,
                'rule_id' => null,
                'source' => 'admin_manual_review',
                'violation_type' => 'category_correction',
                'severity' => 'medium',
                'reason' => $reason,
                'detected_excerpt' => null,
                'status' => 'flagged',
                'assigned_to' => $actor->id,
            ]);

            ViolationAction::query()->create([
                'violation_id' => $violation->id,
                'action_type' => 'manual_category_flag',
                'from_status' => null,
                'to_status' => 'flagged',
                'actor_user_id' => $actor->id,
                'reason' => $reason,
                'metadata' => ['fingerprint' => $fingerprint],
                'created_at' => $now,
            ]);

            $product->update([
                'product_status' => 'flagged',
                'compliance_status' => 'flagged',
            ]);

            $this->audit->record(
                action: 'category_correction_flagged',
                module: self::MODULE,
                subject: $product,
                description: $reason,
                oldValues: [
                    'product_status' => 'active',
                    'compliance_status' => 'clear',
                ],
                newValues: [
                    'product_status' => 'flagged',
                    'compliance_status' => 'flagged',
                    'violation_id' => (int) $violation->id,
                    'violation_no' => $violation->violation_no,
                    'reason' => $reason,
                    'fingerprint_version' => 1,
                    'fingerprint' => $fingerprint,
                    'snapshot' => $snapshot,
                ],
                severity: 'warning',
                request: $request,
                actor: $actor,
            );

            return $violation;
        });
    }

    private function lockReviewableProduct(Product $product): Product
    {
        $product = Product::query()
            ->whereKey($product->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        $this->ensureNoActionableViolation($product);

        if (! Product::query()->visibleToBuyers()->whereKey($product->getKey())->exists()) {
            throw ValidationException::withMessages([
                'product' => 'This listing is no longer Buyer-visible and cannot be reviewed from this queue.',
            ]);
        }

        return $product->load($this->reviewRelations());
    }

    private function ensureNoActionableViolation(Product $product): void
    {
        $hasActionableViolation = ProductViolation::query()
            ->where('product_id', $product->id)
            ->whereIn('status', self::ACTIONABLE_VIOLATION_STATUSES)
            ->lockForUpdate()
            ->exists();

        if ($hasActionableViolation) {
            throw ValidationException::withMessages([
                'product' => 'This listing already has an actionable Product Violation. Continue its review in the Product Violations queue.',
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(Product $product): array
    {
        $images = $product->images
            ->sortBy(fn ($image): string => sprintf('%010d:%010d', (int) $image->position, (int) $image->id))
            ->map(fn ($image): array => [
                'id' => (int) $image->id,
                'disk' => (string) $image->disk,
                'path' => (string) $image->path,
                'mime_type' => $image->mime_type,
                'size_bytes' => $image->size_bytes === null ? null : (int) $image->size_bytes,
                'purpose' => (string) $image->purpose,
                'alt_text' => $image->alt_text,
                'variant_id' => $image->variant_id === null ? null : (int) $image->variant_id,
                'position' => (int) $image->position,
                'is_primary' => (bool) $image->is_primary,
                'updated_at' => $image->updated_at?->toISOString(),
            ])
            ->values()
            ->all();

        $listedCategory = $product->category;
        $approvedCategory = $product->store?->sellerProfile?->approvedCategory;
        $marketplaceSubcategory = $product->marketplaceSubcategory;
        $marketplaceCategory = $marketplaceSubcategory?->marketplaceCategory;

        return [
            'version' => 1,
            'name' => (string) $product->name,
            'description' => (string) ($product->description ?? ''),
            'listed_business_category' => [
                'id' => $listedCategory ? (int) $listedCategory->id : null,
                'name' => $listedCategory?->name,
                'slug' => $listedCategory?->slug,
            ],
            'approved_business_category' => [
                'id' => $approvedCategory ? (int) $approvedCategory->id : null,
                'name' => $approvedCategory?->name,
                'slug' => $approvedCategory?->slug,
            ],
            'marketplace_category' => [
                'id' => $marketplaceCategory ? (int) $marketplaceCategory->id : null,
                'name' => $marketplaceCategory?->name,
                'slug' => $marketplaceCategory?->slug,
            ],
            'marketplace_subcategory' => [
                'id' => $marketplaceSubcategory ? (int) $marketplaceSubcategory->id : null,
                'name' => $marketplaceSubcategory?->name,
                'slug' => $marketplaceSubcategory?->slug,
            ],
            'images' => $images,
        ];
    }

    /** @param array<string, mixed> $snapshot */
    private function fingerprint(array $snapshot): string
    {
        $canonicalSnapshot = json_encode(
            $snapshot,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return hash('sha256', $canonicalSnapshot === false ? '{}' : $canonicalSnapshot);
    }

    /** @return array<int, int> */
    private function unchangedReviewedProductIds(): array
    {
        $productMorphType = (new Product)->getMorphClass();
        $latestReviewIds = AuditLog::query()
            ->selectRaw('MAX(id)')
            ->where('action', self::REVIEW_ACTION)
            ->where('module', self::MODULE)
            ->where('auditable_type', $productMorphType)
            ->whereNotNull('auditable_id')
            ->groupBy('auditable_id');

        $reviewedFingerprints = AuditLog::query()
            ->whereIn('id', $latestReviewIds)
            ->get(['auditable_id', 'new_values'])
            ->mapWithKeys(function (AuditLog $review): array {
                $fingerprint = $review->new_values['fingerprint'] ?? null;

                return is_string($fingerprint) && $fingerprint !== ''
                    ? [(int) $review->auditable_id => $fingerprint]
                    : [];
            })
            ->all();

        if ($reviewedFingerprints === []) {
            return [];
        }

        $unchangedIds = [];

        Product::query()
            ->visibleToBuyers()
            ->whereIn('products.id', array_keys($reviewedFingerprints))
            ->with($this->reviewRelations())
            ->orderBy('products.id')
            ->chunkById(100, function ($products) use (&$unchangedIds, $reviewedFingerprints): void {
                foreach ($products as $product) {
                    $currentFingerprint = $this->fingerprint($this->snapshot($product));
                    $reviewedFingerprint = $reviewedFingerprints[(int) $product->id] ?? null;

                    if (is_string($reviewedFingerprint) && hash_equals($reviewedFingerprint, $currentFingerprint)) {
                        $unchangedIds[] = (int) $product->id;
                    }
                }
            }, 'id', 'id');

        return $unchangedIds;
    }

    /** @return array<int, string> */
    private function reviewRelations(): array
    {
        return [
            'category',
            'marketplaceSubcategory.marketplaceCategory',
            'store.sellerProfile.approvedCategory',
            'images',
            'latestComplianceCheck',
        ];
    }

    /** @return array<string, mixed> */
    private function presentQueueProduct(Product $product): array
    {
        $image = $product->images->firstWhere('is_primary', true) ?? $product->images->first();
        $imageDisk = $image?->disk ?: 'public';
        $imageUrl = $image && filled($image->path) && config("filesystems.disks.{$imageDisk}")
            ? Storage::disk($imageDisk)->url($image->path)
            : null;
        $check = $product->latestComplianceCheck;
        $scanAt = $check?->completed_at ?? $check?->created_at;
        $scanIsStale = $check !== null && (
            $check->name_snapshot !== $product->name
            || $check->description_snapshot !== $product->description
            || ($check->category_id_snapshot === null ? null : (int) $check->category_id_snapshot)
                !== ($product->category_id === null ? null : (int) $product->category_id)
        );

        return [
            'id' => (int) $product->id,
            'name' => $product->name,
            'image_url' => $imageUrl,
            'image_alt' => $image?->alt_text ?: $product->name,
            'seller' => $product->store?->name ?? 'Seller record unavailable',
            'seller_business_name' => $product->store?->sellerProfile?->legal_business_name,
            'approved_business_category' => $product->store?->sellerProfile?->approvedCategory?->name ?? '—',
            'listed_business_category' => $product->category?->name ?? '—',
            'marketplace_category' => $product->marketplaceSubcategory?->marketplaceCategory?->name ?? '—',
            'marketplace_subcategory' => $product->marketplaceSubcategory?->name ?? '—',
            'latest_scan_result' => $check
                ? Str::headline((string) ($check->result ?: $check->status))
                : 'No scan recorded',
            'latest_scan_at' => $scanAt ? Carbon::parse($scanAt)->format('M j, Y g:i A') : null,
            'scan_is_stale' => $scanIsStale,
            'product_status' => Str::headline((string) $product->product_status),
            'compliance_status' => Str::headline((string) $product->compliance_status),
        ];
    }
}
