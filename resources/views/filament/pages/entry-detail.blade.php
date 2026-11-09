<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">
            <span class="inline-flex flex-wrap items-center gap-2">
                <span>{{ $entry->title }}</span>
                <x-filament::badge color="primary" :icon="$typeIcon" :tooltip="$typeLabel">{{ $typeLabel }}</x-filament::badge>
            </span>
        </x-slot>

        @if ($entry->url_host)
            <x-slot name="description">
                <a
                    href="https://{{ $entry->url_host }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-1.5 text-sm text-primary-600 underline-offset-2 hover:underline dark:text-primary-400"
                >
                    <x-filament::icon icon="heroicon-o-globe-alt" class="h-4 w-4" />
                    {{ $entry->url_host }}
                </a>
            </x-slot>
        @endif

        <dl class="grid grid-cols-1 gap-x-8 gap-y-4 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <x-filament::icon icon="heroicon-o-user" class="h-4 w-4" />
                    {{ __('entries.common.owner') }}
                </dt>
                <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $entry->owner?->name ?? __('entries.common.unknown') }}</dd>
            </div>

            <div>
                <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <x-filament::icon icon="heroicon-o-folder" class="h-4 w-4" />
                    {{ __('entries.common.folder') }}
                </dt>
                <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $entry->folder?->name ?? __('entries.common.no_folder') }}</dd>
            </div>

            <div>
                <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <x-filament::icon icon="heroicon-o-clock" class="h-4 w-4" />
                    {{ __('entries.detail.expiry') }}
                </dt>
                <dd class="mt-1 text-sm">
                    @if ($expiryState === 'none')
                        <span class="text-gray-500 dark:text-gray-400">{{ __('entries.detail.no_expiry') }}</span>
                    @elseif ($expiryState === 'expired')
                        <span class="font-medium text-danger-600 dark:text-danger-400">{{ __('entries.detail.expired', ['time' => $entry->expires_at->diffForHumans()]) }}</span>
                    @elseif ($expiryState === 'soon')
                        <span class="font-medium text-warning-600 dark:text-warning-400" title="{{ $entry->expires_at->toDayDateTimeString() }}">{{ $entry->expires_at->diffForHumans() }}</span>
                    @else
                        <span class="text-gray-950 dark:text-white" title="{{ $entry->expires_at->toDayDateTimeString() }}">{{ $entry->expires_at->diffForHumans() }}</span>
                    @endif
                </dd>
            </div>

            <div>
                <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <x-filament::icon icon="heroicon-o-arrow-path" class="h-4 w-4" />
                    {{ __('entries.common.updated') }}
                </dt>
                <dd class="mt-1 text-sm text-gray-950 dark:text-white" title="{{ $entry->updated_at?->toDayDateTimeString() }}">
                    {{ $entry->updated_at?->diffForHumans() ?? '—' }}
                </dd>
            </div>
        </dl>

        @if ($entry->tags->isNotEmpty())
            <div class="mt-6 border-t border-gray-200 pt-4 dark:border-white/10">
                <div class="mb-2 flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <x-filament::icon icon="heroicon-o-tag" class="h-4 w-4" />
                    {{ __('entries.common.tags') }}
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    @foreach ($entry->tags as $tag)
                        <x-filament::badge :color="$tag->color ?? 'gray'">{{ $tag->name }}</x-filament::badge>
                    @endforeach
                </div>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section
        :heading="__('entries.detail.key_values.heading')"
        :description="__('entries.detail.key_values.description')"
    >
        @if (! $canReveal)
            <x-filament::callout color="gray" icon="heroicon-o-lock-closed" :heading="__('entries.detail.reveal_unavailable.heading')" :description="__('entries.detail.reveal_unavailable.description')" />
        @else
            <div
                class="space-y-3"
                x-data="{
                    t: @js([
                        'copy_failed' => __('entries.js.copy_failed'),
                        'copied_to_clipboard' => __('entries.js.copied_to_clipboard'),
                        'copied_label' => __('entries.js.copied_label'),
                        'not_available' => __('entries.js.not_available'),
                    ]),
                    revealed: {},
                    timers: {},
                    busy: '',
                    copied: '',
                    async fetchRaw(url) {
                        const response = await fetch(url, {
                            method: 'POST',
                            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                            credentials: 'same-origin',
                        });
                        if (! response.ok) { throw new Error('denied'); }
                        const data = await response.json();
                        return data.value;
                    },
                    async fetchValue(key, url) {
                        this.revealed[key] = await this.fetchRaw(url);
                        clearTimeout(this.timers[key]);
                        this.timers[key] = setTimeout(() => { delete this.revealed[key]; }, 30000);
                    },
                    async reveal(key, url) {
                        if (this.isRevealed(key)) { delete this.revealed[key]; return; }
                        this.busy = key;
                        try { await this.fetchValue(key, url); }
                        catch (error) { this.revealed[key] = '__error__'; }
                        finally { this.busy = ''; }
                    },
                    async copyRow(key, url, value, label) {
                        this.busy = key;
                        try {
                            const text = url
                                ? (this.isRevealed(key) ? this.revealed[key] : await this.fetchRaw(url))
                                : value;
                            await this.copyText(text);
                            this.flashCopied(key, label);
                        } catch (error) {
                            this.notify(this.t.copy_failed, 'danger');
                        } finally {
                            this.busy = '';
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
                    flashCopied(key, label) {
                        this.copied = key;
                        this.notify((label ? this.t.copied_label.replace(':label', label) : this.t.copied_to_clipboard), 'success');
                        setTimeout(() => { if (this.copied === key) { this.copied = ''; } }, 1500);
                    },
                    notify(title, status) {
                        if (! window.FilamentNotification) { return; }
                        const notification = new FilamentNotification().title(title).seconds(2);
                        status === 'danger' ? notification.danger() : notification.success();
                        notification.icon(status === 'danger' ? 'heroicon-o-x-circle' : 'heroicon-o-clipboard-document-check').send();
                    },
                    display(key) {
                        if (this.revealed[key] === undefined) { return '••••••••••'; }
                        if (this.revealed[key] === '__error__') { return this.t.not_available; }
                        return this.revealed[key];
                    },
                    isRevealed(key) {
                        return this.revealed[key] !== undefined && this.revealed[key] !== '__error__';
                    },
                    isError(key) {
                        return this.revealed[key] === '__error__';
                    }
                }"
            >
                @foreach ($valueRows as $row)
                    <div
                        wire:key="value-{{ $row['key'] }}"
                        class="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-gray-200 p-3 dark:border-white/10"
                    >
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                {{ $row['label'] }}
                                @if ($row['badge'])
                                    <span class="rounded bg-gray-100 px-1 py-0.5 text-[10px] font-semibold normal-case text-gray-600 dark:bg-white/10 dark:text-gray-300">{{ $row['badge'] }}</span>
                                @endif
                            </div>
                            @if ($row['concealable'])
                                <div
                                    class="mt-0.5 whitespace-pre-wrap font-mono text-sm text-gray-950 dark:text-white {{ $row['multiline'] ? 'break-words' : 'break-all' }}"
                                    x-bind:class="isError('{{ $row['key'] }}') && 'text-danger-600 dark:text-danger-400'"
                                    x-text="display('{{ $row['key'] }}')"
                                ></div>
                            @else
                                <div class="mt-0.5 whitespace-pre-wrap font-mono text-sm text-gray-950 dark:text-white {{ $row['multiline'] ? 'break-words' : 'break-all' }}">{{ $row['copyValue'] }}</div>
                            @endif
                        </div>
                        <div class="flex items-center gap-1">
                            @if ($row['concealable'])
                                <button
                                    type="button"
                                    aria-label="{{ __('entries.detail.reveal') }}"
                                    title="{{ __('entries.detail.reveal') }}"
                                    x-cloak
                                    x-show="! isRevealed('{{ $row['key'] }}')"
                                    x-bind:disabled="busy === '{{ $row['key'] }}'"
                                    x-on:click="reveal('{{ $row['key'] }}', {{ \Illuminate\Support\Js::from($row['revealUrl']) }})"
                                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 disabled:cursor-not-allowed disabled:opacity-50 dark:text-gray-400 dark:hover:bg-white/10 dark:hover:text-gray-200"
                                >
                                    <x-filament::icon icon="heroicon-o-eye" class="h-5 w-5" />
                                </button>
                                <button
                                    type="button"
                                    aria-label="{{ __('entries.js.hide') }}"
                                    title="{{ __('entries.js.hide') }}"
                                    x-cloak
                                    x-show="isRevealed('{{ $row['key'] }}')"
                                    x-bind:disabled="busy === '{{ $row['key'] }}'"
                                    x-on:click="reveal('{{ $row['key'] }}', {{ \Illuminate\Support\Js::from($row['revealUrl']) }})"
                                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 disabled:cursor-not-allowed disabled:opacity-50 dark:text-gray-400 dark:hover:bg-white/10 dark:hover:text-gray-200"
                                >
                                    <x-filament::icon icon="heroicon-o-eye-slash" class="h-5 w-5" />
                                </button>
                            @endif
                            <button
                                type="button"
                                aria-label="{{ __('entries.detail.copy') }}"
                                title="{{ __('entries.detail.copy') }}"
                                x-cloak
                                x-show="copied !== '{{ $row['key'] }}'"
                                x-bind:disabled="busy === '{{ $row['key'] }}'"
                                x-on:click="copyRow('{{ $row['key'] }}', {{ \Illuminate\Support\Js::from($row['revealUrl']) }}, {{ \Illuminate\Support\Js::from($row['copyValue']) }}, {{ \Illuminate\Support\Js::from($row['label']) }})"
                                class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 disabled:cursor-not-allowed disabled:opacity-50 dark:text-gray-400 dark:hover:bg-white/10 dark:hover:text-gray-200"
                            >
                                <x-filament::icon icon="heroicon-o-clipboard" class="h-5 w-5" />
                            </button>
                            <button
                                type="button"
                                aria-label="{{ __('entries.detail.copied') }}"
                                title="{{ __('entries.detail.copied') }}"
                                x-cloak
                                x-show="copied === '{{ $row['key'] }}'"
                                x-on:click="copyRow('{{ $row['key'] }}', {{ \Illuminate\Support\Js::from($row['revealUrl']) }}, {{ \Illuminate\Support\Js::from($row['copyValue']) }}, {{ \Illuminate\Support\Js::from($row['label']) }})"
                                class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-success-600 transition hover:bg-gray-100 dark:text-success-400 dark:hover:bg-white/10"
                            >
                                <x-filament::icon icon="heroicon-o-clipboard-document-check" class="h-5 w-5" />
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>

    <x-filament::section
        :heading="__('attachments.heading')"
        :description="__('attachments.description')"
    >
        <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500 dark:text-gray-400">
            <span>
                {{ __('attachments.quota.label') }}:
                {{ __('attachments.quota.usage', ['used' => $attachmentUsage['used'], 'limit' => $attachmentUsage['limit']]) }}
                · {{ __('attachments.quota.per_file', ['size' => $attachmentUsage['per_file']]) }}
            </span>
        </div>

        @if ($canManageAttachments)
            <form
                method="POST"
                action="{{ route('attachments.store', ['vault' => $entry->vault_id, 'entry' => $entry->id]) }}"
                enctype="multipart/form-data"
                class="mt-4 space-y-3"
                x-data="{ uploading: false }"
                x-on:submit="uploading = true"
            >
                @csrf
                <div>
                    <label for="entry-attachment" class="block text-sm font-medium text-gray-950 dark:text-white">{{ __('attachments.upload.label') }}</label>
                    <input
                        id="entry-attachment"
                        type="file"
                        name="attachment"
                        required
                        class="mt-1 block w-full cursor-pointer rounded-lg border border-gray-300 bg-white text-sm text-gray-700 file:mr-3 file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-sm file:font-medium dark:border-white/10 dark:bg-white/5 dark:text-gray-200 dark:file:bg-white/10"
                    >
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('attachments.upload.help', ['size' => $attachmentUsage['per_file']]) }}</p>
                </div>

                @error('attachment')
                    <x-filament::callout color="danger" icon="heroicon-o-exclamation-triangle" :heading="__('attachments.errors.heading')" :description="$message" />
                @enderror

                <x-filament::button type="submit" icon="heroicon-o-arrow-up-tray" x-bind:disabled="uploading">
                    <span x-show="! uploading">{{ __('attachments.upload.submit') }}</span>
                    <span x-show="uploading" x-cloak>{{ __('attachments.upload.busy') }}</span>
                </x-filament::button>
            </form>
        @endif

        @if ($attachmentRows === [])
            <x-filament::empty-state
                class="mt-4"
                icon="heroicon-o-paper-clip"
                :heading="__('attachments.empty.heading')"
                :description="__('attachments.empty.description')"
            />
        @else
            <ul role="list" class="mt-4 divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($attachmentRows as $attachment)
                    <li wire:key="attachment-{{ $attachment['id'] }}" class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 text-sm font-medium text-gray-950 dark:text-white">
                                <x-filament::icon icon="heroicon-o-paper-clip" class="h-4 w-4 shrink-0 text-gray-400" />
                                <span class="truncate">{{ $attachment['filename'] }}</span>
                                <x-filament::badge color="gray">{{ $attachment['mime'] }}</x-filament::badge>
                            </div>
                            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {{ $attachment['size'] }} · {{ $attachment['uploadedBy'] }} · {{ $attachment['uploadedAt'] }}
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            @if ($attachment['downloadUrl'])
                                <x-filament::button
                                    tag="a"
                                    :href="$attachment['downloadUrl']"
                                    icon="heroicon-o-arrow-down-tray"
                                    color="gray"
                                    size="sm"
                                >{{ __('attachments.actions.download') }}</x-filament::button>
                            @endif
                            @if ($canManageAttachments)
                                <form
                                    method="POST"
                                    action="{{ route('attachments.destroy', ['vault' => $entry->vault_id, 'entry' => $entry->id, 'attachment' => $attachment['id']]) }}"
                                    onsubmit="return confirm(@js(__('attachments.remove_confirm')))"
                                >
                                    @csrf
                                    @method('DELETE')
                                    <x-filament::button type="submit" color="danger" size="sm" icon="heroicon-o-trash">
                                        {{ __('attachments.actions.remove') }}
                                    </x-filament::button>
                                </form>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>

    @if ($showTotp && $canReveal)
        <x-filament::section
            :heading="__('entries.detail.totp.heading')"
            :description="__('entries.detail.totp.description')"
        >
            <div
                x-data="{
                    t: @js([
                        'code_copied' => __('entries.js.code_copied'),
                        'copy_failed' => __('entries.js.copy_failed'),
                    ]),
                    code: '',
                    remaining: 0,
                    period: 30,
                    loading: false,
                    error: false,
                    copied: false,
                    async load() {
                        this.loading = true;
                        this.error = false;
                        try {
                            const response = await fetch('{{ route('entries.totp', ['entry' => $entry->id]) }}', {
                                method: 'POST',
                                headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                                credentials: 'same-origin',
                            });
                            if (! response.ok) { throw new Error('denied'); }
                            const data = await response.json();
                            this.code = data.code;
                            this.period = data.period;
                            this.remaining = data.seconds_remaining;
                        } catch (error) {
                            this.error = true;
                        } finally {
                            this.loading = false;
                        }
                    },
                    async copyCode() {
                        try {
                            await navigator.clipboard.writeText(this.code);
                            this.copied = true;
                            if (window.FilamentNotification) {
                                new FilamentNotification().title(this.t.code_copied).success().icon('heroicon-o-clipboard-document-check').seconds(2).send();
                            }
                            setTimeout(() => { this.copied = false; }, 1500);
                        } catch (error) {
                            if (window.FilamentNotification) {
                                new FilamentNotification().title(this.t.copy_failed).danger().seconds(2).send();
                            }
                        }
                    },
                    progress() {
                        return this.period > 0 ? Math.round((this.period - this.remaining) / this.period * 100) : 0;
                    },
                    init() {
                        setInterval(() => {
                            if (this.code === '' || this.error) { return; }
                            if (this.remaining > 0) { this.remaining--; }
                            if (this.remaining === 0) { this.load(); }
                        }, 1000);
                    }
                }"
                class="space-y-3"
            >
                <div class="flex flex-wrap items-center gap-4">
                    <div class="font-mono text-2xl tracking-widest text-gray-950 dark:text-white" x-text="code !== '' ? code : '••• •••'"></div>
                    <x-filament::button type="button" x-bind:disabled="loading" x-on:click="load()">
                        <span x-show="! loading">{{ __('entries.detail.totp.show') }}</span>
                        <span x-show="loading">{{ __('entries.detail.totp.refreshing') }}</span>
                    </x-filament::button>
                    <x-filament::icon-button
                        icon="heroicon-o-clipboard"
                        color="gray"
                        :label="__('entries.detail.copy')"
                        x-cloak
                        x-show="code !== '' && ! error && ! copied"
                        x-on:click="copyCode()"
                    />
                    <x-filament::icon-button
                        icon="heroicon-o-clipboard-document-check"
                        color="success"
                        :label="__('entries.detail.copied')"
                        x-cloak
                        x-show="copied"
                        x-on:click="copyCode()"
                    />
                </div>
                <template x-if="error">
                    <p class="text-sm text-danger-600 dark:text-danger-400">{{ __('entries.detail.totp.error') }}</p>
                </template>
                <template x-if="code !== '' && ! error">
                    <div class="flex items-center gap-3">
                        <div class="h-1.5 w-40 overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                            <div class="h-full bg-primary-500 transition-all" x-bind:style="'width: ' + progress() + '%'"></div>
                        </div>
                        <span class="text-sm text-gray-500 dark:text-gray-400"><span x-text="remaining"></span>{{ __('entries.detail.totp.seconds_remaining') }}</span>
                    </div>
                </template>
            </div>
        </x-filament::section>
    @endif

    <x-filament::section
        :heading="__('entries.detail.access.heading')"
        :description="__('entries.detail.access.description')"
    >
        <x-filament::callout
            :color="$canEdit ? 'success' : 'gray'"
            :icon="$canEdit ? 'heroicon-o-pencil-square' : 'heroicon-o-lock-closed'"
            :heading="$canEdit ? __('entries.detail.access.can_edit_heading') : __('entries.detail.access.read_only_heading')"
            :description="$canEdit
                ? __('entries.detail.access.can_edit_description')
                : __('entries.detail.access.read_only_description')"
        />
        @if ($canRestore)
            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">{{ __('entries.detail.managers_only') }}</p>
        @endif
    </x-filament::section>

    @if ($canRestore)
        <x-filament::section
            :heading="__('entries.detail.history.heading')"
            :description="__('entries.detail.history.description')"
        >
            @if ($history->isEmpty())
                <x-filament::empty-state
                    icon="heroicon-o-clock"
                    :heading="__('entries.detail.history.empty_heading')"
                    :description="__('entries.detail.history.empty_description')"
                />
            @else
                <ul role="list" class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($history as $revision)
                        <li wire:key="revision-{{ $revision->id }}" class="flex flex-wrap items-center justify-between gap-3 py-3">
                            <div class="text-sm">
                                <span class="font-medium text-gray-950 dark:text-white">{{ __('entries.detail.history.revision', ['number' => $revision->revision_number]) }}</span>
                                <span class="text-gray-500 dark:text-gray-400">
                                    · {{ $revision->changedBy?->name ?? __('entries.common.unknown') }}
                                    · {{ $revision->created_at->toDayDateTimeString() }}
                                </span>
                            </div>
                            <x-filament::button
                                size="sm"
                                color="gray"
                                wire:click="restore('{{ $revision->id }}')"
                                wire:confirm="{{ __('entries.detail.history.restore_confirm') }}"
                            >{{ __('entries.detail.history.restore') }}</x-filament::button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
