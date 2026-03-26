<?php

namespace App\Http\Controllers;

use App\Models\Afinamiento;
use App\Models\Paciente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Events\AfinamientoCreado;
use App\Events\ModuloError;

/**
 * @group Afinamientos y Seguimiento
 *
 * Gestión de afinamientos de presión arterial para pacientes con sospecha de HTA.
 */
class AfinamientoController extends Controller
{
    /**
     * Listar afinamientos
     *
     * Permite obtener el histórico de afinamientos realizados, filtrando por usuario, paciente o rango de fechas.
     *
     * @authenticated
     * @queryParam usuario_id string ID del usuario promotor. Example: 4044680601076201931
     * @queryParam paciente_id string ID del paciente. Example: 550e8400-e29b-41d4-a716-446655440000
     * @queryParam fecha_desde date Fecha inicial (Y-m-d). Example: 2024-01-01
     * @queryParam fecha_hasta date Fecha final (Y-m-d). Example: 2024-12-31
     *
     * @response scenario=success [
     *  {
     *    "id": "uuid-123",
     *    "idpaciente": "uuid-pac",
     *    "idusuario": "uuid-user",
     *    "fecha_tamizaje": "2024-03-25",
     *    "presion_arterial_tamiz": "145/95",
     *    "promedio_sistolica": 138,
     *    "promedio_diastolica": 88,
     *    "conducta": "Seguimiento en 3 meses",
     *    "nombre_paciente": "Juan Pérez",
     *    "identificacion_paciente": "12345678",
     *    "edad_paciente": 45,
     *    "promotor_vida": "Maria Gomez"
     *  }
     * ]
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
     * Crear afinamiento
     *
     * Registra un nuevo ciclo de 3 tomas de afinamiento de presión arterial.
     * Los promedios se calculan automáticamente en el servidor.
     *
     * @authenticated
     * @bodyParam idpaciente string required ID del paciente. Example: uuid-paciente
     * @bodyParam procedencia string required Origen/Barrio del paciente. Example: Centro
     * @bodyParam fecha_tamizaje date required Fecha del tamizaje inicial. Example: 2024-03-25
     * @bodyParam presion_arterial_tamiz string required Cifra PA inicial. Example: 145/95
     * @bodyParam primer_afinamiento_fecha date Fecha 1era toma. Example: 2024-03-26
     * @bodyParam presion_sistolica_1 int Presión 1era toma. Example: 138
     * @bodyParam presion_diastolica_1 int Presión 1era toma. Example: 88
     *
     * @response scenario="success creation" {
     *   "message": "Afinamiento creado exitosamente",
     *   "data": { "id": "uuid-001", "promedio_sistolica": 138, "promedio_diastolica": 88 }
     * }
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

            // 🔔 Notificación Telegram
            $usuarioObj = Auth::user();
            event(new AfinamientoCreado([
                'sede'     => optional(optional($usuarioObj)->sede)->nombresede ?? 'N/A',
                'paciente' => $afinamiento->nombre_paciente,
                'usuario'  => optional($usuarioObj)->nombre ?? 'N/A',
            ]));

            return response()->json([
                'message' => 'Afinamiento creado exitosamente',
                'data' => $afinamiento
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error al crear afinamiento: ' . $e->getMessage());
            // 🔔 Notificación error Telegram
            $usuarioObj = Auth::user();
            event(new ModuloError([
                'modulo'  => 'Afinamientos',
                'mensaje' => $e->getMessage(),
                'usuario' => optional($usuarioObj)->nombre ?? 'N/A',
                'sede'    => optional(optional($usuarioObj)->sede)->nombresede ?? 'N/A',
            ]));
            return response()->json([
                'error' => 'Error al crear afinamiento',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Consultar afinamiento
     *
     * Obtiene los detalles de un registro específico de afinamiento.
     *
     * @authenticated
     * @urlParam id string required ID del afinamiento. Example: uuid-123
     *
     * @response scenario=success {
     *   "id": "uuid-123",
     *   "nombre_paciente": "Juan Pérez",
     *   "promedio_sistolica": 140,
     *   "promedio_diastolica": 90
     * }
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
     *
     * Permite modificar datos de tomas previas o agregar nuevas tomas al ciclo.
     *
     * @authenticated
     * @urlParam id string required ID del afinamiento.
     * @bodyParam presion_sistolica_2 int Toma 2da toma. Example: 135
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
