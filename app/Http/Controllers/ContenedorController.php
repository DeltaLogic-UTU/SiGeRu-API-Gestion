<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ContenedorController extends Controller
{
    public function index(Request $request)
    {
        $query = $this->consultaContenedores();

        if (!$request->boolean('incluir_inactivos')) {
            $query->where('c.activo', 1);
        }

        $contenedores = $query
            ->orderBy('c.codigo')
            ->get()
            ->map(fn ($item) => $this->formatearContenedor($item))
            ->values();

        return response()->json([
            'success' => true,
            'data' => $contenedores
        ]);
    }

    public function show(int $id)
    {
        $contenedor = $this->obtenerContenedor($id);

        if (!$contenedor) {
            return response()->json([
                'success' => false,
                'message' => 'Contenedor no encontrado.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatearContenedor($contenedor)
        ]);
    }

    public function catalogos()
    {
        $rutas = DB::table('rutas')
            ->select(
                'id_ruta',
                'circuito',
                'nombre',
                'municipio'
            )
            ->orderBy('municipio')
            ->orderBy('circuito')
            ->get()
            ->map(fn ($ruta) => [
                'id_ruta' => (int) $ruta->id_ruta,
                'circuito' => $ruta->circuito,
                'nombre' => $ruta->nombre,
                'municipio' => $ruta->municipio
            ])
            ->values();

        $tipos = DB::table('tipo_residuo')
            ->select(
                'tipo',
                'descripcion'
            )
            ->orderBy('tipo')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'rutas' => $rutas,
                'tipos' => $tipos,
                'estados' => [
                    'funcional',
                    'roto',
                    'desbordado',
                    'incendiado'           
                ]
            ]
        ]);
    }

    public function resumenCircuitos(Request $request)
    {
        $prioridad = strtolower(
            trim(
                (string) $request->query(
                    'prioridad',
                    ''
                )
            )
        );

        $municipio = strtoupper(
            trim(
                (string) $request->query(
                    'municipio',
                    ''
                )
            )
        );

        $circuito = strtoupper(
            trim(
                (string) $request->query(
                    'circuito',
                    ''
                )
            )
        );

        $query = DB::table('rutas as r')
            ->leftJoin(
                'contenedores as c',
                function ($join) {
                    $join
                        ->on(
                            'c.circuito',
                            '=',
                            'r.circuito'
                        )
                        ->where(
                            'c.activo',
                            '=',
                            1
                        );
                }
            )
            ->select(
                'r.id_ruta',
                'r.circuito',
                'r.municipio'
            )
            ->selectRaw(
                'SUM(CASE
                    WHEN c.id_contenedor IS NOT NULL
                    AND COALESCE(c.dias_sin_recoleccion, 0) <= 2
                    THEN 1 ELSE 0
                END) AS verde'
            )
            ->selectRaw(
                'SUM(CASE
                    WHEN c.id_contenedor IS NOT NULL
                    AND COALESCE(c.dias_sin_recoleccion, 0) BETWEEN 3 AND 5
                    THEN 1 ELSE 0
                END) AS amarillo'
            )
            ->selectRaw(
                'SUM(CASE
                    WHEN c.id_contenedor IS NOT NULL
                    AND COALESCE(c.dias_sin_recoleccion, 0) >= 6
                    THEN 1 ELSE 0
                END) AS rojo'
            );

        if ($municipio !== '') {
            $query->where(
                'r.municipio',
                $municipio
            );
        }

        if ($circuito !== '') {
            $query->where(
                'r.circuito',
                $circuito
            );
        }

        $circuitos = $query
            ->groupBy(
                'r.id_ruta',
                'r.circuito',
                'r.municipio'
            )
            ->orderBy('r.municipio')
            ->orderBy('r.circuito')
            ->get()
            ->map(function ($item) {
                $counts = [
                    'verde' => (int) $item->verde,
                    'amarillo' => (int) $item->amarillo,
                    'rojo' => (int) $item->rojo
                ];

                return [
                    'id_ruta' => (int) $item->id_ruta,
                    'circuito' => $item->circuito,
                    'municipio' => $item->municipio,

                    'total' =>
                        $counts['verde'] +
                        $counts['amarillo'] +
                        $counts['rojo'],

                    'counts' => $counts,
                    'nivel' => $this->nivelCircuito($counts)
                ];
            });

        if (
            in_array(
                $prioridad,
                [
                    'verde',
                    'amarillo',
                    'rojo'
                ],
                true
            )
        ) {
            $circuitos = $circuitos
                ->filter(
                    fn ($item) =>
                        $item['nivel'] === $prioridad
                );
        }

        return response()->json([
            'success' => true,
            'data' => $circuitos->values()
        ]);
    }

    public function store(Request $request)
    {
        $this->normalizarRequest($request);

        $datos = $this->validar($request);

        $dias = array_key_exists(
            'dias_sin_recoleccion',
            $datos
        )
            ? (int) $datos['dias_sin_recoleccion']
            : $this->calcularDias(
                $datos['ultimo_registro'] ?? null
            );

        $id = DB::transaction(function () use ($datos, $dias) {
            $idUbicacion = $this->obtenerOCrearUbicacion($datos);

            $id = DB::table('contenedores')->insertGetId([
                'codigo' => $datos['codigo'],
                'modelo' => $datos['modelo'] ?? null,
                'tipo' => $datos['tipo'],
                'capacidad' => $datos['capacidad'],
                'estado' => $datos['estado'],
                'dias_sin_recoleccion' => $dias,
                'id_ubicacion' => $idUbicacion,
                'circuito' => $datos['circuito'],
                'ultimo_registro' => $datos['ultimo_registro'] ?? null,
                'activo' => 1
            ]);

            $this->registrarHistorial(
                $id,
                null,
                $datos['estado'],
                null,
                $idUbicacion,
                'Alta: Contenedor incorporado al circuito ' .
                $datos['circuito'] .
                '.'
            );

            return $id;
        });

        return response()->json([
            'success' => true,
            'message' => 'Contenedor creado correctamente.',
            'data' => $this->formatearContenedor(
                $this->obtenerContenedor($id)
            )
        ], 201);
    }

    public function update(
        Request $request,
        int $id
    ) {
        $anterior = $this->obtenerContenedor($id);

        if (!$anterior) {
            return response()->json([
                'success' => false,
                'message' => 'Contenedor no encontrado.'
            ], 404);
        }

        if (!(int) $anterior->activo) {
            return response()->json([
                'success' => false,
                'message' =>
                    'No se puede modificar un contenedor dado de baja.'
            ], 422);
        }

        $this->normalizarRequest($request);

        $datos = $this->validar(
            $request,
            $id
        );

        $dias = array_key_exists(
            'dias_sin_recoleccion',
            $datos
        )
            ? (int) $datos['dias_sin_recoleccion']
            : (
                ($datos['ultimo_registro'] ?? null) !==
                $anterior->ultimo_registro
                    ? $this->calcularDias(
                        $datos['ultimo_registro'] ?? null
                    )
                    : (int) $anterior->dias_sin_recoleccion
            );

        DB::transaction(function () use (
            $id,
            $datos,
            $dias,
            $anterior
        ) {
            $idUbicacionNueva =
                $this->obtenerOCrearUbicacion($datos);

            $cambios = [];

            if ($anterior->codigo !== $datos['codigo']) {
                $cambios[] =
                    'Código: ' .
                    $anterior->codigo .
                    ' → ' .
                    $datos['codigo'];
            }

            if (
                ($anterior->modelo ?? '') !==
                ($datos['modelo'] ?? '')
            ) {
                $cambios[] =
                    'Modelo: ' .
                    ($anterior->modelo ?: 'Sin modelo') .
                    ' → ' .
                    ($datos['modelo'] ?: 'Sin modelo');
            }

            if ($anterior->tipo !== $datos['tipo']) {
                $cambios[] =
                    'Tipo: ' .
                    $anterior->tipo .
                    ' → ' .
                    $datos['tipo'];
            }

            if (
                (float) $anterior->capacidad !==
                (float) $datos['capacidad']
            ) {
                $cambios[] =
                    'Capacidad: ' .
                    $anterior->capacidad .
                    ' → ' .
                    $datos['capacidad'] .
                    ' m³';
            }

            if ($anterior->estado !== $datos['estado']) {
                $cambios[] =
                    'Estado: ' .
                    $anterior->estado .
                    ' → ' .
                    $datos['estado'];
            }

            if (
                (int) $anterior->id_ubicacion !==
                (int) $idUbicacionNueva
            ) {
                $ubicacion = $datos['calle'];

                if (!empty($datos['esquina'])) {
                    $ubicacion .=
                        ' y ' .
                        $datos['esquina'];
                }

                $cambios[] =
                    'Ubicación actualizada a ' .
                    $ubicacion;
            }

            if (
                ($anterior->circuito ?? '') !==
                ($datos['circuito'] ?? '')
            ) {
                $cambios[] =
                    'Circuito: ' .
                    ($anterior->circuito ?: 'Sin circuito') .
                    ' → ' .
                    $datos['circuito'];
            }

            if (
                (int) $anterior->dias_sin_recoleccion !==
                $dias
            ) {
                $cambios[] =
                    'Sin recolectar: ' .
                    $anterior->dias_sin_recoleccion .
                    ' → ' .
                    $dias .
                    ' días';
            }

            if (
                ($anterior->ultimo_registro ?? '') !==
                ($datos['ultimo_registro'] ?? '')
            ) {
                $cambios[] =
                    'Último registro: ' .
                    ($anterior->ultimo_registro ?: 'Sin registro') .
                    ' → ' .
                    ($datos['ultimo_registro'] ?: 'Sin registro');
            }

            DB::table('contenedores')
                ->where('id_contenedor', $id)
                ->update([
                    'codigo' => $datos['codigo'],
                    'modelo' => $datos['modelo'] ?? null,
                    'tipo' => $datos['tipo'],
                    'capacidad' => $datos['capacidad'],
                    'estado' => $datos['estado'],
                    'dias_sin_recoleccion' => $dias,
                    'id_ubicacion' => $idUbicacionNueva,
                    'circuito' => $datos['circuito'],
                    'ultimo_registro' =>
                        $datos['ultimo_registro'] ?? null
                ]);

            if (count($cambios)) {
                $cambioEstado =
                    $anterior->estado !== $datos['estado'];

                $cambioUbicacion =
                    (int) $anterior->id_ubicacion !==
                    (int) $idUbicacionNueva;

                $this->registrarHistorial(
                    $id,
                    $cambioEstado
                        ? $anterior->estado
                        : null,
                    $cambioEstado
                        ? $datos['estado']
                        : null,
                    $cambioUbicacion
                        ? (int) $anterior->id_ubicacion
                        : null,
                    $cambioUbicacion
                        ? $idUbicacionNueva
                        : null,
                    'Modificación: ' .
                    implode('. ', $cambios) .
                    '.'
                );
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Contenedor actualizado correctamente.',
            'data' => $this->formatearContenedor(
                $this->obtenerContenedor($id)
            )
        ]);
    }

    public function destroy(int $id)
    {
        $contenedor = $this->obtenerContenedor($id);

        if (!$contenedor) {
            return response()->json([
                'success' => false,
                'message' => 'Contenedor no encontrado.'
            ], 404);
        }

        if (!(int) $contenedor->activo) {
            return response()->json([
                'success' => false,
                'message' =>
                    'El contenedor ya está dado de baja.'
            ], 422);
        }

        DB::transaction(function () use ($id) {
            DB::table('contenedores')
                ->where('id_contenedor', $id)
                ->update([
                    'activo' => 0
                ]);

            $this->registrarHistorial(
                $id,
                null,
                null,
                null,
                null,
                'Baja: Contenedor dado de baja.'
            );
        });

        return response()->json([
            'success' => true,
            'message' =>
                'Contenedor dado de baja correctamente.'
        ]);
    }


public function reactivar(int $id)
{
    $contenedor = $this->obtenerContenedor($id);

    if (!$contenedor) {
        return response()->json([
            'success' => false,
            'message' => 'Contenedor no encontrado.'
        ], 404);
    }

    if ((int) $contenedor->activo) {
        return response()->json([
            'success' => false,
            'message' => 'El contenedor ya está activo.'
        ], 422);
    }

    if ($contenedor->estado === 'incendiado') {
        return response()->json([
            'success' => false,
            'message' =>
                'No se puede reactivar un contenedor incendiado.'
        ], 422);
    }

    DB::transaction(function () use ($id) {
        DB::table('contenedores')
            ->where('id_contenedor', $id)
            ->update([
                'activo' => 1
            ]);

        $this->registrarHistorial(
            $id,
            null,
            null,
            null,
            null,
            'Reactivación: Contenedor habilitado nuevamente.'
        );
    });

    return response()->json([
        'success' => true,
        'message' => 'Contenedor reactivado correctamente.',
        'data' => $this->formatearContenedor(
            $this->obtenerContenedor($id)
        )
    ]);
}
    
public function reemplazar(Request $request, int $id)
{
    $this->normalizarRequest($request);

    $datos = $request->validate([
        'codigo' => [
            'required',
            'string',
            'max:50',
            Rule::unique('contenedores', 'codigo')
        ],
        'modelo' => [
            'nullable',
            'string',
            'max:100'
        ],
        'tipo' => [
            'required',
            'string',
            'max:30',
            Rule::exists('tipo_residuo', 'tipo')
        ],
        'capacidad' => [
            'required',
            'numeric',
            'min:0'
        ]
    ]);

    $resultado = DB::transaction(function () use ($id, $datos) {

        $anterior = DB::table('contenedores')
            ->where('id_contenedor', $id)
            ->lockForUpdate()
            ->first();

        if (!$anterior) {
            return [
                'error' => 'Contenedor no encontrado.',
                'status' => 404
            ];
        }

        if (!(int) $anterior->activo) {
            return [
                'error' => 'El contenedor ya está dado de baja.',
                'status' => 422
            ];
        }

        if ($anterior->estado !== 'incendiado') {
            return [
                'error' => 'Solo se pueden reemplazar contenedores incendiados.',
                'status' => 422
            ];
        }

        $nuevoId = DB::table('contenedores')->insertGetId([
            'codigo' => $datos['codigo'],
            'modelo' => $datos['modelo'] ?? null,
            'tipo' => $datos['tipo'],
            'capacidad' => $datos['capacidad'],
            'estado' => 'funcional',
            'dias_sin_recoleccion' => 0,
            'id_ubicacion' => $anterior->id_ubicacion,
            'circuito' => $anterior->circuito,
            'ultimo_registro' => null,
            'activo' => 1
        ]);

        DB::table('contenedores')
            ->where('id_contenedor', $id)
            ->update([
                'activo' => 0
            ]);

        $this->registrarHistorial(
            $id,
            'incendiado',
            null,
            null,
            null,
            'Reemplazo: Contenedor incendiado dado de baja. ' .
            'Sustituido por ' . $datos['codigo'] .
            ' (ID ' . $nuevoId . ').'
        );

        $this->registrarHistorial(
            $nuevoId,
            null,
            'funcional',
            null,
            $anterior->id_ubicacion !== null
                ? (int) $anterior->id_ubicacion
                : null,
            'Reemplazo: Nuevo contenedor instalado en lugar de ' .
            $anterior->codigo .
            ' (ID ' . $id . ').'
        );

        return [
            'nuevo_id' => $nuevoId
        ];
    });

    if (isset($resultado['error'])) {
        return response()->json([
            'success' => false,
            'message' => $resultado['error']
        ], $resultado['status']);
    }

    return response()->json([
        'success' => true,
        'message' => 'Contenedor reemplazado correctamente.',
        'data' => $this->formatearContenedor(
            $this->obtenerContenedor($resultado['nuevo_id'])
        )
    ], 201);
}


    public function historial(int $id)
    {
        $contenedor = $this->obtenerContenedor($id);

        if (!$contenedor) {
            return response()->json([
                'success' => false,
                'message' => 'Contenedor no encontrado.'
            ], 404);
        }

        $registros = DB::table('historial_contenedores as h')
            ->leftJoin(
                'ubicacion as ua',
                'h.id_ubicacion_anterior',
                '=',
                'ua.id_ubicacion'
            )
            ->leftJoin(
                'ubicacion as un',
                'h.id_ubicacion_nueva',
                '=',
                'un.id_ubicacion'
            )
            ->where('h.id_contenedor', $id)
            ->select(
                'h.id_historial',
                'h.fecha',
                'h.estado_anterior',
                'h.estado_nuevo',
                'h.id_ubicacion_anterior',
                'h.id_ubicacion_nueva',
                'h.motivo',
                'ua.calle as calle_anterior',
                'ua.esquina_1 as esquina_anterior',
                'un.calle as calle_nueva',
                'un.esquina_1 as esquina_nueva'
            )
            ->get()
            ->map(function ($item) {
                return [
                    'id' =>
                        'H-' .
                        $item->id_historial,

                    'fecha' =>
                        $this->formatearFecha(
                            $item->fecha
                        ),

                    'fecha_hora' =>
                        $item->fecha,

                    'tipo' =>
                        $this->tipoHistorial(
                            $item
                        ),

                    'detalle' =>
                        $item->motivo
                        ?: 'Cambio registrado.',

                    'estado' =>
                        'Registrado',

                    'estado_anterior' =>
                        $item->estado_anterior,

                    'estado_nuevo' =>
                        $item->estado_nuevo,

                    'ubicacion_anterior' =>
                        $this->formatearUbicacion(
                            $item->calle_anterior,
                            $item->esquina_anterior
                        ),

                    'ubicacion_nueva' =>
                        $this->formatearUbicacion(
                            $item->calle_nueva,
                            $item->esquina_nueva
                        )
                ];
            })
            ->values()
            ->all();

        $incidencias = DB::table('incidencias')
            ->where('id_contenedor', $id)
            ->where('tipo_recurso', 'contenedor')
            ->select(
                'id_incidencia',
                'tipo',
                'estado',
                'fecha_reporte',
                'fecha_resolucion',
                'descripcion',
                'resolucion'
            )
            ->get()
            ->map(function ($item) {
                return [
                    'id' =>
                        'I-' .
                        $item->id_incidencia,

                    'fecha' =>
                        $this->formatearFecha(
                            $item->fecha_reporte
                        ),

                    'fecha_hora' =>
                        $item->fecha_reporte,

                    'tipo' =>
                        'Incidencia',

                    'detalle' =>
                        $item->descripcion
                        ?: $item->tipo,

                    'estado' =>
                        $item->estado,

                    'tipo_incidencia' =>
                        $item->tipo,

                    'fecha_resolucion' =>
                        $this->formatearFecha(
                            $item->fecha_resolucion
                        ),

                    'resolucion' =>
                        $item->resolucion
                ];
            })
            ->values()
            ->all();

        $recolecciones = DB::table('recoleccion_contenedor')
            ->where('id_contenedor', $id)
            ->select(
                'id_recoleccion',
                'fecha'
            )
            ->get()
            ->map(function ($item) {
                return [
                    'id' =>
                        'R-' .
                        $item->id_recoleccion,

                    'fecha' =>
                        $this->formatearFecha(
                            $item->fecha
                        ),

                    'fecha_hora' =>
                        $item->fecha,

                    'tipo' =>
                        'Recolección',

                    'detalle' =>
                        'Recolección registrada para el contenedor.',

                    'estado' =>
                        'Completado',

                    'id_recoleccion' =>
                        $item->id_recoleccion
                ];
            })
            ->values()
            ->all();

        $historial = array_merge(
            $registros,
            $incidencias,
            $recolecciones
        );

        usort(
            $historial,
            function ($a, $b) {
                return strcmp(
                    $b['fecha_hora'] ?? '',
                    $a['fecha_hora'] ?? ''
                );
            }
        );

        $historial = array_map(
            function ($item) {
                unset(
                    $item['fecha_hora']
                );

                return $item;
            },
            $historial
        );

        return response()->json([
            'success' => true,
            'data' => $historial
        ]);
    }

    private function validar(
        Request $request,
        ?int $id = null
    ): array {
        return $request->validate([
            'codigo' => [
                'required',
                'string',
                'max:50',

                Rule::unique(
                    'contenedores',
                    'codigo'
                )->ignore(
                    $id,
                    'id_contenedor'
                )
            ],

            'modelo' => [
                'nullable',
                'string',
                'max:100'
            ],

            'tipo' => [
                'required',
                'string',
                'max:30',
                Rule::exists(
                    'tipo_residuo',
                    'tipo'
                )
            ],

            'capacidad' => [
                'required',
                'numeric',
                'min:0'
            ],

            'estado' => [
                'required',
                Rule::in([
                    'funcional',
                    'roto',
                    'desbordado',
                    'incendiado'    
                ])
            ],

            'dias_sin_recoleccion' => [
                'nullable',
                'integer',
                'min:0'
            ],

            'municipio' => [
                'required',
                'string',
                'max:100',
                Rule::exists(
                    'rutas',
                    'municipio'
                )
            ],

            'circuito' => [
                'required',
                'string',
                'max:30',

                Rule::exists(
                    'rutas',
                    'circuito'
                )->where(
                    fn ($query) =>
                        $query->where(
                            'municipio',
                            $request->municipio
                        )
                )
            ],

            'ultimo_registro' => [
                'nullable',
                'date'
            ],

            'calle' => [
                'required',
                'string',
                'max:150'
            ],

            'esquina' => [
                'nullable',
                'string',
                'max:150'
            ],

            'esquina_2' => [
                'nullable',
                'string',
                'max:150'
            ],

            'barrio' => [
                'nullable',
                'string',
                'max:100'
            ],

            'descripcion' => [
                'nullable',
                'string'
            ],

            'latitud' => [
                'nullable',
                'numeric',
                'between:-90,90'
            ],

            'longitud' => [
                'nullable',
                'numeric',
                'between:-180,180'
            ]
        ]);
    }

    private function normalizarRequest(
        Request $request
    ): void {
        $datos = [];

        if ($request->has('codigo')) {
            $datos['codigo'] = strtoupper(
                trim(
                    (string) $request->codigo
                )
            );
        }

        if ($request->has('tipo')) {
            $datos['tipo'] = strtolower(
                trim(
                    (string) $request->tipo
                )
            );
        }

        if ($request->has('estado')) {
            $datos['estado'] = strtolower(
                trim(
                    (string) $request->estado
                )
            );
        }

        if ($request->has('circuito')) {
            $datos['circuito'] = strtoupper(
                trim(
                    (string) $request->circuito
                )
            );
        }

        if ($request->has('municipio')) {
            $datos['municipio'] = strtoupper(
                trim(
                    (string) $request->municipio
                )
            );
        }

        foreach (
            [
                'modelo',
                'calle',
                'esquina',
                'esquina_2',
                'barrio',
                'descripcion'
            ] as $campo
        ) {
            if ($request->has($campo)) {
                $valor = trim(
                    (string) $request->{$campo}
                );

                $datos[$campo] =
                    $valor !== ''
                        ? $valor
                        : null;
            }
        }

        $request->merge($datos);
    }

    private function obtenerOCrearUbicacion(
        array $datos
    ): int {
        $calle = trim(
            $datos['calle']
        );

        $esquina1 = isset($datos['esquina'])
            ? trim(
                $datos['esquina']
            )
            : null;

        $esquina2 = isset($datos['esquina_2'])
            ? trim(
                $datos['esquina_2']
            )
            : null;

        $municipio = trim(
            $datos['municipio']
        );

        $barrio = isset($datos['barrio'])
            ? trim(
                $datos['barrio']
            )
            : null;

        $existente = DB::table('ubicacion')
            ->where('calle', $calle)
            ->where(
                'esquina_1',
                $esquina1 ?: null
            )
            ->where(
                'esquina_2',
                $esquina2 ?: null
            )
            ->where(
                'municipio',
                $municipio
            )
            ->where(
                'barrio',
                $barrio ?: null
            )
            ->first();

        if ($existente) {
            $actualizacion = [];

            if (
                array_key_exists(
                    'descripcion',
                    $datos
                )
            ) {
                $actualizacion['descripcion'] =
                    $datos['descripcion'] ?: null;
            }

            if (
                array_key_exists(
                    'latitud',
                    $datos
                )
            ) {
                $actualizacion['latitud'] =
                    $datos['latitud'];
            }

            if (
                array_key_exists(
                    'longitud',
                    $datos
                )
            ) {
                $actualizacion['longitud'] =
                    $datos['longitud'];
            }

            if (count($actualizacion)) {
                DB::table('ubicacion')
                    ->where(
                        'id_ubicacion',
                        $existente->id_ubicacion
                    )
                    ->update($actualizacion);
            }

            return (int) $existente->id_ubicacion;
        }

        return DB::table('ubicacion')
            ->insertGetId([
                'calle' => $calle,
                'esquina_1' => $esquina1 ?: null,
                'esquina_2' => $esquina2 ?: null,
                'municipio' => $municipio,
                'barrio' => $barrio ?: null,
                'descripcion' =>
                    $datos['descripcion'] ?? null,
                'latitud' =>
                    $datos['latitud'] ?? null,
                'longitud' =>
                    $datos['longitud'] ?? null
            ]);
    }

    private function consultaContenedores()
    {
        return DB::table('contenedores as c')
            ->leftJoin(
                'ubicacion as u',
                'c.id_ubicacion',
                '=',
                'u.id_ubicacion'
            )
            ->select(
                'c.id_contenedor',
                'c.codigo',
                'c.modelo',
                'c.tipo',
                'c.capacidad',
                'c.estado',
                'c.dias_sin_recoleccion',
                'c.id_ubicacion',
                'c.circuito',
                'c.ultimo_registro',
                'c.activo',
                'u.calle',
                'u.esquina_1 as esquina',
                'u.esquina_2',
                'u.municipio',
                'u.barrio',
                'u.descripcion',
                'u.latitud',
                'u.longitud'
            );
    }

    private function obtenerContenedor(
        int $id
    ) {
        return $this->consultaContenedores()
            ->where(
                'c.id_contenedor',
                $id
            )
            ->first();
    }

    private function formatearContenedor(
        $item
    ): array {
        $dias = max(
            0,
            (int) (
                $item->dias_sin_recoleccion ?? 0
            )
        );

        return [
            'id_contenedor' =>
                (int) $item->id_contenedor,

            'codigo' =>
                $item->codigo,

            'modelo' =>
                $item->modelo,

            'tipo' =>
                $item->tipo,

            'capacidad' =>
                $item->capacidad !== null
                    ? (float) $item->capacidad
                    : null,

            'estado' =>
                $item->estado,

            'dias_sin_recoleccion' =>
                $dias,

            'nivel_recoleccion' =>
                $this->nivelDesdeDias(
                    $dias
                ),

            'etiqueta_recoleccion' =>
                $this->etiquetaDias(
                    $dias
                ),

            'id_ubicacion' =>
                $item->id_ubicacion !== null
                    ? (int) $item->id_ubicacion
                    : null,

            'circuito' =>
                $item->circuito,

            'ultimo_registro' =>
                $this->formatearFecha(
                    $item->ultimo_registro
                ),

            'activo' =>
                (bool) $item->activo,

            'calle' =>
                $item->calle,

            'esquina' =>
                $item->esquina,

            'esquina_2' =>
                $item->esquina_2,

            'municipio' =>
                $item->municipio,

            'barrio' =>
                $item->barrio,

            'descripcion' =>
                $item->descripcion,

            'latitud' =>
                $item->latitud !== null
                    ? (float) $item->latitud
                    : null,

            'longitud' =>
                $item->longitud !== null
                    ? (float) $item->longitud
                    : null
        ];
    }

    private function registrarHistorial(
        int $idContenedor,
        ?string $estadoAnterior,
        ?string $estadoNuevo,
        ?int $ubicacionAnterior,
        ?int $ubicacionNueva,
        string $motivo
    ): void {
        DB::table('historial_contenedores')
            ->insert([
                'id_contenedor' => $idContenedor,
                'fecha' => now(),
                'estado_anterior' => $estadoAnterior,
                'estado_nuevo' => $estadoNuevo,
                'id_ubicacion_anterior' => $ubicacionAnterior,
                'id_ubicacion_nueva' => $ubicacionNueva,
                'motivo' => $motivo
            ]);
    }

    private function calcularDias(
        ?string $fecha
    ): int {
        if (!$fecha) {
            return 0;
        }

        $registro = Carbon::parse(
            $fecha
        )->startOfDay();

        $hoy = now()->startOfDay();

        if ($registro->greaterThan($hoy)) {
            return 0;
        }

        return (int) $registro
            ->diffInDays($hoy);
    }

    private function nivelDesdeDias(
        int $dias
    ): string {
        if ($dias <= 2) {
            return 'verde';
        }

        if ($dias <= 5) {
            return 'amarillo';
        }

        return 'rojo';
    }

    private function etiquetaDias(
        int $dias
    ): string {
        return $dias .
            ' ' .
            (
                $dias === 1
                    ? 'día'
                    : 'días'
            );
    }

    private function nivelCircuito(
        array $counts
    ): string {
        if ($counts['rojo'] >= 4) {
            return 'rojo';
        }

        if (
            $counts['rojo'] >= 2 ||
            $counts['amarillo'] >= 4
        ) {
            return 'amarillo';
        }

        return 'verde';
    }

    private function tipoHistorial(
        $item
    ): string {
        $motivo = trim(
            (string) (
                $item->motivo ?? ''
            )
        );

        if (str_starts_with($motivo, 'Alta:')) {
            return 'Alta';
        }

        if (str_starts_with($motivo, 'Baja:')) {
            return 'Baja';
        }

        if (str_starts_with($motivo, 'Reactivación:')) {
            return 'Reactivación';
        }
        
        if (str_starts_with($motivo, 'Reemplazo:')) {
            return 'Reemplazo';
        }

        if (str_starts_with($motivo, 'Limpieza:')) {
            return 'Limpieza';
        }

        if (str_starts_with($motivo, 'Mantenimiento:')) {
            return 'Mantenimiento';
        }

        if (str_starts_with($motivo, 'Inspección:')) {
            return 'Inspección';
        }

        if (
            $item->id_ubicacion_anterior &&
            $item->id_ubicacion_nueva &&
            !$item->estado_anterior &&
            !$item->estado_nuevo
        ) {
            return 'Reubicación';
        }

        return 'Modificación';
    }

    private function formatearFecha(
        $fecha
    ): ?string {
        if (!$fecha) {
            return null;
        }

        return substr(
            (string) $fecha,
            0,
            10
        );
    }

    private function formatearUbicacion(
        $calle,
        $esquina
    ): ?string {
        if (!$calle) {
            return null;
        }

        if ($esquina) {
            return $calle .
                ' y ' .
                $esquina;
        }

        return $calle;
    }
}
