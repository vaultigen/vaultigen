@extends('mail.html.layout')

@section('preheader', __('mail.admin_recovered.body'))
@section('kicker', __('mail.admin_recovered.kicker'))
@section('title', __('mail.admin_recovered.title'))

@section('content')
    <p style="margin:0;font-family:inherit;font-size:15px;line-height:1.65;color:#ECF2EA;">{{ __('mail.admin_recovered.body') }}</p>
@endsection

@section('meta', __('mail.admin_recovered.footer', ['sequence' => $auditSequence]))
