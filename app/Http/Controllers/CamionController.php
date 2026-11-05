<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class CamionController extends Controller
{
    public function index()
    {
        $camiones = $this->consultaCamiones()
            ->where('c.activo', true)
            ->orderBy('c.matricula')
            ->get()
            ->map(fn ($item) => $this->formatearCamion($item))
            ->values();

        return response()->json([
            'success' => true,
            'data' => $camiones
        ]);
    }

    public function show(int $id)
    {
        $camion = $this->obtenerCamion($id);

        if (!$camion || !$camion->activo) {
            return response()->json([
                'success' => false,
                'message' => 'Camión no encontrado.'
            ], 404);
        }

        $data = $this->formatearCamion($camion);
        $data['historial'] = $this->obtenerHistorial($id);

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    public function actualizarFoto(Request $request, int $id)
    {
        $camion = DB::table('camiones')
            ->where('id_camion', $id)
            ->where('activo', true)
            ->first();

        if (!$camion) {
            return response()->json([
                'success' => false,
                'message' => 'Camión no encontrado.'
            ], 404);
        }

        if (!Schema::hasColumn('camiones', 'foto')) {
            return response()->json([
                'success' => false,
                'message' => 'La columna foto no existe en camiones.'
            ], 422);
        }

        $datos = $request->validate([
            'foto' => [
                'present',
                'nullable',
                'string',
                'max:8000000'
            ]
        ]);

        $foto = $datos['foto'];

        if ($foto !== null && !preg_match(
            '~\Adata:image/(?:png|jpeg|webp|gif);base64,[A-Za-z0-9+/]+={0,2}\z~i',
            $foto
        )) {
            return response()->json([
                'success' => false,
                'message' => 'La foto debe ser una imagen JPG, PNG, WebP o GIF.'
            ], 422);
        }

        if ($foto !== null) {
            $base64 = substr(
                $foto,
                strpos($foto, ',') + 1
            );

            $contenido = base64_decode($base64, true);

            if (
                $contenido === false ||
                strlen($contenido) > 5 * 1024 * 1024
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'La imagen es inválida o supera los 5 MB.'
                ], 422);
            }
        }

        DB::table('camiones')
            ->where('id_camion', $id)
            ->where('activo', true)
            ->update([
                'foto' => $foto
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Foto del camión actualizada correctamente.'
        ]);
    }

    public function catalogos()
    {
        $tipos = DB::table('tipo_residuo')
            ->orderBy('tipo')
            ->pluck('tipo')
            ->values();

        $choferes = DB::table('chofer as ch')
            ->join(
                'usuarios as u',
                'ch.id_usuario',
                '=',
                'u.id_usuario'
            )
            ->where('u.estado', 'activo')
            ->select(
                'u.id_usuario',
                'u.nombre',
                'u.apellido',
                'ch.licencia'
            )
            ->orderBy('u.apellido')
            ->orderBy('u.nombre')
            ->get();

        $cuadrillas = DB::table('cuadrilla as c')
            ->leftJoin(
                'usuarios as u',
                'c.id_responsable',
                '=',
                'u.id_usuario'
            )
            ->select(
                'c.id_cuadrilla',
                'c.turno',
                'c.estado',
                'c.id_responsable',
                'u.nombre as responsable_nombre',
                'u.apellido as responsable_apellido'
            )
            ->orderBy('c.id_cuadrilla')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'tipos' => $tipos,

                'estados' => [
                    'En base',
                    'En taller',
                    'Disponible',
                    'Recolectando',
                    'En circuito',
                    'Dirigiéndose a vertedero'
                ],

                'turnos' => [
                    'Diurno',
                    'Nocturno'
                ],

                'choferes' => $choferes,
                'cuadrillas' => $cuadrillas
            ]
        ]);
    }

    public function store(Request $request)
    {
        $this->normalizarCamion($request);

        $datos = $this->validarCamion($request);

        if (!empty($datos['foto'])) {
            if (!preg_match(
                '~\Adata:image/(?:png|jpeg|webp|gif);base64,[A-Za-z0-9+/]+={0,2}\z~i',
                $datos['foto']
            )) {
                return response()->json([
                    'success' => false,
                    'message' => 'La foto debe ser una imagen JPG, PNG, WebP o GIF.'
                ], 422);
            }

            $base64 = substr(
                $datos['foto'],
                strpos($datos['foto'], ',') + 1
            );

            $contenido = base64_decode($base64, true);

            if (
                $contenido === false ||
                strlen($contenido) > 5 * 1024 * 1024
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'La imagen es inválida o supera los 5 MB.'
                ], 422);
            }
        }

        if (!empty($datos['id_chofer'])) {
            $error = $this->validarChofer(
                (int) $datos['id_chofer']
            );

            if ($error) {
                return response()->json([
                    'success' => false,
                    'message' => $error
                ], 422);
            }
        }

        $id = DB::transaction(function () use ($datos) {
            $id = DB::table('camiones')->insertGetId([
                'matricula' => $datos['matricula'],
                'tipo' => $datos['tipo'],
                'estado' => $datos['estado'],
                'marca' => $datos['marca'],
                'modelo' => $datos['modelo'],
                'chasis' => $datos['chasis'],
                'km_total' => $datos['km_total'],

                'ult_carga_combustible' =>
                    $datos['ult_carga_combustible'] ?? null,

                'activo' => true,
                'km_en_turno' => 0,
                'tiempo_activo' => '00:00:00',
                'gps' => $datos['gps'] ?? null,

                'capacidad_kg' =>
                    $datos['capacidad_kg'] ?? null,

                'peso_recolectado_kg' => 0,
                'contenedores_asignados' => 0,
                'contenedores_recolectados' => 0,

                'turno' => $datos['turno'] ?? null,
                'id_chofer' => $datos['id_chofer'] ?? null,
                'id_cuadrilla' => null,
                'id_ruta' => null,

                'foto' => $datos['foto'] ?? null
            ]);

            if (!empty($datos['id_chofer'])) {
                DB::table('camion_asignaciones')->insert([
                    'id_camion' => $id,
                    'id_chofer' => $datos['id_chofer'],
                    'id_cuadrilla' => null,
                    'id_ruta' => null,
                    'fecha_asignacion' => now(),
                    'fecha_finalizacion' => null,
                    'estado' => 'Activa',
                    'estado_ruta' => null
                ]);
            }

            return $id;
        });

        return response()->json([
            'success' => true,
            'message' => 'Camión agregado correctamente.',
            'data' => $this->formatearCamion(
                $this->obtenerCamion($id)
            )
        ], 201);
    }

    public function destroy(int $id)
    {
        $camion = DB::table('camiones')
            ->where('id_camion', $id)
            ->where('activo', true)
            ->first();

        if (!$camion) {
            return response()->json([
                'success' => false,
                'message' => 'Camión no encontrado.'
            ], 404);
        }

        DB::transaction(function () use ($id) {
            DB::table('camion_asignaciones')
                ->where('id_camion', $id)
                ->whereNull('fecha_finalizacion')
                ->update([
                    'fecha_finalizacion' => now(),
                    'estado' => 'Finalizada'
                ]);

            DB::table('camiones')
                ->where('id_camion', $id)
                ->update([
                    'activo' => false
                ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Camión dado de baja correctamente.'
        ]);
    }

    public function asignarChofer(
        Request $request,
        int $id
    ) {
        $camion = DB::table('camiones')
            ->where('id_camion', $id)
            ->where('activo', true)
            ->first();

        if (!$camion) {
            return response()->json([
                'success' => false,
                'message' => 'Camión no encontrado.'
            ], 404);
        }

        $datos = $request->validate([
            'id_chofer' => [
                'nullable',
                'integer',
                'exists:chofer,id_usuario'
            ]
        ]);

        $idChofer = isset($datos['id_chofer'])
            ? (int) $datos['id_chofer']
            : null;

        if ($idChofer !== null) {
            $error = $this->validarChofer(
                $idChofer,
                $id
            );

            if ($error) {
                return response()->json([
                    'success' => false,
                    'message' => $error
                ], 422);
            }
        }

        $idChoferActual = $camion->id_chofer !== null
            ? (int) $camion->id_chofer
            : null;

        if ($idChoferActual === $idChofer) {
            return response()->json([
                'success' => true,
                'message' => 'La asignación no tuvo cambios.',
                'data' => $this->formatearCamion(
                    $this->obtenerCamion($id)
                )
            ]);
        }

        $this->actualizarAsignacion(
            $id,
            [
                'id_chofer' => $idChofer
            ]
        );

        return response()->json([
            'success' => true,
            'message' => $idChofer !== null
                ? 'Chofer asignado correctamente.'
                : 'Chofer quitado correctamente.',

            'data' => $this->formatearCamion(
                $this->obtenerCamion($id)
            )
        ]);
    }

    public function asignarCuadrilla(
        Request $request,
        int $id
    ) {
        $camion = DB::table('camiones')
            ->where('id_camion', $id)
            ->where('activo', true)
            ->first();

        if (!$camion) {
            return response()->json([
                'success' => false,
                'message' => 'Camión no encontrado.'
            ], 404);
        }

        $datos = $request->validate([
            'id_cuadrilla' => [
                'nullable',
                'string',
                'max:20',
                'exists:cuadrilla,id_cuadrilla'
            ]
        ]);

        $idCuadrilla = $datos['id_cuadrilla'] ?? null;
        $idCuadrillaActual = $camion->id_cuadrilla ?? null;

        if ($idCuadrillaActual === $idCuadrilla) {
            return response()->json([
                'success' => true,
                'message' => 'La asignación no tuvo cambios.',
                'data' => $this->formatearCamion(
                    $this->obtenerCamion($id)
                )
            ]);
        }

        $this->actualizarAsignacion(
            $id,
            [
                'id_cuadrilla' => $idCuadrilla
            ]
        );

        return response()->json([
            'success' => true,
            'message' => $idCuadrilla !== null
                ? 'Cuadrilla asignada correctamente.'
                : 'Cuadrilla quitada correctamente.',

            'data' => $this->formatearCamion(
                $this->obtenerCamion($id)
            )
        ]);
    }

    public function asignarRuta(Request $request, int $id)
    {
        $datos = $request->validate([
            'id_ruta' => [
                'nullable',
                'integer',
                'exists:rutas,id_ruta'
            ]
        ]);

        $idRuta = isset($datos['id_ruta'])
            ? (int) $datos['id_ruta']
            : null;

        $resultado = DB::transaction(function () use (
            $id,
            $idRuta
        ) {
            $camion = DB::table('camiones')
                ->where('id_camion', $id)
                ->where('activo', true)
                ->lockForUpdate()
                ->first();

            if (!$camion) {
                return [
                    'error' => 'Camión no encontrado.',
                    'status' => 404
                ];
            }

            $idRutaActual = $camion->id_ruta !== null
                ? (int) $camion->id_ruta
                : null;

            $despacho = DB::table('ruta_despachos')
                ->where('id_camion', $id)
                ->lockForUpdate()
                ->first();

            $recoleccionActiva = DB::table('recolecciones')
                ->where('id_camion', $id)
                ->where('estado', 'En curso')
                ->exists();

            if (
                $recoleccionActiva &&
                $idRutaActual !== $idRuta
            ) {
                return [
                    'error' =>
                        'No se puede modificar la ruta mientras el chofer está en recorrido.',
                    'status' => 409
                ];
            }

            if (
                $despacho &&
                $idRuta !== null &&
                (int) $despacho->id_ruta !== $idRuta
            ) {
                return [
                    'error' =>
                        'El camión tiene una ruta despachada. Primero debés quitarla.',
                    'status' => 409
                ];
            }

            if ($idRuta === null && $despacho) {
                $estadoDespacho = mb_strtolower(
                    trim((string) $despacho->estado),
                    'UTF-8'
                );

                if (
                    $recoleccionActiva ||
                    $estadoDespacho !== 'enviada al chofer'
                ) {
                    return [
                        'error' =>
                            'No se puede cancelar este despacho porque ya no está pendiente.',
                        'status' => 409
                    ];
                }

                DB::table('ruta_despachos')
                    ->where('id_camion', $id)
                    ->delete();
            }

            if ($idRutaActual !== $idRuta) {
                $this->actualizarAsignacion(
                    $id,
                    [
                        'id_ruta' => $idRuta
                    ]
                );
            }

            return [
                'message' =>
                    $idRutaActual === $idRuta && !$despacho
                        ? 'La asignación no tuvo cambios.'
                        : (
                            $idRuta === null
                                ? 'Ruta quitada correctamente.'
                                : 'Ruta asignada correctamente.'
                        )
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
            'message' => $resultado['message'],
            'data' => $this->formatearCamion(
                $this->obtenerCamion($id)
            )
        ]);
    }

    public function actualizarEstadoRuta(
        Request $request,
        int $id
    ) {
        $camion = DB::table('camiones')
            ->where('id_camion', $id)
            ->where('activo', true)
            ->first();

        if (!$camion) {
            return response()->json([
                'success' => false,
                'message' => 'Camión no encontrado.'
            ], 404);
        }

        if ($camion->id_ruta === null) {
            return response()->json([
                'success' => false,
                'message' =>
                    'El camión no tiene una ruta asignada.'
            ], 422);
        }

        $datos = $request->validate([
            'estado' => [
                'required',
                Rule::in([
                    'Asignada',
                    'En curso',
                    'Finalizada'
                ])
            ]
        ]);

        $asignacion = DB::table('camion_asignaciones')
            ->where('id_camion', $id)
            ->where('id_ruta', $camion->id_ruta)
            ->whereNull('fecha_finalizacion')
            ->orderByDesc('fecha_asignacion')
            ->first();

        if (!$asignacion) {
            return response()->json([
                'success' => false,
                'message' =>
                    'No existe una asignación activa para esta ruta.'
            ], 422);
        }

        if ($asignacion->estado_ruta === $datos['estado']) {
            return response()->json([
                'success' => true,
                'message' =>
                    'El estado de la ruta no tuvo cambios.',
                'data' => $this->formatearCamion(
                    $this->obtenerCamion($id)
                )
            ]);
        }

        DB::table('camion_asignaciones')
            ->where(
                'id_asignacion',
                $asignacion->id_asignacion
            )
            ->update([
                'estado_ruta' => $datos['estado']
            ]);

        return response()->json([
            'success' => true,
            'message' =>
                'Estado de la ruta actualizado correctamente.',
            'data' => $this->formatearCamion(
                $this->obtenerCamion($id)
            )
        ]);
    }

    private function validarCamion(
        Request $request
    ): array {
        return $request->validate([
            'matricula' => [
                'required',
                'string',
                'max:20',
                'unique:camiones,matricula'
            ],

            'tipo' => [
                'required',
                'string',
                Rule::exists('tipo_residuo', 'tipo')
            ],

            'estado' => [
                'required',
                Rule::in([
                    'En base',
                    'En taller',
                    'Disponible',
                    'Recolectando',
                    'En circuito',
                    'Dirigiéndose a vertedero'
                ])
            ],

            'marca' => [
                'required',
                'string',
                'max:50'
            ],

            'modelo' => [
                'required',
                'string',
                'max:50'
            ],

            'chasis' => [
                'required',
                'string',
                'max:100',
                'unique:camiones,chasis'
            ],

            'km_total' => [
                'required',
                'numeric',
                'min:0'
            ],

            'ult_carga_combustible' => [
                'nullable',
                'date'
            ],

            'gps' => [
                'nullable',
                'string',
                'max:255'
            ],

            'capacidad_kg' => [
                'nullable',
                'numeric',
                'min:0'
            ],

            'turno' => [
                'nullable',
                Rule::in([
                    'Diurno',
                    'Nocturno'
                ])
            ],

            'id_chofer' => [
                'nullable',
                'integer',
                'exists:chofer,id_usuario'
            ],

            'foto' => [
                'nullable',
                'string',
                'max:8000000'
            ]
        ]);
    }

    private function normalizarCamion(
        Request $request
    ): void {
        $datos = [];

        if ($request->has('matricula')) {
            $datos['matricula'] = mb_strtoupper(
                trim((string) $request->matricula)
            );
        }

        if ($request->has('tipo')) {
            $datos['tipo'] = mb_strtolower(
                trim((string) $request->tipo)
            );
        }

        foreach (
            [
                'marca',
                'modelo',
                'chasis',
                'gps'
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

    private function validarChofer(
        int $idChofer,
        ?int $idCamion = null
    ): ?string {
        $activo = DB::table('usuarios')
            ->where('id_usuario', $idChofer)
            ->where('estado', 'activo')
            ->exists();

        if (!$activo) {
            return
                'El chofer seleccionado no está activo.';
        }

        $query = DB::table('camiones')
            ->where('id_chofer', $idChofer)
            ->where('activo', true);

        if ($idCamion !== null) {
            $query->where(
                'id_camion',
                '<>',
                $idCamion
            );
        }

        if ($query->exists()) {
            return
                'El chofer ya está asignado a otro camión.';
        }

        return null;
    }

    private function actualizarAsignacion(
        int $id,
        array $cambios
    ): void {
        DB::transaction(function () use ($id, $cambios) {
            $camion = DB::table('camiones')
                ->where('id_camion', $id)
                ->lockForUpdate()
                ->first();

            if (!$camion) {
                return;
            }

            $asignacionActual = DB::table(
                'camion_asignaciones'
            )
                ->where('id_camion', $id)
                ->whereNull('fecha_finalizacion')
                ->orderByDesc('fecha_asignacion')
                ->first();

            $idChofer = array_key_exists(
                'id_chofer',
                $cambios
            )
                ? $cambios['id_chofer']
                : $camion->id_chofer;

            $idCuadrilla = array_key_exists(
                'id_cuadrilla',
                $cambios
            )
                ? $cambios['id_cuadrilla']
                : $camion->id_cuadrilla;

            $idRuta = array_key_exists(
                'id_ruta',
                $cambios
            )
                ? $cambios['id_ruta']
                : $camion->id_ruta;

            $rutaAnterior = $camion->id_ruta !== null
                ? (int) $camion->id_ruta
                : null;

            $rutaNueva = $idRuta !== null
                ? (int) $idRuta
                : null;

            $cambioRuta =
                array_key_exists('id_ruta', $cambios) &&
                $rutaAnterior !== $rutaNueva;

            if ($rutaNueva === null) {
                $estadoRuta = null;

            } elseif ($cambioRuta) {
                $estadoRuta = 'Asignada';

            } else {
                $estadoRuta =
                    $asignacionActual->estado_ruta
                    ?? 'Asignada';
            }

            DB::table('camion_asignaciones')
                ->where('id_camion', $id)
                ->whereNull('fecha_finalizacion')
                ->update([
                    'fecha_finalizacion' => now(),
                    'estado' => 'Finalizada'
                ]);

            DB::table('camiones')
                ->where('id_camion', $id)
                ->update($cambios);

            if (
                $idChofer !== null ||
                $idCuadrilla !== null ||
                $idRuta !== null
            ) {
                DB::table('camion_asignaciones')->insert([
                    'id_camion' => $id,
                    'id_chofer' => $idChofer,
                    'id_cuadrilla' => $idCuadrilla,
                    'id_ruta' => $idRuta,
                    'fecha_asignacion' => now(),
                    'fecha_finalizacion' => null,
                    'estado' => 'Activa',
                    'estado_ruta' => $estadoRuta
                ]);
            }
        });
    }

    private function consultaCamiones()
    {
        return DB::table('camiones as c')
            ->leftJoin(
                'usuarios as u',
                'c.id_chofer',
                '=',
                'u.id_usuario'
            )
            ->leftJoin(
                'rutas as r',
                'c.id_ruta',
                '=',
                'r.id_ruta'
            )
            ->leftJoin(
                'camion_asignaciones as ca',
                function ($join) {
                    $join
                        ->on(
                            'c.id_camion',
                            '=',
                            'ca.id_camion'
                        )
                        ->whereNull(
                            'ca.fecha_finalizacion'
                        );
                }
            )
            ->select(
                'c.id_camion',
                'c.matricula',
                'c.tipo',
                'c.estado',
                'c.marca',
                'c.modelo',
                'c.chasis',
                'c.km_total',
                'c.ult_carga_combustible',
                'c.activo',
                'c.km_en_turno',
                'c.tiempo_activo',
                'c.gps',
                'c.capacidad_kg',
                'c.peso_recolectado_kg',
                'c.contenedores_asignados',
                'c.contenedores_recolectados',
                'c.turno',
                'c.id_chofer',
                'c.id_cuadrilla',
                'c.id_ruta',
                'c.foto',

                'u.nombre as chofer_nombre',
                'u.apellido as chofer_apellido',

                'r.circuito as ruta_circuito',
                'r.nombre as ruta_nombre',

                'ca.estado_ruta as ruta_estado'
            )
            ->selectSub(function ($query) {
                $query->from(
                    'recolecciones as rec_activa'
                )
                    ->selectRaw('COUNT(*)')
                    ->whereColumn(
                        'rec_activa.id_camion',
                        'c.id_camion'
                    )
                    ->where(
                        'rec_activa.estado',
                        'En curso'
                    );
            }, 'recolecciones_activas');
    }

    private function obtenerCamion(int $id)
    {
        return $this->consultaCamiones()
            ->where('c.id_camion', $id)
            ->first();
    }

    private function formatearCamion($item): array
    {
        $chofer = trim(
            implode(
                ' ',
                array_filter([
                    $item->chofer_nombre,
                    $item->chofer_apellido
                ])
            )
        );

        return [
            'id_camion' => (int) $item->id_camion,
            'matricula' => $item->matricula,
            'foto' => $item->foto ?? null,
            'tipo' => $item->tipo,
            'estado' => $item->estado,

            'recorrido_activo' =>
                (int) (
                    $item->recolecciones_activas ?? 0
                ) > 0,

            'estado_operativo' =>
                (int) (
                    $item->recolecciones_activas ?? 0
                ) > 0
                    ? 'En recorrido'
                    : 'En base',

            'marca' => $item->marca,
            'modelo' => $item->modelo,
            'chasis' => $item->chasis,
            'km_total' => (float) $item->km_total,

            'ult_carga_combustible' =>
                $item->ult_carga_combustible
                    ? substr(
                        (string) $item->ult_carga_combustible,
                        0,
                        10
                    )
                    : null,

            'activo' => (bool) $item->activo,
            'km_en_turno' => (float) $item->km_en_turno,
            'tiempo_activo' => $item->tiempo_activo,
            'gps' => $item->gps,

            'capacidad_kg' =>
                $item->capacidad_kg !== null
                    ? (float) $item->capacidad_kg
                    : null,

            'peso_recolectado_kg' =>
                $item->peso_recolectado_kg !== null
                    ? (float) $item->peso_recolectado_kg
                    : 0,

            'contenedores_asignados' =>
                $item->contenedores_asignados !== null
                    ? (int) $item->contenedores_asignados
                    : 0,

            'contenedores_recolectados' =>
                $item->contenedores_recolectados !== null
                    ? (int) $item->contenedores_recolectados
                    : 0,

            'turno' => $item->turno,

            'id_chofer' =>
                $item->id_chofer !== null
                    ? (int) $item->id_chofer
                    : null,

            'chofer' =>
                $chofer !== ''
                    ? $chofer
                    : null,

            'id_cuadrilla' => $item->id_cuadrilla,

            'id_ruta' =>
                $item->id_ruta !== null
                    ? (int) $item->id_ruta
                    : null,

            'ruta_circuito' => $item->ruta_circuito,
            'ruta_nombre' => $item->ruta_nombre,
            'ruta_estado' => $item->ruta_estado ?? null
        ];
    }

    private function obtenerHistorial(int $id)
    {
        $incidencias = DB::table('incidencias')
            ->where('id_camion', $id)
            ->where('tipo_recurso', 'camion')
            ->select(
                'id_incidencia',
                'tipo',
                'estado',
                'fecha_reporte',
                'descripcion',
                'responsable',
                'resolucion'
            )
            ->get()
            ->map(fn ($item) => [
                'id_historial' =>
                    'incidencia-' . $item->id_incidencia,

                'origen' => 'incidencia',
                'id_registro' => (int) $item->id_incidencia,
                'fecha' => $item->fecha_reporte,
                'tipo' => $item->tipo,
                'detalle' => $item->descripcion,
                'estado' => $item->estado,
                'responsable' => $item->responsable,
                'resolucion' => $item->resolucion
            ]);

        $mantenimientos = DB::table('mantenimientos')
            ->where('id_camion', $id)
            ->select(
                'id_mantenimiento',
                'estado',
                'fecha_inicio',
                'fecha_fin',
                'descripcion',
                'tipo',
                'responsable'
            )
            ->get()
            ->map(fn ($item) => [
                'id_historial' =>
                    'mantenimiento-' . $item->id_mantenimiento,

                'origen' => 'mantenimiento',
                'id_registro' => (int) $item->id_mantenimiento,
                'fecha' => $item->fecha_inicio,
                'tipo' => $item->tipo ?: 'Mantenimiento',
                'detalle' => $item->descripcion,

                'estado' =>
                    $item->estado
                    ?: (
                        $item->fecha_fin
                            ? 'Resuelta'
                            : 'Abierta'
                    ),

                'responsable' => $item->responsable,
                'resolucion' => null
            ]);

        return $incidencias
            ->concat($mantenimientos)
            ->sortByDesc('fecha')
            ->values();
    }

    public function mapaPublico()
    {
    $camiones = DB::table('camiones as c')
        ->where('c.activo', 1)
        ->whereNotNull('c.gps')
        ->whereExists(function ($consulta) {
            $consulta->selectRaw('1')
                ->from('recolecciones as r')
                ->whereColumn('r.id_camion', 'c.id_camion')
                ->where('r.estado', 'En curso');
        })
        ->select('c.gps')
        ->get();

    $posiciones = [];

    foreach ($camiones as $camion) {
        $partes = array_map(
            'trim',
            explode(',', (string) $camion->gps)
        );

        if (
            count($partes) !== 2 ||
            !is_numeric($partes[0]) ||
            !is_numeric($partes[1])
        ) {
            continue;
        }

        $latitud = (float) $partes[0];
        $longitud = (float) $partes[1];

        if (
            abs($latitud) > 90 ||
            abs($longitud) > 180 ||
            ($latitud === 0.0 && $longitud === 0.0)
        ) {
            continue;
        }

        $posiciones[] = [
            'latitud' => $latitud,
            'longitud' => $longitud
        ];
    }

    return response()->json([
        'success' => true,
        'data' => $posiciones
    ]);
    }
}
