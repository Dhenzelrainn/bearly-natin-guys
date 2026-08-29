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
            'stats' => [
                ['label' => 'Total Sales', 'value' => '₱128,450', 'change' => '+12.5% this month', 'icon' => 'philippine-peso', 'tone' => 'gold'],
                ['label' => 'Total Orders', 'value' => '342', 'change' => '+18 this week', 'icon' => 'shopping-bag', 'tone' => 'olive'],
                ['label' => 'Active Products', 'value' => '24', 'change' => '3 added this month', 'icon' => 'package', 'tone' => 'brown'],
                ['label' => 'Low Stock', 'value' => '5', 'change' => 'Needs attention', 'icon' => 'circle-alert', 'tone' => 'red'],
            ],
            'sales' => [9800, 13400, 13800, 18100, 22800, 15700, 24100],
            'orderStatuses' => [
                ['label' => 'New Orders', 'count' => 8, 'percent' => 40, 'icon' => 'shopping-bag', 'tone' => 'amber'],
                ['label' => 'To Prepare', 'count' => 5, 'percent' => 32, 'icon' => 'package-open', 'tone' => 'orange'],
                ['label' => 'Ready for Pickup', 'count' => 3, 'percent' => 22, 'icon' => 'store', 'tone' => 'olive'],
                ['label' => 'In Transit', 'count' => 7, 'percent' => 55, 'icon' => 'truck', 'tone' => 'blue'],
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

    public function products(Request $request): View
    {
        $products = collect($request->session()->get('seller.products', []));
        $categories = $products->pluck('category')->unique()->sort()->values();

        return view('seller.products', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'products' => $products,
            'categories' => $categories,
            'counts' => [
                'all' => $products->count(),
                'active' => $products->where('status', 'Active')->count(),
                'low' => $products->where('stock', '<=', 5)->where('status', 'Active')->count(),
                'archived' => $products->where('status', 'Archived')->count(),
            ],
        ]);
    }

    public function addProduct(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', 'string', 'max:80'],
            'price' => ['required', 'numeric', 'min:0'],
            'stock' => ['required', 'integer', 'min:0'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $products = $request->session()->get('seller.products', []);
        $id = (string) (collect($products)->max(fn ($item) => (int) $item['id']) + 1);
        $image = $request->hasFile('image') ? $request->file('image')->store('seller-products', 'public') : null;
        $products[] = [
            'id' => $id,
            'name' => $validated['name'],
            'category' => $validated['category'],
            'price' => (float) $validated['price'],
            'stock' => (int) $validated['stock'],
            'status' => 'Active',
            'image' => $image,
        ];
        $request->session()->put('seller.products', $products);

        return redirect()->route('seller.products')->with('success', 'Product added successfully.');
    }

    public function toggleProductArchive(Request $request, string $product): RedirectResponse
    {
        $products = collect($request->session()->get('seller.products', []))
            ->map(function (array $item) use ($product) {
                if ((string) $item['id'] === $product) {
                    $item['status'] = $item['status'] === 'Archived' ? 'Active' : 'Archived';
                }
                return $item;
            })->values()->all();

        $request->session()->put('seller.products', $products);
        return redirect()->route('seller.products')->with('success', 'Product status updated.');
    }
}
