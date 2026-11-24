<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * @var array<int, string>|string|null
     */
    protected $proxies;

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;

    /**
     * Trusted proxies come from TRUSTED_PROXIES (comma-separated IPs/CIDRs, or "*" only when
     * the app is reachable solely through the load balancer). Unset keeps the safe default of
     * trusting no forwarded headers; behind a proxy that would collapse every client onto the
     * proxy IP and let one attacker exhaust the shared login/registration rate limits.
     */
    protected function proxies()
    {
        $configured = trim((string) config('app.trusted_proxies'));
        if ($configured === '') {
            return $this->proxies;
        }

        return $configured === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $configured))));
    }
}
