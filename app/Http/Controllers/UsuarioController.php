<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class UsuarioController extends Controller
{
    /**
     * Mostrar una lista de todos los usuarios.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        try {
            // Obtener todos los usuarios con sus sedes relacionadas
            $usuarios = Usuario::with('sede')->get();
            
            // Filtrar por rol si se proporciona
            if ($request->has('rol')) {
                $rol = $request->rol;
                $usuarios = $usuarios->filter(function($usuario) use ($rol) {
                    return strtolower($usuario->rol) === strtolower($rol);
                })->values();
            }
            
            // Filtrar por sede si se proporciona
            if ($request->has('sede_id')) {
                $sedeId = $request->sede_id;
                $usuarios = $usuarios->filter(function($usuario) use ($sedeId) {
                    return $usuario->sede_id == $sedeId;
                })->values();
            }
            
            return response()->json($usuarios);
        } catch (\Exception $e) {
            Log::error('Error al obtener usuarios: ' . $e->getMessage());
            return response()->json(['error' => 'Error al obtener usuarios'], 500);
        }
    }

    /**
     * Almacenar un nuevo usuario.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $request->validate([
            'nombre' => 'required|string|max:255',
            'usuario' => 'required|string|max:50|unique:usuarios',
            'correo' => 'required|email|unique:usuarios',
            'contrasena' => 'required|string|min:6',
            'rol' => 'required|string|in:admin,auxiliar,aux,supervisor',
            'sede_id' => 'required|exists:sedes,id',
        ]);

        try {
            $usuario = Usuario::create([
                'nombre' => $request->nombre,
                'usuario' => $request->usuario,
                'correo' => $request->correo,
                'contrasena' => Hash::make($request->contrasena),
                'rol' => $request->rol,
                'sede_id' => $request->sede_id,
                'estado' => $request->estado ?? 'activo',
            ]);

            return response()->json([
                'message' => 'Usuario creado correctamente',
                'usuario' => $usuario->load('sede')
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error al crear usuario: ' . $e->getMessage());
            return response()->json(['error' => 'Error al crear usuario'], 500);
        }
    }

    /**
     * Mostrar un usuario específico.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        try {
            $usuario = Usuario::with('sede')->findOrFail($id);
            return response()->json($usuario);
        } catch (\Exception $e) {
            Log::error('Error al obtener usuario: ' . $e->getMessage());
            return response()->json(['error' => 'Usuario no encontrado'], 404);
        }
    }

    /**
     * Actualizar un usuario específico.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        try {
            $usuario = Usuario::findOrFail($id);
            
            $request->validate([
                'nombre' => 'sometimes|string|max:255',
                'usuario' => 'sometimes|string|max:50|unique:usuarios,usuario,'.$id,
                'correo' => 'sometimes|email|unique:usuarios,correo,'.$id,
                'contrasena' => 'sometimes|string|min:6',
                'rol' => 'sometimes|string|in:admin,auxiliar,aux,supervisor',
                'sede_id' => 'sometimes|exists:sedes,id',
                'estado' => 'sometimes|string|in:activo,inactivo',
            ]);

            // Actualizar campos si están presentes
            if ($request->has('nombre')) $usuario->nombre = $request->nombre;
            if ($request->has('usuario')) $usuario->usuario = $request->usuario;
            if ($request->has('correo')) $usuario->correo = $request->correo;
            if ($request->has('contrasena')) $usuario->contrasena = Hash::make($request->contrasena);
            if ($request->has('rol')) $usuario->rol = $request->rol;
            if ($request->has('sede_id')) $usuario->sede_id = $request->sede_id;
            if ($request->has('estado')) $usuario->estado = $request->estado;
            
            $usuario->save();

            return response()->json([
                'message' => 'Usuario actualizado correctamente',
                'usuario' => $usuario->load('sede')
            ]);
        } catch (\Exception $e) {
            Log::error('Error al actualizar usuario: ' . $e->getMessage());
            return response()->json(['error' => 'Error al actualizar usuario'], 500);
        }
    }

    /**
     * Eliminar un usuario específico.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        try {
            $usuario = Usuario::findOrFail($id);
            $usuario->delete();
            
            return response()->json([
                'message' => 'Usuario eliminado correctamente'
            ]);
        } catch (\Exception $e) {
            Log::error('Error al eliminar usuario: ' . $e->getMessage());
            return response()->json(['error' => 'Error al eliminar usuario'], 500);
        }
    }
    
    /**
     * Obtener usuarios por rol.
     *
     * @param  string  $rol
     * @return \Illuminate\Http\Response
     */
    public function getUsuariosPorRol($rol)
    {
        try {
            $usuarios = Usuario::with('sede')
                ->where('rol', $rol)
                ->orWhere('rol', 'like', "%$rol%")
                ->get();
                
            return response()->json($usuarios);
        } catch (\Exception $e) {
            Log::error('Error al obtener usuarios por rol: ' . $e->getMessage());
            return response()->json(['error' => 'Error al obtener usuarios'], 500);
        }
    }
    
    /**
     * Obtener auxiliares (método específico).
     *
     * @return \Illuminate\Http\Response
     */
    public function getAuxiliares()
    {
        try {
            $auxiliares = Usuario::with('sede')
                ->where('rol', 'aux')
                ->orWhere('rol', 'auxiliar')
                ->get();
                
            return response()->json($auxiliares);
        } catch (\Exception $e) {
            Log::error('Error al obtener auxiliares: ' . $e->getMessage());
            return response()->json(['error' => 'Error al obtener auxiliares'], 500);
        }
    }
}
