<?php

namespace App\Http\Middleware;

use App\Http\Middleware\Traits\TelegramUserHandler;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TelegramInitUser
{
    use TelegramUserHandler;

    public function handle(Request $request, Closure $next): Response
    {
        return $this->handleRequest($request, $next);
    }
}
