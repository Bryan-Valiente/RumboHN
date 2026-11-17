<?php

declare(strict_types=1);

namespace Rumbo\Backend\Auth;

use Rumbo\Backend\Http\ApiException;

final class AdminAuthorizer
{
    public static function assertAdmin(AuthenticatedUser $user): void
    {
        if (!$user->hasRole('admin')) {
            throw new ApiException(403, 'FORBIDDEN', 'No tenés autorización para esta operación.');
        }
    }
}
