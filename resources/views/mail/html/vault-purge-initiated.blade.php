@extends('mail.html.layout')

@section('preheader', __('mail.vault_purge_initiated.body'))
@section('kicker', __('mail.vault_purge_initiated.kicker'))
@section('title', __('mail.vault_purge_initiated.title'))

@section('content')
    <p style="margin:0;font-family:inherit;font-size:15px;line-height:1.65;color:#ECF2EA;">{{ __('mail.vault_purge_initiated.body') }}</p>
@endsection

@section('meta', __('mail.vault_purge_initiated.footer', ['sequence' => $auditSequence]))
