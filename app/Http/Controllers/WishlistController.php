<?php

namespace App\Http\Controllers;

use App\Models\Wishlist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WishlistController extends Controller
{
    public function index(): View
    {
        $sessionId = session()->getId();
        $wishlistItems = Wishlist::where('session_id', $sessionId)
            ->with('product')
            ->get();

        return view('wishlist.index', compact('wishlistItems'));
    }

    public function toggle(Request $request): JsonResponse
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
        ]);

        $sessionId = session()->getId();

        $wishlistItem = Wishlist::where('session_id', $sessionId)
            ->where('product_id', $request->product_id)
            ->first();

        if ($wishlistItem) {
            $wishlistItem->delete();
            $isWishlisted = false;
        } else {
            Wishlist::create([
                'session_id' => $sessionId,
                'product_id' => $request->product_id,
            ]);
            $isWishlisted = true;
        }

        $wishlistCount = Wishlist::where('session_id', $sessionId)->count();

        return response()->json([
            'success' => true,
            'is_wishlisted' => $isWishlisted,
            'wishlist_count' => $wishlistCount,
        ]);
    }
}
