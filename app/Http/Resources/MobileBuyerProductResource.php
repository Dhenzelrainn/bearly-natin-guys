<?php

namespace App\Http\Resources;

use App\Models\Product;
use App\Models\ProductImage;
use App\Support\ImageUrlPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MobileBuyerProductResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $catalogCard
     * @param  array{count?: int, average?: float}  $ratingSummary
     */
    public function __construct(
        mixed $resource,
        private readonly array $catalogCard,
        private readonly array $ratingSummary = [],
        private readonly bool $detailed = false,
    ) {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Product $product */
        $product = $this->resource;
        $variants = collect($this->catalogCard['variants'] ?? []);
        $images = $product->relationLoaded('images')
            ? $product->images
            : $product->images()->with('variant')->orderBy('position')->get();
        $publicImages = $images
            ->map(fn (ProductImage $image): ?array => $this->imagePayload($image))
            ->filter()
            ->values();
        $primaryImage = $publicImages->firstWhere('is_primary', true)
            ?? $publicImages->firstWhere('variant_id', null);
        $description = trim(strip_tags((string) $product->description));
        $descriptionSummary = Str::limit($description, 240, '…');
        $inStock = $variants->contains(fn (array $variant): bool => (int) ($variant['stock'] ?? 0) > 0);

        $payload = [
            'id' => (int) $product->id,
            'slug' => (string) $product->slug,
            'name' => (string) $product->name,
            'description' => $this->detailed ? (string) ($product->description ?? '') : $descriptionSummary,
            'description_summary' => $descriptionSummary,
            'store' => $product->store ? [
                'name' => (string) $product->store->name,
                'slug' => (string) $product->store->slug,
            ] : null,
            'category' => $product->category ? [
                'id' => (int) $product->category->id,
                'name' => (string) $product->category->name,
                'slug' => (string) $product->category->slug,
                'parent' => $product->category->parent && $product->category->parent->is_active
                    ? [
                        'id' => (int) $product->category->parent->id,
                        'name' => (string) $product->category->parent->name,
                        'slug' => (string) $product->category->parent->slug,
                    ]
                    : null,
            ] : null,
            'price_minor' => (int) ($this->catalogCard['price_minor'] ?? 0),
            'regular_price_minor' => (int) ($this->catalogCard['regular_price_minor'] ?? 0),
            'discount_percent' => (int) ($this->catalogCard['discount_percent'] ?? 0),
            'currency' => 'PHP',
            'image_url' => $primaryImage['url'] ?? null,
            'in_stock' => $inStock,
            'variants' => $variants
                ->map(fn (array $variant): array => [
                    'id' => (int) ($variant['id'] ?? 0),
                    'name' => (string) ($variant['name'] ?? ''),
                    'options' => (array) ($variant['options'] ?? []),
                    'price_minor' => (int) ($variant['price_minor'] ?? 0),
                    'regular_price_minor' => (int) ($variant['regular_price_minor'] ?? 0),
                    'discount_percent' => (int) ($variant['discount_percent'] ?? 0),
                    'currency' => 'PHP',
                    'in_stock' => (int) ($variant['stock'] ?? 0) > 0,
                ])
                ->values()
                ->all(),
            'rating_summary' => [
                'average' => (float) ($this->ratingSummary['average'] ?? 0.0),
                'count' => (int) ($this->ratingSummary['count'] ?? 0),
            ],
        ];

        if ($this->detailed) {
            $payload['images'] = $publicImages
                ->map(fn (array $image): array => [
                    'url' => $image['url'],
                    'alt_text' => $image['alt_text'],
                    'variant_id' => $image['variant_id'],
                    'is_primary' => $image['is_primary'],
                ])
                ->all();
        }

        return $payload;
    }

    /** @return array{url: string, alt_text: ?string, variant_id: ?int, is_primary: bool}|null */
    private function imagePayload(ProductImage $image): ?array
    {
        $url = $this->publicImageUrl($image);
        if ($url === null) {
            return null;
        }

        return [
            'url' => $url,
            'alt_text' => $image->alt_text,
            'variant_id' => $image->variant_id !== null ? (int) $image->variant_id : null,
            'is_primary' => (bool) $image->is_primary,
        ];
    }

    private function publicImageUrl(ProductImage $image): ?string
    {
        $diskName = (string) ($image->disk ?: 'public');
        $diskConfig = config("filesystems.disks.{$diskName}");
        if (! is_array($diskConfig)) {
            return null;
        }

        $isPublicDisk = $diskName === 'public'
            || ($diskConfig['visibility'] ?? null) === 'public'
            || filled($diskConfig['url'] ?? null);
        if (! $isPublicDisk) {
            return null;
        }

        $path = trim((string) $image->path);
        if ($path === '') {
            return null;
        }

        if (preg_match('~^https?://~i', $path) === 1) {
            return ImageUrlPolicy::enforceHttpsUnlessApprovedLocal($path);
        }

        if (str_starts_with($path, '//')) {
            return ImageUrlPolicy::enforceHttpsUnlessApprovedLocal($path);
        }

        $diskUrl = (string) ($diskConfig['url'] ?? '');
        $basePath = trim((string) (parse_url($diskUrl, PHP_URL_PATH) ?: ''), '/');
        $path = ltrim($path, '/');
        if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath.'/'))) {
            $path = ltrim(substr($path, strlen($basePath)), '/');
        }

        $url = ImageUrlPolicy::localPublicDiskUrl($diskName, $path)
            ?? Storage::disk($diskName)->url($path);
        if (! Str::startsWith($url, ['http://', 'https://', '//'])) {
            $url = url('/'.ltrim($url, '/'));
        }

        return ImageUrlPolicy::enforceHttpsUnlessApprovedLocal($url);
    }
}
