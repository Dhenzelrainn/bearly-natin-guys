<?php

namespace App\Services;

use App\Enums\SellerOrderStatus;
use App\Models\OrderItem;
use App\Models\ProductReview;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductReviewService
{
    public const MAX_COMMENT = 500;

    public const MAX_RESPONSE = 400;

    public const MAX_PHOTO_KB = 10240;

    public function __construct(private readonly BuyerCatalogService $catalog) {}

    /**
     * Create or update the buyer's review for one purchased order item.
     *
     * Only items from completed Seller Orders can be reviewed, and each
     * order item carries at most one review.
     */
    public function submitReview(
        User $buyer,
        int $orderItemId,
        int $rating,
        string $comment,
        ?UploadedFile $photo = null,
    ): ProductReview {
        if ($rating < 1 || $rating > 5) {
            throw ValidationException::withMessages([
                'rating' => 'Choose a star rating from 1 to 5.',
            ]);
        }

        $comment = trim($comment);

        if ($comment === '' || mb_strlen($comment) > self::MAX_COMMENT) {
            throw ValidationException::withMessages([
                'comment' => 'Write a review of up to '.self::MAX_COMMENT.' characters.',
            ]);
        }

        if ($photo) {
            if (! in_array(strtolower((string) $photo->getClientOriginalExtension()), ['jpg', 'jpeg', 'png', 'webp'], true)) {
                throw ValidationException::withMessages([
                    'photo' => 'Review photos must be JPG, PNG, or WebP images.',
                ]);
            }

            if ($photo->getSize() === false || $photo->getSize() > self::MAX_PHOTO_KB * 1024) {
                throw ValidationException::withMessages([
                    'photo' => 'Review photos must be 10 MB or smaller.',
                ]);
            }
        }

        return DB::transaction(function () use ($buyer, $orderItemId, $rating, $comment, $photo): ProductReview {
            $item = OrderItem::query()
                ->whereKey($orderItemId)
                ->with(['sellerOrder.order'])
                ->lockForUpdate()
                ->firstOrFail();

            $sellerOrder = $item->sellerOrder;
            $order = $sellerOrder?->order;

            abort_unless($order && (int) $order->buyer_id === (int) $buyer->id, 404);
            abort_unless(
                $sellerOrder->status === SellerOrderStatus::Completed->value,
                409,
                'Only items from completed orders can be reviewed.'
            );

            $existing = ProductReview::query()
                ->where('order_item_id', $item->id)
                ->lockForUpdate()
                ->first();

            $photoPath = $existing?->photo_path;

            if ($photo) {
                $photoPath = $photo->store('review-photos', 'public');
            }

            if ($existing && $existing->photo_path && $existing->photo_path !== $photoPath) {
                Storage::disk($existing->photo_disk ?: 'public')->delete($existing->photo_path);
            }

            $review = ProductReview::query()->updateOrCreate(
                ['order_item_id' => $item->id],
                [
                    'buyer_id' => $buyer->id,
                    'order_id' => $order->id,
                    'seller_order_id' => $sellerOrder->id,
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'store_id' => $sellerOrder->store_id,
                    'rating' => $rating,
                    'comment' => $comment,
                    'photo_path' => $photoPath,
                    'photo_disk' => 'public',
                ],
            );

            return $review->fresh(['item', 'order']);
        }, attempts: 3);
    }

    /**
     * Post the owning store's public response to a buyer review.
     */
    public function reply(User $seller, int $reviewId, string $response): ProductReview
    {
        $response = trim($response);

        if ($response === '' || mb_strlen($response) > self::MAX_RESPONSE) {
            throw ValidationException::withMessages([
                'response' => 'Write a response of up to '.self::MAX_RESPONSE.' characters.',
            ]);
        }

        $review = ProductReview::query()
            ->whereKey($reviewId)
            ->with('store')
            ->lockForUpdate()
            ->firstOrFail();

        $storeId = $seller->sellerProfile?->store?->id;

        abort_unless($storeId && (int) $review->store_id === (int) $storeId, 403, 'You can only reply to reviews of your own Store.');

        $now = now();

        $review->update([
            'seller_response' => $response,
            'seller_response_by' => $seller->id,
            'seller_response_at' => $now,
        ]);

        return $review->fresh(['buyer', 'item', 'order']);
    }

    /**
     * The authenticated buyer's own reviews, keyed by order item.
     *
     * @return Collection<int, ProductReview>
     */
    public function buyerReviews(User $buyer): Collection
    {
        return ProductReview::query()
            ->where('buyer_id', $buyer->id)
            ->with(['item', 'order', 'sellerOrder'])
            ->latest('id')
            ->get();
    }

    /**
     * @return array{count: int, average: float, distribution: array<int, int>, with_comments: int, with_media: int}
     */
    public function productSummary(int $productId): array
    {
        $ratings = ProductReview::query()
            ->visible()
            ->where('product_id', $productId)
            ->pluck('rating');

        $distribution = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];

        foreach ($ratings as $rating) {
            $rating = (int) $rating;

            if (isset($distribution[$rating])) {
                $distribution[$rating]++;
            }
        }

        $count = $ratings->count();

        return [
            'count' => $count,
            'average' => $count > 0 ? round($ratings->avg(), 1) : 0.0,
            'distribution' => $distribution,
            'with_comments' => ProductReview::query()->visible()->where('product_id', $productId)->where('comment', '!=', '')->count(),
            'with_media' => ProductReview::query()->visible()->where('product_id', $productId)->whereNotNull('photo_path')->count(),
        ];
    }

    /**
     * Public aggregate for one Store, excluding reviews hidden from buyers.
     *
     * @return array{count: int, average: float}
     */
    public function storeSummary(int $storeId): array
    {
        $summary = ProductReview::query()
            ->visible()
            ->where('store_id', $storeId)
            ->selectRaw('COUNT(*) as review_count, AVG(rating) as average_rating')
            ->first();
        $count = (int) ($summary?->review_count ?? 0);

        return [
            'count' => $count,
            'average' => $count > 0 ? round((float) $summary->average_rating, 1) : 0.0,
        ];
    }

    /**
     * Public rating summaries for a product collection, loaded in one query.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, array{count: int, average: float}>
     */
    public function productSummaries(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(
            array_map('intval', $productIds),
            fn (int $id): bool => $id > 0,
        )));

        if ($productIds === []) {
            return [];
        }

        return ProductReview::query()
            ->visible()
            ->whereIn('product_id', $productIds)
            ->selectRaw('product_id, COUNT(*) as review_count, AVG(rating) as average_rating')
            ->groupBy('product_id')
            ->get()
            ->mapWithKeys(fn (ProductReview $summary): array => [(int) $summary->product_id => [
                'count' => (int) $summary->review_count,
                'average' => round((float) $summary->average_rating, 1),
            ]])
            ->all();
    }

    /**
     * @return Collection<int, ProductReview>
     */
    public function productReviews(int $productId, int $limit = 10): Collection
    {
        return ProductReview::query()
            ->visible()
            ->where('product_id', $productId)
            ->with(['buyer', 'item'])
            ->latest('id')
            ->take(max(1, min($limit, 50)))
            ->get();
    }

    /**
     * Seller-facing feedback list plus summary statistics for one store.
     *
     * @return array{summary: array<string, mixed>, distribution: array<int, int>, reviews: array<int, array<string, mixed>>}
     */
    public function storeFeedback(int $storeId): array
    {
        $reviews = ProductReview::query()
            ->where('store_id', $storeId)
            ->with(['buyer', 'item', 'order', 'sellerOrder'])
            ->latest('id')
            ->get();

        $total = $reviews->count();
        $replied = $reviews->filter(fn (ProductReview $review): bool => filled($review->seller_response))->count();
        $ratings = $reviews->pluck('rating');
        $distribution = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];

        foreach ($ratings as $rating) {
            $rating = (int) $rating;

            if (isset($distribution[$rating])) {
                $distribution[$rating]++;
            }
        }

        return [
            'summary' => [
                'rating' => $total > 0 ? number_format(round($ratings->avg(), 1), 1) : '0.0',
                'total' => $total,
                'new' => $total - $replied,
                'response_rate' => $total > 0 ? (int) round($replied / $total * 100).'%' : '—',
            ],
            'distribution' => $distribution,
            'reviews' => $reviews->map(fn (ProductReview $review): array => [
                'id' => (int) $review->id,
                'customer' => (string) ($review->buyer?->name ?? 'Former buyer'),
                'initials' => $this->initials($review->buyer?->name),
                'order' => '#'.(string) ($review->order?->order_no ?? ''),
                'product' => (string) ($review->item?->product_name ?? ''),
                'variant' => (string) ($review->item?->variant_name ?: 'Standard'),
                'rating' => (int) $review->rating,
                'date' => $review->created_at?->format('M j, Y g:i A') ?? '',
                'comment' => (string) $review->comment,
                'photo' => $this->photoUrl($review),
                'status' => filled($review->seller_response) ? 'replied' : 'new',
                'response' => $review->seller_response,
                'response_at' => $review->seller_response_at?->format('M j, Y g:i A'),
            ])->all(),
        ];
    }

    /**
     * Public review payload for product surfaces (buyer names are masked).
     *
     * @return array<string, mixed>
     */
    public function publicPayload(ProductReview $review): array
    {
        $review->loadMissing(['buyer', 'item']);

        return [
            'id' => (int) $review->id,
            'name' => $this->maskName($review->buyer?->name),
            'date' => $review->created_at?->format('Y-m-d H:i') ?? '',
            'date_display' => $review->created_at?->format('M j, Y') ?? '',
            'rating' => (int) $review->rating,
            'text' => (string) $review->comment,
            'variation' => (string) ($review->item?->variant_name ?: 'Standard'),
            'photo' => $this->photoUrl($review),
            'response' => $review->seller_response,
            'response_at' => $review->seller_response_at?->format('Y-m-d H:i'),
            'response_at_display' => $review->seller_response_at?->format('M j, Y'),
        ];
    }

    public function photoUrl(ProductReview $review): string
    {
        if (! $review->photo_path) {
            return '';
        }

        return Str::startsWith($review->photo_path, ['http://', 'https://', '/'])
            ? $review->photo_path
            : Storage::disk($review->photo_disk ?: 'public')->url($review->photo_path);
    }

    public function itemImageUrl(OrderItem $item): string
    {
        return $this->catalog->orderItemImageUrl($item);
    }

    public function maskName(?string $name): string
    {
        $clean = preg_replace('/[^a-z0-9]/', '', strtolower((string) $name)) ?? '';

        if (strlen($clean) < 2) {
            return 'b******r';
        }

        return $clean[0].'******'.substr($clean, -1);
    }

    private function initials(?string $name): string
    {
        $parts = preg_split('/\s+/', trim((string) $name)) ?: [];

        $initials = implode('', array_map(fn (string $part): string => strtoupper(mb_substr($part, 0, 1)), array_slice($parts, 0, 2)));

        return $initials !== '' ? $initials : '—';
    }
}
