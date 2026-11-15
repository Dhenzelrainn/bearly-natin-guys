<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ReturnRequest;
use App\Models\SellerOrder;
use App\Models\User;
use App\Notifications\BearlyNotification;
use Illuminate\Support\Collection;

/**
 * Presentation-only data for transactional email templates.
 *
 * This class does not decide whether a notification is sent or who receives
 * it. It only reads immutable order/case references already attached to the
 * notification and scopes those reads to the recipient where appropriate.
 */
class BearlyEmailPresenter
{
    /** @return array<string, mixed> */
    public function forNotification(BearlyNotification $notification, object $recipient): array
    {
        $event = $notification->eventKey();
        $definition = config("notifications.events.{$event}", []);
        $group = is_array($definition) ? (string) ($definition['group'] ?? 'general') : 'general';
        $family = $this->familyFor($group);
        $meta = $notification->meta();

        return [
            'event' => $event,
            'family' => $family,
            'family_label' => $this->familyLabel($family),
            'tone' => $this->tone($notification->type()),
            'action_label' => $this->actionLabel($event, $family),
            'reference' => $this->reference($meta),
            'order' => $family === 'order' ? $this->orderSummary($meta, $recipient) : null,
            'return_case' => $family === 'return' ? $this->returnSummary($meta, $recipient) : null,
        ];
    }

    /** @param array<string, mixed> $meta
     * @return array<string, mixed>|null
     */
    private function orderSummary(array $meta, object $recipient): ?array
    {
        if (! $recipient instanceof User) {
            return null;
        }

        $sellerOrderId = $this->positiveId($meta['seller_order_id'] ?? null);
        $orderId = $this->positiveId($meta['order_id'] ?? null);

        if ($sellerOrderId !== null) {
            $query = SellerOrder::query()->with('items');

            if (! $this->scopeOrdersToRecipient($query, $recipient)) {
                return null;
            }

            $sellerOrder = $query->whereKey($sellerOrderId)->first();

            if (! $sellerOrder) {
                return null;
            }

            return $this->presentOrder(
                (string) $sellerOrder->seller_order_no,
                'Seller order total',
                (int) $sellerOrder->total_minor,
                (string) ($sellerOrder->order?->currency ?? 'PHP'),
                $sellerOrder->items,
            );
        }

        if ($orderId === null || ! $this->mayViewMarketplaceOrders($recipient)) {
            return null;
        }

        $query = Order::query()
            ->with(['sellerOrders.items', 'sellerOrders.store.sellerProfile']);

        if ($recipient->role === 'buyer') {
            $query->where('buyer_id', $recipient->id);
        } elseif ($recipient->role === 'seller') {
            $query->whereHas('sellerOrders.store.sellerProfile', fn ($profiles) => $profiles
                ->where('user_id', $recipient->id));
        }

        $order = $query->whereKey($orderId)->first();

        if (! $order) {
            return null;
        }

        $sellerOrders = $order->sellerOrders;

        if ($recipient->role === 'seller') {
            $sellerOrders = $sellerOrders->filter(fn (SellerOrder $sellerOrder): bool => (int) $sellerOrder->store?->sellerProfile?->user_id === (int) $recipient->id
            );
        }

        $items = $sellerOrders->flatMap(fn (SellerOrder $sellerOrder) => $sellerOrder->items);
        $number = (string) $order->order_no;
        $totalLabel = 'Order total';
        $totalMinor = (int) $order->total_minor;

        if ($recipient->role === 'seller') {
            $totalLabel = 'Seller order total';
            $totalMinor = $sellerOrders->reduce(
                fn (int $total, SellerOrder $sellerOrder): int => $total + (int) $sellerOrder->total_minor,
                0,
            );

            if ($sellerOrders->count() === 1) {
                $number = (string) $sellerOrders->first()->seller_order_no;
            }
        }

        return $this->presentOrder(
            $number,
            $totalLabel,
            $totalMinor,
            (string) $order->currency,
            $items,
        );
    }

    /** @param Collection<int, OrderItem> $items
     * @return array<string, mixed>
     */
    private function presentOrder(
        string $number,
        string $totalLabel,
        int $totalMinor,
        string $currency,
        Collection $items,
    ): array {
        return [
            'number' => $number,
            'total_label' => $totalLabel,
            'total' => $this->money($totalMinor, $currency),
            'item_count' => $items->count(),
            'items' => $items->take(6)->map(fn (OrderItem $item): array => [
                'name' => (string) $item->product_name,
                'variant' => $this->variantLabel($item),
                'quantity' => (int) $item->quantity,
                'unit_price' => $this->money((int) $item->unit_price_minor, $currency),
                'subtotal' => $this->money((int) $item->subtotal_minor, $currency),
            ])->values()->all(),
        ];
    }

    /** @param array<string, mixed> $meta
     * @return array<string, mixed>|null
     */
    private function returnSummary(array $meta, object $recipient): ?array
    {
        $returnRequestId = $this->positiveId($meta['return_request_id'] ?? null);

        if ($returnRequestId === null || ! $recipient instanceof User) {
            return null;
        }

        $query = ReturnRequest::query()
            ->with(['items.orderItem', 'refunds' => fn ($refunds) => $refunds->orderByDesc('id')]);

        if ($recipient->role === 'buyer') {
            $query->where('buyer_id', $recipient->id);
        } elseif ($recipient->role === 'seller') {
            $query->whereHas('store.sellerProfile', fn ($profiles) => $profiles
                ->where('user_id', $recipient->id));
        } elseif ($recipient->role !== 'admin') {
            return null;
        }

        $case = $query->whereKey($returnRequestId)->first();

        if (! $case) {
            return null;
        }

        $refund = $case->refunds->first();

        return [
            'number' => (string) $case->return_no,
            'request_type' => str($case->request_type)->replace('_', ' ')->title()->toString(),
            'requested_amount' => $this->money((int) $case->requested_amount_minor),
            'approved_amount' => $refund ? $this->money((int) $refund->amount_minor) : null,
            'payout_amount' => $refund && $refund->payout_amount_minor !== null
                ? $this->money((int) $refund->payout_amount_minor)
                : null,
            'items' => $case->items->take(5)->map(fn ($returnItem): array => [
                'name' => (string) ($returnItem->orderItem?->product_name ?? 'Returned item'),
                'variant' => $returnItem->orderItem ? $this->variantLabel($returnItem->orderItem) : null,
                'quantity' => (int) $returnItem->quantity,
            ])->values()->all(),
            'item_count' => $case->items->count(),
        ];
    }

    private function scopeOrdersToRecipient($query, User $recipient): bool
    {
        if ($recipient->role === 'buyer') {
            $query->whereHas('order', fn ($orders) => $orders->where('buyer_id', $recipient->id));

            return true;
        }

        if ($recipient->role === 'seller') {
            $query->whereHas('store.sellerProfile', fn ($profiles) => $profiles
                ->where('user_id', $recipient->id));

            return true;
        }

        return $recipient->role === 'admin';
    }

    private function mayViewMarketplaceOrders(User $recipient): bool
    {
        return in_array($recipient->role, ['buyer', 'seller', 'admin'], true);
    }

    private function variantLabel(OrderItem $item): ?string
    {
        $variant = trim((string) $item->variant_name);

        return $variant !== '' && mb_strtolower($variant) !== 'default' ? $variant : null;
    }

    private function money(int $minor, string $currency = 'PHP'): string
    {
        $negative = $minor < 0;
        $absolute = abs($minor);
        $major = number_format(intdiv($absolute, 100));
        $fraction = str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
        $symbol = $currency === 'PHP' ? '₱' : $currency.' ';

        return ($negative ? '-' : '').$symbol.$major.'.'.$fraction;
    }

    /** @param array<string, mixed> $meta */
    private function reference(array $meta): ?array
    {
        foreach ([
            'shipment_no' => 'Shipment reference',
            'run_no' => 'Courier run reference',
            'finance_issue_reference' => 'Finance handoff reference',
        ] as $key => $label) {
            if (isset($meta[$key]) && is_scalar($meta[$key]) && trim((string) $meta[$key]) !== '') {
                return ['label' => $label, 'value' => (string) $meta[$key]];
            }
        }

        return null;
    }

    private function positiveId(mixed $value): ?int
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    private function familyFor(string $group): string
    {
        return match ($group) {
            'account' => 'account',
            'orders' => 'order',
            'shipments' => 'shipping',
            'returns' => 'return',
            'messages' => 'message',
            'announcements' => 'announcement',
            'compliance' => 'compliance',
            'campaigns' => 'campaign',
            'wallet', 'finance', 'cash' => 'finance',
            'pickups', 'fulfillment', 'assignments' => 'task',
            'operations', 'registrations' => 'operations',
            default => 'general',
        };
    }

    private function familyLabel(string $family): string
    {
        return match ($family) {
            'account' => 'Account & security',
            'order' => 'Order update',
            'shipping' => 'Shipping & tracking',
            'return' => 'Return & refund case',
            'message' => 'New message',
            'announcement' => 'Bearly announcement',
            'compliance' => 'Product compliance',
            'campaign' => 'Seller campaign',
            'finance' => 'Finance update',
            'task' => 'Marketplace task',
            'operations' => 'Operations update',
            default => 'Bearly update',
        };
    }

    private function tone(string $type): array
    {
        return match ($type) {
            'success' => ['accent' => '#47734f', 'surface' => '#eef5ee', 'text' => '#2f5b38'],
            'warning' => ['accent' => '#bd8125', 'surface' => '#fff6e8', 'text' => '#74501c'],
            'danger' => ['accent' => '#ad5148', 'surface' => '#fbefed', 'text' => '#813b34'],
            default => ['accent' => '#80634d', 'surface' => '#f6f2ed', 'text' => '#4b3428'],
        };
    }

    private function actionLabel(string $event, string $family): string
    {
        return match (true) {
            $event === 'announcement_published' => 'Read announcement',
            str_starts_with($event, 'buyer_order_') => 'View order',
            str_starts_with($event, 'seller_order_') => 'Manage orders',
            str_contains($event, 'shipment') || str_contains($event, 'handoff') || str_contains($event, 'linehaul') => 'View tracking',
            str_contains($event, 'return') => 'Review case',
            str_contains($event, 'pickup') => 'View pickup',
            str_contains($event, 'withdrawal') => 'View withdrawal',
            str_contains($event, 'refund_cash') => 'View cash assignment',
            str_contains($event, 'dispatch') => 'View delivery batch',
            str_contains($event, 'message') => 'Open conversation',
            $family === 'compliance' => 'Review listing',
            $family === 'campaign' => 'View campaign',
            $family === 'operations' => 'Review application',
            default => 'Open in Bearly',
        };
    }
}
