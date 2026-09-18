<?php

namespace App\Auth;

use App\Services\JwtService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Traits\Macroable;

/**
 * Stateless guard: reads the Bearer token, validates the signature and loads the
 * matching local user.
 */
class JwtGuard implements Guard
{
    use Macroable;

    protected ?Authenticatable $user = null;

    protected bool $resolved = false;

    /**
     * @var array<string, mixed>|null
     */
    protected ?array $claims = null;

    public function __construct(
        protected UserProvider $provider,
        protected Request $request,
        protected JwtService $jwt,
    ) {
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return ! $this->check();
    }

    public function user(): ?Authenticatable
    {
        if ($this->resolved) {
            return $this->user;
        }

        $this->resolved = true;

        $token = $this->request->bearerToken();

        if (! $token) {
            return null;
        }

        $claims = $this->jwt->decode($token);

        if (! $claims || ! isset($claims['sub'])) {
            return null;
        }

        // An application token's `sub` is a business id, not a user id. Without
        // this check it would resolve to whichever user happens to share that id
        // and hand an application the whole management API.
        if (($claims['token_type'] ?? JwtService::TYPE_USER) !== JwtService::TYPE_USER) {
            return null;
        }

        $user = $this->provider->retrieveById($claims['sub']);

        // A user deactivated after the token was issued must lose access at once.
        if (! $user || ($user->is_active ?? true) === false) {
            return null;
        }

        $this->claims = $claims;

        return $this->user = $user;
    }

    public function id(): int|string|null
    {
        return $this->user()?->getAuthIdentifier();
    }

    /**
     * Claims carried by the current access token.
     *
     * @return array<string, mixed>|null
     */
    public function claims(): ?array
    {
        $this->user();

        return $this->claims;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function validate(array $credentials = []): bool
    {
        if (empty($credentials)) {
            return false;
        }

        $user = $this->provider->retrieveByCredentials($credentials);

        return $user !== null && $this->provider->validateCredentials($user, $credentials);
    }

    public function hasUser(): bool
    {
        return $this->user !== null;
    }

    public function setUser(Authenticatable $user): static
    {
        $this->user = $user;
        $this->resolved = true;

        return $this;
    }

    public function setRequest(Request $request): static
    {
        $this->request = $request;
        $this->resolved = false;
        $this->user = null;

        return $this;
    }
}
