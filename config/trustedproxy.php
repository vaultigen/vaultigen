<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | When the application runs behind a reverse proxy that terminates TLS, the
    | framework must be told which proxy addresses to trust so it can read the
    | forwarded protocol, host, and client IP. The value is a comma-separated
    | list of IP addresses or CIDR ranges, or "*" to trust every proxy. It is
    | empty by default, which keeps the safe behavior of ignoring proxy headers.
    | Never trust a proxy you do not control. See https://vaultigen.com/docs/hardening.
    |
    */

    'proxies' => env('VAULTIGEN_TRUSTED_PROXIES'),

];
