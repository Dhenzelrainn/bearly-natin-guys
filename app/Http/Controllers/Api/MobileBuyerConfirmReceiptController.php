<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\SellerOrder;
use App\Services\BuyerOrderConfirmationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class MobileBuyerConfirmReceiptController extends Controller
{
    public function __invoke(Request $request, int $order, BuyerOrderConfirmationService $confirmation): JsonResponse
    {
        try {
            $ownedOrder = Order::query()
                ->where('buyer_id', $request->user()->id)
                ->whereKey($order)
                ->firstOrFail();

            // The shared service rechecks ownership while holding its Order
            // lock; this scoped lookup prevents Buyer-to-Buyer ID discovery.
            $confirmedOrder = $confirmation->confirmReceipt($request->user(), (int) $ownedOrder->id);
            $alreadyConfirmed = (bool) $confirmedOrder->getAttribute('_receipt_already_confirmed');
            $sellerOrders = $confirmedOrder->sellerOrders->sortBy('id')->values();

            return response()->json([
                'data' => [
                    'order_id' => (int) $confirmedOrder->id,
                    'order_reference' => (string) $confirmedOrder->order_no,
                    'status' => (string) $confirmedOrder->status,
                    'payment_status' => (string) $confirmedOrder->payment_status,
                    'currency' => (string) ($confirmedOrder->currency ?: 'PHP'),
                    'total_minor' => (int) $confirmedOrder->total_minor,
                    'completed_at' => $this->iso($confirmedOrder->completed_at),
                    'receipt_confirmed' => $confirmation->isConfirmed($confirmedOrder),
                    'already_confirmed' => $alreadyConfirmed,
                    'seller_orders' => $sellerOrders->map(fn (SellerOrder $sellerOrder): array => [
                        'id' => (int) $sellerOrder->id,
                        'order_reference' => (string) $sellerOrder->seller_order_no,
                        'status' => (string) $sellerOrder->status,
                        'completed_at' => $this->iso($sellerOrder->completed_at),
                    ])->all(),
                ],
                'message' => $alreadyConfirmed
                    ? 'Order receipt was already confirmed.'
                    : 'Order receipt confirmed.',
            ]);
        } catch (ModelNotFoundException) {
            return response()->json([
                'message' => 'Order not found.',
                'error' => ['code' => 'order_not_found'],
            ], 404);
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() === 404) {
                return response()->json([
                    'message' => 'Order not found.',
                    'error' => ['code' => 'order_not_found'],
                ], 404);
            }

            if ($exception->getStatusCode() === 409) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'error' => ['code' => $this->conflictCode($exception->getMessage())],
                ], 409);
            }

            throw $exception;
        } catch (Throwable $exception) {
            Log::error('Mobile Buyer receipt confirmation failed.', [
                'exception_class' => $exception::class,
            ]);

            return response()->json([
                'message' => 'Receipt confirmation could not be completed right now.',
                'error' => ['code' => 'receipt_confirmation_failed'],
            ], 500);
        }
    }

    private function conflictCode(string $message): string
    {
        return match ($message) {
            'This order was cancelled and cannot be confirmed as received.' => 'order_cancelled',
            'This order has no active Seller Orders to confirm.' => 'no_active_seller_orders',
            'This order cannot be confirmed yet because not every parcel has been delivered.' => 'order_not_fully_delivered',
            'The COD payment cannot be completed until every required Rider cash collection is recorded.' => 'cod_cash_collection_incomplete',
            default => 'receipt_confirmation_blocked',
        };
    }

    private function iso(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : Carbon::parse($value)->toIso8601String();
    }
}
