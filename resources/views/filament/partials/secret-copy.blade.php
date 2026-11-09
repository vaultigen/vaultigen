<div
    x-data="{
        t: @js([
            'none_stored' => __('entries.js.none_stored'),
            'copied_label' => __('entries.js.copied_label'),
            'copy_failed' => __('entries.js.copy_failed'),
        ]),
        async copySecret(url, label) {
            try {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                    credentials: 'same-origin',
                });
                if (! response.ok) { throw new Error('denied'); }
                const data = await response.json();
                const value = data.value;
                if (value === null || value === undefined || value === '') {
                    this.notify(this.t.none_stored.replace(':label', label.toLowerCase()), 'warning');
                    return;
                }
                await this.copyText(value);
                this.notify(this.t.copied_label.replace(':label', label), 'success');
            } catch (error) {
                this.notify(this.t.copy_failed, 'danger');
            }
        },
        async copyText(value) {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(value);
                return;
            }
            const textarea = document.createElement('textarea');
            textarea.value = value;
            textarea.setAttribute('readonly', '');
            textarea.style.position = 'fixed';
            textarea.style.top = '-1000px';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            try { document.execCommand('copy'); } finally { document.body.removeChild(textarea); }
        },
        notify(title, status) {
            if (! window.FilamentNotification) { return; }
            const notification = new FilamentNotification().title(title).seconds(2);
            if (status === 'danger') { notification.danger(); }
            else if (status === 'warning') { notification.warning(); notification.icon('heroicon-o-exclamation-triangle'); }
            else { notification.success(); notification.icon('heroicon-o-clipboard-document-check'); }
            notification.send();
        },
    }"
>
    {{ $slot }}
</div>
