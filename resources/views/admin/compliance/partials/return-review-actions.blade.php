<section class="return-review-decision-panel" data-return-actions aria-labelledby="return-decision-title-{{ $caseIndex }}">
    <div class="return-review-decision-heading">
        <span class="return-review-decision-icon" aria-hidden="true"><i data-lucide="gavel"></i></span>
        <div>
            <h3 id="return-decision-title-{{ $caseIndex }}">Admin decision</h3>
            <p>Review the case details, then choose the next step.</p>
        </div>
    </div>

    @if ($case['can_decide'])
        <div class="return-review-important" role="note">
            <i data-lucide="circle-alert" aria-hidden="true"></i>
            <p><strong>Important</strong><span>Check the Buyer’s reason, Seller’s response, and evidence before deciding.</span></p>
        </div>
        <div class="return-action-grid">
            <form
                method="POST"
                action="{{ route('admin.compliance.returns-refunds.approve', $case['database_id']) }}"
                data-return-form
                class="return-action-approve"
            >
                @csrf
                <div class="return-decision-card-heading">
                    <span class="return-decision-choice-icon" aria-hidden="true"><i data-lucide="circle-check"></i></span>
                    <div><strong>Approve</strong><small>Approve the requested {{ $case['type'] === 'Return & Refund' ? 'return and refund' : 'refund' }}.</small></div>
                </div>
                <label for="return-liable-party-{{ $caseIndex }}">Who is financially responsible?
                    <select id="return-liable-party-{{ $caseIndex }}" name="financially_responsible_party" required>
                        <option value="">Choose responsible party</option>
                        <option value="seller">Seller · seller-attributable issue</option>
                        <option value="platform">Platform · platform/logistics-attributable issue</option>
                    </select>
                </label>
                <label for="return-liability-reason-{{ $caseIndex }}">Reason for assigning responsibility
                    <textarea id="return-liability-reason-{{ $caseIndex }}" name="financial_responsibility_reason" rows="2" minlength="5" maxlength="1000" placeholder="Explain the evidence supporting this assignment" required></textarea>
                </label>
                <small class="return-liability-help">The Buyer receives the full approved refund. For physical returns, this decision also determines who is responsible for the separate return-delivery fee.</small>
                <button type="submit" class="button button-primary" data-return-action="approve" data-return-button="approve" data-functional-action="return-decision">
                    <i data-lucide="circle-check" aria-hidden="true"></i>
                    {{ $case['type'] === 'Return & Refund' ? 'Approve return & refund' : 'Approve refund' }}
                </button>
            </form>

            <form
                method="POST"
                action="{{ route('admin.compliance.returns-refunds.reject', $case['database_id']) }}"
                data-return-form
                class="return-action-reject"
            >
                @csrf
                <div class="return-decision-card-heading">
                    <span class="return-decision-choice-icon" aria-hidden="true"><i data-lucide="circle-x"></i></span>
                    <div><strong>Reject</strong><small>Reject the request.</small></div>
                </div>
                <label for="return-reject-reason-{{ $caseIndex }}">Reason for rejection
                    <input id="return-reject-reason-{{ $caseIndex }}" name="reason" type="text" minlength="5" maxlength="1000" placeholder="Explain the decision" required>
                </label>
                <button type="submit" class="button button-danger-soft" data-return-action="reject" data-return-button="reject" data-functional-action="return-decision">
                    <i data-lucide="circle-x" aria-hidden="true"></i>Reject request
                </button>
            </form>

            <form
                method="POST"
                action="{{ route('admin.compliance.returns-refunds.request-evidence', $case['database_id']) }}"
                data-return-form
                class="return-action-evidence"
            >
                @csrf
                <div class="return-decision-card-heading">
                    <span class="return-decision-choice-icon" aria-hidden="true"><i data-lucide="message-square-text"></i></span>
                    <div><strong>Request more info</strong><small>Ask for additional information.</small></div>
                </div>
                <label for="return-evidence-note-{{ $caseIndex }}">What evidence is needed?
                    <textarea id="return-evidence-note-{{ $caseIndex }}" name="note" minlength="5" maxlength="1000" rows="2" placeholder="Tell the parties what to provide" required></textarea>
                </label>
                <div class="return-evidence-request-bottom">
                    <label for="return-evidence-from-{{ $caseIndex }}">Request from
                        <select id="return-evidence-from-{{ $caseIndex }}" name="request_from" required><option value="buyer">Buyer</option><option value="seller">Seller</option><option value="both">Both parties</option></select>
                    </label>
                    <button type="submit" class="button button-ghost" data-return-action="evidence" data-return-button="evidence" data-functional-action="return-decision">
                        <i data-lucide="file-search" aria-hidden="true"></i>Request evidence
                    </button>
                </div>
            </form>
        </div>
    @elseif ($case['can_record_payout'] && $case['cash_payout'])
        <div class="return-payout-notice">
            <i data-lucide="banknote" aria-hidden="true"></i>
            <p>
                <strong>Cash refund in progress · {{ $case['refund_amount'] }}</strong>
                <span>Status: {{ $case['cash_payout']['status_label'] }} · {{ $case['cash_payout']['next_step'] }}</span>
                @if ($case['cash_payout']['finance_reference'])<span>Finance handoff reference: {{ $case['cash_payout']['finance_reference'] }}</span>@endif
                @if ($case['cash_payout']['acknowledgment_reference'])<span>Buyer acknowledgment reference: {{ $case['cash_payout']['acknowledgment_reference'] }}</span>@endif
            </p>
        </div>
        <a class="button button-secondary button-small" href="{{ route('admin.payments') }}#refund-cash-assignments">View cash-refund progress</a>
    @elseif ($case['can_record_payout'])
        <div class="return-payout-notice"><i data-lucide="info" aria-hidden="true"></i><p><strong>Completed external payout only · {{ $case['refund_amount'] }}</strong><span>Use this form only after GCash, bank transfer, or another external payout has reached the Buyer. Bearly records the proof; it does not send money.</span></p></div>
        <a class="button button-secondary button-small" href="{{ route('admin.payments') }}#refund-cash-dispatch">For a cash refund, open the Finance → Logistics → Rider workflow</a>
        <details class="return-payout-disclosure">
            <summary class="return-payout-disclosure-trigger"><i data-lucide="receipt-text" aria-hidden="true"></i>Record completed external payout</summary>
            <form method="POST" enctype="multipart/form-data" action="{{ route('admin.compliance.returns-refunds.record-payout', $case['refund_id']) }}" class="return-payout-form">
                @csrf
                <label class="return-payout-confirmation" for="refund-external-payout-confirmed-{{ $caseIndex }}">
                    <input id="refund-external-payout-confirmed-{{ $caseIndex }}" type="checkbox" name="external_payout_confirmed" value="1" required>
                    <span class="return-payout-confirmation-copy"><strong>I confirm this exact payout was already sent to the Buyer.</strong><small>Submitting records the payout, updates financial records, and resolves the case. It does not send money.</small></span>
                </label>
                <label for="refund-method-{{ $caseIndex }}">External payout method<select id="refund-method-{{ $caseIndex }}" name="method" required><option value="">Choose method</option><option value="gcash">GCash</option><option value="bank_transfer">Bank transfer</option><option value="other">Other</option></select></label>
                <label for="refund-reference-{{ $caseIndex }}">External payout reference<input id="refund-reference-{{ $caseIndex }}" name="provider_reference" type="text" maxlength="150" required></label>
                <label for="refund-paid-on-{{ $caseIndex }}">Date paid<input id="refund-paid-on-{{ $caseIndex }}" name="external_paid_on" type="date" max="{{ now()->toDateString() }}" required></label>
                <label for="refund-receipt-{{ $caseIndex }}">Payout receipt<input id="refund-receipt-{{ $caseIndex }}" name="receipt" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" required><small>JPG, PNG, WebP, or PDF · max 5 MB. Upload the genuine external transaction confirmation.</small></label>
                <button type="submit" class="button button-primary"><i data-lucide="check-check" aria-hidden="true"></i>Confirm &amp; record payout</button>
            </form>
        </details>
    @elseif ($case['can_record_responsibility'])
        <div class="return-payout-notice"><i data-lucide="info" aria-hidden="true"></i><p><strong>Financial responsibility is missing</strong><span>This legacy approved refund must have a documented Seller or Platform responsibility decision before Admin can record a payout.</span></p></div>
        <form method="POST" action="{{ route('admin.compliance.returns-refunds.responsibility', $case['refund_id']) }}" class="return-responsibility-form">
            @csrf
            <label for="legacy-liable-party-{{ $caseIndex }}">Who is financially responsible?
                <select id="legacy-liable-party-{{ $caseIndex }}" name="financially_responsible_party" required><option value="">Choose responsible party</option><option value="seller">Seller</option><option value="platform">Platform</option></select>
            </label>
            <label for="legacy-liability-reason-{{ $caseIndex }}">Reason<textarea id="legacy-liability-reason-{{ $caseIndex }}" name="financial_responsibility_reason" rows="3" minlength="5" maxlength="1000" required></textarea></label>
            <button type="submit" class="button button-primary">Save responsibility decision</button>
        </form>
    @else
        <div class="return-case-state-note">
            <span class="return-state-check" aria-hidden="true"><i data-lucide="clock-3"></i></span>
            <p><strong>{{ $case['status'] }}</strong><span>{{ $case['resolution'] ?: 'No Admin decision is available at this stage. The case will update when the required next step is completed.' }}</span></p>
        </div>
    @endif

    @if ($case['seller_recovery'] && $case['seller_recovery']['outstanding_minor'] > 0)
        <section class="return-seller-recovery-action" aria-labelledby="seller-recovery-heading-{{ $caseIndex }}">
            <h4 id="seller-recovery-heading-{{ $caseIndex }}">Seller recoverable balance</h4>
            <p>Refund-proceeds recovery follows the existing Seller Wallet rules. Return-delivery fees are recovered separately and never automatically deducted.</p>
            @if ($case['seller_recovery']['refund_outstanding_minor'] > 0)
                <details class="return-payout-disclosure">
                    <summary class="return-payout-disclosure-trigger"><i data-lucide="wallet-cards" aria-hidden="true"></i>Record refund-proceeds recovery</summary>
                    <form method="POST" action="{{ route('admin.compliance.returns-refunds.seller-recovery', $case['refund_id']) }}" class="return-payout-form return-recovery-form">
                        @csrf
                        <input type="hidden" name="recovery_component" value="refund_recovery">
                        <label class="return-payout-confirmation" for="recovery-confirmed-{{ $caseIndex }}"><input id="recovery-confirmed-{{ $caseIndex }}" type="checkbox" name="recovery_confirmed" value="1" required><span class="return-payout-confirmation-copy"><strong>I verified this recovery already occurred.</strong><small>Records a collection or an offset already applied to a paid Seller payout; it does not deduct funds.</small></span></label>
                        <label for="recovery-amount-{{ $caseIndex }}">Refund proceeds recovered (₱)<input id="recovery-amount-{{ $caseIndex }}" name="amount" type="number" inputmode="decimal" min="0.01" max="{{ number_format($case['seller_recovery']['refund_outstanding_minor'] / 100, 2, '.', '') }}" step="0.01" required></label>
                        <label for="recovery-method-{{ $caseIndex }}">Recovery method<select id="recovery-method-{{ $caseIndex }}" name="method" required><option value="">Choose method</option><option value="external_collection">External collection from Seller</option><option value="seller_payout_offset">Offset already applied to paid Seller payout</option></select></label>
                        <label for="recovery-reference-{{ $caseIndex }}">Receipt / payout reference<input id="recovery-reference-{{ $caseIndex }}" name="external_reference" type="text" maxlength="150" required></label>
                        <label for="recovery-paid-on-{{ $caseIndex }}">Date recovered<input id="recovery-paid-on-{{ $caseIndex }}" name="recovered_on" type="date" max="{{ now()->toDateString() }}" required></label>
                        <label for="recovery-payout-{{ $caseIndex }}">Paid Seller payout, if offset<select id="recovery-payout-{{ $caseIndex }}" name="seller_payout_id"><option value="">Not an offset</option>@foreach ($case['eligible_recovery_payouts'] as $sellerPayout)<option value="{{ $sellerPayout['id'] }}">{{ $sellerPayout['number'] }} · paid {{ $sellerPayout['paid_at'] }}@if ($sellerPayout['reference']) · {{ $sellerPayout['reference'] }}@endif</option>@endforeach</select><small>Required when the recovery method is payout offset.</small></label>
                        <label class="return-recovery-note" for="recovery-note-{{ $caseIndex }}">Verification note<textarea id="recovery-note-{{ $caseIndex }}" name="note" rows="2" minlength="5" maxlength="1000" required></textarea></label>
                        <button type="submit" class="button button-primary">Record verified recovery</button>
                    </form>
                </details>
            @endif
            @if ($case['seller_recovery']['return_shipping_outstanding_minor'] > 0)
                <details class="return-payout-disclosure">
                    <summary class="return-payout-disclosure-trigger"><i data-lucide="truck" aria-hidden="true"></i>Record return-delivery fee recovery</summary>
                    <form method="POST" action="{{ route('admin.compliance.returns-refunds.seller-recovery', $case['refund_id']) }}" class="return-payout-form return-recovery-form">
                        @csrf
                        <input type="hidden" name="recovery_component" value="return_shipping_fee">
                        <label class="return-payout-confirmation" for="return-fee-recovery-confirmed-{{ $caseIndex }}"><input id="return-fee-recovery-confirmed-{{ $caseIndex }}" type="checkbox" name="recovery_confirmed" value="1" required><span class="return-payout-confirmation-copy"><strong>I verified this fee was already recovered from the Seller.</strong><small>This records an external collection or an offset already applied to a paid Seller payout. It does not deduct funds.</small></span></label>
                        <label for="return-fee-recovery-amount-{{ $caseIndex }}">Return-delivery fee recovered (₱)<input id="return-fee-recovery-amount-{{ $caseIndex }}" name="amount" type="number" inputmode="decimal" min="0.01" max="{{ number_format($case['seller_recovery']['return_shipping_outstanding_minor'] / 100, 2, '.', '') }}" step="0.01" required></label>
                        <label for="return-fee-recovery-method-{{ $caseIndex }}">Recovery method<select id="return-fee-recovery-method-{{ $caseIndex }}" name="method" required><option value="">Choose method</option><option value="external_collection">External collection from Seller</option><option value="seller_payout_offset">Offset already applied to paid Seller payout</option></select></label>
                        <label for="return-fee-recovery-reference-{{ $caseIndex }}">Receipt / payout reference<input id="return-fee-recovery-reference-{{ $caseIndex }}" name="external_reference" type="text" maxlength="150" required></label>
                        <label for="return-fee-recovery-date-{{ $caseIndex }}">Date recovered<input id="return-fee-recovery-date-{{ $caseIndex }}" name="recovered_on" type="date" max="{{ now()->toDateString() }}" required></label>
                        <label for="return-fee-recovery-payout-{{ $caseIndex }}">Paid Seller payout, if offset<select id="return-fee-recovery-payout-{{ $caseIndex }}" name="seller_payout_id"><option value="">Not an offset</option>@foreach ($case['eligible_recovery_payouts'] as $sellerPayout)<option value="{{ $sellerPayout['id'] }}">{{ $sellerPayout['number'] }} · paid {{ $sellerPayout['paid_at'] }}@if ($sellerPayout['reference']) · {{ $sellerPayout['reference'] }}@endif</option>@endforeach</select><small>Required when the recovery method is payout offset.</small></label>
                        <label class="return-recovery-note" for="return-fee-recovery-note-{{ $caseIndex }}">Verification note<textarea id="return-fee-recovery-note-{{ $caseIndex }}" name="note" rows="2" minlength="5" maxlength="1000" required></textarea></label>
                        <button type="submit" class="button button-primary">Record verified return-fee recovery</button>
                    </form>
                </details>
            @endif
        </section>
    @endif

    <div class="return-modal-state-indicator" data-return-state-indicator hidden aria-live="polite">
        <i data-lucide="circle-check" aria-hidden="true"></i>
        <span data-return-state-label>Status updated</span>
    </div>
</section>
