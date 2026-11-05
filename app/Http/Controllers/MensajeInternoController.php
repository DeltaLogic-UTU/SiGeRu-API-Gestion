<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MensajeInternoController extends Controller
{
    private function usuarioAutorizado(Request $request)
    {
        $sesion = $request->user('api');

        if (!$sesion || !$sesion->id_usuario) {
            return null;
        }

        return DB::table('usuarios as u')
            ->join('rol as r', 'r.id_rol', '=', 'u.id_rol')
            ->where('u.id_usuario', (int) $sesion->id_usuario)
            ->where('u.estado', 'activo')
            ->whereIn('r.nombre', [
                'administrador',
                'cuadrilla',
                'barrendero',
                'operario'
            ])
            ->select(
                'u.id_usuario',
                'u.nombre',
                'u.apellido',
                'r.nombre as rol'
            )
            ->first();
    }

    private function rolesContrarios(string $rol): array
    {
        return $rol === 'administrador'
            ? ['cuadrilla', 'barrendero', 'operario']
            : ['administrador'];
    }

    private function contactoPermitido($usuario, int $idContacto)
    {
        if ($idContacto === (int) $usuario->id_usuario) {
            return null;
        }

        return DB::table('usuarios as u')
            ->join('rol as r', 'r.id_rol', '=', 'u.id_rol')
            ->where('u.id_usuario', $idContacto)
            ->where('u.estado', 'activo')
            ->whereIn(
                'r.nombre',
                $this->rolesContrarios($usuario->rol)
            )
            ->select(
                'u.id_usuario',
                'u.nombre',
                'u.apellido',
                'r.nombre as rol'
            )
            ->first();
    }

    private function cuadrillaDelOperativo(int $idOperativo): ?string
    {
        $id = DB::table('cuadrilla_integrantes')
            ->where('id_usuario', $idOperativo)
            ->whereNull('fecha_finalizacion')
            ->value('id_cuadrilla');

        if ($id === null) {
            $id = DB::table('cuadrilla')
                ->where('id_responsable', $idOperativo)
                ->value('id_cuadrilla');
        }

        return $id !== null ? (string) $id : null;
    }

    public function contactos(Request $request)
    {
        $usuario = $this->usuarioAutorizado($request);

        if (!$usuario) {
        return response()->json([
            'success' => false,
            'message' => 'No tenés acceso a la mensajería interna.'
            ], 403);
        }

        $pendientes = DB::table('mensajes_internos')
        ->select('id_remitente')
        ->selectRaw('COUNT(*) AS total')
        ->where(
            'id_destinatario',
            (int) $usuario->id_usuario
        )
        ->whereNull('fecha_lectura')
        ->groupBy('id_remitente');

        $contactos = DB::table('usuarios as u')
        ->join('rol as r', 'r.id_rol', '=', 'u.id_rol')
        ->leftJoinSub(
            $pendientes,
            'pendientes',
            function ($join) {
                $join->on(
                    'pendientes.id_remitente',
                    '=',
                    'u.id_usuario'
                );
            }
        )
        ->where('u.estado', 'activo')
        ->whereIn(
            'r.nombre',
            $this->rolesContrarios($usuario->rol)
        )
        ->select(
            'u.id_usuario',
            'u.nombre',
            'u.apellido',
            'r.nombre as rol'
        )
        ->selectRaw(
            'COALESCE(pendientes.total, 0) AS sin_leer'
        )
        ->orderByDesc('sin_leer')
        ->orderBy('u.apellido')
        ->orderBy('u.nombre')
        ->get();

        return response()->json([
            'success' => true,
            'data' => $contactos
        ]);
    }

    public function conversacion(Request $request, int $idContacto)
    {
        $usuario = $this->usuarioAutorizado($request);

        if (!$usuario) {
            return response()->json([
                'success' => false,
                'message' => 'No tenés acceso a la mensajería interna.'
            ], 403);
        }

        $contacto = $this->contactoPermitido(
            $usuario,
            $idContacto
        );

        if (!$contacto) {
            return response()->json([
                'success' => false,
                'message' => 'Contacto no autorizado o inactivo.'
            ], 403);
        }

        $idUsuario = (int) $usuario->id_usuario;

        $mensajes = DB::table('mensajes_internos')
            ->where(function ($q) use ($idUsuario, $idContacto) {
                $q->where(function ($x) use ($idUsuario, $idContacto) {
                    $x->where('id_remitente', $idUsuario)
                        ->where('id_destinatario', $idContacto);
                })->orWhere(function ($x) use ($idUsuario, $idContacto) {
                    $x->where('id_remitente', $idContacto)
                        ->where('id_destinatario', $idUsuario);
                });
            })
            ->orderByDesc('id_mensaje')
            ->limit(100)
            ->get()
            ->reverse()
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'contacto' => $contacto,
                'mensajes' => $mensajes
            ]
        ]);
    }

public function enviar(Request $request)
{
        $usuario = $this->usuarioAutorizado($request);

        if (!$usuario) {
        return response()->json([
            'success' => false,
            'message' => 'No tenés permiso para enviar mensajes.'
        ], 403);
        }

        $datos = $request->validate([
            'id_destinatario' => [
                'required',
                'integer',
                'exists:usuarios,id_usuario'
        ],
        'mensaje' => [
            'required',
            'string',
            'min:1',
            'max:2000'
        ]
        ]);

        $contacto = $this->contactoPermitido(
        $usuario,
        (int) $datos['id_destinatario']
        );

        if (!$contacto) {
        return response()->json([
            'success' => false,
            'message' =>
                'Solo se permiten mensajes entre personal autorizado y Administración.'
            ], 403);
        }

        $mensaje = trim($datos['mensaje']);

        if ($mensaje === '') {
            return response()->json([
            'success' => false,
            'message' => 'El mensaje no puede estar vacío.'
            ], 422);
        }

        $idOperativo = $usuario->rol === 'administrador'
            ? (int) $contacto->id_usuario
            : (int) $usuario->id_usuario;

        $rolOperativo = $usuario->rol === 'administrador'
            ? $contacto->rol
            : $usuario->rol;

        $idCuadrilla = in_array(
            $rolOperativo,
            ['cuadrilla', 'barrendero'],
            true
        )
            ? $this->cuadrillaDelOperativo($idOperativo)
            : null;

        $id = DB::table('mensajes_internos')
            ->insertGetId([
            'id_remitente' => (int) $usuario->id_usuario,
            'id_destinatario' => (int) $contacto->id_usuario,
            'id_cuadrilla' => $idCuadrilla,
            'mensaje' => $mensaje,
            'fecha_envio' => now(),
            'fecha_lectura' => null
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Mensaje enviado correctamente.',
            'data' => DB::table('mensajes_internos')
                ->where('id_mensaje', $id)
                ->first()
        ], 201);
    }

    public function marcarLeidos(
        Request $request,
        int $idContacto
    ) {
        $usuario = $this->usuarioAutorizado($request);

        if (
            !$usuario ||
            !$this->contactoPermitido($usuario, $idContacto)
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Contacto no autorizado.'
            ], 403);
        }

        $actualizados = DB::table('mensajes_internos')
            ->where('id_remitente', $idContacto)
            ->where(
                'id_destinatario',
                (int) $usuario->id_usuario
            )
            ->whereNull('fecha_lectura')
            ->update([
                'fecha_lectura' => now()
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Mensajes marcados como leídos.',
            'data' => [
                'actualizados' => $actualizados
            ]
        ]);
    }
}
