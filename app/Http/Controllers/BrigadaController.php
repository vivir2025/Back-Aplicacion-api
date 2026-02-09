<?php

namespace App\Http\Controllers;

use App\Models\Brigada;
use App\Models\BrigadaPaciente;
use App\Models\BrigadaPacienteMedicamento;
use App\Models\Paciente;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BrigadaController extends Controller
{
    public function index(Request $request)
    {
        try {
            $usuario = $request->user();
            
            Log::info('📋 [GET] Consultando brigadas', [
                'usuario_id' => $usuario->id ?? 'N/A',
                'usuario_nombre' => $usuario->name ?? 'N/A',
                'sede_id' => $usuario->idsede ?? 'N/A'
            ]);
            
            $brigadas = Brigada::with([
                'pacientes',
                'medicamentosPacientes.medicamento',
                'medicamentosPacientes.paciente'
            ])->get();
    
            Log::info('✅ [GET] Brigadas consultadas exitosamente', ['total' => $brigadas->count()]);
    
            return response()->json([
                'success' => true,
                'data' => $brigadas
            ]);
        } catch (\Exception $e) {
            Log::error('❌ [GET] Error al obtener brigadas', [
                'error' => $e->getMessage(),
                'archivo' => basename($e->getFile()),
                'linea' => $e->getLine()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener brigadas: ' . $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $usuario = $request->user();
            
            DB::beginTransaction();
            
            Log::info('📥 [POST] Creando brigada', [
                'metodo' => 'POST',
                'usuario_id' => $usuario->id ?? 'N/A',
                'usuario_nombre' => $usuario->name ?? 'N/A',
                'sede_id' => $usuario->idsede ?? 'N/A',
                'lugar_evento' => $request->lugar_evento,
                'fecha_brigada' => $request->fecha_brigada,
                'total_pacientes' => count($request->pacientes ?? []),
                'total_medicamentos' => count($request->medicamentos_resumen ?? [])
            ]);
            
            // Validar datos
            $validated = $request->validate([
                'lugar_evento' => 'required|string',
                'fecha_brigada' => 'required|date',
                'nombre_conductor' => 'nullable|string',
                'usuarios_hta' => 'nullable|string',
                'usuarios_dn' => 'nullable|string',
                'usuarios_hta_rcu' => 'nullable|string',
                'usuarios_dm_rcu' => 'nullable|string',
                'tema' => 'required|string',
                'observaciones' => 'nullable|string',
                'pacientes' => 'required|array',
                'pacientes.*' => 'required|string',
                'pacientes_data' => 'nullable|array', // Datos completos de pacientes offline
                'medicamentos_resumen' => 'nullable|array',
            ]);

            // 🔄 PASO 0: SINCRONIZAR PACIENTES OFFLINE
            $mapaIdsPacientes = []; // offline_id => real_id
            
            if (!empty($validated['pacientes_data'])) {
                Log::info('🔄 [SYNC] Iniciando sincronización de pacientes offline', [
                    'total_pacientes' => count($validated['pacientes_data']),
                    'brigada' => $validated['lugar_evento']
                ]);
                
                foreach ($validated['pacientes_data'] as $pacienteData) {
                    try {
                        $idOffline = $pacienteData['id'] ?? null;
                        
                        // Solo procesar si es un ID offline
                        if ($idOffline && Str::startsWith($idOffline, 'offline_')) {
                            Log::info('� [SYNC] Procesando paciente offline', [
                                'id_offline' => $idOffline,
                                'identificacion' => $pacienteData['identificacion'] ?? 'sin identificación',
                                'nombre_completo' => ($pacienteData['nombre'] ?? '') . ' ' . ($pacienteData['apellido'] ?? '')
                            ]);
                            
                            // Preparar identificación (si está vacía, usar el ID offline)
                            $identificacion = !empty($pacienteData['identificacion']) 
                                ? $pacienteData['identificacion'] 
                                : $idOffline; // Usar ID offline como fallback
                            
                            // ✅ Usar updateOrCreate para evitar duplicados por identificación
                            // Esto busca por identificación y actualiza si existe, o crea si no existe
                            $paciente = Paciente::updateOrCreate(
                                [
                                    'identificacion' => $identificacion
                                ],
                                [
                                    'nombre' => $pacienteData['nombre'] ?? 'Sin nombre',
                                    'apellido' => $pacienteData['apellido'] ?? 'Sin apellido',
                                    'fecnacimiento' => $pacienteData['fecnacimiento'] ?? now()->subYears(30),
                                    'genero' => $pacienteData['genero'] ?? 'No especificado',
                                    'latitud' => $pacienteData['latitud'] ?? null,
                                    'longitud' => $pacienteData['longitud'] ?? null,
                                    'idsede' => $pacienteData['idsede'] ?? null,
                                ]
                            );
                            
                            // Mapear el ID offline al ID real (nuevo o existente)
                            $mapaIdsPacientes[$idOffline] = $paciente->id;
                            
                            if ($paciente->wasRecentlyCreated) {
                                Log::info('✅ [SYNC] Paciente creado', [
                                    'id_offline' => $idOffline,
                                    'id_real' => $paciente->id,
                                    'identificacion' => $identificacion,
                                    'nombre_completo' => $paciente->nombre . ' ' . $paciente->apellido
                                ]);
                            } else {
                                Log::info('♻️ [SYNC] Paciente reutilizado', [
                                    'id_offline' => $idOffline,
                                    'id_real' => $paciente->id,
                                    'identificacion' => $identificacion,
                                    'nombre_completo' => $paciente->nombre . ' ' . $paciente->apellido
                                ]);
                            }
                        }
                    } catch (\Exception $e) {
                        Log::error('❌ [SYNC] Error sincronizando paciente offline', [
                            'id_offline' => $idOffline ?? 'N/A',
                            'identificacion' => $pacienteData['identificacion'] ?? 'N/A',
                            'error' => $e->getMessage(),
                            'archivo' => basename($e->getFile()),
                            'linea' => $e->getLine(),
                            'tipo_error' => get_class($e)
                        ]);
                        // Continuar con los demás pacientes sin detener el proceso
                    }
                }
                
                Log::info('✅ [SYNC] Sincronización de pacientes completada', [
                    'total_sincronizados' => count($mapaIdsPacientes),
                    'ids_mapeados' => array_keys($mapaIdsPacientes)
                ]);
            }

            // 1. 🆕 CREAR LA BRIGADA
            $brigada = Brigada::create([
                'lugar_evento' => $validated['lugar_evento'],
                'fecha_brigada' => $validated['fecha_brigada'],
                'nombre_conductor' => $validated['nombre_conductor'],
                'usuarios_hta' => $validated['usuarios_hta'],
                'usuarios_dn' => $validated['usuarios_dn'],
                'usuarios_hta_rcu' => $validated['usuarios_hta_rcu'],
                'usuarios_dm_rcu' => $validated['usuarios_dm_rcu'],
                'tema' => $validated['tema'],
                'observaciones' => $validated['observaciones'] ?? '',
            ]);

            Log::info('✅ [POST] Brigada creada exitosamente', [
                'brigada_id' => $brigada->id,
                'lugar_evento' => $brigada->lugar_evento,
                'fecha_brigada' => $brigada->fecha_brigada
            ]);

            // 2. 👥 ASIGNAR PACIENTES (usar IDs reales)
            foreach ($validated['pacientes'] as $pacienteId) {
                // Si es offline, usar el ID real del mapa, sino usar el ID original
                $idReal = $mapaIdsPacientes[$pacienteId] ?? $pacienteId;
                
                BrigadaPaciente::create([
                    'brigada_id' => $brigada->id,
                    'paciente_id' => $idReal,
                ]);
            }

            Log::info('✅ [POST] Pacientes asignados a brigada', [
                'brigada_id' => $brigada->id,
                'total_pacientes' => count($validated['pacientes'])
            ]);

            // 3. 💊 PROCESAR MEDICAMENTOS (usando IDs reales)
            if (!empty($validated['medicamentos_resumen'])) {
                foreach ($validated['medicamentos_resumen'] as $medicamento) {
                    // Validar que tenga los campos necesarios
                    if (isset($medicamento['paciente_id'], $medicamento['medicamento_id'], $medicamento['cantidad'])) {
                        // Solo crear si la cantidad es mayor a 0
                        if ($medicamento['cantidad'] > 0) {
                            // Si es offline, usar el ID real del mapa
                            $idPacienteReal = $mapaIdsPacientes[$medicamento['paciente_id']] ?? $medicamento['paciente_id'];
                            
                            BrigadaPacienteMedicamento::create([
                                'brigada_id' => $brigada->id,
                                'paciente_id' => $idPacienteReal,
                                'medicamento_id' => $medicamento['medicamento_id'],
                                'dosis' => $medicamento['dosis'] ?? '',
                                'cantidad' => $medicamento['cantidad'],
                                'indicaciones' => $medicamento['indicaciones'] ?? '',
                            ]);
                            
                            Log::info('💊 [POST] Medicamento asignado', [
                                'brigada_id' => $brigada->id,
                                'paciente_id' => $idPacienteReal,
                                'medicamento_id' => $medicamento['medicamento_id'],
                                'dosis' => $medicamento['dosis'] ?? 'N/A',
                                'cantidad' => $medicamento['cantidad']
                            ]);
                        }
                    }
                }
                
                Log::info('✅ [POST] Medicamentos procesados', [
                    'brigada_id' => $brigada->id,
                    'total_medicamentos' => count($validated['medicamentos_resumen'])
                ]);
            }

            // 4. 📤 CARGAR RELACIONES
            $brigada->load(['pacientes', 'medicamentosPacientes.medicamento']);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Brigada creada exitosamente con medicamentos',
                'data' => $brigada,
                'mapa_pacientes' => $mapaIdsPacientes // Devolver el mapeo para referencia
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            $usuario = $request->user();
            
            Log::error('❌ [POST] Error creando brigada', [
                'usuario_id' => $usuario->id ?? 'N/A',
                'usuario_nombre' => $usuario->name ?? 'N/A',
                'sede_id' => $usuario->idsede ?? 'N/A',
                'lugar_evento' => $request->lugar_evento ?? 'N/A',
                'error' => $e->getMessage(),
                'archivo' => basename($e->getFile()),
                'linea' => $e->getLine(),
                'tipo_error' => get_class($e)
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al crear la brigada: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show(Request $request, $id)
    {
        try {
            $usuario = $request->user();
            
            Log::info('🔍 [GET] Consultando brigada específica', [
                'usuario_id' => $usuario->id ?? 'N/A',
                'brigada_id' => $id
            ]);
            
            $brigada = Brigada::with([
                'pacientes',
                'medicamentosPacientes.medicamento',
                'medicamentosPacientes.paciente'
            ])->findOrFail($id);

            Log::info('✅ [GET] Brigada consultada exitosamente', [
                'brigada_id' => $id,
                'lugar_evento' => $brigada->lugar_evento
            ]);

            return response()->json([
                'success' => true,
                'data' => $brigada
            ]);
        } catch (\Exception $e) {
            Log::error('❌ [GET] Error al consultar brigada', [
                'brigada_id' => $id,
                'error' => $e->getMessage(),
                'archivo' => basename($e->getFile()),
                'linea' => $e->getLine()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Brigada no encontrada'
            ], 404);
        }
    }
}