<x-filament-panels::page>
    <x-filament::section
        :heading="__('imports.upload.heading')"
        :description="__('imports.upload.description')"
    >
        @if (count($sources) === 0)
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('imports.upload.no_sources') }}</p>
        @else
            @php($selectedSource = old('source', array_key_first($sources)))
            <form
                method="POST"
                action="{{ route('imports.upload') }}"
                enctype="multipart/form-data"
                class="space-y-4"
                x-data="{ source: @js($selectedSource), details: @js($sourceDetails) }"
            >
                @csrf

                <div>
                    <label for="source" class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">{{ __('imports.fields.source') }}</label>
                    <x-filament::input.wrapper :valid="! $errors->has('source')">
                        <x-filament::input.select id="source" name="source" x-model="source">
                            @foreach ($sources as $value => $label)
                                <option value="{{ $value }}" @selected($selectedSource === $value)>{{ $label }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                    @error('source')<p class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror
                    @foreach ($sourceDetails as $value => $details)
                        @if ($details['hint'] !== null)
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400" x-show="source === @js($value)" @if ($selectedSource !== $value) x-cloak @endif>{{ $details['hint'] }}</p>
                        @endif
                    @endforeach
                </div>

                <div>
                    <label for="file" class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">{{ __('imports.fields.file') }}</label>
                    <x-filament::input.wrapper :valid="! $errors->has('file')">
                        <x-filament::input
                            id="file"
                            name="file"
                            type="file"
                            :accept="$sourceDetails[$selectedSource]['accept'] ?? ''"
                            x-bind:accept="details[source]?.accept ?? ''"
                            required
                        />
                    </x-filament::input.wrapper>
                    @error('file')<p class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror
                </div>

                @foreach ($credentialFields as $field)
                    @php($usedBySelected = in_array($field, $sourceDetails[$selectedSource]['fields'] ?? [], true))
                    @php($usedBySource = '(details[source]?.fields ?? []).includes('.\Illuminate\Support\Js::from($field).')')
                    <div
                        x-show="{{ $usedBySource }}"
                        @unless ($usedBySelected) x-cloak @endunless
                    >
                        <label for="{{ $field }}" class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">{{ __('imports.fields.'.$field) }}</label>
                        <x-filament::input.wrapper :valid="! $errors->has($field)">
                            <x-filament::input
                                id="{{ $field }}"
                                name="{{ $field }}"
                                type="password"
                                autocomplete="off"
                                :required="$usedBySelected"
                                :disabled="! $usedBySelected"
                                :x-bind:required="$usedBySource"
                                :x-bind:disabled="'! '.$usedBySource"
                            />
                        </x-filament::input.wrapper>
                        @error($field)<p class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('imports.upload.password_hint') }}</p>
                    </div>
                @endforeach

                <div class="flex justify-end">
                    <x-filament::button type="submit">{{ __('imports.upload.submit') }}</x-filament::button>
                </div>
            </form>
        @endif
    </x-filament::section>

    <x-filament::section :heading="__('imports.runs.heading')" :description="__('imports.runs.description')">
        {{ $this->getTable() }}
    </x-filament::section>
</x-filament-panels::page>
