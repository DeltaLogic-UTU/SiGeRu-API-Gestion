<?php

namespace App\Http\Controllers;

use App\Services\DespachoAutomaticoRutas;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RutaController extends Controller
{
    private const INICIO_CALENDARIO = '2026-11-02';
    private const DIAS_CICLO = 14;

    public function index()
    {
        $hoy = Carbon::today();

        $ancla = Carbon::parse(
            self::INICIO_CALENDARIO
        )->startOfDay();

        $transcurridos = $hoy->gte($ancla)
            ? (int) $ancla->diffInDays($hoy)
            : 0;

        $numeroCiclo = intdiv(
            $transcurridos,
            self::DIAS_CICLO
        );

        $inicioCiclo = $ancla->copy()->addDays(
            $numeroCiclo * self::DIAS_CICLO
        );

        $finCiclo = $inicioCiclo->copy()->addDays(
            self::DIAS_CICLO - 1
        );

        $rutas = DB::table('rutas as r')
            ->leftJoin(
                'contenedores as c',
                function ($join) {
                    $join->on(
                        'c.circuito',
                        '=',
                        'r.circuito'
                    )->where('c.activo', '=', 1);
                }
            )
            ->select(
                'r.id_ruta',
                'r.circuito',
                'r.nombre',
                'r.municipio',
                'r.zona',
                'r.descripcion',
                'r.frecuencia',
                'r.estado'
            )
            ->selectRaw(
                'COUNT(c.id_contenedor) AS contenedores'
            )
            ->groupBy(
                'r.id_ruta',
                'r.circuito',
                'r.nombre',
                'r.municipio',
                'r.zona',
                'r.descripcion',
                'r.frecuencia',
                'r.estado'
            )
            ->orderBy('r.municipio')
            ->orderBy('r.circuito')
            ->get();

        $diasProgramados =
            DespachoAutomaticoRutas::programacionRutas(
                $rutas
            );

        $ultimas = DB::table('recolecciones')
            ->whereNotNull('id_ruta')
            ->where('estado', 'Finalizada')
            ->select('id_ruta')
            ->selectRaw(
                'MAX(COALESCE(fecha_fin, fecha_inicio)) AS fecha'
            )
            ->groupBy('id_ruta')
            ->pluck('fecha', 'id_ruta');

        $ultimasCircuito = DB::table(
            'recolecciones as rec'
        )
            ->join(
                'recoleccion_contenedor as rc',
                'rc.id_recoleccion',
                '=',
                'rec.id_recoleccion'
            )
            ->join(
                'contenedores as c',
                'c.id_contenedor',
                '=',
                'rc.id_contenedor'
            )
            ->where('rec.estado', 'Finalizada')
            ->select('c.circuito')
            ->selectRaw(
                'MAX(COALESCE(rec.fecha_fin, rec.fecha_inicio)) AS fecha'
            )
            ->groupBy('c.circuito')
            ->pluck('fecha', 'c.circuito');

        $periodo = [
            $inicioCiclo->copy()->startOfDay(),
            $hoy->copy()->endOfDay()
        ];

        $cumplidas = DB::table('recolecciones')
            ->where('estado', 'Finalizada')
            ->whereNotNull('id_ruta')
            ->whereBetween('fecha_fin', $periodo)
            ->distinct()
            ->pluck('id_ruta')
            ->map(fn ($id) => (int) $id)
            ->flip()
            ->all();

        $levantados = DB::table(
            'recolecciones as rec'
        )
            ->join(
                'recoleccion_contenedor as rc',
                'rc.id_recoleccion',
                '=',
                'rec.id_recoleccion'
            )
            ->join(
                'contenedores as c',
                'c.id_contenedor',
                '=',
                'rc.id_contenedor'
            )
            ->where('rec.estado', 'Finalizada')
            ->whereBetween('rec.fecha_fin', $periodo)
            ->select('c.circuito')
            ->selectRaw(
                'COUNT(DISTINCT rc.id_contenedor) AS total'
            )
            ->groupBy('c.circuito')
            ->pluck('total', 'c.circuito');

        $activas = DB::table('recolecciones')
            ->where('estado', 'En curso')
            ->orderByDesc('id_recoleccion')
            ->get();

        $enCursoRuta = $activas->groupBy('id_ruta');
        $enCursoCamion = $activas->groupBy('id_camion');

        $camiones = DB::table('camiones')
            ->where('activo', 1)
            ->whereNotNull('id_ruta')
            ->get()
            ->groupBy('id_ruta');

        $camionesPorId = DB::table('camiones')
            ->select(
                'id_camion',
                'id_chofer',
                'matricula'
            )
            ->get()
            ->keyBy('id_camion');

        $asignaciones = Schema::hasTable(
            'camion_asignaciones'
        )
            ? DB::table('camion_asignaciones as a')
                ->join(
                    'camiones as c',
                    'c.id_camion',
                    '=',
                    'a.id_camion'
                )
                ->whereNull('a.fecha_finalizacion')
                ->whereNotNull('a.id_ruta')
                ->where('c.activo', 1)
                ->select(
                    'a.id_ruta',
                    'a.id_camion',
                    'a.id_chofer',
                    'a.estado',
                    'c.matricula'
                )
                ->orderByDesc('a.fecha_asignacion')
                ->get()
                ->groupBy('id_ruta')
            : collect();

        $despachos = Schema::hasTable(
            'ruta_despachos'
        )
            ? DB::table('ruta_despachos')->get()
            : collect();

        $despachosRuta = $despachos->groupBy(
            'id_ruta'
        );

        $despachosCircuito = [];

        foreach ($despachos as $despacho) {
            $codigos = json_decode(
                $despacho->circuitos ?: '[]',
                true
            );

            if (!is_array($codigos)) {
                continue;
            }

            foreach ($codigos as $codigo) {
                if (
                    is_string($codigo) &&
                    trim($codigo) !== ''
                ) {
                    $clave = mb_strtoupper(
                        trim($codigo),
                        'UTF-8'
                    );

                    $despachosCircuito[$clave] =
                        $despacho;
                }
            }
        }

        $usuarios = DB::table('usuarios')
            ->select(
                'id_usuario',
                'nombre',
                'apellido'
            )
            ->get()
            ->keyBy('id_usuario');

        $resultado = $rutas->map(function ($ruta) use (
            $hoy,
            $inicioCiclo,
            $finCiclo,
            $diasProgramados,
            $ultimas,
            $ultimasCircuito,
            $cumplidas,
            $levantados,
            $enCursoRuta,
            $enCursoCamion,
            $camiones,
            $camionesPorId,
            $asignaciones,
            $despachosRuta,
            $despachosCircuito,
            $usuarios
        ) {
            $id = (int) $ruta->id_ruta;

            $codigo = mb_strtoupper(
                trim((string) $ruta->circuito),
                'UTF-8'
            );

            $fechaUltima = $ultimas->get($id);

            $fechaAdicional = $ultimasCircuito->get(
                $ruta->circuito
            );

            if (
                $fechaAdicional &&
                (
                    !$fechaUltima ||
                    Carbon::parse($fechaAdicional)->gt(
                        Carbon::parse($fechaUltima)
                    )
                )
            ) {
                $fechaUltima = $fechaAdicional;
            }

            $ultima = $fechaUltima
                ? Carbon::parse($fechaUltima)
                : null;

            $cubierta = isset($cumplidas[$id]) || (
                (int) $ruta->contenedores > 0 &&
                (int) $levantados->get(
                    $ruta->circuito,
                    0
                ) >= (int) $ruta->contenedores
            );

            $programada = $inicioCiclo
                ->copy()
                ->addDays(
                    $diasProgramados[$id] ?? 0
                );

            $proxima = $cubierta
                ? $programada->copy()->addDays(
                    self::DIAS_CICLO
                )
                : $programada;

            if ($cubierta) {
                $estadoPlan = 'Al día';

            } elseif ($proxima->lt($hoy)) {
                $estadoPlan = 'Vencida';

            } elseif ($proxima->isSameDay($hoy)) {
                $estadoPlan = 'Hoy';

            } else {
                $estadoPlan = 'Pendiente';
            }

            $despacho = $despachosRuta
                ->get($id, collect())
                ->first()
                ?? ($despachosCircuito[$codigo] ?? null);

            $recoleccion = $enCursoRuta
                ->get($id, collect())
                ->first();

            if (!$recoleccion && $despacho) {
                $recoleccion = $enCursoCamion
                    ->get(
                        $despacho->id_camion,
                        collect()
                    )
                    ->first();
            }

            $camion = $camiones
                ->get($id, collect())
                ->first();

            $asignacion = $asignaciones
                ->get($id, collect())
                ->first();

            $idCamion = $recoleccion?->id_camion
                ?? $despacho?->id_camion
                ?? $camion?->id_camion
                ?? $asignacion?->id_camion;

            $camionReal = $idCamion
                ? $camionesPorId->get($idCamion)
                : null;

            $matricula = $camionReal?->matricula
                ?? $despacho?->matricula
                ?? $camion?->matricula
                ?? $asignacion?->matricula;

            $idChofer = $despacho?->id_chofer
                ?? $camionReal?->id_chofer
                ?? $camion?->id_chofer
                ?? $asignacion?->id_chofer;

            $usuario = $idChofer
                ? $usuarios->get($idChofer)
                : null;

            $nombreChofer = $usuario
                ? trim(
                    ($usuario->nombre ?? '') . ' ' .
                    ($usuario->apellido ?? '')
                )
                : null;

            if ($recoleccion) {
                $operacion = 'En curso';

            } elseif (
                $despacho ||
                $camion ||
                $asignacion
            ) {
                $operacion = 'Asignada';

            } elseif ($cubierta) {
                $operacion = 'Finalizada';

            } else {
                $operacion = 'Pendiente';
            }

            return [
                'id_ruta' => $id,

                'circuito' => $ruta->circuito,

                'nombre' => $ruta->nombre
                    ?: 'Circuito ' . $ruta->circuito,

                'municipio' => $ruta->municipio,
                'zona' => $ruta->zona,
                'descripcion' => $ruta->descripcion,

                'frecuencia' => $ruta->frecuencia
                    ?: 'Cada 3 días',

                'estado' => $ruta->estado,

                'contenedores' =>
                    (int) $ruta->contenedores,

                'ultima_recoleccion' =>
                    $ultima?->toDateString(),

                'proxima_recoleccion' =>
                    $proxima->toDateString(),

                'estado_planificacion' => $estadoPlan,
                'estado_operacion' => $operacion,

                'id_recoleccion_activa' =>
                    $recoleccion?->id_recoleccion,

                'id_camion_asignado' => $idCamion,
                'camion_asignado' => $matricula,

                'chofer_asignado' =>
                    $nombreChofer ?: (
                        $idChofer
                            ? (string) $idChofer
                            : null
                    ),

                'inicio_ciclo' =>
                    $inicioCiclo->toDateString(),

                'fin_ciclo' =>
                    $finCiclo->toDateString(),

                'cubierta_en_ciclo' => $cubierta
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $resultado->values()
        ]);
    }

    public function historial(Request $request)
    {
        $query = DB::table('recolecciones as rec')
            ->leftJoin(
                'rutas as r',
                'rec.id_ruta',
                '=',
                'r.id_ruta'
            )
            ->leftJoin(
                'camiones as c',
                'rec.id_camion',
                '=',
                'c.id_camion'
            )
            ->select(
                'rec.id_recoleccion',
                'rec.id_ruta',
                'r.circuito',
                'r.nombre as ruta_nombre',
                'rec.id_camion',
                'c.matricula',
                'rec.id_cuadrilla',
                'rec.fecha_inicio',
                'rec.fecha_fin',
                'rec.tipo',
                'rec.contenedores_asignados',
                'rec.contenedores_recolectados',
                'rec.peso_recolectado_kg',
                'rec.distancia_km',
                'rec.estado'
            )
            ->orderByDesc('rec.id_recoleccion');

        if ($request->filled('id_ruta')) {
            $query->where(
                'rec.id_ruta',
                (int) $request->query('id_ruta')
            );
        }

        if ($request->filled('estado')) {
            $query->where(
                'rec.estado',
                $request->query('estado')
            );
        }

        $mes = $request->query('mes');

        if (
            is_string($mes) &&
            preg_match(
                '/^\d{4}-(0[1-9]|1[0-2])$/',
                $mes
            )
        ) {
            $inicio = Carbon::createFromFormat(
                '!Y-m-d',
                $mes . '-01'
            );

            $fin = $inicio->copy()->addMonth();

            $query
                ->where(
                    'rec.fecha_inicio',
                    '>=',
                    $inicio
                )
                ->where(
                    'rec.fecha_inicio',
                    '<',
                    $fin
                );
        }

        $limite = min(
            250,
            max(
                1,
                (int) $request->query('limite', 20)
            )
        );

        $historial = $query
            ->limit($limite)
            ->get()
            ->map(fn ($item) => [
                'id_recoleccion' =>
                    (int) $item->id_recoleccion,

                'id_ruta' => $item->id_ruta !== null
                    ? (int) $item->id_ruta
                    : null,

                'circuito' => $item->circuito,
                'ruta_nombre' => $item->ruta_nombre,

                'id_camion' => $item->id_camion !== null
                    ? (int) $item->id_camion
                    : null,

                'matricula' => $item->matricula,
                'id_cuadrilla' => $item->id_cuadrilla,

                'fecha_inicio' => $item->fecha_inicio,
                'fecha_fin' => $item->fecha_fin,
                'tipo' => $item->tipo,

                'contenedores_asignados' =>
                    (int) $item->contenedores_asignados,

                'contenedores_recolectados' =>
                    (int) $item->contenedores_recolectados,

                'peso_recolectado_kg' =>
                    (float) (
                        $item->peso_recolectado_kg ?? 0
                    ),

                'distancia_km' =>
                    (float) (
                        $item->distancia_km ?? 0
                    ),

                'estado' => $item->estado
            ])
            ->values();

        return response()->json([
            'success' => true,
            'data' => $historial
        ]);
    }

    public function configurarHabitual(
        Request $request,
        int $id
    ) {
        $datos = $request->validate([
            'dias' => 'required|integer|min:1|max:14'
        ]);

        $existe = DB::table('rutas')
            ->where('id_ruta', $id)
            ->exists();

        if (!$existe) {
            return response()->json([
                'success' => false,
                'message' => 'Ruta no encontrada.'
            ], 404);
        }

        DB::table('rutas')
            ->where('id_ruta', $id)
            ->update([
                'frecuencia' =>
                    'Cada ' . $datos['dias'] . ' días'
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Frecuencia guardada.'
        ]);
    }

    public function despacharAutomaticamente()
    {
        $resultado = app(
            DespachoAutomaticoRutas::class
        )->ejecutar();

        return response()->json([
            'success' => true,
            'data' => $resultado
        ]);
    }
}
