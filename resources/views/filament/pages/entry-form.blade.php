<x-filament-panels::page>
    @php
        $action = $isEdit
            ? route('entries.update', ['vault' => $vault->id, 'entry' => $entry->id])
            : route('entries.store', ['vault' => $vault->id]);
        $secretNote = $isEdit ? __('entries.form.secret_note') : null;
        $initialCustomFields = collect($customFields)->map(fn (array $field): array => [
            'uid' => 'existing-'.$field['index'],
            'key' => (string) $field['index'],
            'label' => $field['label'],
            'value' => '',
            'concealed' => (bool) $field['concealed'],
        ])->values()->all();
        $metadataErrorKeys = ['type', 'owner_user_id', 'expires_at', 'folder_id', 'new_folder_name', 'algorithm', 'digits', 'period', 'tags'];
    @endphp

    <style>[x-cloak] { display: none !important; }</style>

    <x-filament::section
        :heading="$isEdit ? __('entries.form.heading.edit', ['type' => $typeLabels[$type]]) : __('entries.form.heading.new')"
        :description="__('entries.form.heading.description')"
    >
        <form method="POST" action="{{ $action }}" class="space-y-6">
            @csrf
            @if ($isEdit)
                @method('PUT')
                <input type="hidden" name="type" value="{{ $type }}">
            @endif

            @error('entry')
                <x-filament::callout color="danger" icon="heroicon-o-exclamation-triangle" :heading="$message" />
            @enderror

            <div>
                <label for="title" class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">{{ __('entries.form.title_field.label') }} <span class="text-danger-600">*</span></label>
                <x-filament::input.wrapper :valid="! $errors->has('title')">
                    <x-filament::input id="title" name="title" type="text" maxlength="200" required value="{{ old('title', $entry?->title) }}" />
                </x-filament::input.wrapper>
                @error('title')<p class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror
            </div>

            {{-- Only non-secret metadata is bound to the Filament form. Secret
                 values are never placed in Livewire state. --}}
            {{ $this->form }}

            {{-- Every Livewire-bound metadata value is mirrored into the plain
                 POST so the secret form can be submitted directly. --}}
            <div x-data>
                @unless ($isEdit)
                    <input type="hidden" name="type" :value="$wire.data.type">
                @endunless
                <input type="hidden" name="owner_user_id" :value="$wire.data.owner_user_id">
                <input type="hidden" name="folder_id" :value="$wire.data.folder_id ?? ''">
                <input type="hidden" name="new_folder_name" :value="$wire.data.new_folder_name ?? ''">
                <input type="hidden" name="expires_at" :value="$wire.data.expires_at ?? ''">
                <input type="hidden" name="generated" :value="$wire.data.generated ? '1' : '0'">
                <input type="hidden" name="algorithm" :value="$wire.data.algorithm ?? ''">
                <input type="hidden" name="digits" :value="$wire.data.digits ?? ''">
                <input type="hidden" name="period" :value="$wire.data.period ?? ''">
                @if ($vaultScope)
                    <template x-for="tag in ($wire.data.tags ?? [])" :key="`entry-tag-${tag}`">
                        <input type="hidden" name="tags[]" :value="tag">
                    </template>
                @endif
            </div>

            @foreach ($metadataErrorKeys as $metadataErrorKey)
                @error($metadataErrorKey)<p class="text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror
            @endforeach

            {{-- Login --}}
            <template x-if="$wire.data.type === 'login'">
                <div class="space-y-6">
                    <div class="grid gap-6 md:grid-cols-2">
                        <div>
                            <label for="username" class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">{{ __('entries.form.username.label') }}</label>
                            <x-filament::input.wrapper :valid="! $errors->has('username')">
                                <x-filament::input id="username" name="username" type="text" maxlength="{{ \App\Services\EntryPayload::MAX_USERNAME }}" autocomplete="off" value="" />
                            </x-filament::input.wrapper>
                            @error('username')<p class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label for="url" class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">{{ __('entries.form.url.label') }}</label>
                            <x-filament::input.wrapper :valid="! $errors->has('url')">
                                <x-filament::input id="url" name="url" type="text" maxlength="{{ \App\Services\EntryPayload::MAX_URL }}" autocomplete="off" placeholder="https://example.com/login" value="" />
                            </x-filament::input.wrapper>
                            @error('url')<p class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('entries.form.url.hint') }}</p>
                        </div>
                    </div>

                    <div
                        class="space-y-3"
                        x-data="{
                            t: @js([
                                'password_start' => __('entries.js.password_start'),
                                'password_select_set' => __('entries.js.password_select_set'),
                                'password_weak' => __('entries.js.password_weak'),
                                'password_fair' => __('entries.js.password_fair'),
                                'password_good' => __('entries.js.password_good'),
                                'password_strong' => __('entries.js.password_strong'),
                            ]),
                            length: 20,
                            useLower: true,
                            useUpper: true,
                            useDigits: true,
                            useSymbols: true,
                            bits: 0,
                            label: @js(__('entries.js.password_start')),
                            color: 'gray',
                            showPassword: false,
                            generate() {
                                const sets = [];
                                if (this.useLower) sets.push('abcdefghijkmnopqrstuvwxyz');
                                if (this.useUpper) sets.push('ABCDEFGHJKLMNPQRSTUVWXYZ');
                                if (this.useDigits) sets.push('23456789');
                                if (this.useSymbols) sets.push('!@#$%^&*()-_=+[]{};:,.?');
                                if (! sets.length) { this.label = this.t.password_select_set; this.color = 'danger'; return; }
                                const pool = sets.join('');
                                const size = Math.max(8, Math.min(128, parseInt(this.length) || 20));
                                const bytes = new Uint32Array(size);
                                crypto.getRandomValues(bytes);
                                let value = '';
                                for (let i = 0; i < size; i++) { value += pool[bytes[i] % pool.length]; }
                                this.$refs.password.value = value;
                                $wire.$set('data.generated', true, false);
                                this.score();
                            },
                            score() {
                                const value = this.$refs.password.value || '';
                                let pool = 0;
                                if (/[a-z]/.test(value)) pool += 26;
                                if (/[A-Z]/.test(value)) pool += 26;
                                if (/[0-9]/.test(value)) pool += 10;
                                if (/[^A-Za-z0-9]/.test(value)) pool += 24;
                                this.bits = Math.round(value.length * (pool ? Math.log2(pool) : 0));
                                if (! value) { this.label = this.t.password_start; this.color = 'gray'; }
                                else if (this.bits < 40) { this.label = this.t.password_weak; this.color = 'danger'; }
                                else if (this.bits < 60) { this.label = this.t.password_fair; this.color = 'warning'; }
                                else if (this.bits < 80) { this.label = this.t.password_good; this.color = 'info'; }
                                else { this.label = this.t.password_strong; this.color = 'success'; }
                            }
                        }"
                    >
                        <label for="password" class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">
                            {{ __('entries.form.password.label') }} @unless ($isEdit)<span class="text-danger-600">*</span>@endunless
                        </label>
                        <div class="flex flex-wrap items-start gap-3">
                            <div class="relative min-w-[16rem] flex-1">
                                <x-filament::input.wrapper :valid="! $errors->has('password')" class="pe-10">
                                    <x-filament::input x-ref="password" id="password" name="password" type="password" maxlength="{{ \App\Services\EntryPayload::MAX_PASSWORD }}" autocomplete="new-password" :required="! $isEdit" x-on:input="score()" x-bind:type="showPassword ? 'text' : 'password'" />
                                </x-filament::input.wrapper>
                                <button
                                    type="button"
                                    x-on:click="showPassword = ! showPassword"
                                    :aria-label="showPassword ? @js(__('entries.js.hide_password')) : @js(__('entries.js.show_password'))"
                                    class="absolute inset-y-0 end-0 flex cursor-pointer items-center pe-3 text-gray-400 transition hover:text-gray-600 dark:hover:text-gray-200"
                                >
                                    <x-filament::icon icon="heroicon-o-eye" class="h-5 w-5" x-show="! showPassword" />
                                    <x-filament::icon icon="heroicon-o-eye-slash" class="h-5 w-5" x-cloak x-show="showPassword" />
                                </button>
                            </div>
                            <x-filament::button type="button" color="gray" icon="heroicon-o-sparkles" x-on:click="generate()">{{ __('entries.form.password.generate') }}</x-filament::button>
                        </div>
                        @error('password')<p class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror

                        <div class="grid gap-2 rounded-lg border border-gray-200 p-3 text-sm dark:border-white/10 md:grid-cols-2">
                            <div class="flex items-center gap-3">
                                <label class="text-sm">{{ __('entries.form.password.length') }}
                                    <input type="number" min="8" max="128" x-model.number="length" class="ms-2 w-20 rounded border-gray-300 dark:border-white/10 dark:bg-white/5">
                                </label>
                            </div>
                            <div class="flex flex-wrap items-center gap-4">
                                <label class="flex items-center gap-2"><input type="checkbox" x-model="useLower"> a-z</label>
                                <label class="flex items-center gap-2"><input type="checkbox" x-model="useUpper"> A-Z</label>
                                <label class="flex items-center gap-2"><input type="checkbox" x-model="useDigits"> 0-9</label>
                                <label class="flex items-center gap-2"><input type="checkbox" x-model="useSymbols"> {{ __('entries.form.password.symbols') }}</label>
                            </div>
                        </div>

                        <div class="flex items-center gap-3">
                            <div class="h-2 w-40 overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                                <div class="h-full transition-all" :class="{
                                    'bg-gray-400': color === 'gray',
                                    'bg-danger-500': color === 'danger',
                                    'bg-warning-500': color === 'warning',
                                    'bg-info-500': color === 'info',
                                    'bg-success-500': color === 'success'
                                }" :style="'width: ' + Math.min(100, Math.round(bits / 100 * 100)) + '%'"></div>
                            </div>
                            <span class="text-sm text-gray-600 dark:text-gray-300">
                                <span x-text="label"></span>
                                <span x-show="bits > 0"> · <span x-text="bits"></span> {{ __('entries.form.password.bits_of_entropy') }}</span>
                            </span>
                        </div>
                    </div>

                    <div>
                        <label for="notes" class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">{{ __('entries.form.notes.label') }}</label>
                        <x-filament::input.wrapper :valid="! $errors->has('notes')">
                            <textarea id="notes" name="notes" rows="4" maxlength="{{ \App\Services\EntryPayload::MAX_NOTES }}" class="fi-input block w-full resize-y border-0 bg-transparent p-0 focus:ring-0">{{ old('notes') }}</textarea>
                        </x-filament::input.wrapper>
                        @error('notes')<p class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror
                    </div>

                    <div x-data="{ shown: false }">
                        <label for="totp_secret" class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">{{ __('entries.form.totp_seed.label') }}</label>
                        <div class="relative">
                            <x-filament::input.wrapper :valid="! $errors->has('totp_secret')" class="pe-10">
                                <x-filament::input id="totp_secret" name="totp_secret" type="password" x-bind:type="shown ? 'text' : 'password'" maxlength="{{ \App\Services\EntryPayload::MAX_TOTP_SECRET }}" autocomplete="off" :placeholder="$hasTotpSeed ? __('entries.form.totp_seed.placeholder_saved') : __('entries.form.totp_seed.placeholder_new')" />
                            </x-filament::input.wrapper>
                            <button
                                type="button"
                                x-on:click="shown = ! shown"
                                :aria-label="shown ? @js(__('entries.js.hide')) : @js(__('entries.js.show'))"
                                class="absolute inset-y-0 end-0 flex cursor-pointer items-center pe-3 text-gray-400 transition hover:text-gray-600 dark:hover:text-gray-200"
                            >
                                <x-filament::icon icon="heroicon-o-eye" class="h-5 w-5" x-show="! shown" />
                                <x-filament::icon icon="heroicon-o-eye-slash" class="h-5 w-5" x-cloak x-show="shown" />
                            </button>
                        </div>
                        @error('totp_secret')<p class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('entries.form.totp_seed.hint') }}</p>
                    </div>

                    <x-filament::callout
                        color="warning"
                        icon="heroicon-o-shield-exclamation"
                        :heading="__('entries.form.totp_warning.heading')"
                        :description="__('entries.form.totp_warning.description')"
                    />
                </div>
            </template>

            {{-- Secure note --}}
            <template x-if="$wire.data.type === 'note'">
                <div>
                    <label for="body" class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">{{ __('entries.form.note.label') }} <span class="text-danger-600">*</span></label>
                    <x-filament::input.wrapper :valid="! $errors->has('body')">
                        <textarea id="body" name="body" rows="10" maxlength="{{ \App\Services\EntryPayload::MAX_NOTE_BODY }}" @unless ($isEdit) required @endunless class="fi-input block w-full resize-y border-0 bg-transparent p-0 focus:ring-0">{{ old('body') }}</textarea>
                    </x-filament::input.wrapper>
                    @error('body')<p class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror
                    @if ($secretNote)<p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $secretNote }}</p>@endif
                </div>
            </template>

            {{-- SSH key --}}
            <template x-if="$wire.data.type === 'ssh_key'">
                <div class="space-y-6">
                    <div>
                        <label for="public_key" class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">{{ __('entries.form.public_key.label') }}</label>
                        <x-filament::input.wrapper :valid="! $errors->has('public_key')">
                            <textarea id="public_key" name="public_key" rows="4" maxlength="{{ \App\Services\EntryPayload::MAX_SSH_PUBLIC_KEY }}" class="fi-input block w-full resize-y border-0 bg-transparent p-0 focus:ring-0">{{ old('public_key') }}</textarea>
                        </x-filament::input.wrapper>
                        @error('public_key')<p class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror
                    </div>

                    <div x-data="{ shown: {{ old('private_key') ? 'true' : 'false' }} }">
                        <label for="private_key" class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">{{ __('entries.form.private_key.label') }}</label>
                        <div class="relative">
                            <x-filament::input.wrapper :valid="! $errors->has('private_key')" class="pe-10">
                                <div x-show="! shown" class="flex items-center gap-2 py-1 font-mono text-sm text-gray-500 dark:text-gray-400">
                                    <x-filament::icon icon="heroicon-o-lock-closed" class="h-4 w-4 shrink-0" />
                                    <span>••••••••••••••••••••</span>
                                    <span class="text-xs">{{ __('entries.form.private_key.hidden') }}</span>
                                </div>
                                <textarea id="private_key" name="private_key" rows="6" x-cloak x-show="shown" maxlength="{{ \App\Services\EntryPayload::MAX_SSH_PRIVATE_KEY }}" class="fi-input block w-full resize-y border-0 bg-transparent p-0 focus:ring-0">{{ old('private_key') }}</textarea>
                            </x-filament::input.wrapper>
                            <button
                                type="button"
                                x-on:click="shown = ! shown"
                                :aria-label="shown ? @js(__('entries.js.hide')) : @js(__('entries.js.show'))"
                                class="absolute inset-y-0 end-0 flex cursor-pointer items-start pe-3 pt-2 text-gray-400 transition hover:text-gray-600 dark:hover:text-gray-200"
                            >
                                <x-filament::icon icon="heroicon-o-eye" class="h-5 w-5" x-show="! shown" />
                                <x-filament::icon icon="heroicon-o-eye-slash" class="h-5 w-5" x-cloak x-show="shown" />
                            </button>
                        </div>
                        @error('private_key')<p class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('entries.form.ssh_key.hint') }}</p>
                    </div>

                    <div x-data="{ shown: false }">
                        <label for="passphrase" class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">{{ __('entries.form.passphrase.label') }}</label>
                        <div class="relative">
                            <x-filament::input.wrapper :valid="! $errors->has('passphrase')" class="pe-10">
                                <x-filament::input id="passphrase" name="passphrase" type="password" x-bind:type="shown ? 'text' : 'password'" maxlength="{{ \App\Services\EntryPayload::MAX_SSH_PASSPHRASE }}" autocomplete="off" />
                            </x-filament::input.wrapper>
                            <button
                                type="button"
                                x-on:click="shown = ! shown"
                                :aria-label="shown ? @js(__('entries.js.hide')) : @js(__('entries.js.show'))"
                                class="absolute inset-y-0 end-0 flex cursor-pointer items-center pe-3 text-gray-400 transition hover:text-gray-600 dark:hover:text-gray-200"
                            >
                                <x-filament::icon icon="heroicon-o-eye" class="h-5 w-5" x-show="! shown" />
                                <x-filament::icon icon="heroicon-o-eye-slash" class="h-5 w-5" x-cloak x-show="shown" />
                            </button>
                        </div>
                        @error('passphrase')<p class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="ssh_notes" class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">{{ __('entries.form.notes.label') }}</label>
                        <x-filament::input.wrapper :valid="! $errors->has('notes')">
                            <textarea id="ssh_notes" name="notes" rows="3" maxlength="{{ \App\Services\EntryPayload::MAX_NOTES }}" class="fi-input block w-full resize-y border-0 bg-transparent p-0 focus:ring-0">{{ old('notes') }}</textarea>
                        </x-filament::input.wrapper>
                        @error('notes')<p class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror
                    </div>
                </div>
            </template>

            {{-- TOTP --}}
            <template x-if="$wire.data.type === 'totp'">
                <div class="space-y-6">
                    <div class="grid gap-6 md:grid-cols-2">
                        <div>
                            <label for="issuer" class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">{{ __('entries.form.issuer.label') }}</label>
                            <x-filament::input.wrapper :valid="! $errors->has('issuer')">
                                <x-filament::input id="issuer" name="issuer" type="text" maxlength="{{ \App\Services\EntryPayload::MAX_TOTP_ISSUER }}" value="{{ old('issuer') }}" />
                            </x-filament::input.wrapper>
                            @error('issuer')<p class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label for="account" class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">{{ __('entries.form.account.label') }}</label>
                            <x-filament::input.wrapper :valid="! $errors->has('account')">
                                <x-filament::input id="account" name="account" type="text" maxlength="{{ \App\Services\EntryPayload::MAX_TOTP_ACCOUNT }}" value="{{ old('account') }}" />
                            </x-filament::input.wrapper>
                            @error('account')<p class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    <div x-data="{ shown: false }">
                        <label for="secret" class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">{{ __('entries.form.secret.label') }} <span class="text-danger-600">*</span></label>
                        <div class="relative">
                            <x-filament::input.wrapper :valid="! $errors->has('secret')" class="pe-10">
                                <x-filament::input id="secret" name="secret" type="password" x-bind:type="shown ? 'text' : 'password'" maxlength="{{ \App\Services\EntryPayload::MAX_TOTP_SECRET }}" autocomplete="off" :required="! $isEdit" />
                            </x-filament::input.wrapper>
                            <button
                                type="button"
                                x-on:click="shown = ! shown"
                                :aria-label="shown ? @js(__('entries.js.hide')) : @js(__('entries.js.show'))"
                                class="absolute inset-y-0 end-0 flex cursor-pointer items-center pe-3 text-gray-400 transition hover:text-gray-600 dark:hover:text-gray-200"
                            >
                                <x-filament::icon icon="heroicon-o-eye" class="h-5 w-5" x-show="! shown" />
                                <x-filament::icon icon="heroicon-o-eye-slash" class="h-5 w-5" x-cloak x-show="shown" />
                            </button>
                        </div>
                        @error('secret')<p class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('entries.form.secret.hint') }}</p>
                    </div>

                    <div>
                        <label for="totp_notes" class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">{{ __('entries.form.notes.label') }}</label>
                        <x-filament::input.wrapper :valid="! $errors->has('notes')">
                            <textarea id="totp_notes" name="notes" rows="3" maxlength="{{ \App\Services\EntryPayload::MAX_NOTES }}" class="fi-input block w-full resize-y border-0 bg-transparent p-0 focus:ring-0">{{ old('notes') }}</textarea>
                        </x-filament::input.wrapper>
                        @error('notes')<p class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror
                    </div>
                </div>
            </template>

            <div
                class="rounded-xl border border-gray-200 p-4 dark:border-white/10"
                x-data="{
                    t: @js([
                        'leave_blank' => __('entries.js.leave_blank'),
                    ]),
                    rows: @js($initialCustomFields),
                    add() {
                        this.rows.push({ uid: 'new-' + Date.now() + '-' + this.rows.length, key: '', label: '', value: '', concealed: true });
                    },
                    remove(index) {
                        this.rows.splice(index, 1);
                    }
                }"
            >
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('entries.form.custom_fields.heading') }}</h3>
                <p class="mb-4 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('entries.form.custom_fields.description') }}
                </p>

                @error('custom_fields')<p class="mb-3 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror

                <div class="space-y-3">
                    <template x-for="(row, index) in rows" :key="row.uid">
                        <div class="grid gap-3 rounded-lg border border-gray-200 p-3 dark:border-white/10 md:grid-cols-[1fr_1fr_auto]">
                            <input type="hidden" :name="`custom_fields[${index}][key]`" :value="row.key">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('entries.form.custom_fields.label') }}</label>
                                <input type="text" x-model="row.label" :name="`custom_fields[${index}][label]`" maxlength="{{ \App\Services\EntryPayload::MAX_CUSTOM_LABEL }}" class="fi-input block w-full rounded border-gray-300 dark:border-white/10 dark:bg-white/5">
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('entries.form.custom_fields.value') }}</label>
                                <input :type="row.concealed ? 'password' : 'text'" x-model="row.value" :name="`custom_fields[${index}][value]`" maxlength="{{ \App\Services\EntryPayload::MAX_CUSTOM_VALUE }}" :placeholder="row.key !== '' ? t.leave_blank : ''" class="fi-input block w-full rounded border-gray-300 dark:border-white/10 dark:bg-white/5">
                            </div>
                            <div class="flex items-end gap-3 pb-1">
                                <label class="flex items-center gap-2 text-sm text-gray-950 dark:text-white">
                                    <input type="checkbox" x-model="row.concealed" :name="`custom_fields[${index}][concealed]`" value="1" class="rounded border-gray-300 dark:border-white/10">
                                    {{ __('entries.form.custom_fields.concealed') }}
                                </label>
                                <x-filament::button type="button" size="sm" color="danger" x-on:click="remove(index)">{{ __('entries.form.custom_fields.remove') }}</x-filament::button>
                            </div>
                        </div>
                    </template>
                </div>

                <div class="mt-3">
                    <x-filament::button type="button" color="gray" icon="heroicon-o-plus" x-on:click="add()">{{ __('entries.form.custom_fields.add') }}</x-filament::button>
                </div>
            </div>

            @if ($isEdit)
                <input type="hidden" name="lock_version" value="{{ $lockVersion }}">
                <p class="text-sm text-warning-600 dark:text-warning-400">
                    {{ __('entries.form.edit_notice') }}
                </p>
            @endif

            <div class="flex flex-wrap items-center gap-3">
                <x-filament::button type="submit">{{ $isEdit ? __('entries.form.save_changes') : __('entries.form.create_entry') }}</x-filament::button>
                <x-filament::button tag="a" color="gray" :href="$isEdit ? \App\Filament\Pages\EntryDetail::getUrl(['entry' => $entry->id]) : \App\Filament\Pages\VaultEntries::getUrl(['vault' => $vault->id])">
                    {{ __('entries.form.cancel') }}
                </x-filament::button>
            </div>
        </form>
    </x-filament::section>
</x-filament-panels::page>
