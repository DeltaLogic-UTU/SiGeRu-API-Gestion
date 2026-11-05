<?php

namespace App\Http\Controllers;

use App\Models\Rol;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class EstadisticaController extends Controller
{
    public function index(Request $request)
    {
        $mes = trim((string) $request->query(
            'mes',
            now()->format('Y-m')
        ));

        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) {
            return response()->json([
                'success' => false,
                'message' => 'El mes debe tener formato YYYY-MM.',
            ], 422);
        }

        try {
            $inicio = Carbon::createFromFormat(
                '!Y-m-d',
                $mes . '-01'
            );

            $fin = $inicio->copy()->addMonth();
            $inicioAnterior = $inicio->copy()->subMonth();

            $reclamos = $this->resumenReclamos(
                $inicio,
                $fin
            );

            $reclamos['variacion_vs_mes_anterior_pct'] =
                $this->variacionPorcentual(
                    $reclamos['recibidos'],
                    $this->contarReclamos(
                        $inicioAnterior,
                        $inicio
                    )
                );

            $ranking = $this->rankingDesbordamientos(
                $inicio,
                $fin
            );

            $incidencias = $this->resumenIncidencias(
                $inicio,
                $fin
            );

            $camiones = $this->resumenCamiones();
            $contenedores = $this->resumenContenedores();

            return response()->json([
                'success' => true,
                'data' => [
                    'mes' => $inicio->format('Y-m'),

                    'reclamos' => $reclamos,

                    'reclamos_por_tipo' =>
                        $this->reclamosPorTipo($inicio, $fin),

                    'reclamos_por_estado' =>
                        $this->reclamosPorEstado($inicio, $fin),

                    'reclamos_por_dia' =>
                        $this->reclamosPorDia($inicio, $fin),

                    'ranking_desbordamientos' =>
                        $ranking['ranking'],

                    'desbordamientos' => [
                        'total_mes' => $ranking['total'],
                        'sin_contenedor' =>
                            $ranking['sin_contenedor'],
                    ],

                    'sistema' => [
                        'contenedores_activos' =>
                            $contenedores['activos'],

                        'camiones_activos' =>
                            $camiones['activos'],

                        'cuadrillas' =>
                            $this->contarTabla('cuadrilla'),

                        'incidencias_mes' =>
                            $incidencias['total_mes'],
                    ],

                    'rutas' => $this->resumenRutas(),

                    'recolecciones' =>
                        $this->resumenRecolecciones(
                            $inicio,
                            $fin
                        ),

                    'camiones' => $camiones,
                    'contenedores' => $contenedores,
                    'incidencias' => $incidencias,

                    'usuarios' =>
                        $this->resumenUsuariosPorRol(),
                ],
            ]);

        } catch (Throwable $error) {
            report($error);

            $respuesta = [
                'success' => false,
                'message' =>
                    'No se pudieron calcular las estadísticas.',
            ];

            if (config('app.debug')) {
                $respuesta['error'] = $error->getMessage();
            }

            return response()->json($respuesta, 500);
        }
    }

    private function resumenUsuariosPorRol(): array
    {
        if (
            !Schema::hasTable('rol') ||
            !Schema::hasTable('usuarios')
        ) {
            return [
                'total' => 0,
                'por_rol' => [],
            ];
        }

        $roles = Rol::query()
            ->select(['id_rol', 'nombre'])
            ->withCount('usuarios')
            ->orderBy('nombre')
            ->get();

        return [
            'total' => (int) $roles->sum(
                'usuarios_count'
            ),

            'por_rol' => $roles->map(
                fn ($rol) => [
                    'rol' => $rol->nombre,
                    'total' =>
                        (int) $rol->usuarios_count,
                ]
            )->values()->all(),
        ];
    }

    private function tablaReclamosDisponible(): bool
    {
        return Schema::hasTable('reclamos')
            && Schema::hasColumn(
                'reclamos',
                'fecha_reporte'
            )
            && Schema::hasColumn(
                'reclamos',
                'estado'
            );
    }

    private function consultaMes(
        string $tabla,
        string $campo,
        Carbon $inicio,
        Carbon $fin
    ) {
        return DB::table($tabla)
            ->where($campo, '>=', $inicio)
            ->where($campo, '<', $fin);
    }

    private function contarReclamos(
        Carbon $inicio,
        Carbon $fin
    ): int {
        return $this->tablaReclamosDisponible()
            ? $this->consultaMes(
                'reclamos',
                'fecha_reporte',
                $inicio,
                $fin
            )->count()
            : 0;
    }

    private function resumenReclamos(
        Carbon $inicio,
        Carbon $fin
    ): array {
        $resumen = [
            'recibidos' => 0,
            'pendientes' => 0,
            'resueltos' => 0,
            'sin_asignar' => 0,
            'asignados' => 0,
            'tasa_resolucion_pct' => 0.0,
            'variacion_vs_mes_anterior_pct' => 0.0,
        ];

        if (!$this->tablaReclamosDisponible()) {
            return $resumen;
        }

        $base = $this->consultaMes(
            'reclamos',
            'fecha_reporte',
            $inicio,
            $fin
        );

        $resumen['recibidos'] = (clone $base)->count();

        $resumen['resueltos'] = (clone $base)
            ->whereRaw(
                "LOWER(TRIM(estado)) IN (
                    'completado',
                    'completada',
                    'resuelto',
                    'resuelta',
                    'cerrado',
                    'cerrada'
                )"
            )
            ->count();

        $resumen['sin_asignar'] = (clone $base)
            ->whereRaw(
                "LOWER(TRIM(estado)) IN (
                    'sin_asignar',
                    'sin asignar'
                )"
            )
            ->count();

        $resumen['asignados'] = (clone $base)
            ->whereRaw(
                "LOWER(TRIM(estado)) IN (
                    'asignado',
                    'asignada',
                    'en curso',
                    'en_curso'
                )"
            )
            ->count();

        $resumen['pendientes'] = max(
            0,
            $resumen['recibidos'] -
            $resumen['resueltos']
        );

        $resumen['tasa_resolucion_pct'] =
            $this->porcentaje(
                $resumen['resueltos'],
                $resumen['recibidos']
            );

        return $resumen;
    }

    private function reclamosPorTipo(
        Carbon $inicio,
        Carbon $fin
    ): Collection {
        if (!$this->tablaReclamosDisponible()) {
            return collect();
        }

        $tieneTipo = Schema::hasColumn(
            'reclamos',
            'tipo'
        );

        $tieneCategoria = Schema::hasColumn(
            'reclamos',
            'categoria'
        );

        if (!$tieneTipo && !$tieneCategoria) {
            return collect();
        }

        $columnas = array_values(array_filter([
            $tieneTipo ? 'tipo' : null,
            $tieneCategoria ? 'categoria' : null,
        ]));

        return $this->consultaMes(
            'reclamos',
            'fecha_reporte',
            $inicio,
            $fin
        )
            ->get($columnas)
            ->map(function ($fila) use (
                $tieneTipo,
                $tieneCategoria
            ) {
                $tipo = $tieneTipo
                    ? trim((string) ($fila->tipo ?? ''))
                    : '';

                $categoria = $tieneCategoria
                    ? trim((string) (
                        $fila->categoria ?? ''
                    ))
                    : '';

                return $tipo !== ''
                    ? $tipo
                    : (
                        $categoria !== ''
                            ? $categoria
                            : 'Sin tipo'
                    );
            })
            ->countBy()
            ->map(fn ($total, $tipo) => [
                'tipo' => (string) $tipo,
                'total' => (int) $total,
            ])
            ->sortByDesc('total')
            ->values();
    }

    private function reclamosPorEstado(
        Carbon $inicio,
        Carbon $fin
    ): Collection {
        if (!$this->tablaReclamosDisponible()) {
            return collect();
        }

        return $this->consultaMes(
            'reclamos',
            'fecha_reporte',
            $inicio,
            $fin
        )
            ->pluck('estado')
            ->map(
                fn ($estado) =>
                    trim((string) $estado)
                    ?: 'Sin estado'
            )
            ->countBy()
            ->map(fn ($total, $estado) => [
                'estado' => (string) $estado,
                'total' => (int) $total,
            ])
            ->sortByDesc('total')
            ->values();
    }

    private function reclamosPorDia(
        Carbon $inicio,
        Carbon $fin
    ): Collection {
        if (!$this->tablaReclamosDisponible()) {
            return collect();
        }

        $datos = $this->consultaMes(
            'reclamos',
            'fecha_reporte',
            $inicio,
            $fin
        )
            ->selectRaw(
                'DATE(fecha_reporte) AS fecha, COUNT(*) AS total'
            )
            ->groupByRaw('DATE(fecha_reporte)')
            ->orderBy('fecha')
            ->get()
            ->keyBy('fecha');

        $serie = collect();

        for (
            $dia = $inicio->copy();
            $dia->lt($fin);
            $dia->addDay()
        ) {
            $fecha = $dia->format('Y-m-d');

            $serie->push([
                'fecha' => $fecha,
                'total' => isset($datos[$fecha])
                    ? (int) $datos[$fecha]->total
                    : 0,
            ]);
        }

        return $serie;
    }

    private function rankingDesbordamientos(
        Carbon $inicio,
        Carbon $fin
    ): array {
        $conteos = [];
        $total = 0;
        $sinContenedor = 0;

        if ($this->tablaReclamosDisponible()) {
            $query = $this->consultaMes(
                'reclamos',
                'fecha_reporte',
                $inicio,
                $fin
            );

            $this->aplicarFiltroDesbordamientoReclamo(
                $query
            );

            $total += (clone $query)->count();

            if (
                Schema::hasColumn(
                    'reclamos',
                    'id_contenedor'
                )
            ) {
                $sinContenedor += (clone $query)
                    ->whereNull('id_contenedor')
                    ->count();

                $filas = (clone $query)
                    ->whereNotNull('id_contenedor')
                    ->selectRaw(
                        'id_contenedor, COUNT(*) AS total'
                    )
                    ->groupBy('id_contenedor')
                    ->get();

                foreach ($filas as $fila) {
                    $id = (int) $fila->id_contenedor;

                    $conteos[$id] =
                        ($conteos[$id] ?? 0)
                        + (int) $fila->total;
                }

            } else {
                $sinContenedor += (clone $query)
                    ->count();
            }
        }

        if (
            Schema::hasTable('incidencias') &&
            Schema::hasColumn(
                'incidencias',
                'fecha_reporte'
            ) &&
            Schema::hasColumn(
                'incidencias',
                'tipo'
            )
        ) {
            $query = $this->consultaMes(
                'incidencias',
                'fecha_reporte',
                $inicio,
                $fin
            )
                ->whereRaw(
                    "LOWER(TRIM(tipo)) IN (
                        'desborde',
                        'desbordamiento',
                        'contenedor desbordado'
                    )"
                );

            $total += (clone $query)->count();

            if (
                Schema::hasColumn(
                    'incidencias',
                    'id_contenedor'
                )
            ) {
                $sinContenedor += (clone $query)
                    ->whereNull('id_contenedor')
                    ->count();

                $filas = (clone $query)
                    ->whereNotNull('id_contenedor')
                    ->selectRaw(
                        'id_contenedor, COUNT(*) AS total'
                    )
                    ->groupBy('id_contenedor')
                    ->get();

                foreach ($filas as $fila) {
                    $id = (int) $fila->id_contenedor;

                    $conteos[$id] =
                        ($conteos[$id] ?? 0)
                        + (int) $fila->total;
                }

            } else {
                $sinContenedor += (clone $query)
                    ->count();
            }
        }

        arsort($conteos);

        $ids = array_slice(
            array_keys($conteos),
            0,
            10
        );

        $filas = $ids && Schema::hasTable('contenedores')
            ? DB::table('contenedores')
                ->whereIn('id_contenedor', $ids)
                ->get([
                    'id_contenedor',
                    'codigo',
                    'circuito',
                ])
                ->keyBy('id_contenedor')
            : collect();

        $ranking = collect($ids)
            ->map(function ($id) use (
                $conteos,
                $filas
            ) {
                $contenedor = $filas->get($id);

                return [
                    'id_contenedor' => (int) $id,

                    'codigo' => $contenedor->codigo
                        ?? ('Contenedor #' . $id),

                    'circuito' => $contenedor->circuito
                        ?? 'Sin circuito',

                    'reportes' =>
                        (int) $conteos[$id],
                ];
            })
            ->values();

        return [
            'ranking' => $ranking,
            'total' => $total,
            'sin_contenedor' => $sinContenedor,
        ];
    }

    private function aplicarFiltroDesbordamientoReclamo(
        $query
    ): void {
        $tipo = Schema::hasColumn(
            'reclamos',
            'tipo'
        );

        $categoria = Schema::hasColumn(
            'reclamos',
            'categoria'
        );

        if (!$tipo && !$categoria) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->where(function ($q) use (
            $tipo,
            $categoria
        ) {
            if ($tipo) {
                $q->whereRaw(
                    "LOWER(TRIM(tipo)) IN (
                        'contenedor desbordado',
                        'desborde',
                        'desbordamiento'
                    )"
                );
            }

            if ($categoria) {
                $metodo = $tipo
                    ? 'orWhereRaw'
                    : 'whereRaw';

                $q->{$metodo}(
                    "LOWER(TRIM(categoria)) IN (
                        'contenedor desbordado',
                        'desborde',
                        'desbordamiento'
                    )"
                );
            }
        });
    }

    private function resumenRutas(): array
    {
        $resumen = [
            'total' => 0,
            'pendientes' => 0,
            'vencidas' => 0,
            'hoy' => 0,
            'al_dia' => 0,
            'asignadas' => 0,
            'en_curso' => 0,
        ];

        if (!Schema::hasTable('rutas')) {
            return $resumen;
        }

        $respuesta = app(
            RutaController::class
        )->index();

        $datos = $respuesta->getData(true);

        $rutas = collect(
            $datos['data'] ?? []
        );

        $resumen['total'] = $rutas->count();

        foreach ($rutas as $ruta) {
            $operacion =
                $ruta['estado_operacion']
                ?? 'Pendiente';

            $planificacion =
                $ruta['estado_planificacion']
                ?? 'Pendiente';

            if ($operacion === 'En curso') {
                $resumen['en_curso']++;

            } elseif ($operacion === 'Asignada') {
                $resumen['asignadas']++;

            } elseif ($operacion === 'Pendiente') {
                $resumen['pendientes']++;
            }

            if ($planificacion === 'Vencida') {
                $resumen['vencidas']++;

            } elseif ($planificacion === 'Hoy') {
                $resumen['hoy']++;

            } elseif ($planificacion === 'Al día') {
                $resumen['al_dia']++;
            }
        }

        return $resumen;
    }

    private function resumenRecolecciones(
        Carbon $inicio,
        Carbon $fin
    ): array {
        $vacio = [
            'iniciadas_mes' => 0,
            'finalizadas_mes' => 0,
            'en_curso' => 0,
            'contenedores_levantados_mes' => 0,
            'peso_kg_mes' => 0.0,
            'distancia_planificada_km_mes' => 0.0,
            'tasa_finalizacion_pct' => 0.0,
        ];

        if (!Schema::hasTable('recolecciones')) {
            return $vacio;
        }

        $iniciadas = $this->consultaMes(
            'recolecciones',
            'fecha_inicio',
            $inicio,
            $fin
        );

        $finalizadas = $this->consultaMes(
            'recolecciones',
            'fecha_fin',
            $inicio,
            $fin
        )->where('estado', 'Finalizada');

        $totalInicio = (clone $iniciadas)->count();

        $finalizadasDelMes = (clone $iniciadas)
            ->where('estado', 'Finalizada')
            ->where('fecha_fin', '<', $fin)
            ->count();

        return [
            'iniciadas_mes' => $totalInicio,

            'finalizadas_mes' =>
                (clone $finalizadas)->count(),

            'en_curso' => DB::table('recolecciones')
                ->where('estado', 'En curso')
                ->count(),

            'contenedores_levantados_mes' =>
                (int) (clone $finalizadas)
                    ->sum('contenedores_recolectados'),

            'peso_kg_mes' => round(
                (float) (clone $finalizadas)
                    ->sum('peso_recolectado_kg'),
                1
            ),

            'distancia_planificada_km_mes' => round(
                (float) (clone $finalizadas)
                    ->sum('distancia_km'),
                1
            ),

            'tasa_finalizacion_pct' =>
                $this->porcentaje(
                    $finalizadasDelMes,
                    $totalInicio
                ),
        ];
    }

    private function resumenCamiones(): array
    {
        $resumen = [
            'activos' => 0,
            'disponibles' => 0,
            'recolectando' => 0,
            'con_ruta_asignada' => 0,
            'en_taller' => 0,
        ];

        if (!Schema::hasTable('camiones')) {
            return $resumen;
        }

        $despachados = Schema::hasTable(
            'ruta_despachos'
        )
            ? DB::table('ruta_despachos')
                ->pluck('id_camion')
                ->map(fn ($id) => (int) $id)
                ->flip()
                ->all()
            : [];

        $enCurso = Schema::hasTable('recolecciones')
            ? DB::table('recolecciones')
                ->where('estado', 'En curso')
                ->pluck('id_camion')
                ->map(fn ($id) => (int) $id)
                ->flip()
                ->all()
            : [];

        $camiones = DB::table('camiones')
            ->where('activo', 1)
            ->get([
                'id_camion',
                'estado',
                'id_ruta',
            ]);

        $resumen['activos'] = $camiones->count();

        foreach ($camiones as $camion) {
            $id = (int) $camion->id_camion;

            $estado = strtolower(
                trim((string) $camion->estado)
            );

            $ocupado =
                isset($despachados[$id]) ||
                isset($enCurso[$id]) ||
                $camion->id_ruta !== null;

            if ($ocupado) {
                $resumen['con_ruta_asignada']++;
            }

            if (
                isset($enCurso[$id]) ||
                in_array(
                    $estado,
                    ['recolectando', 'en circuito'],
                    true
                )
            ) {
                $resumen['recolectando']++;

            } elseif ($estado === 'en taller') {
                $resumen['en_taller']++;

            } elseif (
                !$ocupado &&
                in_array(
                    $estado,
                    ['en base', 'disponible'],
                    true
                )
            ) {
                $resumen['disponibles']++;
            }
        }

        return $resumen;
    }

    private function resumenContenedores(): array
    {
        $vacio = [
            'activos' => 0,
            'verde' => 0,
            'amarillo' => 0,
            'rojo' => 0,
        ];

        if (!Schema::hasTable('contenedores')) {
            return $vacio;
        }

        $dias = 'GREATEST(
            0,
            COALESCE(
                DATEDIFF(CURDATE(), ultimo_registro),
                dias_sin_recoleccion,
                0
            )
        )';

        $fila = DB::table('contenedores')
            ->where('activo', 1)
            ->selectRaw("
                COUNT(*) AS activos,

                COALESCE(
                    SUM(
                        CASE WHEN {$dias} <= 2
                        THEN 1 ELSE 0 END
                    ),
                    0
                ) AS verde,

                COALESCE(
                    SUM(
                        CASE WHEN {$dias} BETWEEN 3 AND 5
                        THEN 1 ELSE 0 END
                    ),
                    0
                ) AS amarillo,

                COALESCE(
                    SUM(
                        CASE WHEN {$dias} >= 6
                        THEN 1 ELSE 0 END
                    ),
                    0
                ) AS rojo
            ")
            ->first();

        return [
            'activos' =>
                (int) ($fila->activos ?? 0),

            'verde' =>
                (int) ($fila->verde ?? 0),

            'amarillo' =>
                (int) ($fila->amarillo ?? 0),

            'rojo' =>
                (int) ($fila->rojo ?? 0),
        ];
    }

    private function resumenIncidencias(
        Carbon $inicio,
        Carbon $fin
    ): array {
        $vacio = [
            'total_mes' => 0,
            'abiertas' => 0,
            'resueltas_mes' => 0,
        ];

        if (!Schema::hasTable('incidencias')) {
            return $vacio;
        }

        $vacio['total_mes'] = $this->consultaMes(
            'incidencias',
            'fecha_reporte',
            $inicio,
            $fin
        )->count();

        $vacio['abiertas'] = DB::table('incidencias')
            ->whereRaw(
                "LOWER(TRIM(estado)) NOT IN (
                    'resuelta',
                    'resuelto',
                    'cerrada',
                    'cerrado',
                    'finalizada',
                    'finalizado'
                )"
            )
            ->count();

        if (
            Schema::hasColumn(
                'incidencias',
                'fecha_resolucion'
            )
        ) {
            $vacio['resueltas_mes'] =
                $this->consultaMes(
                    'incidencias',
                    'fecha_resolucion',
                    $inicio,
                    $fin
                )
                    ->whereNotNull(
                        'fecha_resolucion'
                    )
                    ->count();
        }

        return $vacio;
    }

    private function contarTabla(string $tabla): int
    {
        return Schema::hasTable($tabla)
            ? DB::table($tabla)->count()
            : 0;
    }

    private function porcentaje(
        $parte,
        $total
    ): float {
        return (float) $total > 0
            ? round(
                (float) $parte /
                (float) $total * 100,
                1
            )
            : 0.0;
    }

    private function variacionPorcentual(
        $actual,
        $anterior
    ): ?float {
        if ((float) $anterior <= 0) {
            return (float) $actual > 0
                ? null
                : 0.0;
        }

        return round(
            (
                (float) $actual -
                (float) $anterior
            ) / (float) $anterior * 100,
            1
        );
    }
}
