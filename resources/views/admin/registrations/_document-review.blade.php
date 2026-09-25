@forelse ($application['document_records'] as $document)
    <div>
        <a class="document-preview" href="{{ route('admin.application-documents.show', [$application['database_id'], $document->id]) }}" target="_blank" rel="noopener">
            <span><i data-lucide="file-text"></i></span>
            <div><strong>{{ str($document->document_type)->replace('_', ' ')->title() }}</strong><small>{{ $document->original_name }} • {{ str($document->verification_status)->replace('_', ' ')->title() }}</small></div>
            <i data-lucide="external-link"></i>
        </a>
        <div class="row-actions">
            <form method="POST" action="{{ route('admin.application-documents.update', [$application['database_id'], $document->id]) }}">@csrf @method('PATCH')<input type="hidden" name="verification_status" value="verified"><button type="submit" class="button button-primary button-small">Verify</button></form>
            <form method="POST" action="{{ route('admin.application-documents.update', [$application['database_id'], $document->id]) }}" onsubmit="const reason = prompt('Reason for rejecting this document:'); if (!reason) return false; this.querySelector('[name=rejection_reason]').value = reason;">@csrf @method('PATCH')<input type="hidden" name="verification_status" value="rejected"><input type="hidden" name="rejection_reason"><button type="submit" class="button button-danger-soft button-small">Reject</button></form>
        </div>
    </div>
@empty
    <p>No verification documents were submitted.</p>
@endforelse
