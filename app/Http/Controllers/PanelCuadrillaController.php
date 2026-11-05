<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PanelCuadrillaController extends Controller
{
    public function miPanel(Request $request)
    {
            $dato = $request->user('api');

            if (!$dato || !$dato->id_usuario) {
            return response()->json([
                'success' => false,
                'message' => 'Sesión no válida.'
            ], 401);
            }

            $usuario = DB::table('usuarios as u')
            ->join('rol as r', 'r.id_rol', '=', 'u.id_rol')
            ->where('u.id_usuario', $dato->id_usuario)
            ->where('u.estado', 'activo')
            ->whereIn('r.nombre', ['cuadrilla', 'barrendero'])
            ->select(
                'u.id_usuario',
                'u.nombre',
                'u.apellido',
                'r.nombre as rol'
            )
            ->first();

            if (!$usuario) {
            return response()->json([
                'success' => false,
                'message' => 'No tenés permiso para acceder a este panel.'
            ], 403);
            }

            $ids = $this->misCuadrillasAutorizadas($request);

            $vacio = [
            'usuario' => $usuario,
            'cuadrillas' => [],
            'reclamos' => [],
            'incidencias' => [],
            'camiones' => [],
            'rutas' => [],
            'resumen' => [
                'reclamos_pendientes' => 0,
                'incidencias_pendientes' => 0,
                'camiones_asignados' => 0
                ]
            ];

            if (!$ids) {
            return response()->json([
                'success' => true,
                'data' => $vacio
            ]);
            }

            $cuadrillas = DB::table('cuadrilla as q')
            ->leftJoin(
                'usuarios as u',
                'u.id_usuario',
                '=',
                'q.id_responsable'
            )
            ->whereIn('q.id_cuadrilla', $ids)
            ->select(
                'q.*',
                'u.nombre as responsable_nombre',
                'u.apellido as responsable_apellido'
            )
            ->get();

            $integrantes = DB::table('cuadrilla_integrantes as ci')
            ->join(
                'usuarios as u',
                'u.id_usuario',
                '=',
                'ci.id_usuario'
            )
            ->whereIn('ci.id_cuadrilla', $ids)
            ->whereNull('ci.fecha_finalizacion')
            ->where('u.estado', 'activo')
            ->select(
                'ci.id_cuadrilla',
                'u.id_usuario',
                'u.nombre',
                'u.apellido'
            )
            ->get()
            ->groupBy('id_cuadrilla');

            foreach ($cuadrillas as $cuadrilla) {
            $cuadrilla->integrantes = $integrantes
                ->get($cuadrilla->id_cuadrilla, collect())
                ->values();
            }

            $reclamos = DB::table('reclamos as r')
            ->leftJoin(
                'contenedores as c',
                'c.id_contenedor',
                '=',
                'r.id_contenedor'
            )
            ->leftJoin(
                'ubicacion as u',
                'u.id_ubicacion',
                '=',
                'c.id_ubicacion'
            )
            ->whereIn('r.id_cuadrilla', $ids)
            ->where('r.estado', 'asignado')
            ->select(
                'r.id_reclamo as id',
                'r.id_cuadrilla',
                'r.id_contenedor',
                'r.tipo',
                'r.categoria',
                'r.descripcion',
                'r.prioridad',
                'r.estado',
                'r.fecha_reporte',
                'r.ubicacion',
                'r.zona',
                'r.referencia',
                'r.foto',
                'r.foto_resultado',
                'r.resultado',
                'u.calle',
                'u.barrio',
                'u.latitud',
                'u.longitud'
            )
            ->orderByDesc('r.fecha_reporte')
            ->get();

            $incidencias = DB::table('incidencias as i')
            ->leftJoin(
                'contenedores as c',
                'c.id_contenedor',
                '=',
                'i.id_contenedor'
            )
            ->leftJoin(
                'ubicacion as u',
                'u.id_ubicacion',
                '=',
                'c.id_ubicacion'
            )
            ->whereIn('i.id_cuadrilla', $ids)
            ->whereIn('i.estado', ['Abierta', 'En curso'])
            ->select(
                'i.id_incidencia as id',
                'i.id_cuadrilla',
                'i.id_contenedor',
                'i.tipo',
                'i.descripcion',
                'i.estado',
                'i.fecha_reporte',
                'i.id_camion',
                'i.tipo_recurso',
                'u.calle',
                'u.barrio',
                'u.latitud',
                'u.longitud'
            )
            ->orderByDesc('i.fecha_reporte')
            ->get();

            $camionesHistoricosActivos = DB::table('camion_asignaciones')
            ->whereIn('id_cuadrilla', $ids)
            ->whereNull('fecha_finalizacion')
            ->pluck('id_camion');

            $camiones = DB::table('camiones as c')
            ->leftJoin(
                'rutas as r',
                'r.id_ruta',
                '=',
                'c.id_ruta'
            )
            ->leftJoin(
                'usuarios as ch',
                'ch.id_usuario',
                '=',
                'c.id_chofer'
            )
            ->where('c.activo', 1)
            ->where(function ($query) use (
                $ids,
                $camionesHistoricosActivos
            ) {
                $query
                    ->whereIn('c.id_cuadrilla', $ids)
                    ->orWhereIn(
                        'c.id_camion',
                        $camionesHistoricosActivos
                    );
            })
            ->select(
                'c.id_camion',
                'c.id_cuadrilla',
                'c.matricula',
                'c.estado',
                'c.gps',
                'c.id_ruta',
                'r.circuito',
                'ch.nombre as chofer_nombre',
                'ch.apellido as chofer_apellido'
            )
            ->distinct()
            ->get();

            
            $rutas = DB::table('cuadrilla_rutas as cr')
            ->join(
            'rutas as r',
            'r.id_ruta',
            '=',
            'cr.id_ruta'
            )
            ->whereIn('cr.id_cuadrilla', $ids)
            ->whereNull('cr.fecha_finalizacion')
            ->select(
            'cr.id_asignacion',
            'cr.id_cuadrilla',
            'cr.fecha_inicio',
            'cr.estado',
            'cr.prioridad',
            'r.id_ruta',
            'r.circuito',
            'r.nombre',
            'r.municipio'
            )
            ->get();

            return response()->json([
            'success' => true,
            'data' => [
                'usuario' => $usuario,
                'cuadrillas' => $cuadrillas,
                'reclamos' => $reclamos,
                'incidencias' => $incidencias,
                'camiones' => $camiones,
                'rutas' => $rutas,
                'resumen' => [
                    'reclamos_pendientes' => $reclamos->count(),
                    'incidencias_pendientes' => $incidencias->count(),
                    'camiones_asignados' => $camiones->count()
                ]
            ]
        ]);
    }

    private function misCuadrillasAutorizadas(Request $request): array
    {
        $dato = $request->user('api');

        if (!$dato || !$dato->id_usuario) {
            return [];
        }

        $idUsuario = (int) $dato->id_usuario;

        $habilitado = DB::table('usuarios as u')
            ->join('rol as r', 'r.id_rol', '=', 'u.id_rol')
            ->where('u.id_usuario', $idUsuario)
            ->where('u.estado', 'activo')
            ->whereIn('r.nombre', ['cuadrilla', 'barrendero'])
            ->exists();

        if (!$habilitado) {
            return [];
        }

        $integrante = DB::table('cuadrilla_integrantes')
            ->where('id_usuario', $idUsuario)
            ->whereNull('fecha_finalizacion')
            ->pluck('id_cuadrilla');

        $responsable = DB::table('cuadrilla')
            ->where('id_responsable', $idUsuario)
            ->pluck('id_cuadrilla');

        return $integrante
            ->merge($responsable)
            ->unique()
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
    }

    public function resolverReclamo(Request $request, string $id)
    {
        $ids = $this->misCuadrillasAutorizadas($request);

        if (!$ids) {
            return response()->json([
                'success' => false,
                'message' => 'No tenés una cuadrilla habilitada para resolver tareas.'
            ], 403);
        }

        $datos = $request->validate([
            'resultado' => 'required|string|min:5|max:255',
            'foto_resultado' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120'
        ]);

        $resultado = trim($datos['resultado']);
        $rutaFoto = null;

        try {
            return DB::transaction(function () use (
                $request,
                $id,
                $ids,
                $resultado,
                &$rutaFoto
            ) {
                $reclamo = DB::table('reclamos')
                    ->where('id_reclamo', $id)
                    ->lockForUpdate()
                    ->first();

                if (!$reclamo) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Reclamo no encontrado.'
                    ], 404);
                }

                if (!in_array(
                    (string) $reclamo->id_cuadrilla,
                    $ids,
                    true
                )) {
                    return response()->json([
                        'success' => false,
                        'message' => 'El reclamo no pertenece a tu cuadrilla.'
                    ], 403);
                }

                if ($reclamo->estado !== 'asignado') {
                    return response()->json([
                        'success' => false,
                        'message' => 'El reclamo ya no está pendiente de resolución.'
                    ], 409);
                }

                $actualizacion = [
                    'estado' => 'completado',
                    'resultado' => $resultado,
                    'fecha_finalizacion' => now(),
                    'notificado' => 0
                ];

                if ($request->hasFile('foto_resultado')) {
                    $rutaFoto = $request
                        ->file('foto_resultado')
                        ->store('reclamos/resultados', 'public');

                    if (!$rutaFoto) {
                        throw new \RuntimeException(
                            'No se pudo guardar la fotografía.'
                        );
                    }

                    $actualizacion['foto_resultado'] =
                        Storage::disk('public')->url($rutaFoto);
                }

                DB::table('reclamos')
                    ->where('id_reclamo', $id)
                    ->update($actualizacion);

                return response()->json([
                    'success' => true,
                    'message' => 'Reclamo completado correctamente.',
                    'data' => [
                        'id_reclamo' => $id,
                        'estado' => 'completado',
                        'resultado' => $resultado,
                        'foto_resultado' =>
                            $actualizacion['foto_resultado']
                            ?? $reclamo->foto_resultado
                            ?? null,
                        'notificado' => false
                    ]
                ]);
            }, 3);
        } catch (\Throwable $error) {
            if ($rutaFoto) {
                Storage::disk('public')->delete($rutaFoto);
            }

            throw $error;
        }
    }

    public function resolverIncidencia(Request $request, int $id)
    {
        $ids = $this->misCuadrillasAutorizadas($request);

        if (!$ids) {
            return response()->json([
                'success' => false,
                'message' => 'No tenés una cuadrilla habilitada para resolver tareas.'
            ], 403);
        }

        $datos = $request->validate([
            'resolucion' => 'required|string|min:5|max:2000'
        ]);

        $resolucion = trim($datos['resolucion']);

        return DB::transaction(function () use (
            $id,
            $ids,
            $resolucion
        ) {
            $incidencia = DB::table('incidencias')
                ->where('id_incidencia', $id)
                ->lockForUpdate()
                ->first();

            if (!$incidencia) {
                return response()->json([
                    'success' => false,
                    'message' => 'Incidencia no encontrada.'
                ], 404);
            }

            if (!in_array(
                (string) $incidencia->id_cuadrilla,
                $ids,
                true
            )) {
                return response()->json([
                    'success' => false,
                    'message' => 'La incidencia no pertenece a tu cuadrilla.'
                ], 403);
            }

            if (!in_array(
                $incidencia->estado,
                ['Abierta', 'En curso'],
                true
            )) {
                return response()->json([
                    'success' => false,
                    'message' => 'La incidencia ya no está pendiente de resolución.'
                ], 409);
            }

            DB::table('incidencias')
                ->where('id_incidencia', $id)
                ->update([
                    'estado' => 'Resuelta',
                    'resolucion' => $resolucion,
                    'fecha_resolucion' => now(),
                    'responsable' =>
                        'Cuadrilla ' . $incidencia->id_cuadrilla
                ]);

            return response()->json([
                'success' => true,
                'message' => 'Incidencia resuelta y registrada.',
                'data' => [
                    'id_incidencia' => $id,
                    'estado' => 'Resuelta',
                    'resolucion' => $resolucion
                ]
            ]);
        }, 3);
    }
}

