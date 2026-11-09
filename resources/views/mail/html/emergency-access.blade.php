@extends('mail.html.layout')

@section('preheader', __('mail.emergency_access.body'))
@section('kicker', __('mail.emergency_access.kicker'))
@section('title', __('mail.emergency_access.title'))

@section('content')
    <p style="margin:0;font-family:inherit;font-size:15px;line-height:1.65;color:#ECF2EA;">{{ __('mail.emergency_access.body') }}</p>
@endsection

@section('meta', __('mail.emergency_access.footer', ['sequence' => $auditSequence]))
