<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
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

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        
        return response()->json(['message' => 'Sesión cerrada correctamente']);
    }

    public function perfil(Request $request)
    {
        return response()->json($request->user()->load('sede'));
    }

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