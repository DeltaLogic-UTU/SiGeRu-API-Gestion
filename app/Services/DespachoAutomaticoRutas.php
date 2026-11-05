<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class DespachoAutomaticoRutas
{
    private const BASE = [-34.899286, -56.129414];
    private const VERTEDERO = [-34.851019, -56.099007];
    private const INICIO_CALENDARIO = '2026-11-02';
    private const DIAS_CICLO = 14;

    private static function diasPrioridad(
        ?string $frecuencia
    ): int {
        $valor = trim((string) $frecuencia);

        if (preg_match(
            '/^Cada\s+(\d+)\s+d[ií]as?$/iu',
            $valor,
            $m
        )) {
            return max(1, min(30, (int) $m[1]));
        }

        if (preg_match(
            '/^Cada\s+d[ií]a$/iu',
            $valor
        )) {
            return 1;
        }

        return 3;
    }

    private static function ordenarRutasPorFrecuencia(
        Collection $rutas
    ): Collection {
        return $rutas->sort(static function ($a, $b) {
            $diasA = self::diasPrioridad(
                $a->frecuencia ?? null
            );

            $diasB = self::diasPrioridad(
                $b->frecuencia ?? null
            );

            return ($diasA <=> $diasB)
                ?: (
                    (int) $a->id_ruta <=>
                    (int) $b->id_ruta
                );
        })->values();
    }

    public static function programacionRutas(
        Collection $rutas
    ): array {
        $ordenadas = self::ordenarRutasPorFrecuencia(
            $rutas
        );

        $total = $ordenadas->count();
        $programacion = [];

        foreach ($ordenadas as $indice => $ruta) {
            $programacion[(int) $ruta->id_ruta] = intdiv(
                $indice * self::DIAS_CICLO,
                max(1, $total)
            );
        }

        return $programacion;
    }

    public function ejecutar(): array
    {
        foreach ([
            'rutas',
            'circuitos',
            'camiones',
            'chofer',
            'usuarios',
            'contenedores',
            'ubicacion',
            'ruta_despachos',
            'recolecciones',
            'recoleccion_contenedor',
            'camion_asignaciones'
        ] as $tabla) {
            if (!Schema::hasTable($tabla)) {
                throw new RuntimeException(
                    "Falta la tabla {$tabla}."
                );
            }
        }

        $hoy = Carbon::today();

        $ancla = Carbon::parse(
            self::INICIO_CALENDARIO
        )->startOfDay();

        if ($hoy->lt($ancla)) {
            throw new RuntimeException(
                'El calendario automático aún no comenzó.'
            );
        }

        $numeroCiclo = intdiv(
            (int) $ancla->diffInDays($hoy),
            self::DIAS_CICLO
        );

        $inicio = $ancla->copy()->addDays(
            $numeroCiclo * self::DIAS_CICLO
        );

        $fin = $inicio->copy()->addDays(
            self::DIAS_CICLO - 1
        );

        $dia = (int) $inicio->diffInDays($hoy);

        $rutas = DB::table('rutas')
            ->orderBy('id_ruta')
            ->get();

        $programacion = self::programacionRutas($rutas);

        $rutasOrdenadas = self::ordenarRutasPorFrecuencia(
            $rutas
        );

        $total = $rutas->count();
        $porId = $rutas->keyBy('id_ruta');

        $validas = $rutas->pluck('circuito')
            ->map(
                fn ($c) => $this->normalizarCircuito($c)
            )
            ->flip()
            ->all();

        $metaHoy = $this->techoDivision(
            ($dia + 1) * $total,
            self::DIAS_CICLO
        );

        $metaAyer = $this->techoDivision(
            $dia * $total,
            self::DIAS_CICLO
        );

        $cuotaHoy = $metaHoy - $metaAyer;

        $cumplidos = $this->circuitosCumplidos(
            $inicio,
            $hoy,
            $porId
        );

        $reservados = $this->circuitosReservados(
            $porId
        );

        $ocupados = $cumplidos + $reservados;

        $totalOcupados = count(
            array_intersect_key($ocupados, $validas)
        );

        $contadosHoy = $this->circuitosDespachadosHoy(
            $hoy,
            $porId
        );

        $cupos = max(0, min(
            $cuotaHoy - count(
                array_intersect_key(
                    $contadosHoy,
                    $validas
                )
            ),
            $metaHoy - $totalOcupados
        ));

        $asignadas = [];
        $omitidas = [];

        foreach ($rutasOrdenadas as $ruta) {
            if (
                $cupos <= 0 ||
                !$this->hayVehiculoDisponible()
            ) {
                break;
            }

            $idRuta = (int) $ruta->id_ruta;

            $diaProgramado = $programacion[$idRuta] ?? 0;

            if ($diaProgramado > $dia) {
                continue;
            }

            $circuito = $this->normalizarCircuito(
                $ruta->circuito
            );

            if (
                $circuito === '' ||
                isset($ocupados[$circuito])
            ) {
                continue;
            }

            if ($this->rutaOcupada(
                $idRuta,
                $circuito
            )) {
                $reservados[$circuito] = true;
                $ocupados[$circuito] = true;
                continue;
            }

            if (!DB::table('circuitos')
                ->where(
                    'id_circuito',
                    $ruta->circuito
                )
                ->exists()
            ) {
                $omitidas[] = [
                    'circuito' => $circuito,
                    'motivo' =>
                        'El circuito no existe en el catálogo de Gestión.'
                ];

                continue;
            }

            $contenedores = DB::table(
                'contenedores as c'
            )
                ->leftJoin(
                    'ubicacion as u',
                    'u.id_ubicacion',
                    '=',
                    'c.id_ubicacion'
                )
                ->where(
                    'c.circuito',
                    $ruta->circuito
                )
                ->where('c.activo', 1)
                ->select(
                    'c.id_contenedor',
                    'u.latitud',
                    'u.longitud'
                )
                ->orderBy('c.id_contenedor')
                ->get()
                ->all();

            if (!$contenedores) {
                $omitidas[] = [
                    'circuito' => $circuito,
                    'motivo' =>
                        'No tiene contenedores activos.'
                ];

                continue;
            }

            try {
                $ordenados = $this->ordenarContenedores(
                    $contenedores
                );

                $ids = array_map(
                    fn ($c) => (int) $c->id_contenedor,
                    $ordenados
                );

                $puntos = array_merge(
                    [self::BASE],
                    array_map(
                        fn ($c) => [
                            (float) $c->latitud,
                            (float) $c->longitud
                        ],
                        $ordenados
                    ),
                    [
                        self::VERTEDERO,
                        self::BASE
                    ]
                );

                $trazado = $this->trazarPorCalles(
                    $puntos
                );

                $resultado = $this->registrarDespacho(
                    $idRuta,
                    (string) $ruta->circuito,
                    $ids,
                    $trazado['geometria'],
                    $trazado['distanciaKm']
                );

            } catch (Throwable $e) {
                $omitidas[] = [
                    'circuito' => $circuito,
                    'motivo' =>
                        'No se pudo despachar: ' .
                        $e->getMessage()
                ];

                continue;
            }

            if (isset($resultado['asignada'])) {
                $asignadas[] = $resultado['asignada'];
                $ocupados[$circuito] = true;
                $cupos--;

            } elseif (!empty($resultado['motivo'])) {
                $omitidas[] = [
                    'circuito' => $circuito,
                    'motivo' => $resultado['motivo']
                ];
            }
        }

        $totalCumplidos = count(
            array_intersect_key(
                $cumplidos,
                $validas
            )
        );

        return [
            'periodo_inicio' => $inicio->toDateString(),
            'periodo_fin' => $fin->toDateString(),
            'dia_del_ciclo' => $dia + 1,
            'total_circuitos' => $total,
            'cuota_diaria' => $cuotaHoy,

            'circuitos_finalizados_en_periodo' =>
                $totalCumplidos,

            'circuitos_sin_finalizar_en_periodo' =>
                max(0, $total - $totalCumplidos),

            'asignadas' => $asignadas,
            'omitidas' => $omitidas
        ];
    }

    private function techoDivision(
        int $a,
        int $b
    ): int {
        return intdiv($a + $b - 1, $b);
    }

    private function normalizarCircuito(
        $codigo
    ): string {
        return mb_strtoupper(
            trim((string) $codigo),
            'UTF-8'
        );
    }

    private function circuitosCumplidos(
        Carbon $inicio,
        Carbon $hoy,
        $rutas
    ): array {
        $periodo = [
            $inicio->copy()->startOfDay(),
            $hoy->copy()->endOfDay()
        ];

        $cumplidos = [];

        $ids = DB::table('recolecciones')
            ->where('estado', 'Finalizada')
            ->whereBetween('fecha_fin', $periodo)
            ->whereNotNull('id_ruta')
            ->distinct()
            ->pluck('id_ruta');

        foreach ($ids as $id) {
            $ruta = $rutas->get($id);

            if ($ruta) {
                $codigo = $this->normalizarCircuito(
                    $ruta->circuito
                );

                $cumplidos[$codigo] = true;
            }
        }

        $totales = DB::table('contenedores')
            ->where('activo', 1)
            ->select('circuito')
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('circuito')
            ->pluck('total', 'circuito');

        $extra = DB::table('recolecciones as r')
            ->join(
                'recoleccion_contenedor as rc',
                'rc.id_recoleccion',
                '=',
                'r.id_recoleccion'
            )
            ->join(
                'contenedores as c',
                'c.id_contenedor',
                '=',
                'rc.id_contenedor'
            )
            ->where('r.estado', 'Finalizada')
            ->whereBetween('r.fecha_fin', $periodo)
            ->select('c.circuito')
            ->selectRaw(
                'COUNT(DISTINCT rc.id_contenedor) AS levantados'
            )
            ->groupBy('c.circuito')
            ->get();

        foreach ($extra as $fila) {
            $total = (int) (
                $totales->get($fila->circuito) ?? 0
            );

            if (
                $total > 0 &&
                (int) $fila->levantados >= $total
            ) {
                $codigo = $this->normalizarCircuito(
                    $fila->circuito
                );

                $cumplidos[$codigo] = true;
            }
        }

        return $cumplidos;
    }

    private function circuitosReservados(
        $rutas
    ): array {
        $reservados = [];

        $registrarId = function ($id) use (
            $rutas,
            &$reservados
        ) {
            $ruta = $rutas->get($id);

            if ($ruta) {
                $codigo = $this->normalizarCircuito(
                    $ruta->circuito
                );

                $reservados[$codigo] = true;
            }
        };

        $ids = DB::table('camiones')
            ->where('activo', 1)
            ->whereNotNull('id_ruta')
            ->pluck('id_ruta');

        foreach ($ids as $id) {
            $registrarId($id);
        }

        $ids = DB::table('camion_asignaciones')
            ->whereNull('fecha_finalizacion')
            ->whereNotNull('id_ruta')
            ->pluck('id_ruta');

        foreach ($ids as $id) {
            $registrarId($id);
        }

        $ids = DB::table('recolecciones')
            ->where('estado', 'En curso')
            ->whereNotNull('id_ruta')
            ->pluck('id_ruta');

        foreach ($ids as $id) {
            $registrarId($id);
        }

        $despachos = DB::table('ruta_despachos')
            ->get([
                'id_ruta',
                'circuitos'
            ]);

        foreach ($despachos as $d) {
            $registrarId($d->id_ruta);

            foreach (
                $this->leerCircuitos(
                    $d->circuitos
                ) as $codigo
            ) {
                $reservados[$codigo] = true;
            }
        }

        return $reservados;
    }

    private function circuitosDespachadosHoy(
        Carbon $hoy,
        $rutas
    ): array {
        $hoyContados = [];

        $registrarId = function ($id) use (
            $rutas,
            &$hoyContados
        ) {
            $ruta = $rutas->get($id);

            if ($ruta) {
                $codigo = $this->normalizarCircuito(
                    $ruta->circuito
                );

                $hoyContados[$codigo] = true;
            }
        };

        $despachos = DB::table('ruta_despachos')
            ->whereDate(
                'fecha_envio',
                $hoy->toDateString()
            )
            ->get([
                'id_ruta',
                'circuitos'
            ]);

        foreach ($despachos as $d) {
            $registrarId($d->id_ruta);

            foreach (
                $this->leerCircuitos(
                    $d->circuitos
                ) as $codigo
            ) {
                $hoyContados[$codigo] = true;
            }
        }

        $iniciadas = DB::table('recolecciones')
            ->whereDate(
                'fecha_inicio',
                $hoy->toDateString()
            )
            ->whereNotNull('id_ruta')
            ->pluck('id_ruta');

        foreach ($iniciadas as $id) {
            $registrarId($id);
        }

        $extras = DB::table('recolecciones as r')
            ->join(
                'recoleccion_contenedor as rc',
                'rc.id_recoleccion',
                '=',
                'r.id_recoleccion'
            )
            ->join(
                'contenedores as c',
                'c.id_contenedor',
                '=',
                'rc.id_contenedor'
            )
            ->whereDate(
                'r.fecha_inicio',
                $hoy->toDateString()
            )
            ->distinct()
            ->pluck('c.circuito');

        foreach ($extras as $codigo) {
            $hoyContados[
                $this->normalizarCircuito($codigo)
            ] = true;
        }

        return $hoyContados;
    }

    private function leerCircuitos(
        ?string $json
    ): array {
        $datos = json_decode(
            $json ?: '[]',
            true
        );

        if (!is_array($datos)) {
            return [];
        }

        return array_values(
            array_unique(
                array_filter(
                    array_map(
                        fn ($c) => is_string($c)
                            ? $this->normalizarCircuito($c)
                            : '',
                        $datos
                    )
                )
            )
        );
    }

    private function rutaOcupada(
        int $idRuta,
        string $circuito
    ): bool {
        if (
            DB::table('camiones')
                ->where('activo', 1)
                ->where('id_ruta', $idRuta)
                ->exists()

            || DB::table('camion_asignaciones')
                ->whereNull('fecha_finalizacion')
                ->where('id_ruta', $idRuta)
                ->exists()

            || DB::table('recolecciones')
                ->where('estado', 'En curso')
                ->where('id_ruta', $idRuta)
                ->exists()

            || DB::table('ruta_despachos')
                ->where('id_ruta', $idRuta)
                ->exists()
        ) {
            return true;
        }

        $despachos = DB::table('ruta_despachos')
            ->pluck('circuitos');

        foreach ($despachos as $json) {
            if (in_array(
                $circuito,
                $this->leerCircuitos($json),
                true
            )) {
                return true;
            }
        }

        return false;
    }

    private function hayVehiculoDisponible(): bool
    {
        return DB::table('camiones as c')
            ->join(
                'chofer as ch',
                'ch.id_usuario',
                '=',
                'c.id_chofer'
            )
            ->join(
                'usuarios as u',
                'u.id_usuario',
                '=',
                'ch.id_usuario'
            )
            ->where('c.activo', 1)
            ->whereNull('c.id_ruta')
            ->whereIn(
                'c.estado',
                ['En base', 'Disponible']
            )
            ->where('u.estado', 'activo')
            ->whereNotIn(
                'c.id_camion',
                DB::table('ruta_despachos')
                    ->select('id_camion')
            )
            ->whereNotIn(
                'c.id_camion',
                DB::table('recolecciones')
                    ->where('estado', 'En curso')
                    ->select('id_camion')
            )
            ->whereNotIn(
                'c.id_camion',
                DB::table('camion_asignaciones')
                    ->whereNull('fecha_finalizacion')
                    ->whereNotNull('id_ruta')
                    ->select('id_camion')
            )
            ->exists();
    }

    private function ordenarContenedores(
        array $contenedores
    ): array {
        foreach ($contenedores as $c) {
            if (
                !is_numeric($c->latitud) ||
                !is_numeric($c->longitud)
            ) {
                throw new RuntimeException(
                    'Hay contenedores sin coordenadas.'
                );
            }

            $lat = (float) $c->latitud;
            $lng = (float) $c->longitud;

            if (
                abs($lat) > 90 ||
                abs($lng) > 180 ||
                ($lat == 0 && $lng == 0)
            ) {
                throw new RuntimeException(
                    'Hay coordenadas inválidas.'
                );
            }
        }

        $orden = [];
        $pendientes = array_values($contenedores);
        $actual = self::BASE;

        while ($pendientes) {
            $mejor = 0;
            $menor = INF;

            foreach ($pendientes as $i => $c) {
                $dx = (float) $c->latitud -
                    $actual[0];

                $dy = (
                    (float) $c->longitud -
                    $actual[1]
                ) * cos(deg2rad($actual[0]));

                $dist = $dx * $dx + $dy * $dy;

                if ($dist < $menor) {
                    $menor = $dist;
                    $mejor = $i;
                }
            }

            $siguiente = array_splice(
                $pendientes,
                $mejor,
                1
            )[0];

            $orden[] = $siguiente;

            $actual = [
                (float) $siguiente->latitud,
                (float) $siguiente->longitud
            ];
        }

        return $orden;
    }

    private function trazarPorCalles(
        array $puntos
    ): array {
        $geometria = [];
        $metros = 0.0;
        $inicio = microtime(true);

        for (
            $i = 0;
            $i < count($puntos) - 1;
            $i += 14
        ) {
            if (microtime(true) - $inicio > 35) {
                throw new RuntimeException(
                    'Tiempo agotado calculando calles.'
                );
            }

            $tramo = array_slice(
                $puntos,
                $i,
                15
            );

            $coordenadas = implode(
                ';',
                array_map(
                    fn ($p) => $p[1] . ',' . $p[0],
                    $tramo
                )
            );

            $url =
                'https://router.project-osrm.org/route/v1/driving/' .
                $coordenadas;

            $respuesta = Http::acceptJson()
                ->connectTimeout(3)
                ->timeout(7)
                ->get($url, [
                    'overview' => 'full',
                    'geometries' => 'geojson'
                ]);

            if (
                !$respuesta->successful() ||
                $respuesta->json('code') !== 'Ok'
            ) {
                throw new RuntimeException(
                    'OSRM no devolvió un recorrido válido.'
                );
            }

            $ruta = $respuesta->json('routes.0');

            $coords = $ruta['geometry']['coordinates']
                ?? [];

            $distancia = (float) (
                $ruta['distance'] ?? 0
            );

            if (
                count($coords) < 2 ||
                $distancia <= 0
            ) {
                throw new RuntimeException(
                    'Distancia o geometría inválida.'
                );
            }

            $coords = array_map(
                fn ($p) => [
                    (float) $p[1],
                    (float) $p[0]
                ],
                $coords
            );

            array_push(
                $geometria,
                ...(
                    $geometria
                        ? array_slice($coords, 1)
                        : $coords
                )
            );

            $metros += $distancia;
        }

        if (
            count($geometria) < 2 ||
            $metros <= 0
        ) {
            throw new RuntimeException(
                'Recorrido vacío.'
            );
        }

        return [
            'geometria' => $geometria,
            'distanciaKm' => round(
                $metros / 1000,
                2
            )
        ];
    }

    private function registrarDespacho(
        int $idRuta,
        string $circuito,
        array $ids,
        array $geometria,
        float $distanciaKm
    ): array {
        return DB::transaction(function () use (
            $idRuta,
            $circuito,
            $ids,
            $geometria,
            $distanciaKm
        ) {
            $camiones = DB::table('camiones')
                ->where('activo', 1)
                ->orderBy('id_camion')
                ->lockForUpdate()
                ->get();

            $ruta = DB::table('rutas')
                ->where('id_ruta', $idRuta)
                ->lockForUpdate()
                ->first();

            if (
                !$ruta ||
                $this->normalizarCircuito(
                    $ruta->circuito
                ) !==
                $this->normalizarCircuito(
                    $circuito
                )
            ) {
                return [
                    'motivo' =>
                        'La ruta cambió durante la planificación.'
                ];
            }

            if ($this->rutaOcupada(
                $idRuta,
                $this->normalizarCircuito($circuito)
            )) {
                return [
                    'motivo' =>
                        'El circuito fue reservado por otra asignación.'
                ];
            }

            $asignaciones = DB::table(
                'camion_asignaciones'
            )
                ->whereNull('fecha_finalizacion')
                ->whereNotNull('id_ruta')
                ->get();

            $recolecciones = DB::table(
                'recolecciones'
            )
                ->where('estado', 'En curso')
                ->get();

            $despachos = DB::table('ruta_despachos')
                ->get();

            $camionesOcupados = [];
            $choferesOcupados = [];

            foreach ($camiones as $c) {
                if ($c->id_ruta !== null) {
                    $camionesOcupados[
                        (int) $c->id_camion
                    ] = true;

                    if ($c->id_chofer !== null) {
                        $choferesOcupados[
                            (int) $c->id_chofer
                        ] = true;
                    }
                }
            }

            foreach ($asignaciones as $a) {
                $camionesOcupados[
                    (int) $a->id_camion
                ] = true;

                if ($a->id_chofer !== null) {
                    $choferesOcupados[
                        (int) $a->id_chofer
                    ] = true;
                }
            }

            foreach ($despachos as $d) {
                $camionesOcupados[
                    (int) $d->id_camion
                ] = true;

                if ($d->id_chofer !== null) {
                    $choferesOcupados[
                        (int) $d->id_chofer
                    ] = true;
                }
            }

            foreach ($recolecciones as $r) {
                $camionesOcupados[
                    (int) $r->id_camion
                ] = true;

                $camionRecoleccion = $camiones->firstWhere(
                    'id_camion',
                    $r->id_camion
                );

                if (
                    $camionRecoleccion &&
                    $camionRecoleccion->id_chofer !== null
                ) {
                    $choferesOcupados[
                        (int) $camionRecoleccion->id_chofer
                    ] = true;
                }
            }

            $idsChoferes = $camiones
                ->pluck('id_chofer')
                ->filter()
                ->unique()
                ->all();

            $activos = DB::table('chofer as ch')
                ->join(
                    'usuarios as u',
                    'u.id_usuario',
                    '=',
                    'ch.id_usuario'
                )
                ->whereIn(
                    'ch.id_usuario',
                    $idsChoferes
                )
                ->where('u.estado', 'activo')
                ->pluck('ch.id_usuario')
                ->map(fn ($id) => (int) $id)
                ->flip()
                ->all();

            $camion = $camiones->first(
                function ($c) use (
                    $activos,
                    $camionesOcupados,
                    $choferesOcupados
                ) {
                    return $c->id_ruta === null
                        && $c->id_chofer !== null
                        && isset(
                            $activos[
                                (int) $c->id_chofer
                            ]
                        )
                        && !isset(
                            $camionesOcupados[
                                (int) $c->id_camion
                            ]
                        )
                        && !isset(
                            $choferesOcupados[
                                (int) $c->id_chofer
                            ]
                        )
                        && in_array(
                            mb_strtolower(
                                trim((string) $c->estado)
                            ),
                            ['en base', 'disponible'],
                            true
                        );
                }
            );

            if (!$camion) {
                return [
                    'motivo' =>
                        'Sin camión y chofer disponibles.'
                ];
            }

            $validos = DB::table('contenedores')
                ->whereIn('id_contenedor', $ids)
                ->where('activo', 1)
                ->where('circuito', $circuito)
                ->count();

            if ($validos !== count($ids)) {
                return [
                    'motivo' =>
                        'Los contenedores cambiaron durante la planificación.'
                ];
            }

            DB::table('ruta_despachos')->insert([
                'id_camion' => $camion->id_camion,
                'matricula' => $camion->matricula,
                'id_chofer' => $camion->id_chofer,
                'id_ruta' => $idRuta,

                'circuitos' => json_encode(
                    [$circuito],
                    JSON_UNESCAPED_UNICODE
                ),

                'ids_contenedores' => json_encode(
                    $ids
                ),

                'contenedores_procesados' => '{}',

                'geometria_calles' => json_encode(
                    $geometria
                ),

                'distancia_km' => $distanciaKm,
                'km_recorridos' => 0,
                'incidencias_creadas' => 0,
                'estado' => 'Enviada al chofer',
                'fecha_envio' => now(),
                'updated_at' => now()
            ]);

            DB::table('camiones')
                ->where(
                    'id_camion',
                    $camion->id_camion
                )
                ->update([
                    'id_ruta' => $idRuta,

                    'contenedores_asignados' =>
                        count($ids),

                    'contenedores_recolectados' => 0,
                    'km_en_turno' => 0
                ]);

            DB::table('camion_asignaciones')
                ->where(
                    'id_camion',
                    $camion->id_camion
                )
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
                'estado_ruta' => 'Asignada'
            ]);

            return [
                'asignada' => [
                    'circuito' => $circuito,
                    'camion' => $camion->matricula,
                    'chofer' => $camion->id_chofer
                ]
            ];
        }, 3);
    }
}
