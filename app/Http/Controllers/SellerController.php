<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SellerController extends Controller
{
    private function seller(): array
    {
        return [
            'name' => 'Bea Rivera',
            'first_name' => 'Bea',
            'initials' => 'BR',
            'email' => 'bea@juansclothing.test',
            'store' => "Juan's Clothing Shop",
        ];
    }

    private function notifications(): array
    {
        return [
            ['title' => '8 new orders need review', 'time' => '5 minutes ago', 'type' => 'warning'],
            ['title' => 'Classic Linen Shirt is low in stock', 'time' => '42 minutes ago', 'type' => 'danger'],
            ['title' => 'Order #BR-1047 is ready for pickup', 'time' => '1 hour ago', 'type' => 'success'],
        ];
    }

    public function dashboard(): View
    {
        return view('seller.dashboard', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'operations' => [
                'account_status' => 'Seller account active',
                'updated_at' => '10:42 AM',
                'action_count' => 18,
                'pickup_time' => '3:00 PM',
                'tasks' => [
                    ['count' => 8, 'label' => 'New orders', 'action' => 'Review orders', 'icon' => 'clipboard-list', 'tone' => 'amber', 'target' => route('seller.orders') . '?status=new'],
                    ['count' => 5, 'label' => 'To pack', 'action' => 'Prepare orders', 'icon' => 'package', 'tone' => 'olive', 'target' => route('seller.orders') . '?status=to-prepare'],
                    ['count' => 3, 'label' => 'Waybills', 'action' => 'Print labels', 'icon' => 'printer', 'tone' => 'brown', 'target' => route('seller.orders') . '?status=to-prepare'],
                    ['count' => 2, 'label' => 'Pickup requests', 'action' => 'Schedule pickup', 'icon' => 'truck', 'tone' => 'green', 'target' => route('seller.orders') . '?status=ready-pickup'],
                ],
            ],
            'stats' => [
                ['label' => 'Gross Sales', 'value' => '₱128,450', 'change' => '+12.5% this month', 'icon' => 'philippine-peso', 'tone' => 'gold'],
                ['label' => 'Net Revenue', 'value' => '₱115,605', 'change' => 'After 10% commission', 'icon' => 'package-check', 'tone' => 'olive'],
                ['label' => 'Average Order Value', 'value' => '₱1,420', 'change' => 'Per completed order', 'icon' => 'shopping-bag', 'tone' => 'brown'],
                ['label' => 'Fulfillment Rate', 'value' => '96.8%', 'change' => '+1.4% vs last month', 'icon' => 'circle-check-big', 'tone' => 'olive'],
            ],
            'sales' => [9800, 13400, 13800, 18100, 22800, 15700, 24100],
            'topProducts' => [
                ['name' => 'Classic Linen Shirt', 'sku' => 'CLS-LINEN-SHIRT', 'sold' => 42, 'revenue' => '₱54,600', 'percent' => 100, 'icon' => 'shirt'],
                ['name' => 'Canvas Tote Bag', 'sku' => 'CNV-TOTE-BAG', 'sold' => 31, 'revenue' => '₱27,869', 'percent' => 74, 'icon' => 'shopping-bag'],
                ['name' => 'Everyday Sneakers', 'sku' => 'EV-SNKRS-WHT', 'sold' => 18, 'revenue' => '₱23,382', 'percent' => 43, 'icon' => 'footprints'],
            ],
            'recentOrders' => [
                ['id' => '#BR-1048', 'customer' => 'Maria Santos', 'items' => '2 items', 'total' => '₱1,850', 'status' => 'To Prepare', 'tone' => 'warning'],
                ['id' => '#BR-1047', 'customer' => 'Carlo Reyes', 'items' => '1 item', 'total' => '₱899', 'status' => 'Ready for Pickup', 'tone' => 'success'],
                ['id' => '#BR-1046', 'customer' => 'Anne Cruz', 'items' => '3 items', 'total' => '₱2,450', 'status' => 'In Transit', 'tone' => 'info'],
                ['id' => '#BR-1045', 'customer' => 'Miguel Tan', 'items' => '1 item', 'total' => '₱1,299', 'status' => 'Completed', 'tone' => 'neutral'],
            ],
            'inventoryAlerts' => [
                ['name' => 'Classic Linen Shirt', 'stock' => '3 left', 'icon' => 'shirt', 'status' => 'Low Stock', 'tone' => 'warning'],
                ['name' => 'Canvas Tote Bag', 'stock' => '2 left', 'icon' => 'shopping-bag', 'status' => 'Low Stock', 'tone' => 'warning'],
                ['name' => 'Everyday Sneakers / Size 38', 'stock' => '0 left', 'icon' => 'footprints', 'status' => 'Out of Stock', 'tone' => 'danger'],
            ],
            'deliverySummary' => [
                'pickup_time' => '3:00 PM',
                'pickup_date' => 'Today · Laguna route',
                'ready' => 5,
                'not_ready' => 2,
                'steps' => [
                    ['label' => 'Pack orders', 'detail' => 'Complete before 1:30 PM', 'icon' => 'package-check', 'complete' => true],
                    ['label' => 'Print waybills', 'detail' => '3 labels are ready', 'icon' => 'printer', 'complete' => false],
                    ['label' => 'Courier handover', 'detail' => 'Scheduled for 3:00 PM', 'icon' => 'truck', 'complete' => false],
                ],
            ],
            'feedbackSummary' => [
                'rating' => '4.7',
                'new_count' => 3,
                'items' => [
                    ['customer' => 'Maria Santos', 'initials' => 'MS', 'rating' => '5.0', 'comment' => 'Great quality and fast preparation.'],
                    ['customer' => 'Carlo Reyes', 'initials' => 'CR', 'rating' => '4.0', 'comment' => 'Item arrived in good condition.'],
                ],
            ],
        ]);
    }

    public function inventory(): View
    {
        return view('seller.inventory', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'inventoryTotal' => 48,
            'inventorySummary' => [
                ['label' => 'Total SKUs', 'value' => '48', 'note' => '24 active products', 'icon' => 'box', 'tone' => 'neutral'],
                ['label' => 'Available Units', 'value' => '1,246', 'note' => 'Across all variations', 'icon' => 'package-check', 'tone' => 'success'],
                ['label' => 'Low Stock', 'value' => '5', 'note' => 'Below threshold', 'icon' => 'triangle-alert', 'tone' => 'warning'],
                ['label' => 'Out of Stock', 'value' => '2', 'note' => 'Needs restocking', 'icon' => 'circle-x', 'tone' => 'danger'],
            ],
            'inventoryAttention' => ['total' => 7, 'low' => 5, 'out' => 2],
            'inventoryTabs' => [
                ['key' => 'all', 'label' => 'All Inventory', 'count' => 48],
                ['key' => 'low-stock', 'label' => 'Low Stock', 'count' => 5],
                ['key' => 'out-of-stock', 'label' => 'Out of Stock', 'count' => 2],
                ['key' => 'archived', 'label' => 'Archived', 'count' => null],
            ],
            'inventoryCategories' => ['Fashion and Apparel'],
            'inventoryItems' => [
                ['id' => 'inv-1', 'parent' => 'classic-linen-shirt', 'variation_row' => false, 'product' => 'Classic Linen Shirt', 'sku' => 'CLS-OLV-M', 'variation' => 'Olive / Medium', 'category' => 'Fashion and Apparel', 'icon' => 'shirt', 'on_hand' => 12, 'reserved' => 3, 'available' => 9, 'threshold' => 5, 'status' => 'In Stock', 'status_key' => 'in-stock'],
                ['id' => 'inv-2', 'parent' => 'classic-linen-shirt', 'variation_row' => true, 'product' => 'Classic Linen Shirt', 'sku' => 'CLS-CRM-L', 'variation' => 'Cream / Large', 'category' => 'Fashion and Apparel', 'icon' => 'shirt', 'on_hand' => 6, 'reserved' => 2, 'available' => 4, 'threshold' => 5, 'status' => 'Low Stock', 'status_key' => 'low-stock'],
                ['id' => 'inv-3', 'parent' => 'canvas-tote-bag', 'variation_row' => false, 'product' => 'Canvas Tote Bag', 'sku' => 'CTB-NAT-OS', 'variation' => 'Natural / One Size', 'category' => 'Fashion and Apparel', 'icon' => 'shopping-bag', 'on_hand' => 7, 'reserved' => 5, 'available' => 2, 'threshold' => 4, 'status' => 'Low Stock', 'status_key' => 'low-stock'],
                ['id' => 'inv-4', 'parent' => 'everyday-sneakers', 'variation_row' => false, 'product' => 'Everyday Sneakers', 'sku' => 'ES-WHT-38', 'variation' => 'White / Size 38', 'category' => 'Fashion and Apparel', 'icon' => 'footprints', 'on_hand' => 0, 'reserved' => 0, 'available' => 0, 'threshold' => 3, 'status' => 'Out of Stock', 'status_key' => 'out-of-stock'],
                ['id' => 'inv-5', 'parent' => 'everyday-sneakers', 'variation_row' => true, 'product' => 'Everyday Sneakers', 'sku' => 'ES-BLK-40', 'variation' => 'Black / Size 40', 'category' => 'Fashion and Apparel', 'icon' => 'footprints', 'on_hand' => 18, 'reserved' => 4, 'available' => 14, 'threshold' => 3, 'status' => 'In Stock', 'status_key' => 'in-stock'],
            ],
            'stockMovements' => [
                ['date' => 'Aug 31, 2026 · 10:30 AM', 'type' => 'Order Deduction', 'tone' => 'order', 'product' => 'Classic Linen Shirt', 'sku' => 'CLS-OLV-M', 'variation' => 'Olive / Medium', 'reference' => 'Order #BR-1058', 'quantity' => '−2', 'direction' => 'negative', 'balance' => 12, 'actor' => 'System'],
                ['date' => 'Aug 31, 2026 · 9:15 AM', 'type' => 'Reservation', 'tone' => 'reservation', 'product' => 'Canvas Tote Bag', 'sku' => 'CTB-NAT-OS', 'variation' => 'Natural / One Size', 'reference' => 'Order #BR-1057', 'quantity' => '−1', 'direction' => 'negative', 'balance' => 7, 'actor' => 'System'],
                ['date' => 'Aug 30, 2026 · 4:42 PM', 'type' => 'Manual Addition', 'tone' => 'addition', 'product' => 'Everyday Sneakers', 'sku' => 'ES-BLK-40', 'variation' => 'Black / Size 40', 'reference' => 'Stock count', 'quantity' => '+8', 'direction' => 'positive', 'balance' => 18, 'actor' => 'Bea Rivera'],
                ['date' => 'Aug 30, 2026 · 2:10 PM', 'type' => 'Damage Adjustment', 'tone' => 'damage', 'product' => 'Classic Linen Shirt', 'sku' => 'CLS-CRM-L', 'variation' => 'Cream / Large', 'reference' => 'Damaged item', 'quantity' => '−1', 'direction' => 'negative', 'balance' => 6, 'actor' => 'Bea Rivera'],
            ],
        ]);
    }


    public function orders(): View
    {
        return view('seller.orders', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'orderQueue' => [
                ['label' => 'New orders', 'count' => 8, 'note' => 'Review within 2 hrs', 'icon' => 'clipboard-list', 'tone' => 'gold'],
                ['label' => 'To prepare', 'count' => 5, 'note' => 'Pack before 1:30 PM', 'icon' => 'package', 'tone' => 'olive'],
                ['label' => 'Waybills to print', 'count' => 3, 'note' => "For today's pickup", 'icon' => 'printer', 'tone' => 'blue'],
                ['label' => 'Ready for pickup', 'count' => 2, 'note' => 'Courier pickup at 3:00 PM', 'icon' => 'truck', 'tone' => 'brown'],
            ],
            'orderTabs' => [
                ['key' => 'all', 'label' => 'All Orders', 'count' => 23],
                ['key' => 'new', 'label' => 'New', 'count' => 8],
                ['key' => 'to-prepare', 'label' => 'To Prepare', 'count' => 5],
                ['key' => 'ready-pickup', 'label' => 'Ready for Pickup', 'count' => 3],
                ['key' => 'in-transit', 'label' => 'In Transit', 'count' => 4],
                ['key' => 'completed', 'label' => 'Completed', 'count' => null],
                ['key' => 'cancelled', 'label' => 'Cancelled', 'count' => null],
            ],
            'orders' => [
                ['id' => '#BR-1058', 'customer' => 'Maria Santos', 'items' => '2 items', 'payment' => 'GCash · Paid', 'payment_key' => 'paid', 'total' => '₱1,850', 'deadline' => 'Today · 11:30 AM', 'date_key' => 'today', 'urgent' => true, 'status' => 'New', 'status_key' => 'new', 'tone' => 'new', 'action' => 'Review'],
                ['id' => '#BR-1057', 'customer' => 'Carlo Reyes', 'items' => '1 item', 'payment' => 'Cash on Delivery', 'payment_key' => 'cod', 'total' => '₱899', 'deadline' => 'Today · 1:30 PM', 'date_key' => 'today', 'urgent' => true, 'status' => 'To Prepare', 'status_key' => 'to-prepare', 'tone' => 'prepare', 'action' => 'Prepare'],
                ['id' => '#BR-1056', 'customer' => 'Ana Cruz', 'items' => '3 items', 'payment' => 'Maya · Paid', 'payment_key' => 'paid', 'total' => '₱2,450', 'deadline' => 'Today · 3:00 PM', 'date_key' => 'today', 'urgent' => false, 'status' => 'Waybill Ready', 'status_key' => 'to-prepare', 'tone' => 'waybill', 'action' => 'Print Waybill'],
                ['id' => '#BR-1055', 'customer' => 'Miguel Garcia', 'items' => '1 item', 'payment' => 'Cash on Delivery', 'payment_key' => 'cod', 'total' => '₱1,299', 'deadline' => 'Today · 3:00 PM', 'date_key' => 'today', 'urgent' => false, 'status' => 'Ready for Pickup', 'status_key' => 'ready-pickup', 'tone' => 'pickup', 'action' => 'Schedule Pickup'],
                ['id' => '#BR-1054', 'customer' => 'Jamie Lim', 'items' => '2 items', 'payment' => 'GCash · Paid', 'payment_key' => 'paid', 'total' => '₱1,720', 'deadline' => 'September 1', 'date_key' => 'upcoming', 'urgent' => false, 'status' => 'In Transit', 'status_key' => 'in-transit', 'tone' => 'transit', 'action' => 'Track'],
                ['id' => '#BR-1053', 'customer' => 'Sofia Mendoza', 'items' => '1 item', 'payment' => 'GCash · Paid', 'payment_key' => 'paid', 'total' => '₱1,050', 'deadline' => 'Delivered Aug 30', 'date_key' => 'upcoming', 'urgent' => false, 'status' => 'Completed', 'status_key' => 'completed', 'tone' => 'completed', 'action' => 'View'],
            ],
        ]);
    }

    public function store(Request $request): View
    {
        $store = array_merge([
            'name' => "Juan's Clothing Shop",
            'location' => 'Santa Rosa City, Laguna',
            'category' => 'Fashion and Apparel',
            'description' => '',
            'email' => 'juan@example.com',
            'phone' => '+63 917 123 4567',
            'profile_photo' => null,
            'cover_photo' => null,
            'published' => false,
        ], $request->session()->get('seller.store', []));

        $completed = 2 + (int) filled($store['profile_photo']) + (int) filled($store['cover_photo']) + (int) filled($store['description']);

        return view('seller.store', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'store' => $store,
            'completion' => $completed * 20,
        ]);
    }

    public function saveStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'description' => ['nullable', 'string', 'max:500'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'cover_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'intent' => ['required', 'in:draft,publish'],
        ]);

        $store = array_merge($request->session()->get('seller.store', []), [
            'description' => $validated['description'] ?? '',
            'email' => $validated['email'],
            'phone' => $validated['phone'],
        ]);

        foreach (['profile_photo', 'cover_photo'] as $photo) {
            if ($request->hasFile($photo)) {
                $store[$photo] = $request->file($photo)->store('seller-store', 'public');
            }
        }

        $complete = filled($store['description'] ?? null)
            && filled($store['profile_photo'] ?? null)
            && filled($store['cover_photo'] ?? null);
        $store['published'] = $validated['intent'] === 'publish' && $complete;
        $request->session()->put('seller.store', $store);

        $message = $store['published'] ? 'Your store profile is now published.' : 'Your store profile was saved as a draft.';
        return redirect()->route('seller.store')->with('success', $message);
    }

    private function productCategories(): array
    {
        return [
            'Fashion and Apparel',
            'Jewelry and Watches',
            'Electronics and Gadgets',
            'Home and Furniture',
            'Beauty and Personal Care',
            'Food and Gourmet',
            'Sports and Outdoors',
            'Books and Stationery',
            'Automotive and Parts',
            'Toys and Hobbies',
            'Other',
        ];
    }

    private function normalizeProduct(array $product): array
    {
        return array_merge([
            'id' => '',
            'name' => '',
            'category' => '',
            'description' => '',
            'sku' => '',
            'price' => 0,
            'discount_percent' => 0,
            'voucher_eligible' => false,
            'stock' => 0,
            'low_stock_threshold' => 5,
            'option_one_name' => '',
            'option_one_values' => '',
            'option_two_name' => '',
            'option_two_values' => '',
            'status' => 'Draft',
            'previous_status' => 'Active',
            'image' => null,
            'gallery_images' => [],
        ], $product);
    }

    private function validateProduct(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sku' => ['nullable', 'string', 'max:60'],
            'price' => ['required', 'numeric', 'min:0'],
            'discount_percent' => ['nullable', 'integer', 'min:0', 'max:90'],
            'voucher_eligible' => ['nullable', 'boolean'],
            'stock' => ['required', 'integer', 'min:0'],
            'low_stock_threshold' => ['required', 'integer', 'min:0'],
            'option_one_name' => ['nullable', 'string', 'max:40'],
            'option_one_values' => ['nullable', 'string', 'max:250'],
            'option_two_name' => ['nullable', 'string', 'max:40'],
            'option_two_values' => ['nullable', 'string', 'max:250'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'gallery_images' => ['nullable', 'array', 'max:4'],
            'gallery_images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'intent' => ['required', 'in:draft,publish'],
        ]);
    }

    public function products(Request $request): View
    {
        $products = collect($request->session()->get('seller.products', []))
            ->map(fn (array $product) => $this->normalizeProduct($product));

        return view('seller.products', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'products' => $products,
            'categories' => $products->pluck('category')->filter()->unique()->sort()->values(),
            'statuses' => $products->pluck('status')->filter()->unique()->sort()->values(),
            'counts' => [
                'all' => $products->count(),
                'active' => $products->where('status', 'Active')->count(),
                'low' => $products->filter(fn (array $product) => $product['status'] === 'Active'
                    && (int) $product['stock'] <= (int) $product['low_stock_threshold'])->count(),
                'archived' => $products->where('status', 'Archived')->count(),
            ],
        ]);
    }

    public function createProduct(): View
    {
        return view('seller.product-form', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'categories' => $this->productCategories(),
            'product' => $this->normalizeProduct([]),
            'mode' => 'create',
        ]);
    }

    public function addProduct(Request $request): RedirectResponse
    {
        $validated = $this->validateProduct($request);
        $products = $request->session()->get('seller.products', []);
        $id = (string) ((int) collect($products)->max(fn ($item) => (int) ($item['id'] ?? 0)) + 1);

        $galleryImages = [];
        foreach ($request->file('gallery_images', []) as $image) {
            $galleryImages[] = $image->store('seller-products', 'public');
        }

        $products[] = $this->normalizeProduct([
            'id' => $id,
            'name' => $validated['name'],
            'category' => $validated['category'],
            'description' => $validated['description'] ?? '',
            'sku' => ($validated['sku'] ?? '') ?: 'BR-'.str_pad($id, 4, '0', STR_PAD_LEFT),
            'price' => (float) $validated['price'],
            'discount_percent' => (int) ($validated['discount_percent'] ?? 0),
            'voucher_eligible' => (bool) ($validated['voucher_eligible'] ?? false),
            'stock' => (int) $validated['stock'],
            'low_stock_threshold' => (int) $validated['low_stock_threshold'],
            'option_one_name' => $validated['option_one_name'] ?? '',
            'option_one_values' => $validated['option_one_values'] ?? '',
            'option_two_name' => $validated['option_two_name'] ?? '',
            'option_two_values' => $validated['option_two_values'] ?? '',
            'status' => $validated['intent'] === 'publish' ? 'Active' : 'Draft',
            'previous_status' => $validated['intent'] === 'publish' ? 'Active' : 'Draft',
            'image' => $request->hasFile('image')
                ? $request->file('image')->store('seller-products', 'public')
                : null,
            'gallery_images' => $galleryImages,
        ]);

        $request->session()->put('seller.products', $products);

        return redirect()->route('seller.products')->with(
            'success',
            $validated['intent'] === 'publish' ? 'Product published successfully.' : 'Product saved as a draft.'
        );
    }

    public function editProduct(Request $request, string $product): View
    {
        $item = collect($request->session()->get('seller.products', []))
            ->first(fn (array $item) => (string) ($item['id'] ?? '') === $product);

        abort_if(!$item, 404);

        return view('seller.product-form', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'categories' => $this->productCategories(),
            'product' => $this->normalizeProduct($item),
            'mode' => 'edit',
        ]);
    }

    public function updateProduct(Request $request, string $product): RedirectResponse
    {
        $validated = $this->validateProduct($request);
        $found = false;

        $products = collect($request->session()->get('seller.products', []))
            ->map(function (array $item) use ($request, $validated, $product, &$found) {
                if ((string) ($item['id'] ?? '') !== $product) {
                    return $item;
                }

                $found = true;
                $item = $this->normalizeProduct($item);
                $newGalleryImages = $request->file('gallery_images', []);
                $galleryImages = $item['gallery_images'];
                if (count($newGalleryImages) > 0) {
                    $galleryImages = [];
                    foreach ($newGalleryImages as $image) {
                        $galleryImages[] = $image->store('seller-products', 'public');
                    }
                }

                return array_merge($item, [
                    'name' => $validated['name'],
                    'category' => $validated['category'],
                    'description' => $validated['description'] ?? '',
                    'sku' => ($validated['sku'] ?? '') ?: $item['sku'],
                    'price' => (float) $validated['price'],
                    'discount_percent' => (int) ($validated['discount_percent'] ?? 0),
                    'voucher_eligible' => (bool) ($validated['voucher_eligible'] ?? false),
                    'stock' => (int) $validated['stock'],
                    'low_stock_threshold' => (int) $validated['low_stock_threshold'],
                    'option_one_name' => $validated['option_one_name'] ?? '',
                    'option_one_values' => $validated['option_one_values'] ?? '',
                    'option_two_name' => $validated['option_two_name'] ?? '',
                    'option_two_values' => $validated['option_two_values'] ?? '',
                    'status' => $validated['intent'] === 'publish' ? 'Active' : 'Draft',
                    'previous_status' => $validated['intent'] === 'publish' ? 'Active' : 'Draft',
                    'image' => $request->hasFile('image')
                        ? $request->file('image')->store('seller-products', 'public')
                        : $item['image'],
                    'gallery_images' => $galleryImages,
                ]);
            })->values()->all();

        abort_unless($found, 404);
        $request->session()->put('seller.products', $products);

        return redirect()->route('seller.products')->with(
            'success',
            $validated['intent'] === 'publish' ? 'Product changes published.' : 'Product changes saved as a draft.'
        );
    }

    public function toggleProductArchive(Request $request, string $product): RedirectResponse
    {
        $products = collect($request->session()->get('seller.products', []))
            ->map(function (array $item) use ($product) {
                if ((string) ($item['id'] ?? '') === $product) {
                    $item = $this->normalizeProduct($item);
                    if ($item['status'] === 'Archived') {
                        $item['status'] = $item['previous_status'] ?: 'Draft';
                    } else {
                        $item['previous_status'] = $item['status'];
                        $item['status'] = 'Archived';
                    }
                }
                return $item;
            })->values()->all();

        $request->session()->put('seller.products', $products);
        return redirect()->route('seller.products')->with('success', 'Product status updated.');
    }
}
