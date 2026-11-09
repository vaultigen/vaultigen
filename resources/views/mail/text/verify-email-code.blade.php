{{ __('mail.verify_email_code.title') }}

{{ __('mail.verify_email_code.body') }}

{{ __('mail.verify_email_code.code_label') }}: {{ $code }}

{{ trans_choice('mail.verify_email_code.expires', $expiresInMinutes, ['minutes' => $expiresInMinutes]) }}

{{ __('mail.verify_email_code.note') }}

{{ __('mail.layout.footer', ['app' => $brandName]) }}
