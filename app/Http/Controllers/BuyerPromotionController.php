<?php

namespace App\Http\Controllers;

use App\Services\PromotionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BuyerPromotionController extends Controller
{
    public function wallet(Request $request, PromotionService $promotions): JsonResponse
    {
        $wallet = $promotions->wallet($request->user());

        return response()->json([
            'data' => [
                'platform' => $wallet['platform']->values()->all(),
                'store' => $wallet['store']->values()->all(),
                'claimed_count' => (int) $wallet['claimed_count'],
            ],
            'message' => 'Vouchers loaded.',
        ]);
    }

    public function claim(Request $request, PromotionService $promotions): JsonResponse
    {
        $data = $request->validate([
            'platform_promotion_id' => ['nullable', 'integer', 'min:1'],
            'store_promotion_id' => ['nullable', 'integer', 'min:1'],
            'code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);

        if (blank($data['platform_promotion_id'] ?? null)
            && blank($data['store_promotion_id'] ?? null)
            && blank($data['code'] ?? null)) {
            return response()->json([
                'message' => 'Choose a voucher or enter a voucher code.',
                'errors' => ['code' => ['Choose a voucher or enter a voucher code.']],
            ], 422);
        }

        $claim = $promotions->claim($request->user(), $data);

        return response()->json([
            'data' => $claim,
            'message' => 'Voucher added to My Vouchers.',
        ], 201);
    }
}
