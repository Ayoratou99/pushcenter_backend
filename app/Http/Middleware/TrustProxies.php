<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;

/**
 * TLS ends at the reverse proxy (Traefik): X-Forwarded-For / -Proto / -Host /
 * -Port are believed only from the proxies of config/trustedproxy.php
 * (TRUSTED_PROXIES), read on every request, so the cached configuration
 * applies. Registered in bootstrap/app.php in place of Laravel's own.
 */
class TrustProxies extends Middleware
{
    /**
     * "*" trusts the direct peer, a list trusts those addresses (IPs or CIDR
     * ranges, comma separated), an empty value trusts nobody.
     *
     * @return array<int, string>|string|null
     */
    protected function proxies()
    {
        return config('trustedproxy.proxies');
    }
}
