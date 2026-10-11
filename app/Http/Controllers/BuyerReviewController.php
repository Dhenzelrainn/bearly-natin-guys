<?php

namespace App\Http\Controllers;

use App\Services\ProductReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BuyerReviewController extends Controller
{
    public function index(Request $request, ProductReviewService $reviews): JsonResponse
    {
        $data = $reviews->buyerReviews($request->user())->mapWithKeys(fn ($review): array => [
            (int) $review->order_item_id => [
                'order_item_id' => (int) $review->order_item_id,
                'order_no' => (string) ($review->order?->order_no ?? ''),
                'rating' => (int) $review->rating,
                'comment' => (string) $review->comment,
                'photo' => $reviews->photoUrl($review),
                'reviewed_at' => $review->created_at?->format('M j, Y') ?? '',
            ],
        ]);

        return response()->json([
            'data' => $data->all(),
            'message' => 'Reviews retrieved successfully.',
        ]);
    }

    public function store(Request $request, ProductReviewService $reviews): JsonResponse
    {
        $data = $request->validate([
            'order_item_id' => ['required', 'integer', 'min:1'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['required', 'string', 'max:500'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $review = $reviews->submitReview(
            $request->user(),
            (int) $data['order_item_id'],
            (int) $data['rating'],
            (string) $data['comment'],
            $request->file('photo'),
        );

        return response()->json([
            'data' => [
                'order_item_id' => (int) $review->order_item_id,
                'rating' => (int) $review->rating,
                'comment' => (string) $review->comment,
                'photo' => $reviews->photoUrl($review),
            ],
            'message' => 'Review submitted.',
        ], 201);
    }
}
