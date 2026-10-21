<?php

namespace App\Http\Controllers;

use App\Services\ApplicationReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ApplicationStatusController extends Controller
{
    public function pending(
        Request $request,
        ApplicationReviewService $reviews,
    ): View {
        return view('auth.pending', [
            'applicationReview' => $reviews->forSession($request),
        ]);
    }

    public function status(
        Request $request,
        ApplicationReviewService $reviews,
    ): JsonResponse {
        $review = $reviews->forSession($request);

        abort_unless($review, 404);

        return response()
            ->json([
                'data' => [
                    'state' => $review['state'],
                    'status' => $review['status_label'],
                    'heading' => $review['heading'],
                    'message' => $review['message'],
                    'is_final' => $review['final'],
                    'can_sign_in' => $review['can_sign_in'],
                ],
            ])
            ->header('Cache-Control', 'private, no-store, max-age=0');
    }
}
