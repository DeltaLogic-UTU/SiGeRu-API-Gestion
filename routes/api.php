<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CuadrillaController;
use App\Http\Controllers\ContenedorController;
use App\Http\Controllers\InstalacionController;
use App\Http\Controllers\IncidenciaController;
use App\Http\Controllers\CamionController;
use App\Http\Controllers\ReclamoController;
use App\Http\Controllers\CircuitoController;
use App\Http\Controllers\RecoleccionController;
use App\Http\Controllers\RutaController;
use App\Http\Controllers\EstadisticaController;
use App\Http\Controllers\PanelCuadrillaController;
use App\Http\Controllers\MensajeInternoController;
use App\Http\Controllers\RolController;
use App\Http\Controllers\ReporteUsuariosRolController;
use App\Http\Controllers\RecorridoCuadrillaController;
use App\Http\Controllers\NotificacionVecinoController;
use App\Http\Controllers\OperarioController;

Route::get('health', fn () => response()->json([
    'success' => true,
    'message' => 'API Gestion running',
]));

Route::get('mapa/contenedores', [ContenedorController::class, 'index']);
Route::get('mapa/camiones', [CamionController::class, 'mapaPublico']);
Route::get('mapa/circuitos', [CircuitoController::class, 'index']);

Route::middleware('auth:api')->group(function () {

    Route::get(
        'operario/mi-instalacion',
        [OperarioController::class, 'miInstalacion']
    );
    
    Route::delete(
        'vecino/notificaciones/{id}',
        [NotificacionVecinoController::class, 'eliminar']
    )->whereNumber('id');

    Route::get(
        'vecino/notificaciones',
        [NotificacionVecinoController::class, 'index']
    );

    Route::post(
        'vecino/notificaciones/leer',
        [NotificacionVecinoController::class, 'marcarLeidas']
    );

    
    Route::get(
        'cuadrilla/recorridos',
        [RecorridoCuadrillaController::class, 'index']
    );

    Route::get(
        'cuadrilla/recorridos/{id}',
        [RecorridoCuadrillaController::class, 'show']
    )->whereNumber('id');

    Route::post(
        'cuadrilla/recorridos/{id}/iniciar',
        [RecorridoCuadrillaController::class, 'iniciar']
    )->whereNumber('id');

    Route::post(
        'cuadrilla/recorridos/{id}/contenedores/{idContenedor}',
        [RecorridoCuadrillaController::class, 'recolectar']
    )
        ->whereNumber('id')
        ->whereNumber('idContenedor');

    Route::post(
        'cuadrilla/recorridos/{id}/finalizar',
        [RecorridoCuadrillaController::class, 'finalizar']
    )->whereNumber('id');


    Route::get('roles', [RolController::class, 'index'])
    ->middleware('role:administrador');

    Route::post('roles', [RolController::class, 'store'])
    ->middleware('role:administrador');

    Route::get(
    'reports/users-by-role/orm',
    [ReporteUsuariosRolController::class, 'orm']
    )->middleware('role:administrador,supervisor');

    Route::get(
    'reports/users-by-role/sql',
    [ReporteUsuariosRolController::class, 'sql']
    )->middleware('role:administrador,supervisor');

    Route::get('circuitos', [CircuitoController::class, 'index']);
    Route::post('circuitos', [CircuitoController::class, 'store']);
    Route::put('circuitos/{id}', [CircuitoController::class, 'update'])
        ->where('id', '[A-Za-z0-9_-]+');
    Route::get('circuitos/{id}', [CircuitoController::class, 'show'])
        ->where('id', '[A-Za-z0-9_-]+');

    Route::get('cuadrillas', [CuadrillaController::class, 'index']);
    Route::get('cuadrillas/responsables', [CuadrillaController::class, 'responsables']);
    Route::post('cuadrillas', [CuadrillaController::class, 'store']);
    Route::put('cuadrillas/{id}', [CuadrillaController::class, 'update'])
        ->where('id', '[A-Za-z0-9_-]+');
    Route::delete('cuadrillas/{id}', [CuadrillaController::class, 'destroy'])
        ->where('id', '[A-Za-z0-9_-]+');
    Route::get('cuadrillas/{id}/integrantes', [CuadrillaController::class, 'integrantes'])
        ->where('id', '[A-Za-z0-9_-]+');
    Route::get('cuadrillas/{id}/integrantes-disponibles', [CuadrillaController::class, 'integrantesDisponibles'])
        ->where('id', '[A-Za-z0-9_-]+');
    Route::post('cuadrillas/{id}/integrantes', [CuadrillaController::class, 'agregarIntegrante'])
        ->where('id', '[A-Za-z0-9_-]+');
    Route::put('cuadrillas/{id}/integrantes/{idUsuario}', [CuadrillaController::class, 'actualizarIntegrante'])
        ->where('id', '[A-Za-z0-9_-]+')->whereNumber('idUsuario');
    Route::delete('cuadrillas/{id}/integrantes/{idUsuario}', [CuadrillaController::class, 'quitarIntegrante'])
        ->where('id', '[A-Za-z0-9_-]+')->whereNumber('idUsuario');
    Route::get('cuadrillas/{id}/rutas', [CuadrillaController::class, 'rutas'])
        ->where('id', '[A-Za-z0-9_-]+');
    Route::post('cuadrillas/{id}/rutas', [CuadrillaController::class, 'asignarRuta'])
        ->where('id', '[A-Za-z0-9_-]+');
    Route::put('cuadrillas/{id}/rutas/{idAsignacion}', [CuadrillaController::class, 'actualizarRuta'])
        ->where('id', '[A-Za-z0-9_-]+')->whereNumber('idAsignacion');
    Route::delete('cuadrillas/{id}/rutas/{idAsignacion}', [CuadrillaController::class, 'quitarRuta'])
        ->where('id', '[A-Za-z0-9_-]+')->whereNumber('idAsignacion');
    Route::get('cuadrillas/{id}/historiales', [CuadrillaController::class, 'historiales'])
        ->where('id', '[A-Za-z0-9_-]+');

    Route::get('contenedores', [ContenedorController::class, 'index']);
    Route::get('contenedores/catalogos', [ContenedorController::class, 'catalogos']);
    Route::get('contenedores/resumen-circuitos', [ContenedorController::class, 'resumenCircuitos']);
    Route::post('contenedores', [ContenedorController::class, 'store']);
    Route::get('contenedores/{id}', [ContenedorController::class, 'show'])->whereNumber('id');
    Route::put('contenedores/{id}', [ContenedorController::class, 'update'])->whereNumber('id');
    Route::put('contenedores/{id}/ubicacion', [ContenedorController::class, 'actualizarUbicacion'])->whereNumber('id');
    Route::delete('contenedores/{id}', [ContenedorController::class, 'destroy'])->whereNumber('id');
    Route::post('contenedores/{id}/reactivar', [ContenedorController::class, 'reactivar'])->whereNumber('id');
    Route::post('contenedores/{id}/reemplazar', [ContenedorController::class, 'reemplazar'])->whereNumber('id');
    Route::get('contenedores/{id}/historial', [ContenedorController::class, 'historial'])->whereNumber('id');

    Route::get('instalaciones', [InstalacionController::class, 'index']);
    Route::post('instalaciones', [InstalacionController::class, 'store']);
    Route::put('instalaciones/{id}', [InstalacionController::class, 'update'])->whereNumber('id');
    Route::delete('instalaciones/{id}', [InstalacionController::class, 'destroy'])->whereNumber('id');
    Route::get('instalaciones/{id}/inventario', [InstalacionController::class, 'inventario'])->whereNumber('id');
    Route::post('instalaciones/{id}/inventario', [InstalacionController::class, 'agregarInventario'])->whereNumber('id');
    Route::put('instalaciones/{id}/inventario/{idInventario}', [InstalacionController::class, 'actualizarInventario'])
        ->whereNumber('id')->whereNumber('idInventario');
    Route::delete('instalaciones/{id}/inventario/{idInventario}', [InstalacionController::class, 'eliminarInventario'])
        ->whereNumber('id')->whereNumber('idInventario');
    Route::get('instalaciones/{id}/operarios', [InstalacionController::class, 'operarios'])->whereNumber('id');
    Route::get('instalaciones/{id}/operarios-disponibles', [InstalacionController::class, 'operariosDisponibles'])->whereNumber('id');
    Route::post('instalaciones/{id}/operarios', [InstalacionController::class, 'asignarOperario'])->whereNumber('id');
    Route::put('instalaciones/{id}/operarios/{idUsuario}', [InstalacionController::class, 'actualizarOperario'])
        ->whereNumber('id')->whereNumber('idUsuario');
    Route::delete('instalaciones/{id}/operarios/{idUsuario}', [InstalacionController::class, 'quitarOperario'])
        ->whereNumber('id')->whereNumber('idUsuario');

    Route::get('camiones/catalogos', [CamionController::class, 'catalogos']);
    Route::get('camiones', [CamionController::class, 'index']);
    Route::post('camiones', [CamionController::class, 'store']);
    Route::get('camiones/{id}', [CamionController::class, 'show'])->whereNumber('id');
    Route::delete('camiones/{id}', [CamionController::class, 'destroy'])->whereNumber('id');
    Route::put('camiones/{id}/foto', [CamionController::class, 'actualizarFoto'])->whereNumber('id');
    Route::put('camiones/{id}/chofer', [CamionController::class, 'asignarChofer'])->whereNumber('id');
    Route::put('camiones/{id}/cuadrilla', [CamionController::class, 'asignarCuadrilla'])->whereNumber('id');
    Route::match(['put', 'post', 'delete'], 'camiones/{id}/ruta', [CamionController::class, 'asignarRuta'])
        ->whereNumber('id');
    Route::put('camiones/{id}/ruta/estado', [CamionController::class, 'actualizarEstadoRuta'])
        ->whereNumber('id');
    Route::put('camiones/{id}/gps', [RecoleccionController::class, 'actualizarGpsCamion'])
        ->whereNumber('id');

    Route::get('incidencias', [IncidenciaController::class, 'index']);
    Route::get('incidencias/{id}', [IncidenciaController::class, 'show'])->whereNumber('id');
    Route::put('incidencias/{id}', [IncidenciaController::class, 'update'])->whereNumber('id');
    Route::post('/incidencias', [IncidenciaController::class, 'store']);
    Route::get('reclamos', [ReclamoController::class, 'index']);
    Route::post('reclamos', [ReclamoController::class, 'store']);
    Route::get('reclamos/{id}', [ReclamoController::class, 'show'])->where('id', '[A-Za-z0-9_-]+');
    Route::put('reclamos/{id}/asignar', [ReclamoController::class, 'asignar'])->where('id', '[A-Za-z0-9_-]+');
    Route::put('reclamos/{id}/quitar-asignacion', [ReclamoController::class, 'quitarAsignacion'])->where('id', '[A-Za-z0-9_-]+');
    Route::put('reclamos/{id}/completar', [ReclamoController::class, 'completar'])->where('id', '[A-Za-z0-9_-]+');
    Route::put('reclamos/{id}/reabrir', [ReclamoController::class, 'reabrir'])->where('id', '[A-Za-z0-9_-]+');
    Route::put('reclamos/{id}/resultado', [ReclamoController::class, 'registrarResultado'])->where('id', '[A-Za-z0-9_-]+');
    Route::put('reclamos/{id}/notificar', [ReclamoController::class, 'notificar'])->where('id', '[A-Za-z0-9_-]+');

    Route::get('rutas', [RutaController::class, 'index']);
    Route::get('rutas/historial', [RutaController::class, 'historial']);
    Route::put('rutas/{id}/habitual', [RutaController::class, 'configurarHabitual'])
        ->whereNumber('id');
    Route::patch('rutas/{id}/frecuencia', [RutaController::class, 'configurarHabitual'])
        ->whereNumber('id');

    Route::post('rutas/despachar-automaticamente', function (Request $request) {
        $usuario = $request->user('api');
        $rol = data_get($usuario, 'usuario.rol');

        if (is_object($rol)) {
            $rol = data_get($rol, 'nombre', data_get($rol, 'rol'));
        }

        if (!in_array(strtolower(trim((string) $rol)), ['admin', 'administrador'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'No tenés permisos para despachar rutas automáticamente.',
            ], 403);
        }

        return app(RutaController::class)->despacharAutomaticamente();
    });
    Route::get(
    'cuadrilla/mi-panel',
    [PanelCuadrillaController::class, 'miPanel']
    );

    Route::get('recolecciones', [RecoleccionController::class, 'index']);
    Route::post('rutas/despachar', [RecoleccionController::class, 'despacharRuta']);
    Route::get('chofer/ruta', [RecoleccionController::class, 'rutaDeChofer']);
    Route::post('recolecciones/iniciar', [RecoleccionController::class, 'iniciarRecoleccion']);
    Route::post('recolecciones/{id}/contenedor', [RecoleccionController::class, 'recolectarContenedor'])
        ->whereNumber('id');
    Route::post('recolecciones/{id}/finalizar', [RecoleccionController::class, 'finalizarRecoleccion'])
        ->whereNumber('id');
    Route::get('chofer/historial-rutas', [RecoleccionController::class, 'historialRutasChofer']);
    Route::post(
    'cuadrilla/reclamos/{id}/resolver',
    [PanelCuadrillaController::class, 'resolverReclamo']
    )->where('id', '[A-Za-z0-9_-]+');

    Route::get(
    'mensajes/contactos',
    [MensajeInternoController::class, 'contactos']
    );

    Route::get(
    'mensajes/conversacion/{idContacto}',
    [MensajeInternoController::class, 'conversacion']
    )->whereNumber('idContacto');

    Route::post(
    'mensajes/enviar',
    [MensajeInternoController::class, 'enviar']
    );

    Route::put(
    'mensajes/conversacion/{idContacto}/leidos',
    [MensajeInternoController::class, 'marcarLeidos']
    )->whereNumber('idContacto');

    Route::post(
    'cuadrilla/incidencias/{id}/resolver',
    [PanelCuadrillaController::class, 'resolverIncidencia']
    )->whereNumber('id');
    Route::get('estadisticas', [EstadisticaController::class, 'index']);
});