<?php

namespace App\Services;

use App\Models\ComplianceRule;
use App\Models\Product;
use App\Models\ProductComplianceCheck;
use App\Models\ProductComplianceCheckMatch;
use App\Models\ProductViolation;
use App\Models\SellerWarning;
use App\Models\User;
use App\Models\ViolationAction;
use App\Notifications\WorkflowNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ComplianceScanner
{
    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    public function scan(Product $product, string $trigger = 'create', ?User $actor = null): ProductComplianceCheck
    {
        return DB::transaction(function () use ($product, $trigger, $actor): ProductComplianceCheck {
            $product = Product::query()
                ->whereKey($product->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $product->load(['store.sellerProfile.user', 'category']);
            $wasComplianceHeld = in_array($product->compliance_status, ['flagged', 'blocked'], true);

            $check = ProductComplianceCheck::create([
                'product_id' => $product->id,
                'trigger' => $trigger,
                'status' => 'running',
                'name_snapshot' => $product->name,
                'description_snapshot' => $product->description,
                'category_id_snapshot' => $product->category_id,
                'started_at' => now(),
            ]);

            $text = Str::lower($product->name.' '.$product->description);
            $result = 'clear';
            $matchedViolationRuleIds = [];

            foreach (ComplianceRule::query()->where('is_active', true)->get() as $rule) {
                [$matched, $excerpt] = $this->matchRule($rule, $product, $text);

                if (! $matched) {
                    continue;
                }

                ProductComplianceCheckMatch::create([
                    'check_id' => $check->id,
                    'rule_id' => $rule->id,
                    'matched_field' => $rule->target_field,
                    'matched_excerpt' => $excerpt,
                    'severity' => $rule->severity,
                    'recommended_action' => $rule->default_action,
                ]);

                if (in_array($rule->default_action, ['flag', 'hold', 'block'], true)) {
                    $matchedViolationRuleIds[] = (int) $rule->id;

                    [$violation, $wasDismissedFalsePositive] = $this->recordViolation($product, $check, $rule, $excerpt);

                    if ($wasDismissedFalsePositive) {
                        continue;
                    }

                    $this->recordSellerWarning($violation, $rule, $excerpt);

                    if ($rule->default_action === 'block') {
                        $result = 'blocked';
                    } elseif ($result !== 'blocked') {
                        $result = 'review_required';
                    }
                }
            }

            $this->resolveClearedViolations($product, $actor, $matchedViolationRuleIds);

            $check->update([
                'status' => match ($result) {
                    'clear' => 'passed',
                    'blocked' => 'blocked',
                    default => 'flagged',
                },
                'result' => $result,
                'completed_at' => now(),
            ]);

            $product->update([
                'compliance_status' => $result === 'clear'
                    ? 'clear'
                    : ($result === 'blocked' ? 'blocked' : 'flagged'),
                'product_status' => match ($result) {
                    'blocked' => 'blocked',
                    'review_required' => 'flagged',
                    'clear' => $wasComplianceHeld && in_array($product->product_status, ['flagged', 'blocked'], true)
                        ? ($product->published_at ? 'active' : 'draft')
                        : $product->product_status,
                    default => $product->product_status,
                },
            ]);

            if ($result !== 'clear' && ! $wasComplianceHeld) {
                $this->notifyModerators($product, $check, $result);
                $this->notifySeller($product, $check, $result);
            }

            return $check->load('matches');
        });
    }

    /**
     * Only alert moderators when a product newly enters a held state; repeat
     * scans of an already-flagged or blocked product do not re-notify.
     */
    private function notifyModerators(Product $product, ProductComplianceCheck $check, string $result): void
    {
        $this->notifications->sendManyAfterCommit(
            $this->notifications->admins(),
            new WorkflowNotification(
                eventKey: 'admin_compliance_flagged',
                title: $result === 'blocked' ? 'Product blocked by compliance scan' : 'Product flagged by compliance scan',
                body: "\"{$product->name}\" was ".($result === 'blocked' ? 'blocked' : 'flagged').' by the compliance scan and needs moderation.',
                actionUrl: route('admin.compliance.violations'),
                type: $result === 'blocked' ? 'danger' : 'warning',
                meta: ['product_id' => (int) $product->id, 'check_id' => (int) $check->id],
            ),
        );
    }

    /**
     * The Seller is told once when their product newly enters a held or
     * blocked state; repeat scans of an already-held product stay silent.
     */
    private function notifySeller(Product $product, ProductComplianceCheck $check, string $result): void
    {
        $sellerUser = $product->store?->sellerProfile?->user;

        if (! $sellerUser) {
            return;
        }

        $held = $result !== 'blocked';

        $this->notifications->sendAfterCommit($sellerUser, new WorkflowNotification(
            eventKey: 'seller_compliance_flagged',
            title: $held ? 'Product held for compliance review' : 'Product blocked by compliance scan',
            body: $held
                ? "\"{$product->name}\" is hidden from Buyers while an Admin reviews a compliance flag on its listing. Fixing the flagged wording can clear the hold automatically."
                : "\"{$product->name}\" was blocked by the compliance scan and is hidden from Buyers. Review the compliance reason beside the product before making changes.",
            actionUrl: route('seller.products'),
            type: $held ? 'warning' : 'danger',
            meta: ['product_id' => (int) $product->id, 'check_id' => (int) $check->id],
        ));
    }

    /** @return array{bool, string|null} */
    private function matchRule(ComplianceRule $rule, Product $product, string $text): array
    {
        if (in_array($rule->rule_type, ['keyword', 'phrase'], true) && $rule->pattern) {
            $term = $this->findPatternTerm(
                $rule->pattern,
                $text,
                wholeWord: ($rule->configuration['match_mode'] ?? null) === 'whole_word',
            );

            if ($term !== null) {
                return [true, $term];
            }
        }

        if (
            $rule->rule_type === 'restricted_category'
            && $rule->category_id !== null
            && (int) $rule->category_id === (int) $product->category_id
        ) {
            return [true, null];
        }

        if ($rule->rule_type === 'category_mismatch') {
            if (
                $product->store?->sellerProfile?->approved_category_id
                && (int) $product->store->sellerProfile->approved_category_id !== (int) $product->category_id
            ) {
                return [true, null];
            }

            $categorySlug = $product->category?->slug;
            $mismatchTerms = $rule->configuration['mismatched_terms_by_category_slug'][$categorySlug] ?? [];
            $term = $this->findPatternTerm(implode('|', $mismatchTerms), $text, wholeWord: true);

            if ($term !== null) {
                return [true, $term];
            }
        }

        return [false, null];
    }

    private function findPatternTerm(string $pattern, string $text, bool $wholeWord): ?string
    {
        foreach (array_filter(array_map('trim', explode('|', Str::lower($pattern)))) as $term) {
            $matched = $wholeWord
                ? preg_match('/(?<![\p{L}\p{N}])'.preg_quote($term, '/').'(?![\p{L}\p{N}])/iu', $text) === 1
                : Str::contains($text, $term);

            if ($matched) {
                return $term;
            }
        }

        return null;
    }

    /** @return array{ProductViolation, bool} */
    private function recordViolation(
        Product $product,
        ProductComplianceCheck $check,
        ComplianceRule $rule,
        ?string $excerpt,
    ): array {
        $openViolations = ProductViolation::query()
            ->where('product_id', $product->id)
            ->where('rule_id', $rule->id)
            ->where('source', 'automated')
            ->whereIn('status', ['flagged', 'under_review', 'correction_requested'])
            ->lockForUpdate()
            ->orderBy('id')
            ->get();

        $violation = $openViolations->first();

        if ($violation) {
            foreach ($openViolations->skip(1) as $duplicate) {
                $fromStatus = $duplicate->status;
                $duplicate->update([
                    'status' => 'resolved',
                    'resolution' => 'duplicate_scan_merged',
                    'resolved_at' => now(),
                ]);
                $duplicate->warnings()
                    ->whereNull('issued_by')
                    ->where('status', 'active')
                    ->update(['status' => 'resolved', 'updated_at' => now()]);
                ViolationAction::query()->create([
                    'violation_id' => $duplicate->id,
                    'action_type' => 'duplicate_scan_merged',
                    'from_status' => $fromStatus,
                    'to_status' => 'resolved',
                    'reason' => 'This open automated violation was consolidated with an earlier violation for the same product and rule.',
                ]);
            }

            $violation->update([
                'check_id' => $check->id,
                'severity' => $rule->severity,
                'reason' => $rule->name,
                'detected_excerpt' => $excerpt,
                'updated_at' => now(),
            ]);

            return [$violation, false];
        }

        $previousViolation = ProductViolation::query()
            ->where('product_id', $product->id)
            ->where('rule_id', $rule->id)
            ->where('source', 'automated')
            ->latest('id')
            ->first();

        if ($previousViolation?->status === 'confirmed') {
            return [$previousViolation, false];
        }

        if ($previousViolation?->status === 'dismissed') {
            $previousCheck = $previousViolation->check;
            $dismissedTextIsUnchanged = $previousCheck
                && $previousCheck->name_snapshot === $product->name
                && $previousCheck->description_snapshot === $product->description
                && (int) $previousCheck->category_id_snapshot === (int) $product->category_id;

            if ($dismissedTextIsUnchanged) {
                return [$previousViolation, true];
            }
        }

        if (! $violation) {
            $violation = ProductViolation::query()->create([
                'violation_no' => 'VIO-'.Str::upper(Str::random(10)),
                'product_id' => $product->id,
                'seller_profile_id' => $product->store->seller_profile_id,
                'check_id' => $check->id,
                'rule_id' => $rule->id,
                'source' => 'automated',
                'violation_type' => $rule->code,
                'severity' => $rule->severity,
                'reason' => $rule->name,
                'detected_excerpt' => $excerpt,
                'status' => 'flagged',
            ]);
        }

        return [$violation, false];
    }

    private function recordSellerWarning(ProductViolation $violation, ComplianceRule $rule, ?string $excerpt): void
    {
        $hasActiveWarning = SellerWarning::query()
            ->where('violation_id', $violation->id)
            ->where('status', 'active')
            ->exists();

        if ($hasActiveWarning) {
            return;
        }

        SellerWarning::query()->create([
            'warning_no' => 'WRN-'.Str::upper(Str::random(10)),
            'seller_profile_id' => $violation->seller_profile_id,
            'violation_id' => $violation->id,
            'level' => $rule->severity,
            'points' => 0,
            'reason' => $rule->name,
            'issued_by' => null,
            'issued_at' => now(),
        ]);
    }

    /** @param array<int, int> $matchedViolationRuleIds */
    private function resolveClearedViolations(Product $product, ?User $actor, array $matchedViolationRuleIds): void
    {
        $query = ProductViolation::query()
            ->where('product_id', $product->id)
            ->where('source', 'automated')
            ->whereIn('status', ['flagged', 'under_review', 'correction_requested'])
            ->lockForUpdate();

        if ($matchedViolationRuleIds !== []) {
            $query->whereNotIn('rule_id', $matchedViolationRuleIds);
        }

        foreach ($query->get() as $violation) {
            $fromStatus = $violation->status;
            $violation->update([
                'status' => 'resolved',
                'resolution' => 'no_active_rule_match',
                'resolved_at' => now(),
            ]);

            $violation->warnings()
                ->whereNull('issued_by')
                ->where('status', 'active')
                ->update([
                    'status' => 'resolved',
                    'updated_at' => now(),
                ]);

            ViolationAction::query()->create([
                'violation_id' => $violation->id,
                'action_type' => 'automated_recheck_clear',
                'from_status' => $fromStatus,
                'to_status' => 'resolved',
                'actor_user_id' => $actor?->id,
                'reason' => 'A new product compliance scan no longer matched this rule.',
            ]);
        }
    }
}
