@extends('mail.html.layout')

@section('preheader', __('mail.invitation.intro'))
@section('kicker', __('mail.invitation.kicker'))
@section('title', __('mail.invitation.title'))

@section('content')
    <p style="margin:0;font-family:inherit;font-size:15px;line-height:1.65;color:#ECF2EA;">{{ __('mail.invitation.intro') }}</p>
@endsection

@section('action')
    <a href="{{ route('invitation.show', ['token' => $token]) }}" style="display:inline-block;padding:14px 26px;font-family:ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;font-size:15px;font-weight:600;line-height:1.2;color:#0B0E0B;text-decoration:none;border-radius:16px;">{{ __('mail.invitation.accept') }}</a>
@endsection

@section('note', __('mail.invitation.ignore'))
