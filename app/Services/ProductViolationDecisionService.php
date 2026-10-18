<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductViolation;
use App\Models\SellerWarning;
use App\Models\User;
use App\Models\ViolationAction;
use App\Notifications\WorkflowNotification;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductViolationDecisionService
{
    private const ACTIONABLE_STATUSES = [
        'flagged',
        'under_review',
        'correction_requested',
    ];

    private const DECISIONS = [
        'dismiss',
        'confirm',
        'request_correction',
        'remove_product',
        'warn_seller',
    ];

    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    public function decide(
        ProductViolation $violation,
        User $actor,
        string $decision,
        string $note,
    ): ProductViolation {
        if (! in_array($decision, self::DECISIONS, true)) {
            throw ValidationException::withMessages([
                'decision' => 'Choose a supported compliance decision.',
            ]);
        }

        return DB::transaction(function () use ($violation, $actor, $decision, $note): ProductViolation {
            $product = Product::withTrashed()
                ->whereKey($violation->product_id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedViolation = ProductViolation::query()
                ->whereKey($violation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $lockedViolation->product_id !== (int) $product->id) {
                throw (new ModelNotFoundException)
                    ->setModel(ProductViolation::class, [$violation->getKey()]);
            }

            if (! in_array($lockedViolation->status, self::ACTIONABLE_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'decision' => 'This violation has already received a final decision. Reload the page to see its latest status.',
                ]);
            }

            $fromStatus = $lockedViolation->status;
            $toStatus = match ($decision) {
                'dismiss' => 'dismissed',
                'request_correction' => 'under_review',
                default => 'confirmed',
            };
            $now = now();

            $lockedViolation->update([
                'status' => $toStatus,
                'resolution' => $decision,
                'admin_note' => $note,
                'assigned_to' => $actor->id,
                'reviewed_at' => $now,
                'resolved_at' => $toStatus === 'under_review' ? null : $now,
            ]);

            if ($decision === 'dismiss') {
                $hasOtherOpenViolations = $product->violations()
                    ->where('id', '<>', $lockedViolation->getKey())
                    ->whereNotIn('status', ['dismissed', 'resolved'])
                    ->exists();

                if (! $hasOtherOpenViolations) {
                    $product->update([
                        'compliance_status' => 'clear',
                        'product_status' => $product->removed_at === null && $product->published_at
                            ? 'active'
                            : $product->product_status,
                    ]);
                }

                $lockedViolation->warnings()
                    ->whereNull('issued_by')
                    ->where('status', 'active')
                    ->update([
                        'status' => 'revoked',
                        'revoked_at' => $now,
                        'updated_at' => $now,
                    ]);
            }

            if ($decision === 'remove_product') {
                $product->update([
                    'product_status' => 'blocked',
                    'compliance_status' => 'blocked',
                    'removed_at' => $now,
                    'removed_reason' => $note,
                ]);
            }

            if ($decision === 'warn_seller') {
                $warningAttributes = [
                    'level' => $lockedViolation->severity,
                    'points' => match ($lockedViolation->severity) {
                        'critical' => 10,
                        'high' => 5,
                        'medium' => 3,
                        default => 1,
                    },
                    'reason' => $note,
                    'issued_by' => $actor->id,
                    'issued_at' => $now,
                    'status' => 'active',
                    'revoked_at' => null,
                ];
                $automaticWarning = $lockedViolation->warnings()
                    ->whereNull('issued_by')
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->first();

                if ($automaticWarning) {
                    $automaticWarning->update($warningAttributes);
                } else {
                    SellerWarning::query()->create($warningAttributes + [
                        'warning_no' => 'WRN-'.Str::upper(Str::random(10)),
                        'seller_profile_id' => $lockedViolation->seller_profile_id,
                        'violation_id' => $lockedViolation->id,
                    ]);
                }

                $sellerUser = $lockedViolation->sellerProfile?->user;

                if ($sellerUser) {
                    $this->notifications->sendAfterCommit($sellerUser, new WorkflowNotification(
                        eventKey: 'seller_compliance_flagged',
                        title: 'Compliance warning issued',
                        body: "An Admin reviewed a compliance flag on \"{$product->name}\" and issued a warning: {$note}",
                        actionUrl: route('seller.products'),
                        type: 'warning',
                        meta: [
                            'violation_id' => (int) $lockedViolation->id,
                            'product_id' => (int) $product->id,
                        ],
                    ));
                }
            }

            ViolationAction::query()->create([
                'violation_id' => $lockedViolation->id,
                'action_type' => $decision,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'actor_user_id' => $actor->id,
                'reason' => $note,
            ]);

            return $lockedViolation->refresh();
        });
    }
}
