@php
    $brandName = $brandName ?? config('app.name', 'Vaultigen');
    $fontStack = "ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
    $monoStack = "ui-monospace, SFMono-Regular, Menlo, Consolas, 'Liberation Mono', 'Courier New', monospace";
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="dark light">
    <meta name="supported-color-schemes" content="dark light">
    <title>@yield('title')</title>
    {{-- The brand is dark-first, so the mail ships dark and asks clients not
         to auto-invert it. Layout uses tables + inline styles because email
         clients ignore stylesheets and modern CSS. --}}
    <style>
        :root { color-scheme: dark light; }
        body { margin: 0; padding: 0; width: 100% !important; background-color: #0B0E0B; }
        table { border-collapse: collapse; }
        img { border: 0; outline: none; text-decoration: none; -ms-interpolation-mode: bicubic; }
        a { color: #A3E635; }
        @media only screen and (max-width: 600px) {
            .vault-shell { padding: 24px 12px !important; }
            .vault-container { width: 100% !important; max-width: 100% !important; }
            .vault-card { padding: 28px 22px !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background-color:#0B0E0B;color:#ECF2EA;">
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;">@yield('preheader')&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#0B0E0B" style="width:100%;background-color:#0B0E0B;">
    <tr>
        <td class="vault-shell" align="center" style="padding:36px 16px;">
            {{-- "separate", unlike the global "collapse": collapsed borders ignore border-radius, so the card's border would keep square corners. --}}
            <table role="presentation" class="vault-container" width="560" cellpadding="0" cellspacing="0" border="0" style="width:560px;max-width:560px;border-collapse:separate;border-spacing:0;">
                {{-- Brand lockup: the same vault-wheel mark and wordmark as the panel. --}}
                <tr>
                    <td style="padding:0 8px 22px 8px;">
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td valign="middle" width="48">
                                    <img src="cid:vaultigen-icon.png" width="48" height="48" alt="" style="display:block;width:48px;height:48px;border-radius:16px;">
                                </td>
                                <td valign="middle" style="padding-left:12px;font-family:{{ $fontStack }};font-size:17px;line-height:1.2;font-weight:600;letter-spacing:-0.01em;color:#ECF2EA;">
                                    {{ $brandName }}
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td class="vault-card" bgcolor="#161B15" style="background-color:#161B15;border:1px solid #2B332C;border-radius:20px;padding:36px;">
                        @hasSection('kicker')
                            <div style="font-family:{{ $monoStack }};font-size:11px;line-height:1.2;font-weight:600;letter-spacing:0.22em;text-transform:uppercase;color:#A3E635;">@yield('kicker')</div>
                        @endif

                        <h1 style="margin:14px 0 0 0;font-family:{{ $fontStack }};font-size:24px;line-height:1.3;font-weight:700;letter-spacing:-0.02em;color:#ECF2EA;">@yield('title')</h1>

                        <div style="margin-top:16px;font-family:{{ $fontStack }};font-size:15px;line-height:1.65;color:#ECF2EA;">
                            @yield('content')
                        </div>

                        @hasSection('action')
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top:28px;">
                                <tr>
                                    <td align="center" bgcolor="#A3E635" style="background-color:#A3E635;border-radius:16px;">
                                        @yield('action')
                                    </td>
                                </tr>
                            </table>
                        @endif

                        @hasSection('note')
                            <p style="margin:20px 0 0 0;font-family:{{ $fontStack }};font-size:13px;line-height:1.6;color:#97A595;">@yield('note')</p>
                        @endif

                        @hasSection('meta')
                            <div style="margin-top:30px;padding-top:18px;border-top:1px solid #2B332C;font-family:{{ $monoStack }};font-size:12px;line-height:1.6;color:#97A595;">@yield('meta')</div>
                        @endif
                    </td>
                </tr>

                <tr>
                    <td style="padding:20px 8px 0 8px;font-family:{{ $fontStack }};font-size:12px;line-height:1.6;color:#97A595;">
                        {{ __('mail.layout.footer', ['app' => $brandName]) }}
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
