<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RecoleccionController extends Controller
{
    private function leerDespachos(): array
    {
        if (!Schema::hasTable('ruta_despachos')) {
            return [];
        }

        $filas = DB::table('ruta_despachos')->get();
        $despachos = [];

        foreach ($filas as $fila) {
            $despachos[(string) $fila->id_camion] = [
                'id_camion' => (int) $fila->id_camion,
                'matricula' => $fila->matricula,
                'id_chofer' => $fila->id_chofer !== null ? (int) $fila->id_chofer : null,
                'id_ruta' => $fila->id_ruta !== null ? (int) $fila->id_ruta : null,
                'circuitos' => json_decode($fila->circuitos ?: '[]', true) ?: [],
                'ids_contenedores' => json_decode($fila->ids_contenedores ?: '[]', true) ?: [],
                'contenedores_procesados' => json_decode($fila->contenedores_procesados ?: '{}', true) ?: [],
                'geometriaCalles' => json_decode($fila->geometria_calles ?: '[]', true) ?: [],
                'distanciaKm' => (float) ($fila->distancia_km ?? 0),
                'km_recorridos' => (float) ($fila->km_recorridos ?? 0),
                'incidencias_creadas' => (int) ($fila->incidencias_creadas ?? 0),
                'estado' => $fila->estado,
                'fecha_envio' => $fila->fecha_envio,
            ];
        }

        return $despachos;
    }

    
    private function guardarDespachos(array $despachos): void
    {
    if (!Schema::hasTable('ruta_despachos')) {
        throw new \RuntimeException(
            'Falta la tabla ruta_despachos.'
        );
    }

    foreach ($despachos as $d) {
        DB::table('ruta_despachos')->updateOrInsert(
            ['id_camion' => (int) $d['id_camion']],
            [
                'matricula' => $d['matricula'] ?? null,
                'id_chofer' => $d['id_chofer'] ?? null,
                'id_ruta' => $d['id_ruta'] ?? null,
                'circuitos' => json_encode(
                    $d['circuitos'] ?? []
                ),
                'ids_contenedores' => json_encode(
                    $d['ids_contenedores'] ?? []
                ),
                'contenedores_procesados' => json_encode(
                    (object) ($d['contenedores_procesados'] ?? [])
                ),
                'geometria_calles' => json_encode(
                    $d['geometriaCalles'] ?? []
                ),
                'distancia_km' =>
                    (float) ($d['distanciaKm'] ?? 0),
                'km_recorridos' =>
                    (float) ($d['km_recorridos'] ?? 0),
                'incidencias_creadas' =>
                    (int) ($d['incidencias_creadas'] ?? 0),
                'estado' =>
                    $d['estado'] ?? 'Enviada al chofer',
                'fecha_envio' => !empty($d['fecha_envio'])
                    ? \Carbon\Carbon::parse(
                        $d['fecha_envio']
                    )->format('Y-m-d H:i:s')
                    : now(),
                'updated_at' => now(),
            ]
        );
    }
    }


    public function index()
    {
        $recolecciones = DB::table('recolecciones as r')
            ->leftJoin('camiones as c', 'c.id_camion', '=', 'r.id_camion')
            ->leftJoin('rutas as ru', 'ru.id_ruta', '=', 'r.id_ruta')
            ->select(
                'r.*',
                'c.matricula as camion_matricula',
                'ru.circuito as ruta_circuito'
            )
            ->orderByDesc('r.id_recoleccion')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $recolecciones
        ]);
    }

    
    public function despacharRuta(Request $request)
    {
    $datos = $request->validate([
        'id_camion' => 'required|integer|exists:camiones,id_camion',
        'id_ruta' => 'nullable|integer|exists:rutas,id_ruta',
        'circuitos' => 'required|array|min:1',
        'circuitos.*' =>
            'required|string|exists:circuitos,id_circuito',
        'contenedores' => 'required|array|min:1',
        'contenedores.*.id_contenedor' =>
            'required|integer|exists:contenedores,id_contenedor',
        'geometriaCalles' => 'required|array|min:2',
        'distanciaKm' => 'required|numeric|min:0',
    ]);

    $resultado = DB::transaction(function () use ($datos) {
        $camion = DB::table('camiones')
            ->where('id_camion', $datos['id_camion'])
            ->lockForUpdate()
            ->first();

        if (
            !$camion ||
            !(int) $camion->activo ||
            !$camion->id_chofer
        ) {
            return [
                'error' =>
                    'El camión debe estar activo y tener chofer.'
            ];
        }

        if (
            DB::table('ruta_despachos')
                ->where('id_camion', $camion->id_camion)
                ->exists()
        ) {
            return [
                'error' =>
                    'El camión ya tiene una ruta despachada.'
            ];
        }

        if (
            DB::table('recolecciones')
                ->where('id_camion', $camion->id_camion)
                ->where('estado', 'En curso')
                ->exists()
        ) {
            return [
                'error' =>
                    'El camión tiene un recorrido en curso.'
            ];
        }

        $circuitos = array_values(
            array_unique($datos['circuitos'])
        );

        $rutas = DB::table('rutas')
            ->whereIn('circuito', $circuitos)
            ->orderBy('id_ruta')
            ->lockForUpdate()
            ->get();

        $primera = $rutas->firstWhere(
            'circuito',
            $circuitos[0]
        );

        if (!$primera) {
            return [
                'error' =>
                    'El primer circuito no tiene ruta registrada.'
            ];
        }

        $idRuta = (int) $primera->id_ruta;

        if (
            isset($datos['id_ruta']) &&
            (int) $datos['id_ruta'] !== $idRuta
        ) {
            return [
                'error' =>
                    'La ruta no corresponde al primer circuito.'
            ];
        }

        $idRutas = $rutas
            ->pluck('id_ruta')
            ->map(fn ($id) => (int) $id)
            ->all();

        $otraAsignacion = DB::table('camiones')
            ->where('activo', 1)
            ->where(
                'id_camion',
                '<>',
                $camion->id_camion
            )
            ->whereIn('id_ruta', $idRutas)
            ->exists();

        if ($otraAsignacion) {
            return [
                'error' =>
                    'Un circuito ya está asignado a otro camión.'
            ];
        }

        foreach (
            DB::table('ruta_despachos')->get()
            as $actual
        ) {
            $existentes = json_decode(
                $actual->circuitos ?: '[]',
                true
            ) ?: [];

            if (array_intersect($circuitos, $existentes)) {
                return [
                    'error' =>
                        'Ya existe un despacho para uno de estos circuitos.'
                ];
            }
        }

        $ids = array_values(array_unique(array_map(
            fn ($c) => (int) $c['id_contenedor'],
            $datos['contenedores']
        )));

        $validos = DB::table('contenedores')
            ->whereIn('id_contenedor', $ids)
            ->where('activo', 1)
            ->whereIn('circuito', $circuitos)
            ->count();

        if ($validos !== count($ids)) {
            return [
                'error' =>
                    'Existen contenedores inactivos o de otros circuitos.'
            ];
        }

        $despacho = [
            'id_camion' => (int) $camion->id_camion,
            'matricula' => $camion->matricula,
            'id_chofer' => (int) $camion->id_chofer,
            'id_ruta' => $idRuta,
            'circuitos' => $circuitos,
            'ids_contenedores' => $ids,
            'contenedores_procesados' => [],
            'geometriaCalles' => $datos['geometriaCalles'],
            'distanciaKm' => (float) $datos['distanciaKm'],
            'km_recorridos' => 0,
            'incidencias_creadas' => 0,
            'estado' => 'Enviada al chofer',
            'fecha_envio' => now()->toIso8601String(),
        ];

        $this->guardarDespachos([$despacho]);

        DB::table('camiones')
            ->where('id_camion', $camion->id_camion)
            ->update([
                'id_ruta' => $idRuta,
                'contenedores_asignados' => count($ids),
                'contenedores_recolectados' => 0,
                'km_en_turno' => 0,
            ]);

        DB::table('camion_asignaciones')
            ->where('id_camion', $camion->id_camion)
            ->whereNull('fecha_finalizacion')
            ->update([
                'fecha_finalizacion' => now(),
                'estado' => 'Finalizada'
            ]);

        DB::table('camion_asignaciones')->insert([
            'id_camion' => $camion->id_camion,
            'id_chofer' => $camion->id_chofer,
            'id_cuadrilla' => $camion->id_cuadrilla,
            'id_ruta' => $idRuta,
            'fecha_asignacion' => now(),
            'fecha_finalizacion' => null,
            'estado' => 'Activa',
            'estado_ruta' => 'Asignada',
        ]);

        return ['despacho' => $despacho];
    }, 3);

    if (isset($resultado['error'])) {
        return response()->json([
            'success' => false,
            'message' => $resultado['error']
        ], 422);
    }

    return response()->json([
        'success' => true,
        'message' => 'Ruta enviada al chofer correctamente.',
        'data' => $resultado['despacho'],
    ]);
    }


    public function rutaDeChofer(Request $request)
    {
        $usuarioToken = auth('api')->user();
        $idChofer = $request->query('id_chofer') ?: ($usuarioToken->id_usuario ?? null);
        $matricula = $request->query('matricula');

        $despachos = $this->leerDespachos();

        $baseQuery = fn () => DB::table('camiones as c')
            ->leftJoin('rutas as r', 'r.id_ruta', '=', 'c.id_ruta')
            ->leftJoin('usuarios as u', 'u.id_usuario', '=', 'c.id_chofer')
            ->where('c.activo', 1)
            ->select(
                'c.*',
                'r.circuito as ruta_circuito',
                'r.municipio as ruta_municipio',
                DB::raw("TRIM(CONCAT(COALESCE(u.nombre, ''), ' ', COALESCE(u.apellido, ''))) as chofer_nombre")
            );

        $camion = null;

        if ($matricula) {
            $camion = $baseQuery()->where('c.matricula', $matricula)->first();
        }

        if (!$camion && $idChofer) {
            $camion = $baseQuery()->where('c.id_chofer', (int) $idChofer)->first();
        }


        if (!$camion) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontró un camión asignado.'
            ], 404);
        }

        $despachoCamion = $despachos[(string) $camion->id_camion] ?? null;
        $contenedores = [];

        if ($despachoCamion && !empty($despachoCamion['ids_contenedores'])) {
            $ids = array_map('intval', $despachoCamion['ids_contenedores']);
            $filas = DB::table('contenedores as ct')
                ->leftJoin('ubicacion as ub', 'ub.id_ubicacion', '=', 'ct.id_ubicacion')
                ->whereIn('ct.id_contenedor', $ids)
                ->select(
                    'ct.id_contenedor',
                    'ct.codigo',
                    'ct.tipo',
                    'ct.capacidad',
                    'ct.estado',
                    DB::raw('GREATEST(0, COALESCE(DATEDIFF(CURDATE(), ct.ultimo_registro), ct.dias_sin_recoleccion, 0)) AS dias_sin_recoleccion'),
                    'ct.ultimo_registro',
                    'ct.circuito',
                    'ub.calle',
                    'ub.esquina_1 as esquina',
                    'ub.latitud',
                    'ub.longitud'
                )
                ->get()
                ->keyBy('id_contenedor');

            $procesados = $despachoCamion['contenedores_procesados'] ?? [];
            foreach ($ids as $idCont) {
                if (isset($filas[$idCont])) {
                    $item = (array) $filas[$idCont];
                    $item['procesado_en_ruta'] = $procesados[(string) $idCont] ?? null;
                    $contenedores[] = $item;
                }
            }
        } elseif ($camion->ruta_circuito) {
            $contenedores = DB::table('contenedores as ct')
                ->leftJoin('ubicacion as ub', 'ub.id_ubicacion', '=', 'ct.id_ubicacion')
                ->where('ct.activo', 1)
                ->where('ct.circuito', $camion->ruta_circuito)
                ->whereRaw('GREATEST(0, COALESCE(DATEDIFF(CURDATE(), ct.ultimo_registro), ct.dias_sin_recoleccion, 0)) >= 3')
                ->select(
                    'ct.id_contenedor',
                    'ct.codigo',
                    'ct.tipo',
                    'ct.capacidad',
                    'ct.estado',
                    DB::raw('GREATEST(0, COALESCE(DATEDIFF(CURDATE(), ct.ultimo_registro), ct.dias_sin_recoleccion, 0)) AS dias_sin_recoleccion'),
                    'ct.ultimo_registro',
                    'ct.circuito',
                    'ub.calle',
                    'ub.esquina_1 as esquina',
                    'ub.latitud',
                    'ub.longitud'
                )
                ->get();
        }

        $idsContenedores = collect($contenedores)
    ->pluck('id_contenedor')
    ->map(fn ($id) => (int) $id)
    ->all();

    $idsConIncidencia = DB::table('incidencias')
    ->where('tipo_recurso', 'contenedor')
    ->whereIn('estado', ['Abierta', 'En curso'])
    ->whereIn('id_contenedor', $idsContenedores)
    ->pluck('id_contenedor')
    ->map(fn ($id) => (int) $id)
    ->all();

    $contenedores = collect($contenedores)
            ->map(function ($contenedor) use ($idsConIncidencia) {
            $item = (array) $contenedor;

            $item['incidencia_violeta'] = in_array(
                (int) $item['id_contenedor'],
                $idsConIncidencia,
                 true
             );

            return $item;
         })
        ->values();

        $recoleccionActiva = DB::table('recolecciones')
            ->where('id_camion', $camion->id_camion)
            ->where('estado', 'En curso')
            ->orderByDesc('id_recoleccion')
            ->first();

        $camion->recorrido_activo = $recoleccionActiva !== null;
        $camion->estado_operativo = $recoleccionActiva !== null
            ? 'En recorrido' : 'En base';

        return response()->json([
            'success' => true,
            'data' => [
                'camion' => $camion,
                'ruta_despachada' => $despachoCamion,
                'contenedores' => $contenedores,
                'recoleccion_activa' => $recoleccionActiva
            ]
        ]);
    }

    
    public function iniciarRecoleccion(Request $request)
    {
    $datos = $request->validate([
        'id_camion' =>
            'required|integer|exists:camiones,id_camion',
        'id_ruta' =>
            'nullable|integer|exists:rutas,id_ruta',
        'tipo' =>
            'nullable|string|exists:tipo_residuo,tipo',
    ]);

    $resultado = DB::transaction(function () use ($datos) {
        $camion = DB::table('camiones')
            ->where('id_camion', $datos['id_camion'])
            ->lockForUpdate()
            ->first();

        if (
            !$camion ||
            !(int) $camion->activo ||
            !$camion->id_chofer
        ) {
            return [
                'error' => 'El camión no está habilitado.'
            ];
        }

        $existente = DB::table('recolecciones')
            ->where('id_camion', $camion->id_camion)
            ->where('estado', 'En curso')
            ->first();

        if ($existente) {
            return [
                'id' => (int) $existente->id_recoleccion,
                'nuevo' => false
            ];
        }

        $despacho = DB::table('ruta_despachos')
            ->where('id_camion', $camion->id_camion)
            ->lockForUpdate()
            ->first();

        if (!$despacho) {
            return [
                'error' =>
                    'El chofer todavía no tiene una ruta despachada.'
            ];
        }

        if (
            isset($datos['id_ruta']) &&
            (int) $datos['id_ruta'] !==
            (int) $despacho->id_ruta
        ) {
            return [
                'error' =>
                    'La ruta recibida no coincide con el despacho.'
            ];
        }

        $registro = [
            'id_camion' => $camion->id_camion,
            'id_ruta' => $despacho->id_ruta,
            'id_cuadrilla' => $camion->id_cuadrilla,
            'fecha_inicio' => now(),
            'tipo' => $datos['tipo'] ?? $camion->tipo,
            'volumen_recolectado' => 0,
            'peso_recolectado_kg' => 0,
            'contenedores_recolectados' => 0,
            'contenedores_asignados' => count(
                json_decode(
                    $despacho->ids_contenedores ?: '[]',
                    true
                ) ?: []
            ),
            'distancia_km' =>
                (float) $despacho->distancia_km,
            'estado' => 'En curso',
        ];

        $id = DB::table('recolecciones')
            ->insertGetId($registro);

        DB::table('ruta_despachos')
            ->where('id_camion', $camion->id_camion)
            ->update([
                'estado' => 'En curso',
                'updated_at' => now()
            ]);

        DB::table('camion_asignaciones')
            ->where('id_camion', $camion->id_camion)
            ->where('id_ruta', $despacho->id_ruta)
            ->whereNull('fecha_finalizacion')
            ->update([
                'estado_ruta' => 'En curso'
            ]);

        DB::table('camiones')
            ->where('id_camion', $camion->id_camion)
            ->update([
                'estado' => 'Recolectando',
                'contenedores_recolectados' => 0,
                'peso_recolectado_kg' => 0,
            ]);

        return [
            'id' => $id,
            'nuevo' => true
        ];
    }, 3);

    if (isset($resultado['error'])) {
        return response()->json([
            'success' => false,
            'message' => $resultado['error']
        ], 422);
    }

    return response()->json([
        'success' => true,
        'message' => $resultado['nuevo']
            ? 'Recorrido iniciado.'
            : 'Recorrido en curso.',
        'data' => [
            'id_recoleccion' => $resultado['id']
        ],
    ], $resultado['nuevo'] ? 201 : 200);
    }


    
    public function recolectarContenedor(
    Request $request,
    int $idRecoleccion
    ) {
    $datos = $request->validate([
        'id_contenedor' =>
            'required|integer|exists:contenedores,id_contenedor',
        'accion' => 'nullable|in:levantado,no_levantado',
        'motivo' => 'nullable|string|max:180',
        'descripcion' => 'nullable|string|max:500',
        'peso_kg' => 'nullable|numeric|min:0',
    ]);

    $resultado = DB::transaction(
        function () use ($datos, $idRecoleccion) {
            $rec = DB::table('recolecciones')
                ->where('id_recoleccion', $idRecoleccion)
                ->lockForUpdate()
                ->first();

            if (!$rec) {
                return [
                    'error' => 'Recolección no encontrada.',
                    'status' => 404
                ];
            }

            if ($rec->estado !== 'En curso') {
                return [
                    'error' =>
                        'La recolección no está en curso.',
                    'status' => 422
                ];
            }

            $despacho = DB::table('ruta_despachos')
                ->where('id_camion', $rec->id_camion)
                ->lockForUpdate()
                ->first();

            if (!$despacho) {
                return [
                    'error' =>
                        'El camión no tiene despacho activo.',
                    'status' => 422
                ];
            }

            $id = (int) $datos['id_contenedor'];

            $ids = array_map(
                'intval',
                json_decode(
                    $despacho->ids_contenedores ?: '[]',
                    true
                ) ?: []
            );

            if (!in_array($id, $ids, true)) {
                return [
                    'error' =>
                        'El contenedor no pertenece a esta ruta.',
                    'status' => 422
                ];
            }

            $procesados = json_decode(
                $despacho->contenedores_procesados ?: '{}',
                true
            ) ?: [];

            if (isset($procesados[(string) $id])) {
                $previo = $procesados[(string) $id];

                $contenedor = DB::table('contenedores')
                    ->where('id_contenedor', $id)
                    ->first();

                return [
                    'id_contenedor' => $id,
                    'accion' =>
                        $previo['accion'] ?? 'levantado',
                    'ultimo_registro' =>
                        $contenedor->ultimo_registro ?? null,
                    'id_incidencia' =>
                        $previo['id_incidencia'] ?? null,
                    'duplicado' => true,
                ];
            }

            $camion = DB::table('camiones')
                ->where('id_camion', $rec->id_camion)
                ->first();

            $contenedor = DB::table('contenedores')
                ->where('id_contenedor', $id)
                ->lockForUpdate()
                ->first();

            if (
                !$contenedor ||
                !(int) $contenedor->activo
            ) {
                return [
                    'error' =>
                        'El contenedor no está activo.',
                    'status' => 422
                ];
            }

            $accion = $datos['accion'] ?? 'levantado';
            $motivo = $datos['motivo'] ?? null;

            $obs = trim(
                (string) ($datos['descripcion'] ?? '')
            );

            $estadoAnterior =
                $contenedor->estado ?? 'funcional';

            $matricula = $camion->matricula ??
                ('#' . $rec->id_camion);

            $idIncidencia = null;
            $ultimoRegistro = $contenedor->ultimo_registro;
            $historialMotivo = '';
            $estadoNuevo = $estadoAnterior;

            if ($accion === 'levantado') {
                $yaLevantado = DB::table(
                    'recoleccion_contenedor'
                )
                    ->where(
                        'id_recoleccion',
                        $idRecoleccion
                    )
                    ->where('id_contenedor', $id)
                    ->exists();

                if (!$yaLevantado) {
                    DB::table('recoleccion_contenedor')
                        ->insert([
                            'id_recoleccion' =>
                                $idRecoleccion,
                            'id_contenedor' => $id,
                            'fecha' => now(),
                        ]);

                    $peso = (float) (
                        $datos['peso_kg'] ?? 180
                    );

                    DB::table('recolecciones')
                        ->where(
                            'id_recoleccion',
                            $idRecoleccion
                        )
                        ->increment(
                            'peso_recolectado_kg',
                            $peso
                        );

                    DB::table('camiones')
                        ->where(
                            'id_camion',
                            $rec->id_camion
                        )
                        ->increment(
                            'peso_recolectado_kg',
                            $peso
                        );
                }

                $ultimoRegistro = now()->toDateString();
                $estadoNuevo = 'funcional';

                DB::table('contenedores')
                    ->where('id_contenedor', $id)
                    ->update([
                        'dias_sin_recoleccion' => 0,
                        'ultimo_registro' => $ultimoRegistro,
                        'estado' => $estadoNuevo,
                    ]);

                $historialMotivo =
                    'Limpieza por camión ' .
                    $matricula .
                    ($obs !== '' ? ': ' . $obs : '');

                if ($motivo !== null && $motivo !== '') {
                    $idIncidencia = DB::table(
                        'incidencias'
                    )->insertGetId([
                        'tipo_recurso' => 'contenedor',
                        'id_camion' => $rec->id_camion,
                        'id_contenedor' => $id,
                        'id_cuadrilla' =>
                            $rec->id_cuadrilla,
                        'tipo' => $motivo,
                        'estado' => 'Abierta',
                        'fecha_reporte' => now(),
                        'descripcion' =>
                            'Levantado con incidencia: ' .
                            $motivo .
                            ($obs !== ''
                                ? ' - ' . $obs
                                : ''),
                        'responsable' =>
                            'Camión ' . $matricula,
                    ]);
                }

                $total = DB::table(
                    'recoleccion_contenedor'
                )
                    ->where(
                        'id_recoleccion',
                        $idRecoleccion
                    )
                    ->count();

                DB::table('recolecciones')
                    ->where(
                        'id_recoleccion',
                        $idRecoleccion
                    )
                    ->update([
                        'contenedores_recolectados' =>
                            $total
                    ]);

                DB::table('camiones')
                    ->where(
                        'id_camion',
                        $rec->id_camion
                    )
                    ->update([
                        'contenedores_recolectados' =>
                            $total
                    ]);
            } else {
                $motivo = $motivo ?:
                    'No se pudo levantar';

                $estadoNuevo = stripos(
                    $motivo,
                    'roto'
                ) !== false
                    ? 'roto'
                    : $estadoAnterior;

                if ($estadoNuevo !== $estadoAnterior) {
                    DB::table('contenedores')
                        ->where('id_contenedor', $id)
                        ->update([
                            'estado' => $estadoNuevo
                        ]);
                }

                $idIncidencia = DB::table(
                    'incidencias'
                )->insertGetId([
                    'tipo_recurso' => 'contenedor',
                    'id_camion' => $rec->id_camion,
                    'id_contenedor' => $id,
                    'id_cuadrilla' => $rec->id_cuadrilla,
                    'tipo' => $motivo,
                    'estado' => 'Abierta',
                    'fecha_reporte' => now(),
                    'descripcion' =>
                        'No levantado por camión ' .
                        $matricula .
                        '. Motivo: ' . $motivo .
                        ($obs !== ''
                            ? ' - ' . $obs
                            : ''),
                    'responsable' =>
                        'Camión ' . $matricula,
                ]);

                $historialMotivo =
                    'No levantado por camión ' .
                    $matricula . ': ' . $motivo;
            }

            if (Schema::hasTable('historial_contenedores')) {
                DB::table('historial_contenedores')
                    ->insert([
                        'id_contenedor' => $id,
                        'fecha' => now(),
                        'estado_anterior' =>
                            $estadoAnterior,
                        'estado_nuevo' => $estadoNuevo,
                        'id_ubicacion_anterior' => null,
                        'id_ubicacion_nueva' => null,
                        'motivo' => $historialMotivo,
                    ]);
            }

            $procesados[(string) $id] = [
                'accion' => $accion,
                'motivo' => $motivo,
                'fecha' => now()->toIso8601String(),
                'id_incidencia' => $idIncidencia,
            ];

            DB::table('ruta_despachos')
                ->where('id_camion', $rec->id_camion)
                ->update([
                    'contenedores_procesados' =>
                        json_encode(
                            (object) $procesados,
                            JSON_UNESCAPED_UNICODE
                        ),
                    'incidencias_creadas' =>
                        (int) $despacho->incidencias_creadas +
                        ($idIncidencia ? 1 : 0),
                    'updated_at' => now(),
                ]);

            return [
                'id_contenedor' => $id,
                'accion' => $accion,
                'ultimo_registro' => $ultimoRegistro,
                'id_incidencia' => $idIncidencia,
                'duplicado' => false,
            ];
        },
        3
    );

    if (isset($resultado['error'])) {
        return response()->json([
            'success' => false,
            'message' => $resultado['error'],
        ], $resultado['status']);
    }

    $resultado['incidencia_violeta'] = DB::table('incidencias')
        ->where('tipo_recurso', 'contenedor')
        ->where('id_contenedor', $resultado['id_contenedor'])
        ->whereIn('estado', ['Abierta', 'En curso'])
        ->exists();

    return response()->json([
        'success' => true,
        'message' => $resultado['duplicado']
            ? 'El contenedor ya estaba procesado en este recorrido.'
            : (
                $resultado['accion'] === 'levantado'
                    ? 'Contenedor levantado e historial guardado.'
                    : 'Incidencia registrada en el contenedor.'
            ),
        'data' => $resultado,
    ]);
    }


    public function actualizarGpsCamion(Request $request, int $idCamion)
    {
        $datos = $request->validate([
            'latitud' => 'required|numeric|between:-90,90',
            'longitud' => 'required|numeric|between:-180,180'
        ]);

        $camion = DB::table('camiones')
            ->where('id_camion', $idCamion)
            ->where('activo', 1)
            ->first();
        if (!$camion) {
            return response()->json([
                'success' => false,
                'message' => 'Camión activo no encontrado.'
            ], 404);
        }

        $idUsuario = auth('api')->user()->id_usuario ?? null;
        if (!$idUsuario || (int) $camion->id_chofer !== (int) $idUsuario) {
            return response()->json([
                'success' => false,
                'message' => 'El camión no corresponde al chofer autenticado.'
            ], 403);
        }

        $tieneRecorrido = DB::table('recolecciones')
            ->where('id_camion', $idCamion)
            ->where('estado', 'En curso')
            ->exists();
        if (!$tieneRecorrido) {
            return response()->json([
                'success' => false,
                'message' => 'No hay una recolección activa para actualizar GPS.'
            ], 409);
        }

        DB::table('camiones')
            ->where('id_camion', $idCamion)
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('recolecciones as r_activa')
                    ->whereColumn('r_activa.id_camion', 'camiones.id_camion')
                    ->where('r_activa.estado', 'En curso');
            })
            ->update([
                'gps' => $datos['latitud'] . ', ' . $datos['longitud']
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Ubicación GPS recibida.'
        ]);
    }

    public function finalizarRecoleccion(
    Request $request,
    int $idRecoleccion
    ) {
    $resultado = DB::transaction(
        function () use ($idRecoleccion) {
            $rec = DB::table('recolecciones')
                ->where('id_recoleccion', $idRecoleccion)
                ->lockForUpdate()
                ->first();

            if (!$rec) {
                return [
                    'error' => 'Recolección no encontrada.',
                    'status' => 404
                ];
            }

            if ($rec->estado === 'Finalizada') {
                return ['finalizada' => true];
            }

            if ($rec->estado !== 'En curso') {
                return [
                    'error' => 'La recolección no está en curso.',
                    'status' => 422
                ];
            }

            $camion = DB::table('camiones')
                ->where('id_camion', $rec->id_camion)
                ->lockForUpdate()
                ->first();

            DB::table('recolecciones')
                ->where('id_recoleccion', $idRecoleccion)
                ->update([
                    'fecha_fin' => now(),
                    'estado' => 'Finalizada'
                ]);

            DB::table('ruta_despachos')
                ->where('id_camion', $rec->id_camion)
                ->where('id_ruta', $rec->id_ruta)
                ->delete();

            DB::table('camion_asignaciones')
                ->where('id_camion', $rec->id_camion)
                ->whereNull('fecha_finalizacion')
                ->update([
                    'fecha_finalizacion' => now(),
                    'estado' => 'Finalizada',
                    'estado_ruta' => 'Finalizada'
                ]);

            DB::table('camiones')
                ->where('id_camion', $rec->id_camion)
                ->update([
                    'estado' => 'En base',
                    'id_ruta' => null,
                    'peso_recolectado_kg' => 0,
                    'km_en_turno' => 0,
                    'contenedores_asignados' => 0,
                    'contenedores_recolectados' => 0,
                ]);

            if (
                $camion &&
                (
                    $camion->id_chofer !== null ||
                    $camion->id_cuadrilla !== null
                )
            ) {
                DB::table('camion_asignaciones')->insert([
                    'id_camion' => $camion->id_camion,
                    'id_chofer' => $camion->id_chofer,
                    'id_cuadrilla' => $camion->id_cuadrilla,
                    'id_ruta' => null,
                    'fecha_asignacion' => now(),
                    'fecha_finalizacion' => null,
                    'estado' => 'Activa',
                    'estado_ruta' => null,
                ]);
            }

            return ['finalizada' => true];
        },
        3
    );

    if (isset($resultado['error'])) {
        return response()->json([
            'success' => false,
            'message' => $resultado['error']
        ], $resultado['status']);
    }

    return response()->json([
        'success' => true,
        'message' => 'Recolección finalizada.'
    ]);
    }
    
    public function historialRutasChofer(Request $request)
    {
        $idCamion = $request->query('id_camion');
        $idChofer = $request->query('id_chofer');
        $tieneAsignados = Schema::hasColumn('recolecciones', 'contenedores_asignados');
        $tieneDistancia = Schema::hasColumn('recolecciones', 'distancia_km');

        $query = DB::table('recolecciones as rec')
            ->leftJoin('camiones as c', 'c.id_camion', '=', 'rec.id_camion')
            ->leftJoin('rutas as r', 'r.id_ruta', '=', 'rec.id_ruta')
            ->select(
                'rec.id_recoleccion',
                'rec.id_camion',
                'rec.fecha_inicio',
                'rec.fecha_fin',
                'rec.estado',
                'rec.contenedores_recolectados',
                $tieneAsignados
                    ? 'rec.contenedores_asignados'
                    : DB::raw('rec.contenedores_recolectados AS contenedores_asignados'),
                $tieneDistancia
                    ? 'rec.distancia_km'
                    : DB::raw('0 AS distancia_km'),
                'c.matricula',
                'c.id_chofer',
                'r.circuito'
            )
            ->whereNotNull('rec.fecha_fin')
            ->orderByDesc('rec.fecha_fin');

        if ($idCamion) {
            $query->where('rec.id_camion', (int) $idCamion);
        } elseif ($idChofer) {
            $query->where('c.id_chofer', (int) $idChofer);
        }

        $historial = $query->limit(50)->get()->map(function ($fila) {
            $inicio = $fila->fecha_inicio ? \Carbon\Carbon::parse($fila->fecha_inicio) : null;
            $fin = $fila->fecha_fin ? \Carbon\Carbon::parse($fila->fecha_fin) : null;
            $minutos = ($inicio && $fin) ? max(1, $inicio->diffInMinutes($fin)) : 0;
            $asignados = max((int) $fila->contenedores_asignados, (int) $fila->contenedores_recolectados);

            return [
                'id' => (int) $fila->id_recoleccion,
                'fecha' => $fin ? $fin->format('d/m/Y H:i') : null,
                'circuito' => $fila->circuito ?: 'Sin circuito',
                'estado' => $fila->estado ?: 'Finalizada',
                'levantados' => (int) $fila->contenedores_recolectados,
                'noLevantados' => max(0, $asignados - (int) $fila->contenedores_recolectados),
                'kmRecorridos' => number_format((float) $fila->distancia_km, 1, ',', '.') . ' km',
                'duracion' => $minutos >= 60
                    ? floor($minutos / 60) . 'h ' . ($minutos % 60) . 'm'
                    : $minutos . ' min',
            ];
        });

        return response()->json(['success' => true, 'data' => $historial]);
    }
}
