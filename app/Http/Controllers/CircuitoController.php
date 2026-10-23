<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CircuitoController extends Controller
{
    // Lista todos los circuitos con sus coordenadas y cantidad de contenedores
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
                if (!empty($item->coordenadas)) {
                    $decodificado = json_decode($item->coordenadas, true);
                    if (is_array($decodificado)) {
                        $coordenadas = $decodificado;
                    }
                }

                return [
                    'id_circuito' => $item->id_circuito,
                    'municipio' => $item->municipio,
                    'zona' => $item->zona,
                    'descripcion' => $item->descripcion,
                    'estado' => $item->estado,
                    'coordenadas' => $coordenadas,
                    'total_contenedores' => (int) ($conteos[$item->id_circuito] ?? 0)
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $circuitos
        ]);
    }

    // Guarda un nuevo circuito dibujado en el mapa o actualiza uno existente
    public function store(Request $request)
    {
        $validated = $request->validate([
            'id_circuito' => 'required|string|max:30',
            'municipio' => 'nullable|string|max:100',
            'zona' => 'nullable|string|max:100',
            'descripcion' => 'nullable|string',
            'coordenadas' => 'required'
        ]);

        $codigo = strtoupper(trim($validated['id_circuito']));
        $municipio = !empty($validated['municipio']) ? strtoupper(trim($validated['municipio'])) : 'Montevideo';
        $zona = !empty($validated['zona']) ? trim($validated['zona']) : null;
        $descripcion = !empty($validated['descripcion']) ? trim($validated['descripcion']) : null;

        $coordenadas = is_array($validated['coordenadas'])
            ? $validated['coordenadas']
            : json_decode($validated['coordenadas'], true);

        if (!is_array($coordenadas) || count($coordenadas) < 3) {
            return response()->json([
                'success' => false,
                'message' => 'El circuito debe tener al menos 3 puntos para delimitar una zona.'
            ], 422);
        }

        // Guardamos en la tabla circuitos
        DB::table('circuitos')->updateOrInsert(
            ['id_circuito' => $codigo],
            [
                'municipio' => $municipio,
                'zona' => $zona,
                'descripcion' => $descripcion,
                'coordenadas' => json_encode($coordenadas),
                'estado' => 'activo'
            ]
        );

        // Mantenemos sincronizada la tabla rutas
        DB::table('rutas')->updateOrInsert(
            ['circuito' => $codigo],
            [
                'nombre' => $zona ?: "Circuito $codigo",
                'municipio' => $municipio,
                'zona' => $zona,
                'descripcion' => $descripcion,
                'estado' => 'Asignada'
            ]
        );

        // Si hay contenedores con coordenadas que caen dentro de este nuevo circuito,
        // o si pertenecen a este código, calculamos el total
        $totalContenedores = DB::table('contenedores')
            ->where('circuito', $codigo)
            ->where('activo', 1)
            ->count();

        return response()->json([
            'success' => true,
            'message' => 'Circuito guardado correctamente.',
            'data' => [
                'id_circuito' => $codigo,
                'municipio' => $municipio,
                'zona' => $zona,
                'descripcion' => $descripcion,
                'coordenadas' => $coordenadas,
                'total_contenedores' => $totalContenedores,
                'estado' => 'activo'
            ]
        ], 201);
    }

    // Consulta los detalles de un circuito específico
    public function show(string $id)
    {
        $circuito = DB::table('circuitos')
            ->where('id_circuito', $id)
            ->first();

        if (!$circuito) {
            return response()->json([
                'success' => false,
                'message' => 'Circuito no encontrado.'
            ], 404);
        }

        $coordenadas = null;
        if (!empty($circuito->coordenadas)) {
            $coordenadas = json_decode($circuito->coordenadas, true);
        }

        $totalContenedores = DB::table('contenedores')
            ->where('circuito', $circuito->id_circuito)
            ->where('activo', 1)
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'id_circuito' => $circuito->id_circuito,
                'municipio' => $circuito->municipio,
                'zona' => $circuito->zona,
                'descripcion' => $circuito->descripcion,
                'estado' => $circuito->estado,
                'coordenadas' => $coordenadas,
                'total_contenedores' => $totalContenedores
            ]
        ]);
    }
}

