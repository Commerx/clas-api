<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * The authenticated caller, as resolved by the auth.token middleware.
     *
     * Every endpoint behind that middleware has one, so there is nothing to
     * check here. Note what this deliberately does not do: it never reads
     * `uid` from the request. The old API took the caller's identity from
     * that parameter, which is how three of its write endpoints came to
     * accept an arbitrary user id.
     */
    protected function caller(Request $request): User
    {
        return $request->attributes->get('api_user');
    }

    protected function userId(Request $request): int
    {
        return (int) $this->caller($request)->id;
    }
}
