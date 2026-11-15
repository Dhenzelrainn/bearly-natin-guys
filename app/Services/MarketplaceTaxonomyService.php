<?php

namespace App\Services;

use App\Models\Category;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceSubcategory;
use App\Models\MarketplaceSubcategoryRequest;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MarketplaceTaxonomyService
{
    public function __construct(private readonly AuditService $audit) {}

    /** @return array{profile:SellerProfile,store:Store,category:Category} */
    public function sellerContext(User $seller, bool $lock = false): array
    {
        $profileQuery = SellerProfile::query()
            ->with(['store', 'approvedCategory'])
            ->where('user_id', $seller->id);

        if ($lock) {
            $profileQuery->lockForUpdate();
        }

        $profile = $profileQuery->first();
        if (! $profile || ! $profile->store || ! $profile->approvedCategory || ! $profile->approvedCategory->is_active) {
            throw ValidationException::withMessages([
                'marketplace_subcategory_id' => 'Your active approved Seller category and Store must be available before marketplace classification can be used.',
            ]);
        }

        return [
            'profile' => $profile,
            'store' => $profile->store,
            'category' => $profile->approvedCategory,
        ];
    }

    /** @return EloquentCollection<int, MarketplaceCategory> */
    public function marketplaceCategoriesForSeller(User $seller): EloquentCollection
    {
        $context = $this->sellerContext($seller);

        return MarketplaceCategory::query()
            ->where('is_active', true)
            ->whereHas('businessCategories', fn ($query) => $query
                ->whereKey($context['category']->id)
                ->where('categories.is_active', true))
            ->with(['subcategories' => fn ($query) => $query
                ->where('is_active', true)
                ->orderBy('position')
                ->orderBy('name')])
            ->orderBy('position')
            ->orderBy('name')
            ->get();
    }

    /** @return EloquentCollection<int, MarketplaceSubcategory> */
    public function marketplaceSubcategoriesForSeller(User $seller): EloquentCollection
    {
        $subcategories = [];
        foreach ($this->marketplaceCategoriesForSeller($seller) as $category) {
            foreach ($category->subcategories as $subcategory) {
                $subcategory->setRelation('marketplaceCategory', $category);
                $subcategories[] = $subcategory;
            }
        }

        return new EloquentCollection($subcategories);
    }

    public function resolveMarketplaceSubcategory(
        Category $businessCategory,
        mixed $subcategoryId,
        ?Product $existingProduct = null,
    ): ?MarketplaceSubcategory {
        if (blank($subcategoryId)) {
            return null;
        }

        if (! is_numeric($subcategoryId) || (int) $subcategoryId < 1) {
            throw ValidationException::withMessages(['marketplace_subcategory_id' => 'Choose an active marketplace subcategory.']);
        }

        $subcategoryReference = MarketplaceSubcategory::query()->whereKey((int) $subcategoryId)->first();
        $marketplaceCategory = $subcategoryReference
            ? MarketplaceCategory::query()
                ->whereKey($subcategoryReference->marketplace_category_id)
                ->lockForUpdate()
                ->first()
            : null;
        $subcategory = $marketplaceCategory
            ? MarketplaceSubcategory::query()
                ->whereKey((int) $subcategoryId)
                ->where('marketplace_category_id', $marketplaceCategory->id)
                ->lockForUpdate()
                ->first()
            : null;
        if ($subcategory && $marketplaceCategory) {
            $subcategory->setRelation('marketplaceCategory', $marketplaceCategory);
        }

        if (! $subcategory
            || ! $marketplaceCategory?->is_active
            || ! $marketplaceCategory->businessCategories()
                ->whereKey($businessCategory->id)
                ->where('categories.is_active', true)
                ->exists()
            || (! $subcategory->is_active
                && (! $existingProduct
                    || (int) $existingProduct->marketplace_subcategory_id !== (int) $subcategory->id
                    || (int) $existingProduct->category_id !== (int) $businessCategory->id))) {
            throw ValidationException::withMessages([
                'marketplace_subcategory_id' => 'Choose an active marketplace subcategory valid for your approved business category.',
            ]);
        }

        return $subcategory;
    }

    /** @return LengthAwarePaginator<int, MarketplaceSubcategoryRequest> */
    public function sellerRequests(User $seller): LengthAwarePaginator
    {
        $context = $this->sellerContext($seller);

        return MarketplaceSubcategoryRequest::query()
            ->with(['businessCategory', 'marketplaceCategory', 'marketplaceSubcategory'])
            ->where('seller_profile_id', $context['profile']->id)
            ->where('store_id', $context['store']->id)
            ->latest('id')
            ->paginate(15);
    }

    public function requestSubcategory(User $seller, string $suggestedName, string $reason, Request $request): MarketplaceSubcategoryRequest
    {
        $suggestedName = Str::squish($suggestedName);
        $nameKey = self::nameKey($suggestedName);
        if ($suggestedName === '' || mb_strlen($suggestedName) < 2) {
            throw ValidationException::withMessages(['suggested_name' => 'Enter a subcategory name of at least two characters.']);
        }

        return DB::transaction(function () use ($seller, $suggestedName, $nameKey, $reason, $request): MarketplaceSubcategoryRequest {
            $context = $this->sellerContext($seller, lock: true);
            $businessCategory = Category::query()->whereKey($context['category']->id)->lockForUpdate()->firstOrFail();

            if (! $businessCategory->marketplaceCategories()->where('is_active', true)->exists()) {
                throw ValidationException::withMessages([
                    'suggested_name' => 'Your approved business category is not mapped to an active Buyer category yet.',
                ]);
            }

            $pendingDuplicate = MarketplaceSubcategoryRequest::query()
                ->where('business_category_id', $businessCategory->id)
                ->where('name_key', $nameKey)
                ->where('status', MarketplaceSubcategoryRequest::STATUS_PENDING_REVIEW)
                ->exists();

            if ($pendingDuplicate) {
                throw ValidationException::withMessages([
                    'suggested_name' => 'A matching subcategory request is already pending for your approved business category.',
                ]);
            }

            $mappedCategories = $businessCategory->marketplaceCategories()
                ->where('marketplace_categories.is_active', true)
                ->get();
            if ($mappedCategories->count() === 1
                && MarketplaceSubcategory::query()
                    ->where('marketplace_category_id', $mappedCategories->sole()->id)
                    ->where('name_key', $nameKey)
                    ->where('is_active', true)
                    ->exists()) {
                throw ValidationException::withMessages([
                    'suggested_name' => 'That marketplace subcategory already exists. Select it on the product form instead of requesting a duplicate.',
                ]);
            }

            $taxonomyRequest = MarketplaceSubcategoryRequest::query()->create([
                'seller_profile_id' => $context['profile']->id,
                'store_id' => $context['store']->id,
                'business_category_id' => $businessCategory->id,
                'suggested_name' => $suggestedName,
                'name_key' => $nameKey,
                'reason' => trim($reason),
                'status' => MarketplaceSubcategoryRequest::STATUS_PENDING_REVIEW,
            ]);

            $this->audit->record(
                action: 'marketplace_subcategory.requested',
                module: 'marketplace_taxonomy',
                subject: $taxonomyRequest,
                description: 'Seller requested a marketplace subcategory for the approved business category.',
                newValues: [
                    'suggested_name' => $suggestedName,
                    'business_category_id' => (int) $businessCategory->id,
                    'store_id' => (int) $context['store']->id,
                    'status' => MarketplaceSubcategoryRequest::STATUS_PENDING_REVIEW,
                ],
                request: $request,
                actor: $seller,
            );

            return $taxonomyRequest;
        }, attempts: 3);
    }

    public function createSubcategory(User $admin, int $marketplaceCategoryId, string $name, int $position, Request $request): MarketplaceSubcategory
    {
        $name = Str::squish($name);
        $nameKey = self::nameKey($name);

        return DB::transaction(function () use ($admin, $marketplaceCategoryId, $name, $nameKey, $position, $request): MarketplaceSubcategory {
            $category = MarketplaceCategory::query()->whereKey($marketplaceCategoryId)->lockForUpdate()->firstOrFail();
            abort_unless($category->is_active, 404);
            $this->assertMarketplaceCategoryMapped($category);
            $this->assertUniqueSubcategoryName($category, $nameKey);

            $subcategory = MarketplaceSubcategory::query()->create([
                'marketplace_category_id' => $category->id,
                'name' => $name,
                'name_key' => $nameKey,
                'slug' => $this->uniqueSubcategorySlug($category, $name),
                'is_active' => true,
                'position' => $position,
                'created_by_user_id' => $admin->id,
                'updated_by_user_id' => $admin->id,
            ]);

            $this->audit->record(
                action: 'marketplace_subcategory.created',
                module: 'marketplace_taxonomy',
                subject: $subcategory,
                description: 'Admin added a canonical marketplace subcategory.',
                newValues: [
                    'marketplace_category_id' => (int) $category->id,
                    'name' => $subcategory->name,
                    'slug' => $subcategory->slug,
                    'position' => (int) $subcategory->position,
                ],
                request: $request,
                actor: $admin,
            );

            return $subcategory;
        }, attempts: 3);
    }

    public function updateSubcategory(User $admin, int $subcategoryId, array $data, Request $request): MarketplaceSubcategory
    {
        return DB::transaction(function () use ($admin, $subcategoryId, $data, $request): MarketplaceSubcategory {
            $subcategoryReference = MarketplaceSubcategory::query()->findOrFail($subcategoryId);
            $category = MarketplaceCategory::query()
                ->whereKey($subcategoryReference->marketplace_category_id)
                ->lockForUpdate()
                ->firstOrFail();
            $subcategory = MarketplaceSubcategory::query()
                ->whereKey($subcategoryId)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($category->is_active, 404);

            $oldValues = [
                'name' => $subcategory->name,
                'position' => (int) $subcategory->position,
                'is_active' => (bool) $subcategory->is_active,
            ];
            $newName = array_key_exists('name', $data) ? Str::squish((string) $data['name']) : $subcategory->name;
            $newNameKey = self::nameKey($newName);
            if ($newNameKey !== $subcategory->name_key) {
                $this->assertUniqueSubcategoryName($category, $newNameKey, (int) $subcategory->id);
            }

            $subcategory->update([
                'name' => $newName,
                'name_key' => $newNameKey,
                'position' => (int) ($data['position'] ?? $subcategory->position),
                'is_active' => (bool) ($data['is_active'] ?? $subcategory->is_active),
                'updated_by_user_id' => $admin->id,
            ]);

            $this->audit->record(
                action: 'marketplace_subcategory.updated',
                module: 'marketplace_taxonomy',
                subject: $subcategory,
                description: 'Admin updated a canonical marketplace subcategory.',
                oldValues: $oldValues,
                newValues: [
                    'name' => $subcategory->name,
                    'slug' => $subcategory->slug,
                    'position' => (int) $subcategory->position,
                    'is_active' => (bool) $subcategory->is_active,
                ],
                request: $request,
                actor: $admin,
            );

            return $subcategory->fresh('marketplaceCategory');
        }, attempts: 3);
    }

    public function decideRequest(User $admin, int $requestId, array $data, Request $request): MarketplaceSubcategoryRequest
    {
        return DB::transaction(function () use ($admin, $requestId, $data, $request): MarketplaceSubcategoryRequest {
            $taxonomyRequest = MarketplaceSubcategoryRequest::query()
                ->with(['sellerProfile', 'store'])
                ->whereKey($requestId)
                ->lockForUpdate()
                ->firstOrFail();
            if ($taxonomyRequest->status !== MarketplaceSubcategoryRequest::STATUS_PENDING_REVIEW) {
                throw ValidationException::withMessages(['decision' => 'This request has already been reviewed.']);
            }

            $currentProfile = SellerProfile::query()
                ->whereKey($taxonomyRequest->seller_profile_id)
                ->lockForUpdate()
                ->first();
            if (! $currentProfile || (int) $currentProfile->approved_category_id !== (int) $taxonomyRequest->business_category_id) {
                throw ValidationException::withMessages(['decision' => 'The Seller’s approved category changed after this request. Review it against the current category before deciding.']);
            }

            $decision = $data['decision'];
            $decisionReason = trim((string) ($data['decision_reason'] ?? ''));
            $marketplaceCategory = null;
            $subcategory = null;

            if ($decision === 'reuse') {
                $subcategoryReference = MarketplaceSubcategory::query()
                    ->whereKey((int) ($data['marketplace_subcategory_id'] ?? 0))
                    ->firstOrFail();
                $marketplaceCategory = MarketplaceCategory::query()
                    ->whereKey($subcategoryReference->marketplace_category_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                abort_unless($marketplaceCategory->is_active, 404);
                if (! $marketplaceCategory->businessCategories()
                    ->whereKey($taxonomyRequest->business_category_id)
                    ->where('categories.is_active', true)
                    ->exists()) {
                    throw ValidationException::withMessages(['marketplace_subcategory_id' => 'Choose an existing subcategory valid for the Seller’s approved business category.']);
                }
                $subcategory = MarketplaceSubcategory::query()
                    ->whereKey($subcategoryReference->id)
                    ->where('marketplace_category_id', $marketplaceCategory->id)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->firstOrFail();
                if ($subcategory->name_key !== $taxonomyRequest->name_key) {
                    throw ValidationException::withMessages(['marketplace_subcategory_id' => 'The selected canonical subcategory does not match the requested name.']);
                }
            } elseif ($decision === 'approve') {
                $marketplaceCategory = MarketplaceCategory::query()
                    ->whereKey((int) ($data['marketplace_category_id'] ?? 0))
                    ->lockForUpdate()
                    ->firstOrFail();
                abort_unless($marketplaceCategory->is_active, 404);
                if (! $marketplaceCategory->businessCategories()
                    ->whereKey($taxonomyRequest->business_category_id)
                    ->where('categories.is_active', true)
                    ->exists()) {
                    throw ValidationException::withMessages(['marketplace_category_id' => 'Choose a Buyer category mapped to this Seller’s approved business category.']);
                }

                $existing = MarketplaceSubcategory::query()
                    ->where('marketplace_category_id', $marketplaceCategory->id)
                    ->where('name_key', $taxonomyRequest->name_key)
                    ->first();
                if ($existing) {
                    throw ValidationException::withMessages([
                        'decision' => 'A matching canonical subcategory already exists in that Buyer category. Choose Reuse Existing instead.',
                    ]);
                }

                $subcategory = MarketplaceSubcategory::query()->create([
                    'marketplace_category_id' => $marketplaceCategory->id,
                    'name' => $taxonomyRequest->suggested_name,
                    'name_key' => $taxonomyRequest->name_key,
                    'slug' => $this->uniqueSubcategorySlug($marketplaceCategory, $taxonomyRequest->suggested_name),
                    'is_active' => true,
                    'position' => (int) ($marketplaceCategory->subcategories()->max('position') ?? 0) + 1,
                    'created_by_user_id' => $admin->id,
                    'updated_by_user_id' => $admin->id,
                ]);
            }

            $oldValues = [
                'status' => $taxonomyRequest->status,
                'marketplace_category_id' => $taxonomyRequest->marketplace_category_id,
                'marketplace_subcategory_id' => $taxonomyRequest->marketplace_subcategory_id,
            ];
            $taxonomyRequest->update([
                'status' => $decision === 'reject'
                    ? MarketplaceSubcategoryRequest::STATUS_REJECTED
                    : MarketplaceSubcategoryRequest::STATUS_APPROVED,
                'marketplace_category_id' => $marketplaceCategory?->id,
                'marketplace_subcategory_id' => $subcategory?->id,
                'reviewed_by_user_id' => $admin->id,
                'reviewed_at' => now(),
                'decision_reason' => $decisionReason !== '' ? $decisionReason : null,
            ]);

            $this->audit->record(
                action: 'marketplace_subcategory_request.'.$decision,
                module: 'marketplace_taxonomy',
                subject: $taxonomyRequest,
                description: match ($decision) {
                    'reject' => 'Admin rejected a marketplace subcategory request.',
                    'reuse' => 'Admin approved a marketplace subcategory request by reusing an existing canonical entry.',
                    default => 'Admin approved a marketplace subcategory request and created a canonical entry.',
                },
                oldValues: $oldValues,
                newValues: [
                    'status' => $taxonomyRequest->status,
                    'business_category_id' => (int) $taxonomyRequest->business_category_id,
                    'marketplace_category_id' => $taxonomyRequest->marketplace_category_id,
                    'marketplace_subcategory_id' => $taxonomyRequest->marketplace_subcategory_id,
                    'resolution' => $decision === 'reuse' ? 'reused' : ($decision === 'reject' ? 'rejected' : 'created'),
                    'reason' => $decisionReason,
                ],
                severity: $decision === 'reject' ? 'warning' : 'info',
                request: $request,
                actor: $admin,
            );

            return $taxonomyRequest->fresh(['businessCategory', 'marketplaceCategory', 'marketplaceSubcategory', 'reviewer']);
        }, attempts: 3);
    }

    /**
     * @return EloquentCollection<int, MarketplaceCategory>
     */
    public function adminCategories(): EloquentCollection
    {
        return MarketplaceCategory::query()
            ->with(['businessCategories:id,name', 'subcategories' => fn ($query) => $query
                ->withCount(['products as visible_products_count' => fn ($products) => $products->visibleToBuyers()])
                ->orderBy('position')
                ->orderBy('name')])
            ->orderBy('position')
            ->orderBy('name')
            ->get();
    }

    /** @return LengthAwarePaginator<int, MarketplaceSubcategoryRequest> */
    public function pendingRequests(): LengthAwarePaginator
    {
        $requests = MarketplaceSubcategoryRequest::query()
            ->with(['sellerProfile.user', 'store', 'businessCategory', 'reviewer'])
            ->where('status', MarketplaceSubcategoryRequest::STATUS_PENDING_REVIEW)
            ->latest('id')
            ->paginate(20);

        $businessCategoryIds = $requests->getCollection()
            ->pluck('business_category_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
        $allowedCategories = MarketplaceCategory::query()
            ->where('is_active', true)
            ->whereHas('businessCategories', fn ($query) => $query
                ->whereIn('categories.id', $businessCategoryIds->all())
                ->where('categories.is_active', true))
            ->with([
                'businessCategories' => fn ($query) => $query
                    ->whereIn('categories.id', $businessCategoryIds->all())
                    ->where('categories.is_active', true),
                'subcategories' => fn ($query) => $query->where('is_active', true)->orderBy('position')->orderBy('name'),
            ])
            ->orderBy('position')
            ->orderBy('name')
            ->get();

        $requests->getCollection()->each(function (MarketplaceSubcategoryRequest $taxonomyRequest) use ($allowedCategories): void {
            $allowed = $allowedCategories
                ->filter(fn (MarketplaceCategory $category): bool => $category->businessCategories
                    ->contains('id', (int) $taxonomyRequest->business_category_id))
                ->values();
            $allowed->each(fn (MarketplaceCategory $category) => $category->subcategories
                ->each(fn (MarketplaceSubcategory $subcategory) => $subcategory->setRelation('marketplaceCategory', $category)));
            $matches = $allowed
                ->flatMap(fn (MarketplaceCategory $category) => $category->subcategories)
                ->filter(fn (MarketplaceSubcategory $subcategory): bool => $subcategory->name_key === $taxonomyRequest->name_key)
                ->values();

            $taxonomyRequest->setRelation('allowedMarketplaceCategories', $allowed);
            $taxonomyRequest->setRelation('matchingSubcategories', $matches);
        });

        return $requests;
    }

    public static function nameKey(string $name): string
    {
        return Str::lower(Str::squish($name));
    }

    private function assertMarketplaceCategoryMapped(MarketplaceCategory $category): void
    {
        if (! $category->businessCategories()->where('categories.is_active', true)->exists()) {
            throw ValidationException::withMessages([
                'marketplace_category_id' => 'This Buyer category is not mapped to an approved Seller business category.',
            ]);
        }
    }

    private function assertUniqueSubcategoryName(MarketplaceCategory $category, string $nameKey, ?int $ignoreId = null): void
    {
        if (MarketplaceSubcategory::query()
            ->where('marketplace_category_id', $category->id)
            ->where('name_key', $nameKey)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()) {
            throw ValidationException::withMessages([
                'name' => 'A marketplace subcategory with this name already exists in that Buyer category.',
            ]);
        }
    }

    private function uniqueSubcategorySlug(MarketplaceCategory $category, string $name): string
    {
        $baseSlug = Str::slug($name) ?: 'subcategory';
        $slug = $baseSlug;
        $suffix = 2;
        while (MarketplaceSubcategory::query()
            ->where('marketplace_category_id', $category->id)
            ->where('slug', $slug)
            ->exists()) {
            $slug = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
