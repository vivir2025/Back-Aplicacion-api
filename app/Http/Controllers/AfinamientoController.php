<?php

namespace App\Http\Controllers;

use App\Models\Afinamiento;
use App\Models\Paciente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AfinamientoController extends Controller
{
    /**
     * Mostrar lista de afinamientos
     */
    public function index(Request $request)
    {
        try {
            $query = Afinamiento::with(['paciente', 'usuario']);

            // Filtrar por usuario si se proporciona
            if ($request->has('usuario_id')) {
                $query->where('idusuario', $request->usuario_id);
            }

            // Filtrar por paciente si se proporciona
            if ($request->has('paciente_id')) {
                $query->where('idpaciente', $request->paciente_id);
            }

            // Filtrar por fecha si se proporciona
            if ($request->has('fecha_desde')) {
                $query->where('fecha_tamizaje', '>=', $request->fecha_desde);
            }

            if ($request->has('fecha_hasta')) {
                $query->where('fecha_tamizaje', '<=', $request->fecha_hasta);
            }

            $afinamientos = $query->orderBy('created_at', 'desc')->get();

            // Agregar datos adicionales del paciente
            $afinamientos->transform(function ($afinamiento) {
                $afinamiento->nombre_paciente = $afinamiento->paciente->nombre . ' ' . $afinamiento->paciente->apellido;
                $afinamiento->identificacion_paciente = $afinamiento->paciente->identificacion;
                $afinamiento->edad_paciente = Carbon::parse($afinamiento->paciente->fecnacimiento)->age;
                $afinamiento->promotor_vida = $afinamiento->usuario->nombre;
                return $afinamiento;
            });

            return response()->json($afinamientos);
        } catch (\Exception $e) {
            Log::error('Error al obtener afinamientos: ' . $e->getMessage());
            return response()->json(['error' => 'Error al obtener afinamientos'], 500);
        }
    }

    /**
     * Crear nuevo afinamiento
     */
    public function store(Request $request)
    {
        $request->validate([
            'idpaciente' => 'required|exists:pacientes,id',
            'procedencia' => 'required|string|max:100',
            'fecha_tamizaje' => 'required|date',
            'presion_arterial_tamiz' => 'required|string|max:20',
            
            // Primer afinamiento (opcional)
            'primer_afinamiento_fecha' => 'nullable|date',
            'presion_sistolica_1' => 'nullable|integer|min:50|max:300',
            'presion_diastolica_1' => 'nullable|integer|min:30|max:200',
            
            // Segundo afinamiento (opcional)
            'segundo_afinamiento_fecha' => 'nullable|date',
            'presion_sistolica_2' => 'nullable|integer|min:50|max:300',
            'presion_diastolica_2' => 'nullable|integer|min:30|max:200',
            
            // Tercer afinamiento (opcional)
            'tercer_afinamiento_fecha' => 'nullable|date',
            'presion_sistolica_3' => 'nullable|integer|min:50|max:300',
            'presion_diastolica_3' => 'nullable|integer|min:30|max:200',
            
            'conducta' => 'nullable|string',
        ]);

        try {
            // Obtener el usuario autenticado
            $usuarioId = $request->user()->id ?? $request->idusuario;
            
            if (!$usuarioId) {
                return response()->json(['error' => 'Usuario no autenticado'], 401);
            }

            $data = $request->all();
            $data['idusuario'] = $usuarioId;

            // Crear el afinamiento (los promedios se calculan automáticamente)
            $afinamiento = Afinamiento::create($data);

            // Cargar las relaciones
            $afinamiento->load(['paciente', 'usuario']);

            // Agregar datos adicionales
            $afinamiento->nombre_paciente = $afinamiento->paciente->nombre . ' ' . $afinamiento->paciente->apellido;
            $afinamiento->identificacion_paciente = $afinamiento->paciente->identificacion;
            $afinamiento->edad_paciente = Carbon::parse($afinamiento->paciente->fecnacimiento)->age;
            $afinamiento->promotor_vida = $afinamiento->usuario->nombre;

            return response()->json([
                'message' => 'Afinamiento creado exitosamente',
                'data' => $afinamiento
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error al crear afinamiento: ' . $e->getMessage());
            return response()->json([
                'error' => 'Error al crear afinamiento',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mostrar afinamiento específico
     */
    public function show($id)
    {
        try {
            $afinamiento = Afinamiento::with(['paciente', 'usuario'])->findOrFail($id);
            
            // Agregar datos adicionales
            $afinamiento->nombre_paciente = $afinamiento->paciente->nombre . ' ' . $afinamiento->paciente->apellido;
            $afinamiento->identificacion_paciente = $afinamiento->paciente->identificacion;
            $afinamiento->edad_paciente = Carbon::parse($afinamiento->paciente->fecnacimiento)->age;
            $afinamiento->promotor_vida = $afinamiento->usuario->nombre;

            return response()->json($afinamiento);
        } catch (\Exception $e) {
            Log::error('Error al obtener afinamiento: ' . $e->getMessage());
            return response()->json(['error' => 'Afinamiento no encontrado'], 404);
        }
    }

    /**
     * Actualizar afinamiento
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'idpaciente' => 'sometimes|exists:pacientes,id',
            'procedencia' => 'sometimes|string|max:100',
            'fecha_tamizaje' => 'sometimes|date',
            'presion_arterial_tamiz' => 'sometimes|string|max:20',
            
            'primer_afinamiento_fecha' => 'nullable|date',
            'presion_sistolica_1' => 'nullable|integer|min:50|max:300',
            'presion_diastolica_1' => 'nullable|integer|min:30|max:200',
            
            'segundo_afinamiento_fecha' => 'nullable|date',
            'presion_sistolica_2' => 'nullable|integer|min:50|max:300',
            'presion_diastolica_2' => 'nullable|integer|min:30|max:200',
            
            'tercer_afinamiento_fecha' => 'nullable|date',
            'presion_sistolica_3' => 'nullable|integer|min:50|max:300',
            'presion_diastolica_3' => 'nullable|integer|min:30|max:200',
            
            'conducta' => 'nullable|string',
        ]);

        try {
            $afinamiento = Afinamiento::findOrFail($id);
            
            // Actualizar solo los campos proporcionados
            $afinamiento->update($request->all());
            
            // Cargar las relaciones
            $afinamiento->load(['paciente', 'usuario']);

            // Agregar datos adicionales
            $afinamiento->nombre_paciente = $afinamiento->paciente->nombre . ' ' . $afinamiento->paciente->apellido;
            $afinamiento->identificacion_paciente = $afinamiento->paciente->identificacion;
            $afinamiento->edad_paciente = Carbon::parse($afinamiento->paciente->fecnacimiento)->age;
            $afinamiento->promotor_vida = $afinamiento->usuario->nombre;

            return response()->json([
                'message' => 'Afinamiento actualizado exitosamente',
                'data' => $afinamiento
            ]);

        } catch (\Exception $e) {
            Log::error('Error al actualizar afinamiento: ' . $e->getMessage());
            return response()->json([
                'error' => 'Error al actualizar afinamiento',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar afinamiento
     */
    public function destroy($id)
    {
        try {
            $afinamiento = Afinamiento::findOrFail($id);
            $afinamiento->delete();

            return response()->json([
                'message' => 'Afinamiento eliminado exitosamente'
            ]);

        } catch (\Exception $e) {
            Log::error('Error al eliminar afinamiento: ' . $e->getMessage());
            return response()->json(['error' => 'Error al eliminar afinamiento'], 500);
        }
    }

    /**
     * Obtener afinamientos por paciente
     */
    public function getAfinamientosPorPaciente($pacienteId)
    {
        try {
            $afinamientos = Afinamiento::with(['paciente', 'usuario'])
                ->where('idpaciente', $pacienteId)
                ->orderBy('fecha_tamizaje', 'desc')
                ->get();

            $afinamientos->transform(function ($afinamiento) {
                $afinamiento->nombre_paciente = $afinamiento->paciente->nombre . ' ' . $afinamiento->paciente->apellido;
                $afinamiento->identificacion_paciente = $afinamiento->paciente->identificacion;
                $afinamiento->edad_paciente = Carbon::parse($afinamiento->paciente->fecnacimiento)->age;
                $afinamiento->promotor_vida = $afinamiento->usuario->nombre;
                return $afinamiento;
            });

            return response()->json($afinamientos);
        } catch (\Exception $e) {
            Log::error('Error al obtener afinamientos por paciente: ' . $e->getMessage());
            return response()->json(['error' => 'Error al obtener afinamientos'], 500);
        }
    }

    /**
     * Obtener afinamientos del usuario autenticado
     */
    public function getMisAfinamientos(Request $request)
    {
        try {
            $usuarioId = $request->user()->id;
            
            $afinamientos = Afinamiento::with(['paciente', 'usuario'])
                ->where('idusuario', $usuarioId)
                ->orderBy('created_at', 'desc')
                ->get();

            $afinamientos->transform(function ($afinamiento) {
                $afinamiento->nombre_paciente = $afinamiento->paciente->nombre . ' ' . $afinamiento->paciente->apellido;
                $afinamiento->identificacion_paciente = $afinamiento->paciente->identificacion;
                $afinamiento->edad_paciente = Carbon::parse($afinamiento->paciente->fecnacimiento)->age;
                $afinamiento->promotor_vida = $afinamiento->usuario->nombre;
                return $afinamiento;
            });

            return response()->json($afinamientos);
        } catch (\Exception $e) {
            Log::error('Error al obtener mis afinamientos: ' . $e->getMessage());
            return response()->json(['error' => 'Error al obtener afinamientos'], 500);
        }
    }
}
