<?php

namespace App\Http\Controllers;

use App\Models\Rol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RolController extends Controller
{
    public function index(): JsonResponse
    {
        $roles = Rol::orderBy('id_rol')
            ->get(['id_rol', 'nombre']);

        return response()->json([
            'success' => true,
            'data' => $roles
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'nombre' => [
                'required',
                'string',
                'max:50',
                Rule::unique('rol', 'nombre')
            ]
        ]);

        $rol = Rol::create([
            'nombre' => strtolower(trim($datos['nombre']))
        ]);

        return response()->json([
            'success' => true,
            'data' => $rol
        ], 201);
    }
}
