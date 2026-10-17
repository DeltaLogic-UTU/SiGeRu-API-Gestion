<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CuadrillaController;
use App\Http\Controllers\ContenedorController;
use App\Http\Controllers\InstalacionController;
use App\Http\Controllers\IncidenciaController;
use App\Http\Controllers\CamionController;
use App\Http\Controllers\ReclamoController;

// Se eliminó la ruta GET '/csrf-token' porque las APIs


Route::get(
    '/api/cuadrillas',
    [CuadrillaController::class, 'index']
);

Route::get(
    '/api/cuadrillas/responsables',
    [CuadrillaController::class, 'responsables']
);

Route::post(
    '/api/cuadrillas',
    [CuadrillaController::class, 'store']
);

Route::put(
    '/api/cuadrillas/{id}',
    [CuadrillaController::class, 'update']
)->where('id', '[A-Za-z0-9_-]+');

Route::delete(
    '/api/cuadrillas/{id}',
    [CuadrillaController::class, 'destroy']
)->where('id', '[A-Za-z0-9_-]+');


Route::get(
    '/api/cuadrillas/{id}/integrantes',
    [CuadrillaController::class, 'integrantes']
)->where('id', '[A-Za-z0-9_-]+');

Route::get(
    '/api/cuadrillas/{id}/integrantes-disponibles',
    [CuadrillaController::class, 'integrantesDisponibles']
)->where('id', '[A-Za-z0-9_-]+');

Route::post(
    '/api/cuadrillas/{id}/integrantes',
    [CuadrillaController::class, 'agregarIntegrante']
)->where('id', '[A-Za-z0-9_-]+');

Route::put(
    '/api/cuadrillas/{id}/integrantes/{idUsuario}',
    [CuadrillaController::class, 'actualizarIntegrante']
)
    ->where('id', '[A-Za-z0-9_-]+')
    ->whereNumber('idUsuario');

Route::delete(
    '/api/cuadrillas/{id}/integrantes/{idUsuario}',
    [CuadrillaController::class, 'quitarIntegrante']
)
    ->where('id', '[A-Za-z0-9_-]+')
    ->whereNumber('idUsuario');


Route::get(
    '/api/cuadrillas/{id}/rutas',
    [CuadrillaController::class, 'rutas']
)->where('id', '[A-Za-z0-9_-]+');

Route::post(
    '/api/cuadrillas/{id}/rutas',
    [CuadrillaController::class, 'asignarRuta']
)->where('id', '[A-Za-z0-9_-]+');

Route::put(
    '/api/cuadrillas/{id}/rutas/{idAsignacion}',
    [CuadrillaController::class, 'actualizarRuta']
)
    ->where('id', '[A-Za-z0-9_-]+')
    ->whereNumber('idAsignacion');

Route::delete(
    '/api/cuadrillas/{id}/rutas/{idAsignacion}',
    [CuadrillaController::class, 'quitarRuta']
)
    ->where('id', '[A-Za-z0-9_-]+')
    ->whereNumber('idAsignacion');


Route::get(
    '/api/cuadrillas/{id}/historiales',
    [CuadrillaController::class, 'historiales']
)->where('id', '[A-Za-z0-9_-]+');


Route::get(
    '/api/contenedores',
    [ContenedorController::class, 'index']
);

Route::get(
    '/api/contenedores/catalogos',
    [ContenedorController::class, 'catalogos']
);

Route::get(
    '/api/contenedores/resumen-circuitos',
    [ContenedorController::class, 'resumenCircuitos']
);

Route::post(
    '/api/contenedores',
    [ContenedorController::class, 'store']
);

Route::get(
    '/api/contenedores/{id}',
    [ContenedorController::class, 'show']
)->whereNumber('id');

Route::put(
    '/api/contenedores/{id}',
    [ContenedorController::class, 'update']
)->whereNumber('id');

Route::delete(
    '/api/contenedores/{id}',
    [ContenedorController::class, 'destroy']
)->whereNumber('id');

Route::post(
    '/api/contenedores/{id}/reactivar',
    [ContenedorController::class, 'reactivar']
)->whereNumber('id');

Route::get(
    '/api/contenedores/{id}/historial',
    [ContenedorController::class, 'historial']
)->whereNumber('id');



Route::get(
    '/api/instalaciones',
    [InstalacionController::class, 'index']
);

Route::post(
    '/api/instalaciones',
    [InstalacionController::class, 'store']
);

Route::put(
    '/api/instalaciones/{id}',
    [InstalacionController::class, 'update']
)->whereNumber('id');

Route::delete(
    '/api/instalaciones/{id}',
    [InstalacionController::class, 'destroy']
)->whereNumber('id');


Route::get(
    '/api/instalaciones/{id}/inventario',
    [InstalacionController::class, 'inventario']
)->whereNumber('id');

Route::post(
    '/api/instalaciones/{id}/inventario',
    [InstalacionController::class, 'agregarInventario']
)->whereNumber('id');

Route::put(
    '/api/instalaciones/{id}/inventario/{idInventario}',
    [InstalacionController::class, 'actualizarInventario']
)
    ->whereNumber('id')
    ->whereNumber('idInventario');

Route::delete(
    '/api/instalaciones/{id}/inventario/{idInventario}',
    [InstalacionController::class, 'eliminarInventario']
)
    ->whereNumber('id')
    ->whereNumber('idInventario');


Route::get(
    '/api/instalaciones/{id}/operarios',
    [InstalacionController::class, 'operarios']
)->whereNumber('id');

Route::get(
    '/api/instalaciones/{id}/operarios-disponibles',
    [InstalacionController::class, 'operariosDisponibles']
)->whereNumber('id');

Route::post(
    '/api/instalaciones/{id}/operarios',
    [InstalacionController::class, 'asignarOperario']
)->whereNumber('id');

Route::put(
    '/api/instalaciones/{id}/operarios/{idUsuario}',
    [InstalacionController::class, 'actualizarOperario']
)
    ->whereNumber('id')
    ->whereNumber('idUsuario');

Route::delete(
    '/api/instalaciones/{id}/operarios/{idUsuario}',
    [InstalacionController::class, 'quitarOperario']
)
    ->whereNumber('id')
    ->whereNumber('idUsuario');

Route::get(
    '/api/camiones/catalogos',
    [CamionController::class, 'catalogos']
);

Route::get(
    '/api/camiones',
    [CamionController::class, 'index']
);

Route::post(
    '/api/camiones',
    [CamionController::class, 'store']
);

Route::get(
    '/api/camiones/{id}',
    [CamionController::class, 'show']
)->whereNumber('id');

Route::delete(
    '/api/camiones/{id}',
    [CamionController::class, 'destroy']
)->whereNumber('id');

Route::put(
    '/api/camiones/{id}/chofer',
    [CamionController::class, 'asignarChofer']
)->whereNumber('id');

Route::put(
    '/api/camiones/{id}/cuadrilla',
    [CamionController::class, 'asignarCuadrilla']
)->whereNumber('id');

Route::put(
    '/api/camiones/{id}/ruta',
    [CamionController::class, 'asignarRuta']
)->whereNumber('id');

Route::put(
    '/api/camiones/{id}/ruta/estado',
    [CamionController::class, 'actualizarEstadoRuta']
)->whereNumber('id');


Route::get(
    '/api/incidencias',
    [IncidenciaController::class, 'index']
);

Route::get(
    '/api/incidencias/{id}',
    [IncidenciaController::class, 'show']
)->whereNumber('id');

Route::put(
    '/api/incidencias/{id}',
    [IncidenciaController::class, 'update']
)->whereNumber('id');


Route::get(
    '/api/reclamos',
    [ReclamoController::class, 'index']
);

Route::get(
    '/api/reclamos/{id}',
    [ReclamoController::class, 'show']
)->where(
    'id',
    '[A-Za-z0-9_-]+'
);

Route::put(
    '/api/reclamos/{id}/asignar',
    [ReclamoController::class, 'asignar']
)->where(
    'id',
    '[A-Za-z0-9_-]+'
);

Route::put(
    '/api/reclamos/{id}/quitar-asignacion',
    [ReclamoController::class, 'quitarAsignacion']
)->where(
    'id',
    '[A-Za-z0-9_-]+'
);

Route::put(
    '/api/reclamos/{id}/completar',
    [ReclamoController::class, 'completar']
)->where(
    'id',
    '[A-Za-z0-9_-]+'
);

Route::put(
    '/api/reclamos/{id}/reabrir',
    [ReclamoController::class, 'reabrir']
)->where(
    'id',
    '[A-Za-z0-9_-]+'
);

Route::put(
    '/api/reclamos/{id}/resultado',
    [ReclamoController::class, 'registrarResultado']
)->where(
    'id',
    '[A-Za-z0-9_-]+'
);

Route::put(
    '/api/reclamos/{id}/notificar',
    [ReclamoController::class, 'notificar']
)->where(
    'id',
    '[A-Za-z0-9_-]+'
);