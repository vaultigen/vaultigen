@extends('mail.html.layout')

@section('preheader', __('mail.factors_reset.body'))
@section('kicker', __('mail.factors_reset.kicker'))
@section('title', __('mail.factors_reset.title'))

@section('content')
    <p style="margin:0;font-family:inherit;font-size:15px;line-height:1.65;color:#ECF2EA;">{{ __('mail.factors_reset.body') }}</p>
@endsection

@section('meta', __('mail.factors_reset.footer', ['sequence' => $auditSequence]))
