<?php

declare(strict_types=1);

namespace Rumbo\Backend\Http;

use Rumbo\Backend\Config;

final class OriginGuard
{
    public static function assertAllowed(Request $request, Config $config): void
    {
        $origin = $request->header('origin');
        if ($origin === null) {
            return;
        }

        $allowed = $config->get('RUMBO_ALLOWED_ORIGIN');
        if ($allowed === null || !hash_equals(rtrim($allowed, '/'), rtrim($origin, '/'))) {
            throw new ApiException(403, 'FORBIDDEN', 'El origen de la solicitud no está autorizado.');
        }
    }
}
