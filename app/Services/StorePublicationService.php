<?php

namespace App\Services;

use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StorePublicationService
{
    public function __construct(
        private readonly SellerProductService $products,
    ) {}

    /**
     * Return the current publication requirements for the authenticated Seller.
     *
     * @return array{store: Store, sellerProfile: SellerProfile, requirements: array<string, bool>, ready: bool}
     */
    public function readiness(User $user): array
    {
        $profile = $this->sellerProfileFor($user);
        $store = $this->products->storeFor($user);

        return $this->readinessFor($user, $profile, $store);
    }

    /**
     * Publish or unpublish the Store owned by the authenticated Seller.
     *
     * Repeating the requested state is a no-op. A repeated publish therefore
     * preserves the original published_at timestamp.
     */
    public function setPublished(User $user, bool $published): Store
    {
        return DB::transaction(function () use ($user, $published): Store {
            $profile = $this->sellerProfileFor($user, lock: true);
            $store = $this->products->storeFor($user);
            $store = Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();

            if ($published && $store->publication_status === 'published') {
                return $store;
            }

            if (! $published && $store->publication_status !== 'published' && $store->published_at === null) {
                return $store;
            }

            if ($published) {
                $state = $this->readinessFor($user, $profile, $store);

                if (! $state['ready']) {
                    throw ValidationException::withMessages([
                        'published' => 'Complete every store publication requirement before publishing.',
                    ]);
                }

                $store->update([
                    'publication_status' => 'published',
                    'published_at' => now(),
                ]);
            } else {
                $store->update([
                    'publication_status' => 'draft',
                    'published_at' => null,
                ]);
            }

            return $store->fresh();
        });
    }

    private function sellerProfileFor(User $user, bool $lock = false): SellerProfile
    {
        $query = $user->sellerProfile();

        if ($lock) {
            $query->lockForUpdate();
        }

        $profile = $query->first();

        if (! $profile) {
            throw (new ModelNotFoundException)->setModel(SellerProfile::class, [$user->id]);
        }

        return $profile;
    }

    /**
     * @return array{store: Store, sellerProfile: SellerProfile, requirements: array<string, bool>, ready: bool}
     */
    private function readinessFor(User $user, SellerProfile $profile, Store $store): array
    {
        $category = $profile->approvedCategory;
        $requirements = [
            'seller_approved' => $user->status === 'active'
                && $profile->standing_status === 'good_standing'
                && filled($profile->approved_at)
                && $category !== null
                && (bool) $category->is_active,
            'contact_information' => filled($store->contact_email) && filled($store->contact_phone),
            'description' => filled($store->description),
            'profile_photo' => filled($store->logo_path),
            'cover_photo' => filled($store->banner_path),
            'eligible_product' => $store->products()->eligibleForStorePublication()->exists(),
        ];

        return [
            'store' => $store,
            'sellerProfile' => $profile,
            'requirements' => $requirements,
            'ready' => ! in_array(false, $requirements, true),
        ];
    }
}
