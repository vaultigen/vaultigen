<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ __('invitation.title') }}</title></head>
<body><main><h1>{{ __('invitation.heading') }}</h1>
<p>{{ __('invitation.description') }}</p>
<p>{{ __('invitation.security_notice') }}</p>
@foreach ($errors->all() as $error)<p role="alert">{{ $error }}</p>@endforeach
<form method="POST" action="{{ route('invitation.store') }}">
@csrf
<input type="hidden" name="token" value="{{ $token }}">
<p><label>{{ __('invitation.fields.name') }} <input name="name" required maxlength="255" autocomplete="name"></label></p>
<p><label>{{ __('invitation.fields.email') }} <input name="email" type="email" required autocomplete="email"></label></p>
<p><label>{{ __('invitation.fields.password') }} <input name="password" type="password" required minlength="15" autocomplete="new-password"></label></p>
<p><label>{{ __('invitation.fields.password_confirmation') }} <input name="password_confirmation" type="password" required autocomplete="new-password"></label></p>
<p><label><input type="checkbox" name="policy" value="1" required> {{ __('invitation.policy') }}</label></p>
<button type="submit">{{ __('invitation.submit') }}</button>
</form></main></body></html>
