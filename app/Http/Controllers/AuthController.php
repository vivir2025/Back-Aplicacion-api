<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * @group Autenticación
 *
 * Endpoints para gestionar el acceso a la API y el perfil del usuario.
 */
class AuthController extends Controller
{
    /**
     * Iniciar sesión
     *
     * Permite a un usuario obtener un token de acceso (Bearer) proporcionando sus credenciales.
     *
     * @bodyParam usuario string required El nombre de usuario. Example: admin
     * @bodyParam contrasena string required La contraseña del usuario. Example: secret123
     *
     * @response 200 {
     *  "token": "1|ABC...",
     *  "usuario": {"id": 1, "nombre": "Admin", "rol": "admin"},
     *  "sede": {"id": 1, "nombre": "Sede Central"}
     * }
     * @response 422 {
     *  "message": "Las credenciales proporcionadas son incorrectas.",
     *  "errors": {"usuario": ["Las credenciales proporcionadas son incorrectas."]}
     * }
     * @unauthenticated
     */
    public function login(Request $request)
    {
        $request->validate([
            'usuario' => 'required',
            'contrasena' => 'required',
        ]);

        $usuario = Usuario::where('usuario', $request->usuario)->first();

        if (!$usuario || !Hash::check($request->contrasena, $usuario->contrasena)) {
            throw ValidationException::withMessages([
                'usuario' => ['Las credenciales proporcionadas son incorrectas.'],
            ]);
        }

        return response()->json([
            'token' => $usuario->createToken($request->usuario)->plainTextToken,
            'usuario' => $usuario,
            'sede' => $usuario->sede
        ]);
    }

    /**
     * Cerrar sesión
     *
     * Revoca el token de acceso actual del usuario autenticado.
     *
     * @authenticated
     * @response 200 {
     *  "message": "Sesión cerrada correctamente"
     * }
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        
        return response()->json(['message' => 'Sesión cerrada correctamente']);
    }

    /**
     * Ver perfil
     *
     * Obtiene la información detallada del usuario autenticado y su sede asociada.
     *
     * @authenticated
     * @response 200 {
     *  "id": 1,
     *  "nombre": "Admin",
     *  "usuario": "admin",
     *  "correo": "admin@test.com",
     *  "rol": "admin",
     *  "sede": {"id": 1, "nombre": "Sede Central"}
     * }
     */
    public function perfil(Request $request)
    {
        return response()->json($request->user()->load('sede'));
    }

    /**
     * Actualizar perfil
     *
     * Permite al usuario autenticado actualizar su nombre, correo y contraseña.
     *
     * @authenticated
     * @bodyParam nombre string El nombre completo. Example: Juan Perez
     * @bodyParam correo string El correo electrónico. Example: juan@test.com
     * @bodyParam contrasena_actual string La contraseña actual (requerida si se cambia la nueva).
     * @bodyParam contrasena_nueva string La nueva contraseña (mínimo 6 caracteres).
     *
     * @response 200 {
     *  "message": "Perfil actualizado correctamente",
     *  "usuario": {"id": 1, "nombre": "Juan Perez", "correo": "juan@test.com"}
     * }
     * @response 422 {
     *  "error": "La contrasena actual no es correcta"
     * }
     */
    public function actualizarPerfil(Request $request)
    {
        $usuario = $request->user();

        $request->validate([
            'nombre' => 'sometimes|string|max:255',
            'correo' => 'sometimes|email|unique:usuarios,correo,'.$usuario->id,
            'contrasena_actual' => 'sometimes|required_with:contrasena_nueva',
            'contrasena_nueva' => 'sometimes|required_with:contrasena_actual|min:6',
        ]);

        if ($request->has('contrasena_actual') && !Hash::check($request->contrasena_actual, $usuario->contrasena)) {
            return response()->json(['error' => 'La contrasena actual no es correcta'], 422);
        }

        $usuario->update([
            'nombre' => $request->nombre ?? $usuario->nombre,
            'correo' => $request->correo ?? $usuario->correo,
            'contrasena' => $request->contrasena_nueva ? Hash::make($request->contrasena_nueva) : $usuario->contrasena,
        ]);

        return response()->json(['message' => 'Perfil actualizado correctamente', 'usuario' => $usuario->load('sede')]);
    }
}