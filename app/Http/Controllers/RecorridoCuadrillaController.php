<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RecorridoCuadrillaController extends Controller
{
    private function misCuadrillas(Request $request): array
    {
        $id = (int) ($request->user('api')?->id_usuario ?? 0);

        if (!$id) {
            abort(401, 'Sesión inválida.');
        }

        $permitido = DB::table('usuarios as u')
            ->join('rol as r', 'r.id_rol', '=', 'u.id_rol')
            ->where('u.id_usuario', $id)
            ->where('u.estado', 'activo')
            ->whereIn('r.nombre', ['cuadrilla', 'barrendero'])
            ->exists();

        if (!$permitido) {
            abort(403, 'No tenés permisos de cuadrilla.');
        }

        $integrante = DB::table('cuadrilla_integrantes')
            ->where('id_usuario', $id)
            ->whereNull('fecha_finalizacion')
            ->pluck('id_cuadrilla');

        $responsable = DB::table('cuadrilla')
            ->where('id_responsable', $id)
            ->pluck('id_cuadrilla');

        return $integrante->merge($responsable)
            ->unique()->values()->all();
    }

    private function buscarRuta(
        int $id,
        array $cuadrillas,
        bool $bloquear = false
    ) {
        $consulta = DB::table('cuadrilla_rutas as cr')
            ->join('rutas as r', 'r.id_ruta', '=', 'cr.id_ruta')
            ->where('cr.id_asignacion', $id)
            ->whereIn('cr.id_cuadrilla', $cuadrillas)
            ->select(
                'cr.*',
                'r.nombre',
                'r.circuito',
                'r.municipio'
            );

        return ($bloquear
            ? $consulta->lockForUpdate()
            : $consulta)->first();
    }

    private function resumen($ruta): array
    {
        $contenedores = DB::table('cuadrilla_ruta_contenedores as rc')
            ->join(
                'contenedores as c',
                'c.id_contenedor',
                '=',
                'rc.id_contenedor'
            )
            ->leftJoin(
                'ubicacion as u',
                'u.id_ubicacion',
                '=',
                'c.id_ubicacion'
            )
            ->where('rc.id_asignacion', $ruta->id_asignacion)
            ->select(
                'rc.id_contenedor',
                'rc.estado as estado_recorrido',
                'rc.fecha_recoleccion',
                'c.codigo',
                'c.circuito',
                'c.dias_sin_recoleccion',
                'u.calle',
                'u.barrio',
                'u.latitud',
                'u.longitud'
            )
            ->orderBy('c.codigo')
            ->get();

        $recolectados = $contenedores
            ->where('estado_recorrido', 'Recolectado')
            ->count();

        return [
            'id_asignacion' => (int) $ruta->id_asignacion,
            'id_cuadrilla' => $ruta->id_cuadrilla,
            'id_ruta' => (int) $ruta->id_ruta,
            'nombre' => $ruta->nombre,
            'circuito' => $ruta->circuito,
            'municipio' => $ruta->municipio,
            'estado' => $ruta->estado,
            'fecha_inicio' => $ruta->fecha_inicio,
            'fecha_finalizacion' => $ruta->fecha_finalizacion,
            'total' => $contenedores->count(),
            'recolectados' => $recolectados,
            'pendientes' => $contenedores->count() - $recolectados,
            'contenedores' => $contenedores->values()
        ];
    }

    public function index(Request $request)
    {
        $rutas = DB::table('cuadrilla_rutas as cr')
            ->join('rutas as r', 'r.id_ruta', '=', 'cr.id_ruta')
            ->whereIn(
                'cr.id_cuadrilla',
                $this->misCuadrillas($request)
            )
            ->whereNull('cr.fecha_finalizacion')
            ->whereIn('cr.estado', ['Asignada', 'En curso'])
            ->select(
                'cr.id_asignacion',
                'cr.id_cuadrilla',
                'cr.id_ruta',
                'cr.estado',
                'cr.fecha_inicio',
                'r.nombre',
                'r.circuito'
            )
            ->orderByDesc('cr.fecha_asignacion')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $rutas
        ]);
    }

    public function show(Request $request, int $id)
    {
        $ruta = $this->buscarRuta(
            $id,
            $this->misCuadrillas($request)
        );

        if (!$ruta) {
            abort(404, 'Ruta no asignada a tu cuadrilla.');
        }

        return response()->json([
            'success' => true,
            'data' => $this->resumen($ruta)
        ]);
    }

    public function iniciar(Request $request, int $id)
    {
        $cuadrillas = $this->misCuadrillas($request);

        $resultado = DB::transaction(function () use (
            $id,
            $cuadrillas
        ) {
            $ruta = $this->buscarRuta(
                $id,
                $cuadrillas,
                true
            );

            if (!$ruta) {
                return [
                    'error' => 'Ruta no asignada.',
                    'status' => 404
                ];
            }

            if (
                $ruta->estado === 'En curso' &&
                !$ruta->fecha_finalizacion
            ) {
                return [
                    'message' => 'Este recorrido ya está iniciado.'
                ];
            }

            if (
                $ruta->estado !== 'Asignada' ||
                $ruta->fecha_finalizacion
            ) {
                return [
                    'error' => 'Esta ruta no se puede iniciar.',
                    'status' => 409
                ];
            }

            $codigos = array_values(array_filter(
                array_map(
                    'trim',
                    preg_split('/[,;|]/', (string) $ruta->circuito)
                )
            ));

            if (!$codigos) {
                return [
                    'error' => 'La ruta no tiene circuito.',
                    'status' => 422
                ];
            }

            $contenedores = DB::table('contenedores')
                ->whereIn('circuito', $codigos)
                ->where('activo', 1)
                ->pluck('id_contenedor');

            if ($contenedores->isEmpty()) {
                return [
                    'error' => 'El circuito no tiene contenedores activos.',
                    'status' => 422
                ];
            }

            foreach ($contenedores->chunk(200) as $grupo) {
                DB::table('cuadrilla_ruta_contenedores')
                    ->insertOrIgnore(
                        $grupo->map(fn ($contenedor) => [
                            'id_asignacion' => $id,
                            'id_contenedor' => (int) $contenedor,
                            'estado' => 'Pendiente'
                        ])->all()
                    );
            }

            DB::table('cuadrilla_rutas')
                ->where('id_asignacion', $id)
                ->update([
                    'estado' => 'En curso',
                    'fecha_inicio' => now()
                ]);

            return [
                'message' => 'Recorrido iniciado.'
            ];
        }, 3);

        if (isset($resultado['error'])) {
            return response()->json([
                'success' => false,
                'message' => $resultado['error']
            ], $resultado['status']);
        }

        return response()->json([
            'success' => true,
            'message' => $resultado['message']
        ]);
    }

    public function recolectar(
        Request $request,
        int $id,
        int $idContenedor
    ) {
        $cuadrillas = $this->misCuadrillas($request);

        $resultado = DB::transaction(function () use (
            $request,
            $cuadrillas,
            $id,
            $idContenedor
        ) {
            $ruta = $this->buscarRuta(
                $id,
                $cuadrillas,
                true
            );

            if (
                !$ruta ||
                $ruta->estado !== 'En curso' ||
                $ruta->fecha_finalizacion
            ) {
                return [
                    'error' => 'La ruta no está en curso.',
                    'status' => 409
                ];
            }

            $registro = DB::table('cuadrilla_ruta_contenedores')
                ->where('id_asignacion', $id)
                ->where('id_contenedor', $idContenedor)
                ->lockForUpdate()
                ->first();

            if (!$registro) {
                return [
                    'error' => 'El contenedor no pertenece a esta ruta.',
                    'status' => 404
                ];
            }

            if ($registro->estado === 'Recolectado') {
                return [
                    'message' => 'Este contenedor ya estaba recolectado.'
                ];
            }

            $contenedor = DB::table('contenedores')
                ->where('id_contenedor', $idContenedor)
                ->lockForUpdate()
                ->first();

            if (!$contenedor || !(int) $contenedor->activo) {
                return [
                    'error' => 'El contenedor no está activo.',
                    'status' => 422
                ];
            }

            DB::table('cuadrilla_ruta_contenedores')
                ->where('id_registro', $registro->id_registro)
                ->update([
                    'estado' => 'Recolectado',
                    'fecha_recoleccion' => now(),
                    'id_usuario' => (int) $request
                        ->user('api')->id_usuario
                ]);

            DB::table('contenedores')
                ->where('id_contenedor', $idContenedor)
                ->update([
                    'ultimo_registro' => now()->toDateString(),
                    'dias_sin_recoleccion' => 0
                ]);

            if (Schema::hasTable('historial_contenedores')) {
                DB::table('historial_contenedores')->insert([
                    'id_contenedor' => $idContenedor,
                    'fecha' => now(),
                    'estado_anterior' => $contenedor->estado,
                    'estado_nuevo' => $contenedor->estado,
                    'id_ubicacion_anterior' => null,
                    'id_ubicacion_nueva' => null,
                    'motivo' =>
                        'Recolectado por cuadrilla ' .
                        $ruta->id_cuadrilla .
                        ' en ruta ' . $ruta->id_ruta .
                        ' (asignación ' . $id . ')'
                ]);
            }

            return [
                'message' => 'Contenedor recolectado.'
            ];
        }, 3);

        if (isset($resultado['error'])) {
            return response()->json([
                'success' => false,
                'message' => $resultado['error']
            ], $resultado['status']);
        }

        return response()->json([
            'success' => true,
            'message' => $resultado['message']
        ]);
    }

    public function finalizar(Request $request, int $id)
    {
        $cuadrillas = $this->misCuadrillas($request);

        $resultado = DB::transaction(function () use (
            $id,
            $cuadrillas
        ) {
            $ruta = $this->buscarRuta(
                $id,
                $cuadrillas,
                true
            );

            if (
                !$ruta ||
                $ruta->estado !== 'En curso' ||
                $ruta->fecha_finalizacion
            ) {
                return [
                    'error' => 'La ruta no está en curso.',
                    'status' => 409
                ];
            }

            $total = DB::table('cuadrilla_ruta_contenedores')
                ->where('id_asignacion', $id)
                ->count();

            if (!$total) {
                return [
                    'error' => 'Esta ruta no tiene contenedores registrados.',
                    'status' => 422
                ];
            }

            $recolectados = DB::table('cuadrilla_ruta_contenedores')
                ->where('id_asignacion', $id)
                ->where('estado', 'Recolectado')
                ->count();

            DB::table('cuadrilla_rutas')
                ->where('id_asignacion', $id)
                ->update([
                    'estado' => 'Finalizada',
                    'fecha_finalizacion' => now()
                ]);

            return [
                'total' => $total,
                'recolectados' => $recolectados
            ];
        }, 3);

        if (isset($resultado['error'])) {
            return response()->json([
                'success' => false,
                'message' => $resultado['error']
            ], $resultado['status']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Recorrido finalizado y agregado al historial.',
            'data' => $resultado
        ]);
    }
}
