<?php

namespace App\Http\Middleware;

use App\Services\LocaleResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function __construct(private LocaleResolver $resolver) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale($this->resolver->resolve($request));

        return $next($request);
    }
}
