<?php

declare(strict_types=1);

namespace Rumbo\Backend\Auth;

use Rumbo\Backend\Config;

final class SessionCookieManager
{
    public function __construct(private readonly Config $config)
    {
    }

    public function issue(string $sessionToken, string $csrfToken, int $expiresAt): void
    {
        setcookie($this->config->sessionCookieName(), $sessionToken, $this->sessionOptions($expiresAt));
        setcookie($this->config->csrfCookieName(), $csrfToken, $this->csrfOptions($expiresAt));
    }

    public function clear(): void
    {
        $expired = time() - 3600;
        setcookie($this->config->sessionCookieName(), '', [
            'expires' => $expired,
            'path' => '/api/v1',
            'secure' => $this->config->cookieSecure(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        setcookie($this->config->csrfCookieName(), '', [
            'expires' => $expired,
            'path' => '/',
            'secure' => $this->config->cookieSecure(),
            'httponly' => false,
            'samesite' => 'Strict',
        ]);
    }

    /** @return array{expires: int, path: string, secure: bool, httponly: bool, samesite: string} */
    public function sessionOptions(int $expiresAt): array
    {
        return [
            'expires' => $expiresAt,
            'path' => '/api/v1',
            'secure' => $this->config->cookieSecure(),
            'httponly' => true,
            'samesite' => 'Strict',
        ];
    }

    /** @return array{expires: int, path: string, secure: bool, httponly: bool, samesite: string} */
    public function csrfOptions(int $expiresAt): array
    {
        return [
            'expires' => $expiresAt,
            'path' => '/',
            'secure' => $this->config->cookieSecure(),
            'httponly' => false,
            'samesite' => 'Strict',
        ];
    }
}
