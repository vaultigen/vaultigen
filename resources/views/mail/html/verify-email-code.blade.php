@extends('mail.html.layout')

@section('preheader', __('mail.verify_email_code.body'))
@section('kicker', __('mail.verify_email_code.kicker'))
@section('title', __('mail.verify_email_code.title'))

@section('content')
    <p style="margin:0;font-family:inherit;font-size:15px;line-height:1.65;color:#ECF2EA;">{{ __('mail.verify_email_code.body') }}</p>
    <div style="margin-top:20px;padding:18px 20px;background-color:#232B24;border:1px solid #2B332C;border-radius:16px;text-align:center;">
        <div style="font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, 'Liberation Mono', 'Courier New', monospace;font-size:11px;font-weight:600;letter-spacing:0.18em;text-transform:uppercase;color:#97A595;">{{ __('mail.verify_email_code.code_label') }}</div>
        <div style="margin-top:10px;font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, 'Liberation Mono', 'Courier New', monospace;font-size:32px;font-weight:700;letter-spacing:0.28em;color:#ECF2EA;">{{ $code }}</div>
    </div>
    <p style="margin:18px 0 0 0;font-family:inherit;font-size:13px;line-height:1.6;color:#97A595;">{{ trans_choice('mail.verify_email_code.expires', $expiresInMinutes, ['minutes' => $expiresInMinutes]) }}</p>
@endsection

@section('note', __('mail.verify_email_code.note'))
