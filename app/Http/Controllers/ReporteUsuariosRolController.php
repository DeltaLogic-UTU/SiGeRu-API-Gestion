<?php

namespace App\Http\Controllers;

use App\Models\Rol;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ReporteUsuariosRolController extends Controller
{
    public function orm(): JsonResponse
    {
        $datos = Rol::query()
            ->select(['id_rol', 'nombre'])
            ->withCount('usuarios')
            ->orderBy('id_rol')
            ->get()
            ->map(fn ($rol) => [
                'id' => (int) $rol->id_rol,
                'name' => $rol->nombre,
                'users_count' => (int) $rol->usuarios_count
            ]);

        return response()->json([
            'success' => true,
            'data' => $datos
        ]);
    }

    public function sql(): JsonResponse
    {
        $filas = DB::select(
            'SELECT
                r.id_rol AS id,
                r.nombre AS name,
                COUNT(u.id_usuario) AS users_count
            FROM rol AS r
            LEFT JOIN usuarios AS u
                ON u.id_rol = r.id_rol
            GROUP BY r.id_rol, r.nombre
            ORDER BY r.id_rol'
        );

        $datos = collect($filas)->map(fn ($fila) => [
            'id' => (int) $fila->id,
            'name' => $fila->name,
            'users_count' => (int) $fila->users_count
        ]);

        return response()->json([
            'success' => true,
            'data' => $datos
        ]);
    }
}
