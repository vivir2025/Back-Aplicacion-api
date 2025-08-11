<?php

namespace App\Http\Controllers;

use App\Models\Paciente;
use Illuminate\Http\Request;

class PacienteController extends Controller
{
    public function index()
    {
        return Paciente::with('sede')->get();
    }

    public function store(Request $request)
    {
        $request->validate([
            'identificacion' => 'required|unique:pacientes',
            'fecnacimiento' => 'required|date',
            'nombre' => 'required',
            'apellido' => 'required',
            'genero' => 'required',
            'idsede' => 'required|exists:sedes,id',
        ]);

        $paciente = Paciente::create($request->all());

        return response()->json($paciente, 201);
    }

    public function show($id)
    {
        return Paciente::with('sede')->findOrFail($id);
    }

    public function update(Request $request, $id)
    {
        $paciente = Paciente::findOrFail($id);

        $request->validate([
            'identificacion' => 'sometimes|required|unique:pacientes,identificacion,'.$paciente->id,
            'fecnacimiento' => 'sometimes|required|date',
            'nombre' => 'sometimes|required',
            'apellido' => 'sometimes|required',
            'genero' => 'sometimes|required',
            'idsede' => 'sometimes|required|exists:sedes,id',
        ]);

        $paciente->update($request->all());

        return response()->json($paciente);
    }

    public function destroy($id)
    {
        Paciente::findOrFail($id)->delete();
        return response()->json(null, 204);
    }

    public function buscarPorIdentificacion($identificacion)
    {
        $paciente = Paciente::where('identificacion', $identificacion)->first();

        if (!$paciente) {
            return response()->json(['message' => 'Paciente no encontrado'], 404);
        }

        return response()->json($paciente);
    }
    // En PacienteController.php
public function updateCoordenadas(Request $request, $id)
{
    try {
        $request->validate([
            'latitud' => 'required|numeric|between:-90,90',
            'longitud' => 'required|numeric|between:-180,180',
        ]);

        $paciente = Paciente::findOrFail($id);
        
        $paciente->update([
            'latitud' => $request->latitud,
            'longitud' => $request->longitud,
            'updated_at' => now(),
        ]);

        Log::info('Coordenadas del paciente actualizadas via API', [
            'paciente_id' => $id,
            'latitud' => $request->latitud,
            'longitud' => $request->longitud
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Coordenadas actualizadas exitosamente',
            'data' => $paciente->fresh()
        ]);

    } catch (\Exception $e) {
        Log::error('Error actualizando coordenadas: ' . $e->getMessage());
        
        return response()->json([
            'success' => false,
            'message' => 'Error al actualizar coordenadas: ' . $e->getMessage()
        ], 500);
    }
}
}