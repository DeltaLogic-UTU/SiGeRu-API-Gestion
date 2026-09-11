<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ContenedorSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('tipo_residuo')->updateOrInsert(
            ['tipo' => 'general'],
            ['descripcion' => 'Residuos urbanos generales']
        );

        DB::table('tipo_residuo')->updateOrInsert(
            ['tipo' => 'reciclaje'],
            ['descripcion' => 'Materiales reciclables']
        );

        DB::table('tipo_residuo')->updateOrInsert(
            ['tipo' => 'mixto'],
            ['descripcion' => 'Residuos generales y reciclables']
        );

        $distribuciones = [
            'B_01' => [
                'verde' => 7,
                'amarillo' => 2,
                'rojo' => 1
            ],
            'B_02' => [
                'verde' => 4,
                'amarillo' => 4,
                'rojo' => 2
            ],
            'B_03' => [
                'verde' => 2,
                'amarillo' => 3,
                'rojo' => 5
            ],
            'B_04' => [
                'verde' => 8,
                'amarillo' => 2,
                'rojo' => 0
            ],
            'CH_102' => [
                'verde' => 5,
                'amarillo' => 3,
                'rojo' => 2
            ],
            'CH_105' => [
                'verde' => 3,
                'amarillo' => 3,
                'rojo' => 4
            ],
            'CH_108' => [
                'verde' => 6,
                'amarillo' => 3,
                'rojo' => 1
            ],
            'CH_109' => [
                'verde' => 3,
                'amarillo' => 5,
                'rojo' => 2
            ],
            'CH_114' => [
                'verde' => 2,
                'amarillo' => 4,
                'rojo' => 4
            ]
        ];

        $rutas = DB::table('rutas')
            ->orderBy('circuito')
            ->get();

        $tipos = [
            'general',
            'reciclaje',
            'mixto'
        ];

        $modelos = [
            'Ecopunto',
            'EASY',
            'Metálico'
        ];

        $capacidades = [
            3,
            2.25,
            3
        ];

        foreach ($rutas as $ruta) {
            $distribucion =
                $distribuciones[$ruta->circuito]
                ?? [
                    'verde' => 6,
                    'amarillo' => 3,
                    'rojo' => 1
                ];

            $dias = array_merge(
                array_fill(
                    0,
                    $distribucion['verde'],
                    2
                ),
                array_fill(
                    0,
                    $distribucion['amarillo'],
                    4
                ),
                array_fill(
                    0,
                    $distribucion['rojo'],
                    6
                )
            );

            foreach ($dias as $indice => $diasSinRecoleccion) {
                $numero =
                    $indice + 1;

                $codigoCircuito =
                    str_replace(
                        '_',
                        '',
                        $ruta->circuito
                    );

                $codigo =
                    'CONT-' .
                    $codigoCircuito .
                    '-' .
                    str_pad(
                        $numero,
                        2,
                        '0',
                        STR_PAD_LEFT
                    );

                $existente = DB::table('contenedores')
                    ->where('codigo', $codigo)
                    ->first();

                if ($existente) {
                    continue;
                }

                $idUbicacion = DB::table('ubicacion')
                    ->insertGetId([
                        'calle' =>
                            'Calle ' .
                            $numero,
                        'esquina_1' =>
                            'Esquina ' .
                            $numero,
                        'esquina_2' =>
                            null,
                        'municipio' =>
                            $ruta->municipio,
                        'barrio' =>
                            $ruta->zona
                            ?: 'Zona ' .
                            $ruta->municipio,
                        'descripcion' =>
                            'Ubicación del contenedor ' .
                            $codigo,
                        'latitud' =>
                            null,
                        'longitud' =>
                            null
                    ]);

                if ($diasSinRecoleccion >= 6) {
                    $estado =
                        $numero % 2 === 0
                            ? 'roto'
                            : 'desbordado';
                } else {
                    $estado =
                        'funcional';
                }

                $idContenedor = DB::table('contenedores')
                    ->insertGetId([
                        'codigo' =>
                            $codigo,
                        'modelo' =>
                            $modelos[
                                $indice %
                                count($modelos)
                            ],
                        'tipo' =>
                            $tipos[
                                $indice %
                                count($tipos)
                            ],
                        'capacidad' =>
                            $capacidades[
                                $indice %
                                count($capacidades)
                            ],
                        'estado' =>
                            $estado,
                        'dias_sin_recoleccion' =>
                            $diasSinRecoleccion,
                        'id_ubicacion' =>
                            $idUbicacion,
                        'circuito' =>
                            $ruta->circuito,
                        'ultimo_registro' =>
                            Carbon::today()
                                ->subDays(
                                    $diasSinRecoleccion
                                )
                                ->toDateString(),
                        'activo' =>
                            1
                    ]);

                DB::table('historial_contenedores')
                    ->insert([
                        'id_contenedor' =>
                            $idContenedor,
                        'fecha' =>
                            now(),
                        'estado_anterior' =>
                            null,
                        'estado_nuevo' =>
                            $estado,
                        'id_ubicacion_anterior' =>
                            null,
                        'id_ubicacion_nueva' =>
                            $idUbicacion,
                        'motivo' =>
                            'Alta: Contenedor incorporado al circuito ' .
                            $ruta->circuito .
                            '.'
                    ]);
            }
        }
    }
}