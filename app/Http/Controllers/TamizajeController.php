<?php

namespace App\Http\Controllers;

use App\Models\Tamizaje;
use App\Models\Paciente;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class TamizajeController extends Controller
{
    /**
     * Obtener todos los tamizajes con información del paciente y usuario
     */
    public function index(Request $request)
    {
        try {
            $query = Tamizaje::with([
                'paciente.sede',
                'usuario'
            ]);

            // Filtros opcionales
            if ($request->has('paciente_id')) {
                $query->where('idpaciente', $request->paciente_id);
            }

            if ($request->has('usuario_id')) {
                $query->where('idusuario', $request->usuario_id);
            }

            if ($request->has('fecha_desde')) {
                $query->whereDate('fecha_primera_toma', '>=', $request->fecha_desde);
            }

            if ($request->has('fecha_hasta')) {
                $query->whereDate('fecha_primera_toma', '<=', $request->fecha_hasta);
            }

            $tamizajes = $query->orderBy('fecha_primera_toma', 'desc')->get();

            // Agregar datos adicionales del paciente con validación
            $tamizajes->transform(function ($tamizaje) {
                return $this->transformTamizaje($tamizaje);
            });

            return response()->json($tamizajes);

        } catch (\Exception $e) {
            Log::error('Error al obtener tamizajes: ' . $e->getMessage());
            return response()->json(['error' => 'Error al obtener tamizajes'], 500);
        }
    }

    /**
     * Crear un nuevo tamizaje
     */
    public function store(Request $request)
    {
        $request->validate([
            'idpaciente' => 'required|exists:pacientes,id',
            'vereda_residencia' => 'required|string|max:100',
            'telefono' => 'nullable|string|max:20',
            'brazo_toma' => 'required|in:izquierdo,derecho',
            'posicion_persona' => 'required|in:de_pie,acostado,sentado',
            'reposo_cinco_minutos' => 'required|in:si,no',
            'fecha_primera_toma' => 'required|date',
            'pa_sistolica' => 'required|integer|min:50|max:300',
            'pa_diastolica' => 'required|integer|min:30|max:200',
            'conducta' => 'nullable|string|max:1000'
        ]);

        try {
            // Validar que la presión sistólica sea mayor que la diastólica
            if ($request->pa_sistolica <= $request->pa_diastolica) {
                return response()->json([
                    'error' => 'La presión sistólica debe ser mayor que la diastólica'
                ], 422);
            }

            // Obtener el usuario autenticado
            $usuarioId = $request->user()->id ?? $request->idusuario;
            
            if (!$usuarioId) {
                return response()->json(['error' => 'Usuario no autenticado'], 401);
            }

            $data = $request->all();
            $data['idusuario'] = $usuarioId;

            $tamizaje = Tamizaje::create($data);

            // Cargar las relaciones
            $tamizaje->load(['paciente.sede', 'usuario']);

            // Transformar con validación
            $tamizajeTransformado = $this->transformTamizaje($tamizaje);

            return response()->json([
                'message' => 'Tamizaje creado exitosamente',
                'data' => $tamizajeTransformado
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error al crear tamizaje: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            return response()->json([
                'error' => 'Error al crear tamizaje',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener un tamizaje específico
     */
    public function show($id)
    {
        try {
            $tamizaje = Tamizaje::with(['paciente.sede', 'usuario'])->findOrFail($id);
            
            return response()->json($this->transformTamizaje($tamizaje));

        } catch (\Exception $e) {
            Log::error('Error al obtener tamizaje: ' . $e->getMessage());
            return response()->json(['error' => 'Tamizaje no encontrado'], 404);
        }
    }

    /**
     * Actualizar un tamizaje
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'vereda_residencia' => 'sometimes|string|max:100',
            'telefono' => 'sometimes|nullable|string|max:20',
            'brazo_toma' => 'sometimes|in:izquierdo,derecho',
            'posicion_persona' => 'sometimes|in:de_pie,acostado,sentado',
            'reposo_cinco_minutos' => 'sometimes|in:si,no',
            'fecha_primera_toma' => 'sometimes|date',
            'pa_sistolica' => 'sometimes|integer|min:50|max:300',
            'pa_diastolica' => 'sometimes|integer|min:30|max:200',
            'conducta' => 'sometimes|nullable|string|max:1000'
        ]);

        try {
            $tamizaje = Tamizaje::findOrFail($id);

            // Validar presión arterial si se están actualizando ambos valores
            if ($request->has('pa_sistolica') && $request->has('pa_diastolica')) {
                if ($request->pa_sistolica <= $request->pa_diastolica) {
                    return response()->json([
                        'error' => 'La presión sistólica debe ser mayor que la diastólica'
                    ], 422);
                }
            }

            $tamizaje->update($request->all());
            $tamizaje->load(['paciente.sede', 'usuario']);

            return response()->json([
                'message' => 'Tamizaje actualizado exitosamente',
                'data' => $this->transformTamizaje($tamizaje)
            ]);

        } catch (\Exception $e) {
            Log::error('Error al actualizar tamizaje: ' . $e->getMessage());
            return response()->json([
                'error' => 'Error al actualizar tamizaje',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar un tamizaje
     */
    public function destroy($id)
    {
        try {
            $tamizaje = Tamizaje::findOrFail($id);
            $tamizaje->delete();

            return response()->json([
                'message' => 'Tamizaje eliminado exitosamente'
            ]);

        } catch (\Exception $e) {
            Log::error('Error al eliminar tamizaje: ' . $e->getMessage());
            return response()->json(['error' => 'Error al eliminar tamizaje'], 500);
        }
    }

    /**
     * Obtener tamizajes por paciente
     */
    public function tamizajesPorPaciente($pacienteId)
    {
        try {
            $tamizajes = Tamizaje::with(['paciente.sede', 'usuario'])
                ->where('idpaciente', $pacienteId)
                ->orderBy('fecha_primera_toma', 'desc')
                ->get();

            $tamizajes->transform(function ($tamizaje) {
                return $this->transformTamizaje($tamizaje);
            });

            return response()->json($tamizajes);

        } catch (\Exception $e) {
            Log::error('Error al obtener tamizajes por paciente: ' . $e->getMessage());
            return response()->json(['error' => 'Error al obtener tamizajes'], 500);
        }
    }

    /**
     * Obtener tamizajes del usuario autenticado
     */
    public function getMisTamizajes(Request $request)
    {
        try {
            $usuarioId = $request->user()->id;
            
            $tamizajes = Tamizaje::with(['paciente.sede', 'usuario'])
                ->where('idusuario', $usuarioId)
                ->orderBy('created_at', 'desc')
                ->get();

            $tamizajes->transform(function ($tamizaje) {
                return $this->transformTamizaje($tamizaje);
            });

            return response()->json($tamizajes);
        } catch (\Exception $e) {
            Log::error('Error al obtener mis tamizajes: ' . $e->getMessage());
            return response()->json(['error' => 'Error al obtener tamizajes'], 500);
        }
    }

    /**
     * Obtener estadísticas de tamizajes
     */
    public function estadisticas()
    {
        try {
            $totalTamizajes = Tamizaje::count();
            $tamizajesHoy = Tamizaje::whereDate('created_at', today())->count();
            $tamizajesEsteMes = Tamizaje::whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count();

            // Estadísticas de presión arterial
            $hipertensionStage1 = Tamizaje::where('pa_sistolica', '>=', 130)
                ->where('pa_sistolica', '<=', 139)
                ->orWhere(function($query) {
                    $query->where('pa_diastolica', '>=', 80)
                          ->where('pa_diastolica', '<=', 89);
                })->count();

            $hipertensionStage2 = Tamizaje::where('pa_sistolica', '>=', 140)
                ->orWhere('pa_diastolica', '>=', 90)->count();

            return response()->json([
                'total_tamizajes' => $totalTamizajes,
                'tamizajes_hoy' => $tamizajesHoy,
                'tamizajes_este_mes' => $tamizajesEsteMes,
                'hipertension_stage_1' => $hipertensionStage1,
                'hipertension_stage_2' => $hipertensionStage2
            ]);

        } catch (\Exception $e) {
            Log::error('Error al obtener estadísticas: ' . $e->getMessage());
            return response()->json(['error' => 'Error al obtener estadísticas'], 500);
        }
    }

    /**
     * Transformar tamizaje con validación de fechas
     */
    private function transformTamizaje($tamizaje)
    {
        // Validar y calcular edad de forma segura
        $edad = null;
        try {
            if ($tamizaje->paciente && $tamizaje->paciente->fecnacimiento) {
                if ($tamizaje->paciente->fecnacimiento instanceof Carbon) {
                    $edad = $tamizaje->paciente->fecnacimiento->age;
                } else {
                    // Intentar parsear si es string
                    $fecha = Carbon::parse($tamizaje->paciente->fecnacimiento);
                    $edad = $fecha->age;
                }
            }
        } catch (\Exception $e) {
            Log::warning('Error al calcular edad del paciente: ' . $e->getMessage());
            $edad = 'N/A';
        }

        $tamizaje->nombre_paciente = $tamizaje->paciente->nombre . ' ' . $tamizaje->paciente->apellido;
        $tamizaje->identificacion_paciente = $tamizaje->paciente->identificacion;
        $tamizaje->edad_paciente = $edad;
        $tamizaje->sexo_paciente = $tamizaje->paciente->genero ?? 'N/A';
        $tamizaje->sede_paciente = $tamizaje->paciente->sede->nombresede ?? 'Sin sede';
        $tamizaje->promotor_vida = $tamizaje->usuario->nombre ?? 'N/A';
        $tamizaje->presion_arterial = $tamizaje->pa_sistolica . '/' . $tamizaje->pa_diastolica;
        
        return $tamizaje;
    }
}
