<?php

namespace Ahinest\LaravelPermissionManager\Http;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege la entrada HTTP y transforma fallos SQL conocidos sin revelar detalles internos.
 */
class RequireActor
{
    /**
     * Exige un usuario autenticado antes de continuar y oculta detalles de errores SQL.
     *
     * Convierte unicidad, deadlock y espera de lock reconocidos en 409; reporta los demás
     * errores de consulta y responde 500 genérico. La autorización de dominio sigue en servicios.
     *
     * @throws AuthenticationException Si la petición no contiene un usuario.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            throw new AuthenticationException;
        }
        try {
            return $next($request);
        } catch (QueryException $e) {
            if (in_array($e->errorInfo[1] ?? null, [1062, 1205, 1213], true) || ($e->errorInfo[0] ?? '') === '40001') {
                return response()->json(['message' => 'The operation conflicts with the current state. Reload and retry.'], 409);
            }
            report($e);

            return response()->json(['message' => 'The operation could not be completed.'], 500);
        }
    }
}
