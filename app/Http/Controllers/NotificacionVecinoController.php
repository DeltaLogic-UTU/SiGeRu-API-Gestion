<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificacionVecinoController extends Controller
{
    public function eliminar(int $id)
    {
    $idUsuario = $this->vecinoActual();

    $eliminadas = DB::table('notificaciones_vecino')
        ->where('id_usuario', $idUsuario)
        ->where('id_notificacion', $id)
        ->delete();

    if (!$eliminadas) {
        return response()->json([
            'success' => false,
            'message' => 'Notificación no encontrada.'
        ], 404);
    }

    return response()->json([
        'success' => true,
        'message' => 'Notificación eliminada correctamente.'
    ]);
    }
    
    private function vecinoActual(): int
    {
        $dato = auth('api')->user();

        if (!$dato) {
            abort(401, 'Sesión no válida.');
        }

        $rol = strtolower(trim(
            (string) ($dato->usuario?->rol?->nombre ?? '')
        ));

        if ($rol !== 'vecino') {
            abort(
                403,
                'Solo los vecinos pueden consultar estas notificaciones.'
            );
        }

        return (int) $dato->id_usuario;
    }

    public function index(Request $request)
    {
        $idUsuario = $this->vecinoActual();

        $notificaciones = DB::table('notificaciones_vecino')
            ->where('id_usuario', $idUsuario)
            ->orderByDesc('id_notificacion')
            ->limit(40)
            ->get([
                'id_notificacion',
                'id_reclamo',
                'tipo',
                'estado_anterior',
                'estado_nuevo',
                'fecha',
                'leida_at',
            ]);

        $sinLeer = DB::table('notificaciones_vecino')
            ->where('id_usuario', $idUsuario)
            ->whereNull('leida_at')
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'notificaciones' => $notificaciones,
                'sin_leer' => $sinLeer,
            ],
        ]);
    }

    public function marcarLeidas(Request $request)
    {
        $idUsuario = $this->vecinoActual();

        $actualizadas = DB::table('notificaciones_vecino')
            ->where('id_usuario', $idUsuario)
            ->whereNull('leida_at')
            ->update([
                'leida_at' => now()
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Notificaciones marcadas como leídas.',
            'data' => [
                'actualizadas' => $actualizadas
            ],
        ]);
    }
}
