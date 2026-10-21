<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CuadrillaController;
use App\Http\Controllers\ContenedorController;
use App\Http\Controllers\InstalacionController;
use App\Http\Controllers\IncidenciaController;
use App\Http\Controllers\CamionController;
use App\Http\Controllers\ReclamoController;
use App\Http\Controllers\CircuitoController;

// Ruta pública de comprobación (no requiere token JWT).
Route::get('health', fn () => response()->json([
    'success' => true,
    'message' => 'API Gestion running',
]));

// Rutas públicas para mostrar contenedores y circuitos delimitados en el mapa
Route::get(
    'mapa/contenedores',
    [ContenedorController::class, 'index']
);

Route::get(
    'mapa/circuitos',
    [CircuitoController::class, 'index']
);

// Rutas protegidas con el middleware auth:api (requieren Authorization: Bearer <token>).
Route::middleware('auth:api')->group(function () {

    Route::get(
        'circuitos',
        [CircuitoController::class, 'index']
    );

    Route::post(
        'circuitos',
        [CircuitoController::class, 'store']
    );

    Route::get(
        'circuitos/{id}',
        [CircuitoController::class, 'show']
    )->where('id', '[A-Za-z0-9_-]+');

    Route::get(
        'cuadrillas',
        [CuadrillaController::class, 'index']
    );

    Route::get(
        'cuadrillas/responsables',
        [CuadrillaController::class, 'responsables']
    );

    Route::post(
        'cuadrillas',
        [CuadrillaController::class, 'store']
    );

    Route::put(
        'cuadrillas/{id}',
        [CuadrillaController::class, 'update']
    )->where('id', '[A-Za-z0-9_-]+');

    Route::delete(
        'cuadrillas/{id}',
        [CuadrillaController::class, 'destroy']
    )->where('id', '[A-Za-z0-9_-]+');


    Route::get(
        'cuadrillas/{id}/integrantes',
        [CuadrillaController::class, 'integrantes']
    )->where('id', '[A-Za-z0-9_-]+');

    Route::get(
        'cuadrillas/{id}/integrantes-disponibles',
        [CuadrillaController::class, 'integrantesDisponibles']
    )->where('id', '[A-Za-z0-9_-]+');

    Route::post(
        'cuadrillas/{id}/integrantes',
        [CuadrillaController::class, 'agregarIntegrante']
    )->where('id', '[A-Za-z0-9_-]+');

    Route::put(
        'cuadrillas/{id}/integrantes/{idUsuario}',
        [CuadrillaController::class, 'actualizarIntegrante']
    )
        ->where('id', '[A-Za-z0-9_-]+')
        ->whereNumber('idUsuario');

    Route::delete(
        'cuadrillas/{id}/integrantes/{idUsuario}',
        [CuadrillaController::class, 'quitarIntegrante']
    )
        ->where('id', '[A-Za-z0-9_-]+')
        ->whereNumber('idUsuario');


    Route::get(
        'cuadrillas/{id}/rutas',
        [CuadrillaController::class, 'rutas']
    )->where('id', '[A-Za-z0-9_-]+');

    Route::post(
        'cuadrillas/{id}/rutas',
        [CuadrillaController::class, 'asignarRuta']
    )->where('id', '[A-Za-z0-9_-]+');

    Route::put(
        'cuadrillas/{id}/rutas/{idAsignacion}',
        [CuadrillaController::class, 'actualizarRuta']
    )
        ->where('id', '[A-Za-z0-9_-]+')
        ->whereNumber('idAsignacion');

    Route::delete(
        'cuadrillas/{id}/rutas/{idAsignacion}',
        [CuadrillaController::class, 'quitarRuta']
    )
        ->where('id', '[A-Za-z0-9_-]+')
        ->whereNumber('idAsignacion');


    Route::get(
        'cuadrillas/{id}/historiales',
        [CuadrillaController::class, 'historiales']
    )->where('id', '[A-Za-z0-9_-]+');


    Route::get(
        'contenedores',
        [ContenedorController::class, 'index']
    );

    Route::get(
        'contenedores/catalogos',
        [ContenedorController::class, 'catalogos']
    );

    Route::get(
        'contenedores/resumen-circuitos',
        [ContenedorController::class, 'resumenCircuitos']
    );

    Route::post(
        'contenedores',
        [ContenedorController::class, 'store']
    );

    Route::get(
        'contenedores/{id}',
        [ContenedorController::class, 'show']
    )->whereNumber('id');

    Route::put(
        'contenedores/{id}',
        [ContenedorController::class, 'update']
    )->whereNumber('id');

    Route::delete(
        'contenedores/{id}',
        [ContenedorController::class, 'destroy']
    )->whereNumber('id');

    Route::post(
        'contenedores/{id}/reactivar',
        [ContenedorController::class, 'reactivar']
    )->whereNumber('id');

    Route::get(
        'contenedores/{id}/historial',
        [ContenedorController::class, 'historial']
    )->whereNumber('id');


    Route::get(
        'instalaciones',
        [InstalacionController::class, 'index']
    );

    Route::post(
        'instalaciones',
        [InstalacionController::class, 'store']
    );

    Route::put(
        'instalaciones/{id}',
        [InstalacionController::class, 'update']
    )->whereNumber('id');

    Route::delete(
        'instalaciones/{id}',
        [InstalacionController::class, 'destroy']
    )->whereNumber('id');


    Route::get(
        'instalaciones/{id}/inventario',
        [InstalacionController::class, 'inventario']
    )->whereNumber('id');

    Route::post(
        'instalaciones/{id}/inventario',
        [InstalacionController::class, 'agregarInventario']
    )->whereNumber('id');

    Route::put(
        'instalaciones/{id}/inventario/{idInventario}',
        [InstalacionController::class, 'actualizarInventario']
    )
        ->whereNumber('id')
        ->whereNumber('idInventario');

    Route::delete(
        'instalaciones/{id}/inventario/{idInventario}',
        [InstalacionController::class, 'eliminarInventario']
    )
        ->whereNumber('id')
        ->whereNumber('idInventario');


    Route::get(
        'instalaciones/{id}/operarios',
        [InstalacionController::class, 'operarios']
    )->whereNumber('id');

    Route::get(
        'instalaciones/{id}/operarios-disponibles',
        [InstalacionController::class, 'operariosDisponibles']
    )->whereNumber('id');

    Route::post(
        'instalaciones/{id}/operarios',
        [InstalacionController::class, 'asignarOperario']
    )->whereNumber('id');

    Route::put(
        'instalaciones/{id}/operarios/{idUsuario}',
        [InstalacionController::class, 'actualizarOperario']
    )
        ->whereNumber('id')
        ->whereNumber('idUsuario');

    Route::delete(
        'instalaciones/{id}/operarios/{idUsuario}',
        [InstalacionController::class, 'quitarOperario']
    )
        ->whereNumber('id')
        ->whereNumber('idUsuario');

    Route::get(
        'camiones/catalogos',
        [CamionController::class, 'catalogos']
    );

    Route::get(
        'camiones',
        [CamionController::class, 'index']
    );

    Route::post(
        'camiones',
        [CamionController::class, 'store']
    );

    Route::get(
        'camiones/{id}',
        [CamionController::class, 'show']
    )->whereNumber('id');

    Route::delete(
        'camiones/{id}',
        [CamionController::class, 'destroy']
    )->whereNumber('id');

    Route::put(
        'camiones/{id}/chofer',
        [CamionController::class, 'asignarChofer']
    )->whereNumber('id');

    Route::put(
        'camiones/{id}/cuadrilla',
        [CamionController::class, 'asignarCuadrilla']
    )->whereNumber('id');

    Route::put(
        'camiones/{id}/ruta',
        [CamionController::class, 'asignarRuta']
    )->whereNumber('id');

    Route::put(
        'camiones/{id}/ruta/estado',
        [CamionController::class, 'actualizarEstadoRuta']
    )->whereNumber('id');


    Route::get(
        'incidencias',
        [IncidenciaController::class, 'index']
    );

    Route::get(
        'incidencias/{id}',
        [IncidenciaController::class, 'show']
    )->whereNumber('id');

    Route::put(
        'incidencias/{id}',
        [IncidenciaController::class, 'update']
    )->whereNumber('id');


    Route::get(
        'reclamos',
        [ReclamoController::class, 'index']
    );

    Route::get(
        'reclamos/{id}',
        [ReclamoController::class, 'show']
    )->where(
        'id',
        '[A-Za-z0-9_-]+'
    );

    Route::put(
        'reclamos/{id}/asignar',
        [ReclamoController::class, 'asignar']
    )->where(
        'id',
        '[A-Za-z0-9_-]+'
    );

    Route::put(
        'reclamos/{id}/quitar-asignacion',
        [ReclamoController::class, 'quitarAsignacion']
    )->where(
        'id',
        '[A-Za-z0-9_-]+'
    );

    Route::put(
        'reclamos/{id}/completar',
        [ReclamoController::class, 'completar']
    )->where(
        'id',
        '[A-Za-z0-9_-]+'
    );

    Route::put(
        'reclamos/{id}/reabrir',
        [ReclamoController::class, 'reabrir']
    )->where(
        'id',
        '[A-Za-z0-9_-]+'
    );

    Route::put(
        'reclamos/{id}/resultado',
        [ReclamoController::class, 'registrarResultado']
    )->where(
        'id',
        '[A-Za-z0-9_-]+'
    );

    Route::put(
        'reclamos/{id}/notificar',
        [ReclamoController::class, 'notificar']
    )->where(
        'id',
        '[A-Za-z0-9_-]+'
    );
});


