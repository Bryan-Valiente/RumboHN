<?php

declare(strict_types=1);

namespace Rumbo\Backend\Auth;

use Rumbo\Backend\Config;
use Rumbo\Backend\Http\ApiException;
use Rumbo\Backend\Http\Request;

final class CsrfGuard
{
    public function __construct(private readonly Config $config)
    {
    }

    public function assertValid(Request $request, AuthenticatedUser $user): void
    {
        if (!$request->isStateChanging() || $user->credentialType !== 'cookie') {
            return;
        }

        $header = trim((string) $request->header('x-csrf-token'));
        $cookie = trim((string) $request->cookie($this->config->csrfCookieName()));
        if ($header === '' || $cookie === '' || $user->csrfToken === null
            || !hash_equals($cookie, $header)
            || !hash_equals($user->csrfToken, $header)) {
            throw new ApiException(403, 'CSRF_INVALID', 'La verificación de la solicitud no es válida.');
        }
    }
}
