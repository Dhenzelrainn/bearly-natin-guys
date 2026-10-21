<?php

namespace App\Http\Controllers;

use App\Models\AccountApplication;
use App\Models\Address;
use App\Models\Conversation;
use App\Models\StorePromotion;
use App\Models\StorePromotionalBanner;
use App\Services\CampaignService;
use App\Services\ConversationService;
use App\Services\InternationalPhone;
use App\Services\NotificationPreferenceService;
use App\Services\ProductReviewService;
use App\Services\SellerEarningsService;
use App\Services\SellerIdentityService;
use App\Services\SellerInventoryService;
use App\Services\SellerNotificationService;
use App\Services\SellerProductDiscountService;
use App\Services\SellerProductService;
use App\Services\SellerPromotionService;
use App\Services\StoreAppearanceService;
use App\Services\StorePublicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SellerController extends Controller
{
    private function seller(): array
    {
        return app(
            SellerIdentityService::class
        )->current();
    }

    private function notifications(): array
    {
        return app(SellerNotificationService::class)->recentFor(auth()->user());
    }

    private function moneyFromMinor(int $amountMinor): string
    {
        $sign = $amountMinor < 0 ? '−' : '';

        return $sign.'₱'.number_format(abs($amountMinor) / 100, 2);
    }

    private function changeNote(?float $percent, string $previousLabel): string
    {
        if ($percent === null) {
            return 'No prior-period data';
        }

        $sign = $percent > 0.05 ? '+' : ($percent < -0.05 ? '−' : '');

        return $sign.number_format(abs($percent), 1).'% vs '.$previousLabel;
    }

    private function changeIcon(?float $percent): string
    {
        if ($percent === null) {
            return 'minus';
        }

        return $percent > 0.05 ? 'trending-up' : ($percent < -0.05 ? 'trending-down' : 'minus');
    }

    public function dashboard(): View
    {
        return view('seller.Dashboard.dashboard', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'dashboard' => [
                'updated_at' => '10:42 AM',
                'action_count' => 18,
                'pickup_time' => '3:00 PM',
                'actions' => [
                    ['count' => 8, 'label' => 'new orders', 'detail' => 'Review within 2 hours', 'action' => 'Review', 'icon' => 'clipboard-list', 'tone' => 'amber', 'target' => route('seller.orders.new')],
                    ['count' => 5, 'label' => 'orders to prepare', 'detail' => 'Pack before 1:30 PM', 'action' => 'Prepare', 'icon' => 'package', 'tone' => 'olive', 'target' => route('seller.orders.prepare')],
                    ['count' => 3, 'label' => 'waybills to print', 'detail' => "For today's pickup", 'action' => 'Print', 'icon' => 'printer', 'tone' => 'brown', 'target' => route('seller.fulfillment.waybills')],
                    ['count' => 2, 'label' => 'pickup requests', 'detail' => 'Awaiting logistics approval', 'action' => 'View requests', 'icon' => 'truck', 'tone' => 'green', 'target' => route('seller.fulfillment.pickups')],
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
            'payoutSummary' => [
                ['label' => 'Gross sales', 'value' => '₱128,450'],
                ['label' => 'Platform commission (10%)', 'value' => '−₱12,845'],
                ['label' => 'Net revenue', 'value' => '₱115,605'],
                ['label' => 'Next payout', 'value' => 'September 5'],
            ],
            'pickupSummary' => [
                'time' => '3:00 PM',
                'date' => 'Today · Laguna route',
                'ready' => 5,
                'not_ready' => 2,
            ],
        ]);
    }

    public function workspace(Request $request): View
    {
        $key = (string) $request->route('workspace');
        $pages = $this->workspacePages();
        abort_unless(isset($pages[$key]), 404);

        return view('seller.workspace', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'page' => $pages[$key],
        ]);
    }

    public function salesReport(Request $request, SellerEarningsService $earnings): View
    {
        $range = $earnings->reportRange($request->query('from'), $request->query('to'));
        $sort = $request->query('sort') === 'units' ? 'units' : 'net';
        $data = $earnings->salesReportFor($request->user()->sellerProfile, $range['from'], $range['to'], $sort);
        $periodLabel = $earnings->rangeLabel($range['from'], $range['to']);
        $previousLabel = $earnings->rangeLabel($data['previous_period']['from'], $data['previous_period']['to']);
        $totals = $data['totals'];
        $grossChange = $data['change']['gross'];
        $fullMonth = $range['from']->isStartOfMonth() && $range['to']->isEndOfMonth() && $range['from']->isSameMonth($range['to']);

        return view('seller.Reports.sales', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'dateRange' => ['from' => $range['from']->toDateString(), 'to' => $range['to']->toDateString()],
            'periodLabel' => $periodLabel,
            'previousLabel' => $previousLabel,
            'summary' => [
                ['label' => 'Gross Sales', 'value' => $this->moneyFromMinor($totals['gross_minor']), 'note' => $this->changeNote($grossChange, $previousLabel), 'icon' => $this->changeIcon($grossChange)],
                ['label' => 'Completed Orders', 'value' => number_format($totals['orders']), 'note' => $this->changeNote($data['change']['orders'], $previousLabel), 'icon' => $this->changeIcon($data['change']['orders'])],
                ['label' => 'Units Sold', 'value' => number_format($totals['units']), 'note' => $this->changeNote($data['change']['units'], $previousLabel), 'icon' => $this->changeIcon($data['change']['units'])],
                ['label' => 'Average Order', 'value' => $this->moneyFromMinor($totals['average_order_minor']), 'note' => $this->changeNote($data['change']['average_order'], $previousLabel), 'icon' => $this->changeIcon($data['change']['average_order'])],
            ],
            'momentumHeading' => 'Momentum through '.($fullMonth ? $range['from']->format('F') : 'the period'),
            'momentumSummary' => $grossChange === null
                ? 'No prior-period sales recorded for comparison.'
                : 'Gross sales closed '.number_format(abs($grossChange), 1).'% '.($grossChange >= 0 ? 'above ' : 'below ').$previousLabel.'.',
            'momentumChange' => [
                'label' => $grossChange === null
                    ? '—'
                    : (($grossChange > 0.05 ? '+' : ($grossChange < -0.05 ? '−' : '')).number_format(abs($grossChange), 1).'%'),
                'context' => $grossChange === null ? 'no prior-period data' : 'vs '.$previousLabel,
            ],
            'momentum' => collect($data['momentum'])->map(fn (array $bucket): array => [
                'label' => $bucket['label'],
                'value' => intdiv($bucket['gross_minor'], 100),
                'value_label' => $this->moneyFromMinor((int) $bucket['gross_minor']),
                'height' => $bucket['height'],
            ])->values(),
            'productPerformance' => collect($data['products'])->map(fn (array $product): array => [
                'rank' => $product['rank'],
                'name' => $product['name'],
                'sku' => $product['sku'],
                'subcategory' => $product['subcategory'],
                'units' => $product['units'],
                'gross' => $this->moneyFromMinor($product['gross_minor']),
                'discounts' => $product['discounts_minor'] > 0 ? '−'.$this->moneyFromMinor($product['discounts_minor']) : '—',
                'refunds' => $product['refunds_minor'] > 0 ? '−'.$this->moneyFromMinor($product['refunds_minor']) : '—',
                'net' => $this->moneyFromMinor($product['net_minor']),
                'revenue' => $this->moneyFromMinor($product['net_minor']),
                'share' => number_format($product['share'], 1),
                'icon' => 'package',
            ])->values(),
            'rows' => collect($data['momentum'])->map(fn (array $bucket): array => [
                'period' => $bucket['start'].' to '.$bucket['end'],
                'orders' => $bucket['orders'],
                'units' => $bucket['units'],
                'gross' => $this->moneyFromMinor($bucket['gross_minor']),
                'discounts' => $bucket['discounts_minor'] > 0 ? '−'.$this->moneyFromMinor($bucket['discounts_minor']) : '—',
                'refunds' => $bucket['refunds_minor'] > 0 ? '−'.$this->moneyFromMinor($bucket['refunds_minor']) : '—',
                'netSales' => $this->moneyFromMinor($bucket['net_minor']),
            ])->reverse()->values(),
            'sort' => $sort,
            'sortUrls' => [
                'net' => route('seller.reports.sales', [
                    'from' => $range['from']->toDateString(),
                    'to' => $range['to']->toDateString(),
                    'sort' => 'net',
                ]),
                'units' => route('seller.reports.sales', [
                    'from' => $range['from']->toDateString(),
                    'to' => $range['to']->toDateString(),
                    'sort' => 'units',
                ]),
            ],
            'exportUrl' => route('seller.reports.sales.export', [
                'from' => $range['from']->toDateString(),
                'to' => $range['to']->toDateString(),
            ]),
        ]);
    }

    public function messages(Request $request, ConversationService $conversations): View
    {
        $store = $request->user()->sellerProfile?->store;

        abort_unless($store, 404, 'Seller Store not found.');

        $inbox = $conversations->inboxForSeller($request->user(), (int) $store->id);

        $activeId = (int) $request->query('conversation', 0);
        $active = null;

        if ($activeId > 0) {
            foreach ($inbox as $summary) {
                if ((int) $summary['id'] === $activeId) {
                    $active = $summary;

                    break;
                }
            }

            abort_unless($active, 404);
        } elseif ($inbox !== []) {
            $active = $inbox[0];
        }

        $activeThread = null;

        if ($active) {
            $conversation = Conversation::query()->find((int) $active['id']);

            $activeThread = $conversation
                ? $conversations->threadPayload($conversation, $request->user(), 'seller.support.messages.attachment')
                : null;
        }

        return view('seller.CustomerService.messages', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'conversations' => $inbox,
            'activeConversation' => $active,
            'activeThread' => $activeThread,
            'totalUnread' => array_sum(array_column($inbox, 'unread')),
            'quickReplies' => [
                ['label' => 'Size availability', 'text' => 'Let me check the available sizes for you. I’ll send an update shortly.'],
                ['label' => 'Order preparation', 'text' => 'Your order is currently being prepared and will be ready before the scheduled pickup.'],
                ['label' => 'Pickup schedule', 'text' => 'Your parcel is scheduled for courier pickup today. Tracking will update after handover.'],
            ],
        ]);
    }

    public function customerFeedback(Request $request, ProductReviewService $reviews): View
    {
        $storeId = $request->user()->sellerProfile?->store?->id;

        abort_unless($storeId, 404, 'Seller Store not found.');

        $feedback = $reviews->storeFeedback((int) $storeId);

        return view('seller.CustomerService.feedback', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'summary' => $feedback['summary'],
            'distribution' => $feedback['distribution'],
            'reviews' => $feedback['reviews'],
        ]);
    }

    public function replyFeedback(Request $request, int $review, ProductReviewService $reviews): JsonResponse
    {
        $data = $request->validate([
            'response' => ['required', 'string', 'max:400'],
        ]);

        $model = $reviews->reply($request->user(), $review, (string) $data['response']);

        return response()->json([
            'data' => [
                'id' => (int) $model->id,
                'status' => 'replied',
                'response' => (string) $model->seller_response,
                'response_at' => $model->seller_response_at?->format('M j, Y g:i A'),
            ],
            'message' => 'Reply posted.',
        ]);
    }

    public function pickupRequests(): View
    {
        return view('seller.fulfillment.pickups', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'summary' => [
                ['label' => 'Ready to Request', 'value' => '2', 'note' => 'Labeled parcels', 'icon' => 'package-check', 'tone' => 'gold'],
                ['label' => 'Awaiting Approval', 'value' => '1', 'note' => 'Submitted to logistics', 'icon' => 'hourglass', 'tone' => 'brown'],
                ['label' => 'Rider Assigned', 'value' => '1', 'note' => 'Pickup scheduled', 'icon' => 'bike', 'tone' => 'olive'],
                ['label' => 'Picked Up Today', 'value' => '5', 'note' => 'Handover confirmed', 'icon' => 'truck', 'tone' => 'info'],
            ],
            'eligibleOrders' => [
                ['order' => '#BR-1058', 'tracking' => 'BRLY-784231', 'packages' => 1, 'weight' => '0.6 kg'],
                ['order' => '#BR-1057', 'tracking' => 'BRLY-784230', 'packages' => 2, 'weight' => '1.4 kg'],
            ],
            'requests' => [
                ['id' => 'PU-0903-04', 'orders' => '2 orders', 'packages' => 3, 'provider' => 'Bearly Logistics', 'schedule' => 'Today · 3:00–5:00 PM', 'address' => 'Juan’s Clothing Shop, Santa Rosa', 'rider' => 'Marco Dela Cruz · Rider 014', 'status' => 'Rider Assigned', 'status_key' => 'assigned'],
                ['id' => 'PU-0903-03', 'orders' => '1 order', 'packages' => 1, 'provider' => 'Bearly Logistics', 'schedule' => 'Tomorrow · 10:00 AM–12:00 PM', 'address' => 'Juan’s Clothing Shop, Santa Rosa', 'rider' => 'Awaiting assignment', 'status' => 'Pending Approval', 'status_key' => 'pending'],
                ['id' => 'PU-0902-08', 'orders' => '4 orders', 'packages' => 5, 'provider' => 'Bearly Logistics', 'schedule' => 'Sep 2 · 3:00 PM', 'address' => 'Juan’s Clothing Shop, Santa Rosa', 'rider' => 'Paolo Reyes · Rider 008', 'status' => 'Picked Up', 'status_key' => 'picked-up'],
                ['id' => 'PU-0901-06', 'orders' => '2 orders', 'packages' => 2, 'provider' => 'Bearly Logistics', 'schedule' => 'Sep 1 · 1:00 PM', 'address' => 'Juan’s Clothing Shop, Santa Rosa', 'rider' => 'Not assigned', 'status' => 'Cancelled', 'status_key' => 'cancelled'],
            ],
        ]);
    }

    public function shipmentTracking(): View
    {
        return view('seller.fulfillment.tracking', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'summary' => [
                ['label' => 'In Transit', 'value' => '6', 'note' => 'Moving between hubs', 'icon' => 'truck', 'tone' => 'info'],
                ['label' => 'Out for Delivery', 'value' => '2', 'note' => 'With delivery riders', 'icon' => 'bike', 'tone' => 'gold'],
                ['label' => 'Delivered Today', 'value' => '9', 'note' => 'Proof recorded', 'icon' => 'badge-check', 'tone' => 'olive'],
                ['label' => 'Needs Attention', 'value' => '1', 'note' => 'Delivery exception', 'icon' => 'triangle-alert', 'tone' => 'warning'],
            ],
            'shipments' => [
                ['tracking' => 'BRLY-784220', 'order' => '#BR-1055', 'customer' => 'Sofia Mendoza', 'destination' => 'Cabuyao, Laguna', 'rider' => 'Marco Dela Cruz', 'latest' => 'Parcel received at Santa Rosa Sorting Center', 'updated' => 'Today · 12:18 PM', 'eta' => 'Sep 4', 'status' => 'At Sorting Center', 'status_key' => 'sorting'],
                ['tracking' => 'BRLY-784201', 'order' => '#BR-1054', 'customer' => 'Jamie Lim', 'destination' => 'Biñan, Laguna', 'rider' => 'Rider assignment pending', 'latest' => 'Departed origin sorting center', 'updated' => 'Today · 11:42 AM', 'eta' => 'Sep 4', 'status' => 'In Transit', 'status_key' => 'transit'],
                ['tracking' => 'BRLY-784188', 'order' => '#BR-1052', 'customer' => 'Ana Cruz', 'destination' => 'Los Baños, Laguna', 'rider' => 'Paolo Reyes', 'latest' => 'Rider is delivering the parcel', 'updated' => 'Today · 9:15 AM', 'eta' => 'Today', 'status' => 'Out for Delivery', 'status_key' => 'out-delivery'],
                ['tracking' => 'BRLY-784176', 'order' => '#BR-1051', 'customer' => 'Miguel Garcia', 'destination' => 'Calamba, Laguna', 'rider' => 'John Ramos', 'latest' => 'Delivered to customer; proof uploaded', 'updated' => 'Today · 10:05 AM', 'eta' => 'Delivered', 'status' => 'Delivered', 'status_key' => 'delivered'],
                ['tracking' => 'BRLY-784160', 'order' => '#BR-1049', 'customer' => 'Paolo Ramos', 'destination' => 'Pagsanjan, Laguna', 'rider' => 'Alex Santos', 'latest' => 'Delivery failed: customer unavailable', 'updated' => 'Yesterday · 5:40 PM', 'eta' => 'Reschedule', 'status' => 'Delivery Failed', 'status_key' => 'failed'],
            ],
        ]);
    }

    public function account(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $user->loadMissing('sellerProfile');

        $fullName = trim((string) (
            $user->name
            ?: trim(implode(' ', array_filter([
                $user->first_name,
                $user->middle_initial,
                $user->last_name,
            ])))
        ));

        if ($fullName === '') {
            $fullName = 'Bearly Seller';
        }

        $addressParts = array_filter([
            $user->street_address,
            $user->barangay ? 'Barangay '.$user->barangay : null,
            $user->city,
            $user->province,
        ]);

        return view('seller.Settings.account', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'account' => [
                'full_name' => $fullName,
                'email' => (string) $user->email,
                'phone' => (string) ($user->phone ?: $user->contact_number ?: ''),
                'birthday' => $user->birthday?->format('F j, Y') ?? 'Not provided',
                'sex' => $this->sexLabel($user->sex),
                'address' => $addressParts !== [] ? implode(', ', $addressParts) : 'Not provided',
                'status' => ucfirst((string) ($user->status ?: 'pending')),
                'verification' => $user->sellerProfile?->approved_at !== null ? 'Verified' : 'Pending review',
                'member_since' => $user->created_at?->format('F j, Y') ?? '—',
                'last_updated' => $user->updated_at?->format('F j, Y · g:i A') ?? '—',
                'last_sign_in' => $user->last_login_at?->format('M j, Y · g:i A') ?? 'No sign-in recorded yet',
                'identity_verified' => $user->sellerProfile?->approved_at !== null,
                'email_verified' => $user->email_verified_at !== null,
                'contact_verified' => $user->phone_verified_at !== null,
            ],
        ]);
    }

    public function updateAccount(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $contact = trim((string) $request->input('contact'));

        if ($contact !== '') {
            try {
                $contact = app(InternationalPhone::class)
                    ->normalize($contact, 'PH');
            } catch (ValidationException $exception) {
                throw ValidationException::withMessages([
                    'contact' => $exception->errors()['contact_number']
                        ?? ['Enter a valid Philippine mobile number.'],
                ]);
            }
        }

        $request->merge(['contact' => $contact]);

        $validated = $request->validate([
            'contact' => [
                'required',
                'string',
                'max:17',
                Rule::unique('users', 'contact_number')->ignore($user->id),
                Rule::unique('users', 'phone')->ignore($user->id),
            ],
        ]);

        $user->update([
            'phone' => $validated['contact'],
            'contact_number' => $validated['contact'],
        ]);

        return redirect(
            route('seller.settings.account').'#contact'
        )->with(
            'success',
            'Contact number updated successfully.'
        );
    }

    public function security(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $activity = collect([
            $user->last_login_at ? [
                'event' => 'Successful sign-in',
                'detail' => 'Latest sign-in recorded for this account',
                'time' => $user->last_login_at->format('M j, Y · g:i A'),
                'tone' => 'success',
                'at' => $user->last_login_at,
            ] : null,
            $user->email_verified_at ? [
                'event' => 'Email verified',
                'detail' => (string) $user->email,
                'time' => $user->email_verified_at->format('M j, Y · g:i A'),
                'tone' => 'neutral',
                'at' => $user->email_verified_at,
            ] : null,
            $user->phone_verified_at ? [
                'event' => 'Contact verified',
                'detail' => (string) ($user->phone ?: $user->contact_number),
                'time' => $user->phone_verified_at->format('M j, Y · g:i A'),
                'tone' => 'neutral',
                'at' => $user->phone_verified_at,
            ] : null,
            [
                'event' => 'Account created',
                'detail' => 'Seller account registration',
                'time' => $user->created_at?->format('M j, Y · g:i A') ?? '—',
                'tone' => 'neutral',
                'at' => $user->created_at ?? now(),
            ],
        ])->filter()->sortByDesc('at')->values()->all();

        return view('seller.Settings.security', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'security' => [
                'activity' => $activity,
                'last_sign_in' => $user->last_login_at?->format('M j, Y · g:i A') ?? 'No sign-in recorded yet',
            ],
        ]);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => [
                'required',
                'string',
            ],
            'new_password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
            ],
        ]);

        if (! Hash::check(
            $validated['current_password'],
            $user->password
        )) {
            return redirect(
                route('seller.settings.security').'#password'
            )->withErrors([
                'current_password' =>
                    'The current password is incorrect.',
            ]);
        }

        $user->update([
            'password' => Hash::make(
                $validated['new_password']
            ),
        ]);

        return redirect(
            route('seller.settings.security').'#password'
        )->with(
            'success',
            'Password updated successfully.'
        );
    }

    private function sexLabel(?string $sex): string
    {
        return match (strtolower((string) $sex)) {
            'male' => 'Male',
            'female' => 'Female',
            'prefer_not_to_say', 'other' => 'Prefer not to say',
            default => $sex !== null && trim($sex) !== '' ? $sex : 'Not provided',
        };
    }

    public function notificationSettings(NotificationPreferenceService $preferenceService): View
    {
        $icons = [
            'orders' => 'clipboard-list',
            'fulfillment' => 'truck',
            'shipments' => 'package-search',
            'messages' => 'message-circle-more',
            'finance' => 'coins',
            'returns' => 'package-x',
            'account' => 'shield-alert',
            'compliance' => 'badge-check',
            'registrations' => 'user-check',
        ];

        $preferences = collect($preferenceService->forUser(auth()->user()))
            ->map(fn (array $event): array => [
                'key' => $event['key'],
                'title' => $event['label'],
                'description' => $event['description'],
                'icon' => $icons[$event['group']] ?? 'bell-ring',
                'in_app' => $event['in_app'],
                'email' => $event['email'],
                'required' => $event['mandatory'],
            ])
            ->values()
            ->all();

        return view('seller.Settings.notifications', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'preferences' => $preferences,
        ]);
    }

    private function workspacePages(): array
    {
        return [
            'fulfillment-waybills' => ['title' => 'Waybills', 'subtitle' => 'Generate and print shipping labels for packed orders.', 'kpis' => [['Ready to print', '3'], ['Printed today', '12'], ['Reprint needed', '1'], ['Pickup cutoff', '2:30 PM']], 'columns' => ['Order', 'Courier', 'Packages', 'Destination', 'Label Status', 'Pickup', 'Status'], 'rows' => [['#BR-1057', 'Bearly Logistics', '1', 'Santa Rosa, Laguna', 'Ready to print', 'Today · 3:00 PM', 'Pending'], ['#BR-1056', 'Bearly Logistics', '2', 'Calamba, Laguna', 'Printed', 'Today · 3:00 PM', 'Ready']], 'action' => 'Print Waybill'],
            'fulfillment-pickups' => ['title' => 'Pickup Requests', 'subtitle' => 'Submit labeled and packed parcels for logistics approval and pickup assignment.', 'kpis' => [['Ready to request', '2'], ['Approved today', '5'], ['Awaiting approval', '1'], ['Next approved pickup', '3:00 PM']], 'columns' => ['Request', 'Orders', 'Packages', 'Logistics Provider', 'Preferred Time', 'Pickup Location', 'Status'], 'rows' => [['PU-0901-01', '3 orders', '4', 'Bearly Logistics', 'Today · 3:00 PM', 'Juan’s Clothing Shop', 'Approved'], ['Draft request', '2 orders', '2', 'Bearly Logistics', 'Choose preferred time', 'Store address', 'Draft']], 'action' => 'View Request'],
            'fulfillment-tracking' => ['title' => 'Shipment Tracking', 'subtitle' => 'Monitor parcels after rider handover through sorting and final delivery.', 'kpis' => [['At sorting center', '3'], ['Assigned to rider', '2'], ['Out for delivery', '2'], ['Delivered today', '9']], 'columns' => ['Tracking No.', 'Order', 'Logistics Provider', 'Destination', 'Latest Update', 'Updated', 'Status'], 'rows' => [['BRLY-784201', '#BR-1054', 'Bearly Logistics', 'Biñan, Laguna', 'Parcel received at sorting center', 'Today · 8:40 AM', 'At Sorting Center'], ['BRLY-784188', '#BR-1052', 'Bearly Logistics', 'Cabuyao, Laguna', 'Assigned to delivery rider', 'Today · 9:15 AM', 'Assigned to Rider'], ['BRLY-784176', '#BR-1051', 'Bearly Logistics', 'Calamba, Laguna', 'Rider is delivering the parcel', 'Today · 10:05 AM', 'Out for Delivery']], 'action' => 'Track'],
            'products-pricing' => ['title' => 'Pricing & Promotions', 'subtitle' => 'Manage product prices, discounts, and voucher eligibility.', 'kpis' => [['Active products', '24'], ['Discounted', '6'], ['Voucher eligible', '12'], ['Ending soon', '2']], 'columns' => ['Product', 'SKU', 'Regular Price', 'Sale Price', 'Discount', 'Voucher', 'Status'], 'rows' => [['Classic Linen Shirt', 'CLS-LINEN-SHIRT', '₱1,299', '₱1,169', '10%', 'Eligible', 'Active'], ['Canvas Tote Bag', 'CNV-TOTE-BAG', '₱899', '₱899', '—', 'Not eligible', 'Regular']], 'action' => 'Edit Pricing'],
            'settings-account' => ['title' => 'Account', 'subtitle' => 'Manage the seller contact information used for account communication.', 'kpis' => [['Account status', 'Active'], ['Email', 'Verified'], ['Phone', 'Verified'], ['Role', 'Seller']], 'columns' => ['Field', 'Current Value', 'Visibility', 'Verification', 'Last Updated', 'Managed By', 'Status'], 'rows' => [['Full name', 'Bea Rivera', 'Private', 'Verified', 'Registration', 'Seller', 'Locked'], ['Email', 'bea@juansclothing.test', 'Private', 'Verified', 'Aug 31, 2026', 'Seller', 'Active']], 'action' => 'Edit'],
            'settings-security' => ['title' => 'Security', 'subtitle' => 'Protect account access and review recent sign-in activity.', 'kpis' => [['Password', 'Set'], ['Two-step verification', 'Off'], ['Active sessions', '1'], ['Security alerts', '0']], 'columns' => ['Security Item', 'Current State', 'Recommendation', 'Last Updated', 'Device', 'Location', 'Status'], 'rows' => [['Password', 'Set', 'Change regularly', 'Aug 20, 2026', '—', '—', 'Protected'], ['Current session', 'Active', 'Recognized device', 'Now', 'Windows · Edge', 'Laguna', 'Active']], 'action' => 'Manage'],
            'settings-notifications' => ['title' => 'Notifications', 'subtitle' => 'Choose which seller events are shown in-app or sent by email.', 'kpis' => [['Order alerts', 'On'], ['Stock alerts', 'On'], ['Pickup alerts', 'On'], ['Marketing', 'Off']], 'columns' => ['Notification', 'In-app', 'Email', 'Trigger', 'Priority', 'Last Sent', 'Status'], 'rows' => [['New order', 'Enabled', 'Enabled', 'Order placed', 'High', '5 min ago', 'Active'], ['Low stock', 'Enabled', 'Enabled', 'Below threshold', 'Medium', '42 min ago', 'Active']], 'action' => 'Configure'],
        ];
    }

    public function inventory(Request $request, SellerInventoryService $inventory): View
    {
        return view('seller.Products.inventory', array_merge([
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
        ], $inventory->inventoryFor($request->user())));
    }

    public function adjustInventory(Request $request, SellerInventoryService $inventory): RedirectResponse
    {
        $validated = $request->validate([
            'inventory_action' => ['required', 'in:adjust'],
            'variant_ids' => ['required', 'array', 'min:1', 'max:500'],
            'variant_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'mode' => ['required', Rule::in(['add', 'remove', 'set'])],
            'quantity' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'reason' => ['required', Rule::in(array_keys(SellerInventoryService::ADJUSTMENT_REASONS))],
            'reason_note' => ['nullable', 'string', 'max:180'],
        ]);

        $changed = $inventory->adjustStock(
            $request->user(),
            $validated['variant_ids'],
            $validated['mode'],
            (int) $validated['quantity'],
            $validated['reason'],
            $validated['reason_note'] ?? null,
        );

        return back()->with(
            'success',
            $changed > 0
                ? "Stock adjusted and recorded for {$changed} SKU(s)."
                : 'No stock balance changes were needed.',
        );
    }

    public function setInventoryThreshold(Request $request, SellerInventoryService $inventory): RedirectResponse
    {
        $validated = $request->validate([
            'inventory_action' => ['required', 'in:threshold'],
            'variant_ids' => ['required', 'array', 'min:1', 'max:500'],
            'variant_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'threshold' => ['required', 'integer', 'min:0', 'max:4294967295'],
        ]);

        $updated = $inventory->setThreshold(
            $request->user(),
            $validated['variant_ids'],
            (int) $validated['threshold'],
        );

        return back()->with('success', "Low-stock threshold saved for {$updated} SKU(s).");
    }

    public function orders(Request $request): View
    {
        return view('seller.Orders.orders', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'defaultOrderStatus' => in_array((string) $request->query('status', 'all'), ['all', 'new', 'to-prepare', 'ready-pickup', 'in-transit', 'history', 'completed', 'cancelled'], true)
                ? (string) $request->query('status', 'all')
                : 'all',
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
                ['key' => 'history', 'label' => 'History', 'count' => null],
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
                ['id' => '#BR-1049', 'customer' => 'Paolo Ramos', 'items' => '2 items', 'payment' => 'Refunded', 'payment_key' => 'paid', 'total' => '₱1,780', 'deadline' => 'Cancelled Aug 28', 'date_key' => 'upcoming', 'urgent' => false, 'status' => 'Cancelled', 'status_key' => 'cancelled', 'tone' => 'cancelled', 'action' => 'View'],
            ],
        ]);
    }

    public function returns(): View
    {
        return view('seller.Orders.returns-refunds.returns', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'summary' => [
                ['label' => 'Action Required', 'value' => '2', 'note' => 'Respond before deadline', 'icon' => 'triangle-alert', 'tone' => 'warning'],
                ['label' => 'Under Review', 'value' => '3', 'note' => 'Awaiting platform decision', 'icon' => 'scan-search', 'tone' => 'neutral'],
                ['label' => 'Return in Progress', 'value' => '1', 'note' => 'Parcel returning to store', 'icon' => 'package-open', 'tone' => 'info'],
                ['label' => 'Refunded This Month', 'value' => '₱3,079', 'note' => '2 resolved requests', 'icon' => 'badge-check', 'tone' => 'success'],
            ],
            'tabs' => [
                ['key' => 'all', 'label' => 'All Cases', 'count' => 6],
                ['key' => 'action-required', 'label' => 'Action Required', 'count' => 2],
                ['key' => 'under-review', 'label' => 'Under Review', 'count' => 2],
                ['key' => 'return-shipping', 'label' => 'Return Shipping', 'count' => 1],
                ['key' => 'resolved', 'label' => 'Resolved', 'count' => 1],
            ],
            'returns' => $this->returnCases(),
        ]);
    }

    public function returnDetails(string $caseId): View
    {
        $case = collect($this->returnCases())->firstWhere('id', strtoupper($caseId));
        abort_if($case === null, 404);

        return view('seller.Orders.returns-refunds.return-details', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'case' => $case,
        ]);
    }

    private function returnCases(): array
    {
        return [
            ['id' => 'RR-2041', 'order' => '#BR-1048', 'customer' => 'Maria Santos', 'product' => 'Classic Linen Shirt · Olive / M', 'sku' => 'CLS-OLV-M', 'request' => 'Return & Refund', 'reason' => 'Wrong size received', 'amount' => '₱1,299', 'submitted' => 'Today · 9:20 AM', 'deadline' => '1h 42m left', 'status' => 'Action Required', 'status_key' => 'action-required', 'tone' => 'warning', 'evidence' => '3 photos', 'buyer_note' => 'The tag says Medium, but the actual fit and measurements are smaller than the listed size.', 'seller_response' => 'No response submitted yet.', 'resolution' => 'Awaiting seller response', 'action' => 'Review Case', 'tracking' => 'Not yet created'],
            ['id' => 'RR-2040', 'order' => '#BR-1046', 'customer' => 'Paolo Ramos', 'product' => 'Canvas Tote Bag · Natural', 'sku' => 'CTB-NAT-OS', 'request' => 'Refund Only', 'reason' => 'Missing item', 'amount' => '₱899', 'submitted' => 'Today · 8:05 AM', 'deadline' => '3h 10m left', 'status' => 'Action Required', 'status_key' => 'action-required', 'tone' => 'warning', 'evidence' => '1 unboxing video', 'buyer_note' => 'The parcel arrived sealed, but the tote bag was not inside the package.', 'seller_response' => 'No response submitted yet.', 'resolution' => 'Awaiting seller response', 'action' => 'Review Case', 'tracking' => 'Not yet created'],
            ['id' => 'RR-2038', 'order' => '#BR-1044', 'customer' => 'Carlo Reyes', 'product' => 'Everyday Sneakers · White / 39', 'sku' => 'ES-WHT-39', 'request' => 'Return & Refund', 'reason' => 'Item not as described', 'amount' => '₱1,780', 'submitted' => 'Yesterday · 4:10 PM', 'deadline' => 'Responded', 'status' => 'Under Review', 'status_key' => 'under-review', 'tone' => 'review', 'evidence' => '4 photos', 'buyer_note' => 'The color and sole pattern do not match the product listing.', 'seller_response' => 'Requested platform review; warehouse packing photo was attached.', 'resolution' => 'Platform reviewing both parties’ evidence', 'action' => 'View Case', 'tracking' => 'Not yet created'],
            ['id' => 'RR-2035', 'order' => '#BR-1041', 'customer' => 'Sofia Mendoza', 'product' => 'Classic Linen Shirt · Cream / L', 'sku' => 'CLS-CRM-L', 'request' => 'Refund Only', 'reason' => 'Damaged item', 'amount' => '₱1,299', 'submitted' => 'Aug 30, 2026', 'deadline' => 'Responded', 'status' => 'Under Review', 'status_key' => 'under-review', 'tone' => 'review', 'evidence' => '2 photos', 'buyer_note' => 'There is a visible tear near the left sleeve seam.', 'seller_response' => 'Accepted buyer evidence and approved refund without return.', 'resolution' => 'Refund approval being processed', 'action' => 'View Case', 'tracking' => 'Not required'],
            ['id' => 'RR-2031', 'order' => '#BR-1036', 'customer' => 'Jamie Lim', 'product' => 'Everyday Sneakers · Black / 40', 'sku' => 'ES-BLK-40', 'request' => 'Return & Refund', 'reason' => 'Damaged during delivery', 'amount' => '₱1,780', 'submitted' => 'Aug 29, 2026', 'deadline' => 'Return by Sep 6', 'status' => 'Return Shipping', 'status_key' => 'return-shipping', 'tone' => 'shipping', 'evidence' => '3 photos', 'buyer_note' => 'The shoe box and right shoe were crushed when delivered.', 'seller_response' => 'Return approved after reviewing courier damage evidence.', 'resolution' => 'Return parcel is in transit to seller', 'action' => 'Track Return', 'tracking' => 'BRLY-RET-20431'],
            ['id' => 'RR-2025', 'order' => '#BR-1029', 'customer' => 'Ana Cruz', 'product' => 'Canvas Tote Bag · Natural', 'sku' => 'CTB-NAT-OS', 'request' => 'Refund Only', 'reason' => 'Seller approved refund', 'amount' => '₱1,299', 'submitted' => 'Aug 27, 2026', 'deadline' => 'Closed Aug 29', 'status' => 'Refunded', 'status_key' => 'resolved', 'tone' => 'resolved', 'evidence' => '2 photos', 'buyer_note' => 'The printed design was incomplete on one side.', 'seller_response' => 'Refund approved. No item return required.', 'resolution' => '₱1,299 returned to buyer via GCash', 'action' => 'View Details', 'tracking' => 'Not required'],
        ];
    }

    public function store(Request $request, SellerProductService $productService): View
    {
        $user = $request->user();
        $sellerProfile = $user->sellerProfile;

        abort_if($sellerProfile === null, 404, 'Seller profile not found.');

        $store = $productService->storeFor($user);

        $pickupAddress = $sellerProfile->pickup_address_id
            ? Address::find($sellerProfile->pickup_address_id)
            : null;

        $storeLocation = collect([
            $pickupAddress?->city_municipality,
            $pickupAddress?->province,
        ])->filter()->implode(', ');

        $application = AccountApplication::query()
            ->with('documents')
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();

        $documentLabels = [
            'valid_id' => 'Valid ID',
            'business_permit' => 'Business Permit',
        ];

        $documentStatusLabels = [
            'pending' => 'Under Review',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
        ];

        $documentStatusIcons = [
            'pending' => 'clock-3',
            'approved' => 'circle-check',
            'rejected' => 'circle-x',
            'not_submitted' => 'circle-alert',
        ];

        $documents = collect($documentLabels)->map(function (string $label, string $type) use (
            $application,
            $documentStatusLabels,
            $documentStatusIcons
        ): array {
            $document = $application?->documents->firstWhere('document_type', $type);
            $status = $document?->verification_status ?: 'not_submitted';

            return [
                'label' => $label,
                'status' => $status,
                'status_label' => $documentStatusLabels[$status] ?? ucfirst($status),
                'status_icon' => $documentStatusIcons[$status] ?? 'circle-alert',
                'file_name' => $document?->original_name,
                'preview_url' => $document
                    ? route('seller.store.documents.preview', ['type' => $type])
                    : null,
            ];
        })->values()->all();

        $storeData = [
            'name' => $store->name,
            'location' => $storeLocation ?: 'Pickup address not set',
            'category' => $sellerProfile->approvedCategory?->name ?? $user->business_category ?? 'Marketplace Seller',
            'description' => $store->description ?? '',
            'email' => $store->contact_email ?? '',
            'phone' => $store->contact_phone
                ?: $user->contact_number
                ?: $user->phone
                ?: '',
            'profile_photo' => $store->logo_path,
            'cover_photo' => $store->banner_path,
            'published' => $store->publication_status === 'published',
        ];

        $completed = 2
            + (int) filled($storeData['profile_photo'])
            + (int) filled($storeData['cover_photo'])
            + (int) filled($storeData['description']);

        return view('seller.Store.store', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'store' => $storeData,
            'documents' => $documents,
            'completion' => $completed * 20,
            'pickupAddress' => $pickupAddress,
        ]);
    }

    public function updatePickupCoordinates(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
        ]);
        $profile = $request->user()->sellerProfile;
        abort_unless($profile?->pickup_address_id, 404);

        Address::query()
            ->whereKey($profile->pickup_address_id)
            ->where('user_id', $request->user()->id)
            ->update([
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
            ]);

        if ($request->expectsJson()) {
            return response()->json([
                'data' => [
                    'latitude' => $data['latitude'] ?? null,
                    'longitude' => $data['longitude'] ?? null,
                ],
                'message' => 'Pickup map pin saved.',
            ]);
        }

        return back()->with('success', 'Pickup map pin saved.');
    }

    public function storeDocument(Request $request, string $type)
    {
        abort_unless(in_array($type, ['valid_id', 'business_permit'], true), 404);

        $application = AccountApplication::query()
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->first();

        abort_if($application === null, 404, 'Application not found.');

        $document = $application->documents()
            ->where('document_type', $type)
            ->first();

        abort_if($document === null, 404, 'Document not found.');
        abort_unless(Storage::disk('local')->exists($document->file_path), 404, 'Document file not found.');

        return response()->file(
            Storage::disk('local')->path($document->file_path),
            [
                'Content-Disposition' => 'inline; filename="'.basename($document->original_name).'"',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    public function saveStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'intent' => ['required', 'in:draft,publish'],
        ]);

        $sellerProfile = $request->user()->sellerProfile;

        abort_if($sellerProfile === null, 404, 'Seller profile not found.');

        $store = $sellerProfile->store;

        abort_if($store === null, 404, 'Store not found.');

        $phoneInput = preg_replace('/[^\d+]/', '', $validated['phone']) ?? '';

        try {
            $phone = app(InternationalPhone::class)->normalize($phoneInput, 'PH');
        } catch (ValidationException $e) {
            $messages = $e->errors()['contact_number'] ?? [$e->getMessage()];

            return back()
                ->withErrors(['phone' => $messages])
                ->withInput();
        }

        $store->update([
            'contact_email' => strtolower(trim($validated['email'])),
            'contact_phone' => $phone,
        ]);

        return redirect()
            ->route('seller.store')
            ->with('success', 'Store contact details saved successfully.');
    }

    public function storeAppearance(Request $request): View
    {
        $user = $request->user();
        $sellerProfile = $user->sellerProfile;

        abort_if($sellerProfile === null, 404, 'Seller profile not found.');

        $storeRecord = $sellerProfile->store;

        abort_if($storeRecord === null, 404, 'Store not found.');

        $promoBanners = $storeRecord->promotionalBanners
            ->map(fn (StorePromotionalBanner $banner): array => [
                'id' => $banner->id,
                'path' => $banner->image_path,
                'url' => Storage::disk('public')->url($banner->image_path),
                'title' => $banner->title ?? '',
                'link' => $banner->link ?? '',
            ])
            ->values()
            ->all();

        if ($promoBanners === [] && filled($storeRecord->promo_banner_path)) {
            $promoBanners[] = [
                'id' => null,
                'path' => $storeRecord->promo_banner_path,
                'url' => Storage::disk('public')->url($storeRecord->promo_banner_path),
                'title' => $storeRecord->promo_banner_title ?? '',
                'link' => $storeRecord->promo_banner_link ?? '',
            ];
        }

        $store = [
            'name' => $storeRecord->name,
            'category' => $sellerProfile->approvedCategory?->name ?? $user->business_category ?? 'Marketplace Seller',
            'description' => $storeRecord->description ?? '',
            'announcement' => $storeRecord->announcement ?? '',
            'operating_hours' => $storeRecord->operating_hours ?? '',
            'profile_photo' => $storeRecord->logo_path,
            'profile_photo_url' => $storeRecord->logo_path
                ? Storage::disk('public')->url($storeRecord->logo_path)
                : null,
            'cover_photo' => $storeRecord->banner_path,
            'cover_photo_url' => $storeRecord->banner_path
                ? Storage::disk('public')->url($storeRecord->banner_path)
                : null,
            'promo_banner' => $storeRecord->promo_banner_path,
            'promo_banner_title' => $storeRecord->promo_banner_title ?? '',
            'promo_banner_link' => $storeRecord->promo_banner_link ?? '',
            'promo_banners' => $promoBanners,
            'shipping_policy' => $storeRecord->shipping_policy ?? '',
            'return_policy' => $storeRecord->return_policy ?? '',
            'warranty_policy' => $storeRecord->warranty_policy ?? '',
        ];

        return view('seller.Store.appearance', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'store' => $store,
        ]);
    }

    public function saveStoreAppearance(Request $request, StoreAppearanceService $appearanceService): RedirectResponse
    {
        $validated = $request->validate([
            'description' => ['nullable', 'string', 'max:500'],
            'announcement' => ['nullable', 'string', 'max:180'],
            'operating_hours' => ['nullable', 'string', 'max:120'],
            'remove_profile_photo' => ['nullable', 'boolean'],
            'remove_cover_photo' => ['nullable', 'boolean'],
            'profile_photo' => [
                'nullable',
                'file',
                'image',
                'mimetypes:image/jpeg,image/png,image/webp',
                'extensions:jpg,jpeg,png,webp',
                'max:10240',
            ],
            'cover_photo' => [
                'nullable',
                'file',
                'image',
                'mimetypes:image/jpeg,image/png,image/webp',
                'extensions:jpg,jpeg,png,webp',
                'max:20480',
            ],
            'promo_banner_form' => ['nullable', 'boolean'],
            'promo_banner_ids' => ['nullable', 'array'],
            'promo_banner_ids.*' => ['nullable', 'integer', 'min:1', 'distinct'],
            'promo_banners' => ['nullable', 'array'],
            'promo_banners.*' => [
                'nullable',
                'file',
                'image',
                'mimetypes:image/jpeg,image/png,image/webp',
                'extensions:jpg,jpeg,png,webp',
                'max:20480',
            ],
            'promo_banner_titles' => ['nullable', 'array'],
            'promo_banner_titles.*' => ['nullable', 'string', 'max:160'],
            'promo_banner_links' => ['nullable', 'array'],
            'promo_banner_links.*' => ['nullable', 'url:http,https', 'max:500'],
            'shipping_policy' => ['nullable', 'string', 'max:3000'],
            'return_policy' => ['nullable', 'string', 'max:3000'],
            'warranty_policy' => ['nullable', 'string', 'max:3000'],
        ]);

        $sellerProfile = $request->user()->sellerProfile;

        abort_if($sellerProfile === null, 404, 'Seller profile not found.');

        $store = $sellerProfile->store;

        abort_if($store === null, 404, 'Store not found.');

        $appearanceService->save(
            $store,
            $validated,
            $request->file('profile_photo'),
            $request->file('cover_photo'),
            $request->file('promo_banners', []),
        );

        return redirect()
            ->route('seller.store.appearance')
            ->with('success', 'Store appearance saved successfully.');
    }

    public function publicationSettings(Request $request, StorePublicationService $publicationService): View
    {
        $user = $request->user();
        $state = $publicationService->readiness($user);
        $storeRecord = $state['store'];
        $requirementsState = $state['requirements'];

        $requirements = [
            ['label' => 'Business information verified', 'detail' => 'Store name and category were approved during registration.', 'complete' => $requirementsState['seller_approved'], 'route' => 'seller.store'],
            ['label' => 'Contact information added', 'detail' => 'Buyers and the platform have valid store contact details.', 'complete' => $requirementsState['contact_information'], 'route' => 'seller.store'],
            ['label' => 'Store description added', 'detail' => 'Explain what your store sells and what buyers can expect.', 'complete' => $requirementsState['description'], 'route' => 'seller.store.appearance'],
            ['label' => 'Profile photo added', 'detail' => 'Use a clear square image that identifies your store.', 'complete' => $requirementsState['profile_photo'], 'route' => 'seller.store.appearance'],
            ['label' => 'Cover photo added', 'detail' => 'Use a storefront banner suitable for desktop and mobile.', 'complete' => $requirementsState['cover_photo'], 'route' => 'seller.store.appearance'],
            ['label' => 'At least one active product', 'detail' => 'A published store must have something available to buyers.', 'complete' => $requirementsState['eligible_product'], 'route' => 'seller.products'],
        ];

        $completeCount = collect($requirements)->where('complete', true)->count();

        return view('seller.Store.publication', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'store' => [
                'name' => $storeRecord->name,
                'description' => $storeRecord->description ?? '',
                'profile_photo' => $storeRecord->logo_path,
                'cover_photo' => $storeRecord->banner_path,
                'published' => $storeRecord->publication_status === 'published',
                'slug' => $storeRecord->slug,
            ],
            'requirements' => $requirements,
            'completeCount' => $completeCount,
            'completion' => (int) round(($completeCount / count($requirements)) * 100),
        ]);
    }

    public function updatePublication(Request $request, StorePublicationService $publicationService): RedirectResponse|JsonResponse
    {
        $validated = $request->validate(['published' => ['required', 'boolean']]);
        try {
            $store = $publicationService->setPublished(
                $request->user(),
                (bool) $validated['published'],
            );
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $message = $errors['published'][0] ?? 'Store publication requirements are not complete.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message, 'errors' => $errors], 422);
            }

            return back()->withErrors($errors);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'data' => [
                    'published' => $store->publication_status === 'published',
                    'slug' => $store->slug,
                ],
                'message' => $store->publication_status === 'published'
                    ? 'Store published successfully.'
                    : 'Store unpublished successfully.',
            ]);
        }

        return back()->with('success', $store->publication_status === 'published'
            ? 'Store published successfully.'
            : 'Store unpublished successfully.');
    }

    public function products(Request $request, SellerProductService $productService): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'subcategory' => ['nullable', 'regex:/^(none|[1-9][0-9]*)$/'],
            'status' => ['nullable', 'string', 'max:32'],
        ]);
        $listing = $productService->productManagementListing($request->user(), $filters);

        return view('seller.Products.products', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            ...$listing,
        ]);
    }

    public function pricing(
        Request $request,
        SellerPromotionService $promotionService,
        SellerProductDiscountService $productDiscountService,
        CampaignService $campaignService,
    ): View {
        $promotionListing = $promotionService->listingFor($request->user());
        $discountListing = $productDiscountService->listingFor($request->user());
        $bearlyCampaigns = $campaignService->availableFor($request->user());

        return view('seller.Products.pricing', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'discountListing' => $discountListing,
            'voucherListing' => $promotionListing,
            'bearlyCampaigns' => $bearlyCampaigns,
        ]);
    }

    public function createPromotion(Request $request, SellerPromotionService $promotionService): View
    {
        return view('seller.Products.promotion-form', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'products' => $promotionService->eligibleProductsFor($request->user()),
        ]);
    }

    public function storePromotion(Request $request, SellerPromotionService $promotionService): RedirectResponse
    {
        $request->merge([
            'start_time' => $request->input('start_time', '00:00'),
            'end_time' => $request->input('end_time', '23:59'),
        ]);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:80'],
            'type' => ['required', Rule::in([StorePromotion::TYPE_STORE_VOUCHER])],
            'code' => [
                'nullable',
                'string',
                'max:40',
                'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('store_promotions', 'code'),
                Rule::unique('platform_promotions', 'code'),
            ],
            'discount_type' => ['required', Rule::in([StorePromotion::DISCOUNT_FIXED, StorePromotion::DISCOUNT_PERCENT])],
            'value' => ['nullable', 'required_if:discount_type,fixed', 'string', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/D'],
            'discount_percent' => ['nullable', 'required_if:discount_type,percent', 'integer', 'between:1,100'],
            'max_discount' => ['nullable', 'string', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/D'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'products' => ['required', 'array', 'min:1', 'max:100'],
            'products.*' => ['required', 'integer', 'distinct'],
            'minimum_spend' => ['nullable', 'string', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/D'],
            'redemption_limit' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'buyer_limit' => ['required', 'integer', 'between:1,10'],
            'terms' => ['nullable', 'string', 'max:2000'],
            'is_public' => ['sometimes', 'boolean'],
        ]);

        if (StorePromotion::dateTimeFromInput($validated['end_date'], $validated['end_time'], true)
            ->lessThan(StorePromotion::dateTimeFromInput($validated['start_date'], $validated['start_time']))) {
            throw ValidationException::withMessages([
                'end_time' => 'The end time must be after the start time.',
            ]);
        }

        $promotionService->create($request->user(), $validated);

        return redirect()
            ->route('seller.products.pricing', ['tab' => 'store-vouchers'])
            ->with('success', 'Store voucher saved and published for Buyer claims.');
    }
}
