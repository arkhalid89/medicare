<!-- Confirmation dialog used by every destructive action (data-confirm) -->
<div class="modal-backdrop" id="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="confirm-title">
    <div class="modal">
        <div class="modal-body" style="display:flex;gap:1rem;padding-top:1.4rem">
            <div class="confirm-icon"><?= icon('alert') ?></div>
            <div>
                <h3 id="confirm-title" style="font-size:1.1rem;margin-bottom:.35rem">Are you sure?</h3>
                <p class="muted mb-0" id="confirm-text">This action cannot be undone.</p>
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-outline" data-confirm-cancel>Cancel</button>
            <button type="button" class="btn btn-danger" data-confirm-ok>Yes, continue</button>
        </div>
    </div>
</div>

<div class="toasts" id="toasts" aria-live="polite"></div>

<div class="loading-overlay" id="loading-overlay">
    <div class="loading-box"><span class="spinner"></span><span id="loading-text">Please wait…</span></div>
</div>
