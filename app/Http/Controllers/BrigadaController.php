<?php

namespace App\Http\Controllers;

use App\Models\Brigada;
use App\Models\BrigadaPaciente;
use App\Models\BrigadaPacienteMedicamento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BrigadaController extends Controller
{
    public function index()
    {
        try {
            $brigadas = Brigada::with([
                'pacientes',
                'medicamentosPacientes.medicamento',
                'medicamentosPacientes.paciente'
            ])->get();
    
            return response()->json([
                'success' => true,
                'data' => $brigadas
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener brigadas: ' . $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            DB::beginTransaction();
            
            Log::info('📥 Datos recibidos para crear brigada:', $request->all());
            
            // Validar datos
            $validated = $request->validate([
                'lugar_evento' => 'required|string',
                'fecha_brigada' => 'required|date',
                'nombre_conductor' => 'required|string',
                'usuarios_hta' => 'required|string',
                'usuarios_dn' => 'required|string',
                'usuarios_hta_rcu' => 'required|string',
                'usuarios_dm_rcu' => 'required|string',
                'tema' => 'required|string',
                'observaciones' => 'nullable|string',
                'pacientes' => 'required|array',
                'pacientes.*' => 'required|string',
                'medicamentos_resumen' => 'nullable|array',
            ]);

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

            Log::info('✅ Brigada creada:', ['id' => $brigada->id]);

            // 2. 👥 ASIGNAR PACIENTES
            foreach ($validated['pacientes'] as $pacienteId) {
                BrigadaPaciente::create([
                    'brigada_id' => $brigada->id,
                    'paciente_id' => $pacienteId,
                ]);
            }

            Log::info('✅ Pacientes asignados:', ['count' => count($validated['pacientes'])]);

            // 3. 💊 PROCESAR MEDICAMENTOS (CORREGIDO)
            if (!empty($validated['medicamentos_resumen'])) {
                foreach ($validated['medicamentos_resumen'] as $medicamento) {
                    // Validar que tenga los campos necesarios
                    if (isset($medicamento['paciente_id'], $medicamento['medicamento_id'], $medicamento['cantidad'])) {
                        // Solo crear si la cantidad es mayor a 0
                        if ($medicamento['cantidad'] > 0) {
                            BrigadaPacienteMedicamento::create([
                                'brigada_id' => $brigada->id,
                                'paciente_id' => $medicamento['paciente_id'],
                                'medicamento_id' => $medicamento['medicamento_id'],
                                'dosis' => $medicamento['dosis'] ?? '',
                                'cantidad' => $medicamento['cantidad'],
                                'indicaciones' => $medicamento['indicaciones'] ?? '',
                            ]);
                            
                            Log::info('💊 Medicamento asignado:', [
                                'brigada_id' => $brigada->id,
                                'paciente_id' => $medicamento['paciente_id'],
                                'medicamento_id' => $medicamento['medicamento_id'],
                                'cantidad' => $medicamento['cantidad']
                            ]);
                        }
                    }
                }
                
                Log::info('✅ Medicamentos procesados:', ['count' => count($validated['medicamentos_resumen'])]);
            }

            // 4. 📤 CARGAR RELACIONES (CORREGIDO)
            $brigada->load(['pacientes', 'medicamentosPacientes.medicamento']);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Brigada creada exitosamente con medicamentos',
                'data' => $brigada
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('❌ Error creando brigada:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al crear la brigada: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $brigada = Brigada::with([
                'pacientes',
                'medicamentosPacientes.medicamento',
                'medicamentosPacientes.paciente'
            ])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $brigada
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Brigada no encontrada'
            ], 404);
        }
    }
}