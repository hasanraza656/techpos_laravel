<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $nonce = base64_encode(random_bytes(24));
        $request->attributes->set('csp_nonce', $nonce);
        Vite::useCspNonce($nonce);
        $response = $next($request);
        $dev = app()->environment('local', 'testing');
        $scripts = "'self' 'nonce-$nonce'".($dev ? ' http://127.0.0.1:5173 http://localhost:5173' : '');
        $connect = "'self'".($dev ? ' ws://127.0.0.1:5173 ws://localhost:5173' : '');
        $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src $scripts; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; connect-src $connect; worker-src 'self' blob:; frame-src 'self'; frame-ancestors 'self'; object-src 'none'; base-uri 'self'; form-action 'self'");
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(), payment=()');
        $response->headers->remove('X-Powered-By');
        if ($request->is('api/*') || $request->is('payment-upload/*')) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }
        if (app()->environment('production') && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
