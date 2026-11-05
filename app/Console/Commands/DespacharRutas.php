<?php

namespace App\Console\Commands;

use App\Services\DespachoAutomaticoRutas;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class DespacharRutas extends Command
{
    protected $signature = 'sigeru:despachar-rutas';

    protected $description =
        'Asigna automáticamente rutas pendientes a choferes y camiones disponibles.';

    public function handle(
        DespachoAutomaticoRutas $servicio
    ): int {
        $this->info(
            'SiGeRu: iniciando asignación automática de rutas...'
        );

        try {
            $resultado = $servicio->ejecutar();

            $asignadas = $resultado['asignadas'] ?? [];
            $omitidas = $resultado['omitidas'] ?? [];

            $this->info(
                'Rutas asignadas: ' . count($asignadas)
            );

            foreach ($asignadas as $ruta) {
                $this->line(
                    'Circuito: ' . $ruta['circuito'] .
                    ' | Camión: ' . $ruta['camion'] .
                    ' | Chofer: ' . $ruta['chofer']
                );
            }

            $this->warn(
                'Rutas omitidas: ' . count($omitidas)
            );

            foreach ($omitidas as $ruta) {
                $this->line(
                    'Circuito: ' . $ruta['circuito'] .
                    ' | Motivo: ' . $ruta['motivo']
                );
            }

            Log::info(
                'SiGeRu: asignación automática ejecutada.',
                [
                    'asignadas' => count($asignadas),
                    'omitidas' => count($omitidas)
                ]
            );

            return self::SUCCESS;

        } catch (Throwable $e) {
            Log::error(
                'SiGeRu: error en asignación automática.',
                [
                    'mensaje' => $e->getMessage(),
                    'archivo' => $e->getFile(),
                    'linea' => $e->getLine()
                ]
            );

            $this->error(
                'No se pudieron asignar las rutas: ' .
                $e->getMessage()
            );

            return self::FAILURE;
        }
    }
}
