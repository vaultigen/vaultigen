@forelse ($fields as $field)
    <div>
        <label for="{{ $field }}" class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">{{ __('imports.fields.'.$field) }}</label>
        <x-filament::input.wrapper :valid="! $errors->has($field)">
            <x-filament::input
                id="{{ $field }}"
                name="{{ $field }}"
                type="password"
                autocomplete="off"
                form="execute-import-form"
            />
        </x-filament::input.wrapper>
        @error($field)<p class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('imports.confirm.hints.'.$field) }}</p>
    </div>
@empty
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('imports.confirm.no_credentials') }}</p>
@endforelse
