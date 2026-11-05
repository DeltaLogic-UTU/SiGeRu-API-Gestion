<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(
        Request $request,
        Closure $next,
        string ...$roles
    ): Response {
        $dato = $request->user('api');

        if (!$dato) {
            return response()->json([
                'success' => false,
                'message' => 'No autenticado.'
            ], 401);
        }

        $dato->loadMissing('usuario.rol');

        $usuario = $dato->usuario;

        $rol = strtolower(
            trim((string) ($usuario?->rol?->nombre ?? ''))
        );

        $permitidos = array_map(
            fn (string $r) => strtolower(trim($r)),
            $roles
        );

        if (
            !$usuario ||
            $usuario->estado !== 'activo' ||
            !in_array($rol, $permitidos, true)
        ) {
            return response()->json([
                'success' => false,
                'message' => 'No tenés permisos para esta acción.'
            ], 403);
        }

        return $next($request);
    }
}
