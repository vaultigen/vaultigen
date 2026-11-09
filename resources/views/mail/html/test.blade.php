@extends('mail.html.layout')

@section('preheader', __('mail.test.body'))
@section('kicker', __('mail.test.kicker'))
@section('title', __('mail.test.title'))

@section('content')
    <p style="margin:0;font-family:inherit;font-size:15px;line-height:1.65;color:#ECF2EA;">{{ __('mail.test.body') }}</p>
@endsection

@section('meta', __('mail.test.footer'))
