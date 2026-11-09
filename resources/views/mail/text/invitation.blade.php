{{ __('mail.invitation.title') }}

{{ __('mail.invitation.intro') }}

{{ __('mail.invitation.accept') }}:
{{ route('invitation.show', ['token' => $token]) }}

{{ __('mail.invitation.ignore') }}

{{ __('mail.layout.footer', ['app' => $brandName]) }}
