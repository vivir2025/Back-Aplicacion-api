<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\Log;

use App\Models\Paciente;
use Illuminate\Http\Request;

/**
 * @group Gestión de Pacientes
 *
 * Endpoints para el registro, consulta y actualización de pacientes en el sistema.
 */
class PacienteController extends Controller
{
    /**
     * Listar pacientes
     *
     * Obtiene todos los pacientes registrados con su sede asociada.
     * 
     * @authenticated
     * @response 200 [
     *  {
     *   "id": "uuid-1234",
     *   "identificacion": "102030",
     *   "nombre": "Juan",
     *   "apellido": "Perez",
     *   "sede": {"id": 1, "nombre": "Sede Central"}
     *  }
     * ]
     */
    public function index()
    {
        return Paciente::with('sede')->get();
    }

    /**
     * Registrar paciente
     *
     * Crea un nuevo registro de paciente.
     * 
     * @authenticated
     * @bodyParam identificacion string required El documento de identidad. Example: 10203040
     * @bodyParam fecnacimiento string required Fecha de nacimiento (Y-m-d). Example: 1990-05-15
     * @bodyParam nombre string required Nombres del paciente. Example: Carlos
     * @bodyParam apellido string required Apellidos del paciente. Example: Rodriguez
     * @bodyParam genero string required Género (Masculino/Femenino/Otro). Example: Masculino
     * @bodyParam idsede string required ID de la sede a la que pertenece. Example: 1
     * 
     * @response 201 {
     *  "id": "uuid-5678",
     *  "identificacion": "10203040",
     *  "nombre": "Carlos",
     *  "estado": "activo"
     * }
     */
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

    /**
     * Consultar paciente
     * 
     * @authenticated
     * @urlParam id string required ID del paciente.
     */
    public function show($id)
    {
        return Paciente::with('sede')->findOrFail($id);
    }

    /**
     * Actualizar paciente
     * 
     * @authenticated
     * @urlParam id string required ID del paciente.
     */
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

    /**
     * Eliminar paciente
     * 
     * @authenticated
     * @urlParam id string required ID del paciente.
     */
    public function destroy($id)
    {
        Paciente::findOrFail($id)->delete();
        return response()->json(null, 204);
    }

    /**
     * Buscar por identificación
     *
     * Encuentra un paciente usando su número de documento.
     * 
     * @authenticated
     * @urlParam identificacion string required El número del documento. Example: 102030
     * 
     * @response 200 {
     *  "id": "uuid-1234",
     *  "identificacion": "102030",
     *  "nombre": "Juan"
     * }
     * @response 404 {
     *  "message": "Paciente no encontrado"
     * }
     */
    public function buscarPorIdentificacion($identificacion)
    {
        $paciente = Paciente::where('identificacion', $identificacion)->first();

        if (!$paciente) {
            return response()->json(['message' => 'Paciente no encontrado'], 404);
        }

        return response()->json($paciente);
    }
    // En PacienteController.php
    /**
     * Actualizar coordenadas
     *
     * Actualiza la ubicación geográfica (Latitud/Longitud) del paciente.
     * 
     * @authenticated
     * @bodyParam latitud number required Latitud entre -90 y 90. Example: 6.2442
     * @bodyParam longitud number required Longitud entre -180 y 180. Example: -75.5812
     * 
     * @response 200 {
     *  "success": true,
     *  "message": "Coordenadas actualizadas exitosamente",
     *  "data": {"id": "uuid-123", "latitud": 6.2442, "longitud": -75.5812}
     * }
     */
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