<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OperarioController extends Controller
{
    public function miInstalacion(Request $request)
    {
        $sesion = $request->user('api');

        if (!$sesion || !$sesion->id_usuario) {
            return response()->json([
                'success' => false,
                'message' => 'Sesión no válida.'
            ], 401);
        }

        $idUsuario = (int) $sesion->id_usuario;

        $operario = DB::table('usuarios as u')
            ->join('rol as r', 'r.id_rol', '=', 'u.id_rol')
            ->join('operario as o', 'o.id_usuario', '=', 'u.id_usuario')
            ->where('u.id_usuario', $idUsuario)
            ->where('u.estado', 'activo')
            ->where('r.nombre', 'operario')
            ->select(
                'u.id_usuario',
                'u.nombre',
                'u.apellido'
            )
            ->first();

        if (!$operario) {
            return response()->json([
                'success' => false,
                'message' => 'Acceso exclusivo para operarios activos.'
            ], 403);
        }

        $instalacion = DB::table('instalacion_operario as io')
            ->join(
                'instalaciones as i',
                'i.id_instalacion',
                '=',
                'io.id_instalacion'
            )
            ->leftJoin(
                'ubicacion as ub',
                'ub.id_ubicacion',
                '=',
                'i.id_ubicacion'
            )
            ->leftJoin(
                'instalacion_tipo_residuo as tr',
                'tr.id_instalacion',
                '=',
                'i.id_instalacion'
            )
            ->where('io.id_usuario', $idUsuario)
            ->whereNull('io.fecha_finalizacion')
            ->select(
                'i.id_instalacion',
                'i.nombre',
                'i.tipo',
                'i.estado',
                'i.capacidad',
                'i.capacidad_actual',
                'i.unidad_capacidad',
                'ub.calle',
                'ub.esquina_1',
                'ub.esquina_2',
                'tr.tipo as tipo_residuo',
                'io.fecha_asignacion'
            )
            ->orderByDesc('io.fecha_asignacion')
            ->first();

        if (!$instalacion) {
            return response()->json([
                'success' => true,
                'data' => [
                    'usuario' => $operario,
                    'instalacion' => null,
                    'insumos' => [],
                    'inventario_disponible' => false
                ]
            ]);
        }

        $esDeposito = $instalacion->tipo === 'Depósito';

        $insumos = $esDeposito
            ? DB::table('inventario')
                ->where(
                    'id_instalacion',
                    $instalacion->id_instalacion
                )
                ->select(
                    'id_inventario',
                    'nombre_item',
                    'tipo',
                    'estado',
                    'cantidad'
                )
                ->orderBy('nombre_item')
                ->get()
            : collect();

        $capacidad = (float) $instalacion->capacidad_actual;

        $capacidadEstado = $capacidad >= 100
            ? 'Lleno'
            : ($capacidad >= 85 ? 'Casi lleno' : 'Disponible');

        return response()->json([
            'success' => true,
            'data' => [
                'usuario' => $operario,
                'instalacion' => [
                    'id_instalacion' => $instalacion->id_instalacion,
                    'nombre' => $instalacion->nombre,
                    'tipo' => $instalacion->tipo,
                    'estado' => $instalacion->estado,
                    'tipo_residuo' => $instalacion->tipo_residuo,
                    'calle' => $instalacion->calle,
                    'esquina_1' => $instalacion->esquina_1,
                    'esquina_2' => $instalacion->esquina_2,
                    'capacidad' => (float) $instalacion->capacidad,
                    'capacidad_actual' => $capacidad,
                    'capacidad_estado' => $capacidadEstado,
                    'unidad_capacidad' => $instalacion->unidad_capacidad,
                    'fecha_asignacion' => $instalacion->fecha_asignacion
                ],
                'insumos' => $insumos,
                'inventario_disponible' => $esDeposito
            ]
        ]);
    }
}