<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    |
    | TLS ends at the reverse proxy (Traefik), which forwards plain HTTP with
    | X-Forwarded-For / -Proto / -Host / -Port. Laravel only reads those headers
    | from the proxies listed here; otherwise every request looks like HTTP from
    | the proxy's address: URLs come out as http:// (the Swagger page then loads
    | its assets as blocked mixed content) and $request->ip() is the proxy's IP
    | for everyone (login and token throttles, IP stored with each session).
    |
    | '*' trusts the direct peer, whatever its address: fine as long as the API
    | is only reachable through the reverse proxy. Otherwise give the proxy
    | addresses, comma separated (IPs or CIDR), e.g. TRUSTED_PROXIES=10.0.1.2
    | or TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12. Empty trusts nobody.
    |
    | Read on every request by App\Http\Middleware\TrustProxies, which
    | bootstrap/app.php puts in place of Laravel's own.
    |
    */

    'proxies' => env('TRUSTED_PROXIES', '*'),

];
