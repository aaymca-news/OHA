<?php

namespace App\Http\Responses;

use App\Demo\Sandboxes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where signing out leads: the front page, as always; in a visitor's own demo, back to
 * "Choose who to be", so they can be someone else (they leave the demo from its banner).
 */
class LogoutResponse implements LogoutResponseContract
{
    /** @param  Request  $request */
    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 204);
        }

        return Sandboxes::current() !== null ? redirect()->route('login') : redirect('/');
    }
}
