<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CircuitoController extends Controller
{
    public function index()
    {
        $conteos = DB::table('contenedores')
            ->where('activo', 1)
            ->select('circuito', DB::raw('count(*) as total'))
            ->groupBy('circuito')
            ->pluck('total', 'circuito');

        $circuitos = DB::table('circuitos')
            ->orderBy('id_circuito')
            ->get()
            ->map(function ($item) use ($conteos) {
                $coordenadas = null;

                if (!empty($item->coordenadas ?? null)) {
                    $decodificado = json_decode($item->coordenadas, true);

                    if (is_array($decodificado)) {
                        $coordenadas = $decodificado;
                    }
                }

                return [
                    'id_circuito' => $item->id_circuito,
                    'municipio' => $item->municipio ?? null,
                    'zona' => $item->zona ?? null,
                    'descripcion' => $item->descripcion ?? null,
                    'estado' => $item->estado ?? 'activo',
                    'coordenadas' => $coordenadas,
                    'total_contenedores' => (int) ($conteos[$item->id_circuito] ?? 0)
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $circuitos
        ]);
    }

    public function store(Request $request)
    {
        return $this->guardarCircuito($request, null);
    }

    public function update(Request $request, string $id)
    {
        return $this->guardarCircuito($request, $id);
    }

    private function guardarCircuito(Request $request, ?string $idOriginal = null)
    {
        $validated = $request->validate([
            'id_circuito' => 'required|string|max:30',
            'municipio' => 'nullable|string|max:100',
            'zona' => 'nullable|string|max:100',
            'descripcion' => 'nullable|string',
            'coordenadas' => 'required'
        ]);

        $codigo = strtoupper(trim($validated['id_circuito']));

        $idOriginalNormalizado = $idOriginal !== null
            ? strtoupper(trim($idOriginal))
            : null;

        $municipio = !empty($validated['municipio'])
            ? strtoupper(trim($validated['municipio']))
            : null;

        if (!$municipio && str_contains($codigo, '_')) {
            $municipio = explode('_', $codigo)[0];
        }

        if (!$municipio) {
            $municipio = 'CH';
        }

        $zona = isset($validated['zona']) &&
            trim((string) $validated['zona']) !== ''
                ? trim((string) $validated['zona'])
                : null;

        $descripcion = isset($validated['descripcion']) &&
            trim((string) $validated['descripcion']) !== ''
                ? trim((string) $validated['descripcion'])
                : null;

        $coordenadasRecibidas =
            is_array($validated['coordenadas'])
                ? $validated['coordenadas']
                : json_decode(
                    $validated['coordenadas'],
                    true
                );

        if (!is_array($coordenadasRecibidas)) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Las coordenadas del circuito no tienen un formato válido.'
            ], 422);
        }

        $poligonos =
            $this->normalizarPoligonos(
                $coordenadasRecibidas
            );

        if (empty($poligonos)) {
            return response()->json([
                'success' => false,
                'message' =>
                    'El circuito debe tener al menos un polígono válido con 3 puntos.'
            ], 422);
        }

        $coordenadasParaGuardar =
            count($poligonos) === 1
                ? $poligonos[0]
                : $poligonos;

        if (
            !Schema::hasColumn(
                'circuitos',
                'coordenadas'
            )
        ) {
            return response()->json([
                'success' => false,
                'message' =>
                    'La base aún no tiene la columna coordenadas. Aplicá el parche SQL de SiGeRu.'
            ], 422);
        }

        DB::transaction(function () use (
            $codigo,
            $idOriginalNormalizado,
            $municipio,
            $zona,
            $descripcion,
            $poligonos,
            $coordenadasParaGuardar
        ) {


            $datosCircuito = [
                'coordenadas' => json_encode(
                    $coordenadasParaGuardar,
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES
                )
            ];

            if (
                Schema::hasColumn(
                    'circuitos',
                    'municipio'
                )
            ) {
                $datosCircuito['municipio'] =
                    $municipio;
            }

            if (
                Schema::hasColumn(
                    'circuitos',
                    'zona'
                )
            ) {
                $datosCircuito['zona'] =
                    $zona;
            }

            if (
                Schema::hasColumn(
                    'circuitos',
                    'descripcion'
                )
            ) {
                $datosCircuito['descripcion'] =
                    $descripcion;
            }

            if (
                Schema::hasColumn(
                    'circuitos',
                    'estado'
                )
            ) {
                $datosCircuito['estado'] =
                    'activo';
            }

            DB::table('circuitos')
                ->updateOrInsert(
                    [
                        'id_circuito' =>
                            $codigo
                    ],
                    $datosCircuito
                );


            $datosRuta = [
                'municipio' =>
                    $municipio
            ];

            if (
                Schema::hasColumn(
                    'rutas',
                    'nombre'
                )
            ) {
                $datosRuta['nombre'] =
                    $zona ?: "Circuito $codigo";
            }

            if (
                Schema::hasColumn(
                    'rutas',
                    'zona'
                )
            ) {
                $datosRuta['zona'] =
                    $zona;
            }

            if (
                Schema::hasColumn(
                    'rutas',
                    'descripcion'
                )
            ) {
                $datosRuta['descripcion'] =
                    $descripcion;
            }

            if (
                Schema::hasColumn(
                    'rutas',
                    'frecuencia'
                )
            ) {
                $datosRuta['frecuencia'] =
                    'Cada 3 días';
            }

            if (
                Schema::hasColumn(
                    'rutas',
                    'estado'
                )
            ) {
                $datosRuta['estado'] =
                    'Asignada';
            }

            DB::table('rutas')
                ->updateOrInsert(
                    [
                        'circuito' =>
                            $codigo
                    ],
                    $datosRuta
                );


            if (
                $idOriginalNormalizado &&
                $idOriginalNormalizado !== $codigo
            ) {

                DB::table('contenedores')
                    ->where(
                        'circuito',
                        $idOriginalNormalizado
                    )
                    ->update([
                        'circuito' =>
                            $codigo
                    ]);

                $rutasViejas =
                    DB::table('rutas')
                        ->where(
                            'circuito',
                            $idOriginalNormalizado
                        )
                        ->pluck('id_ruta')
                        ->toArray();

                $rutaNuevaId =
                    DB::table('rutas')
                        ->where(
                            'circuito',
                            $codigo
                        )
                        ->value('id_ruta');

                if (
                    !empty($rutasViejas) &&
                    $rutaNuevaId
                ) {

                    if (
                        Schema::hasTable('camiones') &&
                        Schema::hasColumn(
                            'camiones',
                            'id_ruta'
                        )
                    ) {
                        DB::table('camiones')
                            ->whereIn(
                                'id_ruta',
                                $rutasViejas
                            )
                            ->update([
                                'id_ruta' =>
                                    $rutaNuevaId
                            ]);
                    }

                    if (
                        Schema::hasTable(
                            'camion_asignaciones'
                        ) &&
                        Schema::hasColumn(
                            'camion_asignaciones',
                            'id_ruta'
                        )
                    ) {
                        DB::table(
                            'camion_asignaciones'
                        )
                            ->whereIn(
                                'id_ruta',
                                $rutasViejas
                            )
                            ->update([
                                'id_ruta' =>
                                    $rutaNuevaId
                            ]);
                    }

                    if (
                        Schema::hasTable(
                            'cuadrilla_rutas'
                        ) &&
                        Schema::hasColumn(
                            'cuadrilla_rutas',
                            'id_ruta'
                        )
                    ) {
                        DB::table(
                            'cuadrilla_rutas'
                        )
                            ->whereIn(
                                'id_ruta',
                                $rutasViejas
                            )
                            ->update([
                                'id_ruta' =>
                                    $rutaNuevaId
                            ]);
                    }

                    if (
                        Schema::hasTable(
                            'recolecciones'
                        ) &&
                        Schema::hasColumn(
                            'recolecciones',
                            'id_ruta'
                        )
                    ) {
                        DB::table(
                            'recolecciones'
                        )
                            ->whereIn(
                                'id_ruta',
                                $rutasViejas
                            )
                            ->update([
                                'id_ruta' =>
                                    $rutaNuevaId
                            ]);
                    }

                    if (
                        Schema::hasTable(
                            'ruta_despachos'
                        ) &&
                        Schema::hasColumn(
                            'ruta_despachos',
                            'id_ruta'
                        )
                    ) {
                        DB::table(
                            'ruta_despachos'
                        )
                            ->whereIn(
                                'id_ruta',
                                $rutasViejas
                            )
                            ->update([
                                'id_ruta' =>
                                    $rutaNuevaId
                            ]);
                    }

                    DB::table('rutas')
                        ->whereIn(
                            'id_ruta',
                            $rutasViejas
                        )
                        ->delete();
                }

                DB::table('circuitos')
                    ->where(
                        'id_circuito',
                        $idOriginalNormalizado
                    )
                    ->delete();
            }


            $contenedoresConUbicacion =
                DB::table(
                    'contenedores as c'
                )
                    ->join(
                        'ubicacion as u',
                        'c.id_ubicacion',
                        '=',
                        'u.id_ubicacion'
                    )
                    ->whereNotNull(
                        'u.latitud'
                    )
                    ->whereNotNull(
                        'u.longitud'
                    )
                    ->get([
                        'c.id_contenedor',
                        'c.circuito',
                        'u.latitud',
                        'u.longitud'
                    ]);

            foreach (
                $contenedoresConUbicacion
                as $cont
            ) {

                $lat =
                    (float) $cont->latitud;

                $lng =
                    (float) $cont->longitud;

                if (
                    $this
                        ->puntoDentroDePoligonos(
                            $lat,
                            $lng,
                            $poligonos
                        )
                ) {

                    if (
                        (string) $cont->circuito !==
                        $codigo
                    ) {
                        DB::table(
                            'contenedores'
                        )
                            ->where(
                                'id_contenedor',
                                $cont->id_contenedor
                            )
                            ->update([
                                'circuito' =>
                                    $codigo
                            ]);
                    }

                    continue;
                }

                $circuitoActual =
                    strtoupper(
                        trim(
                            (string) (
                                $cont->circuito ?? ''
                            )
                        )
                    );

                if (
                    $circuitoActual ===
                    $codigo
                ) {

                    $otroCircuito =
                        $this
                            ->detectarOtroCircuito(
                                $lat,
                                $lng,
                                $codigo
                            );

                    DB::table(
                        'contenedores'
                    )
                        ->where(
                            'id_contenedor',
                            $cont->id_contenedor
                        )
                        ->update([
                            'circuito' =>
                                $otroCircuito
                        ]);
                }
            }
        });

        $totalContenedores =
            DB::table('contenedores')
                ->where(
                    'circuito',
                    $codigo
                )
                ->where(
                    'activo',
                    1
                )
                ->count();

        return response()->json([
            'success' => true,
            'message' =>
                'Circuito guardado correctamente.',
            'data' => [
                'id_circuito' =>
                    $codigo,

                'municipio' =>
                    $municipio,

                'zona' =>
                    $zona,

                'descripcion' =>
                    $descripcion,

                'coordenadas' =>
                    $coordenadasParaGuardar,

                'total_contenedores' =>
                    $totalContenedores,

                'estado' =>
                    'activo'
            ]
        ], $idOriginalNormalizado ? 200 : 201);
    }

    public function show(string $id)
    {
        $circuito =
            DB::table('circuitos')
                ->where(
                    'id_circuito',
                    $id
                )
                ->first();

        if (!$circuito) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Circuito no encontrado.'
            ], 404);
        }

        $coordenadas = null;

        if (
            !empty(
                $circuito->coordenadas ?? null
            )
        ) {

            $decodificado =
                json_decode(
                    $circuito->coordenadas,
                    true
                );

            if (
                is_array(
                    $decodificado
                )
            ) {
                $coordenadas =
                    $decodificado;
            }
        }

        $totalContenedores =
            DB::table('contenedores')
                ->where(
                    'circuito',
                    $circuito->id_circuito
                )
                ->where(
                    'activo',
                    1
                )
                ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'id_circuito' =>
                    $circuito->id_circuito,

                'municipio' =>
                    $circuito->municipio
                    ?? null,

                'zona' =>
                    $circuito->zona
                    ?? null,

                'descripcion' =>
                    $circuito->descripcion
                    ?? null,

                'estado' =>
                    $circuito->estado
                    ?? 'activo',

                'coordenadas' =>
                    $coordenadas,

                'total_contenedores' =>
                    $totalContenedores
            ]
        ]);
    }

    private function normalizarPoligonos(
        array $coordenadas
    ): array {

        $poligonos = [];

        $this->extraerPoligonos(
            $coordenadas,
            $poligonos
        );

        return $poligonos;
    }

    private function extraerPoligonos(
        array $nodo,
        array &$poligonos
    ): void {

        if (empty($nodo)) {
            return;
        }

        $primerPunto =
            $this->normalizarPunto(
                $nodo[0] ?? null
            );

        if ($primerPunto !== null) {

            $poligono = [];

            foreach (
                $nodo as $punto
            ) {

                $normalizado =
                    $this->normalizarPunto(
                        $punto
                    );

                if (
                    $normalizado !== null
                ) {
                    $poligono[] =
                        $normalizado;
                }
            }

            if (
                count($poligono) >= 3
            ) {
                $poligonos[] =
                    $poligono;
            }

            return;
        }

        foreach (
            $nodo as $hijo
        ) {

            if (
                is_array($hijo)
            ) {
                $this
                    ->extraerPoligonos(
                        $hijo,
                        $poligonos
                    );
            }
        }
    }

    private function normalizarPunto(
        $punto
    ): ?array {

        if (!is_array($punto)) {
            return null;
        }

        if (
            array_key_exists(
                'lat',
                $punto
            ) &&
            array_key_exists(
                'lng',
                $punto
            ) &&
            is_numeric(
                $punto['lat']
            ) &&
            is_numeric(
                $punto['lng']
            )
        ) {
            return [
                (float) $punto['lat'],
                (float) $punto['lng']
            ];
        }

        if (
            array_key_exists(
                0,
                $punto
            ) &&
            array_key_exists(
                1,
                $punto
            ) &&
            is_numeric(
                $punto[0]
            ) &&
            is_numeric(
                $punto[1]
            )
        ) {
            return [
                (float) $punto[0],
                (float) $punto[1]
            ];
        }

        return null;
    }

    private function puntoDentroDePoligonos(
        float $lat,
        float $lng,
        array $poligonos
    ): bool {

        foreach (
            $poligonos as $poligono
        ) {

            if (
                $this
                    ->puntoDentroDePoligonoSimple(
                        $lat,
                        $lng,
                        $poligono
                    )
            ) {
                return true;
            }
        }

        return false;
    }

    private function puntoDentroDePoligonoSimple(
        float $lat,
        float $lng,
        array $poligono
    ): bool {

        $total =
            count($poligono);

        if ($total < 3) {
            return false;
        }

        $dentro = false;

        $j =
            $total - 1;

        for (
            $i = 0;
            $i < $total;
            $i++
        ) {

            $latI =
                (float) (
                    $poligono[$i][0]
                    ?? 0
                );

            $lngI =
                (float) (
                    $poligono[$i][1]
                    ?? 0
                );

            $latJ =
                (float) (
                    $poligono[$j][0]
                    ?? 0
                );

            $lngJ =
                (float) (
                    $poligono[$j][1]
                    ?? 0
                );

            $cruza =
                (
                    ($lngI > $lng) !==
                    ($lngJ > $lng)
                )
                &&
                (
                    $lat <
                    ($latJ - $latI)
                    *
                    ($lng - $lngI)
                    /
                    (
                        ($lngJ - $lngI)
                        ?: 0.0000001
                    )
                    +
                    $latI
                );

            if ($cruza) {
                $dentro =
                    !$dentro;
            }

            $j = $i;
        }

        return $dentro;
    }

    private function detectarOtroCircuito(
        float $lat,
        float $lng,
        string $excluirCodigo
    ): ?string {

        $circuitos =
            DB::table('circuitos')
                ->where(
                    'id_circuito',
                    '<>',
                    $excluirCodigo
                )
                ->whereNotNull(
                    'coordenadas'
                )
                ->orderBy(
                    'id_circuito'
                )
                ->get([
                    'id_circuito',
                    'coordenadas'
                ]);

        foreach (
            $circuitos as $circuito
        ) {

            $coordenadas =
                json_decode(
                    $circuito->coordenadas,
                    true
                );

            if (
                !is_array(
                    $coordenadas
                )
            ) {
                continue;
            }

            $poligonos =
                $this
                    ->normalizarPoligonos(
                        $coordenadas
                    );

            if (
                $this
                    ->puntoDentroDePoligonos(
                        $lat,
                        $lng,
                        $poligonos
                    )
            ) {
                return
                    $circuito->id_circuito;
            }
        }

        return null;
    }
}