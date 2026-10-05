<?php

declare(strict_types=1);

namespace Fixwire\Laravel;

use Fixwire\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as Auth;

/** @internal the signed-in user, as Fixwire sends it: the id, and the email with send_default_pii */
final class Users
{
    public static function of(Authenticatable $user, bool $pii): User
    {
        $email = $pii && isset($user->email) && \is_string($user->email) ? $user->email : null;

        return new User((string) $user->getAuthIdentifier(), $email);
    }

    /** The default guard's user, if it found one already: asking never makes it look the user up. */
    public static function signedIn(Auth $auth, bool $pii): ?User
    {
        $guard = $auth->guard();
        if (!$guard->hasUser()) {
            return null;
        }
        $user = $guard->user();

        return $user === null ? null : self::of($user, $pii);
    }
}
