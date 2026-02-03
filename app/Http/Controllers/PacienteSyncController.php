<?php

namespace App\Http\Controllers;

use App\Models\Paciente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class PacienteSyncController extends Controller
{
    /**
     * Sincronización masiva de pacientes desde la app móvil
     */
    public function syncBatch(Request $request)
    {
        try {
            $request->validate([
                'pacientes' => 'required|array',
                'pacientes.*.id' => 'required|string',
                'pacientes.*.identificacion' => 'required|string',
                'pacientes.*.nombre' => 'required|string',
                'pacientes.*.apellido' => 'required|string',
                'pacientes.*.fecnacimiento' => 'nullable|date',
                'pacientes.*.genero' => 'nullable|string',
                'pacientes.*.latitud' => 'nullable|numeric',
                'pacientes.*.longitud' => 'nullable|numeric',
                'pacientes.*.idsede' => 'nullable|string',
            ]);

            $resultados = [
                'creados' => 0,
                'actualizados' => 0,
                'errores' => [],
            ];

            DB::beginTransaction();

            foreach ($request->pacientes as $pacienteData) {
                try {
                    $paciente = Paciente::updateOrCreate(
                        ['id' => $pacienteData['id']], // Buscar por ID
                        [
                            'identificacion' => $pacienteData['identificacion'],
                            'nombre' => $pacienteData['nombre'],
                            'apellido' => $pacienteData['apellido'],
                            'fecnacimiento' => $pacienteData['fecnacimiento'] ?? now()->subYears(30),
                            'genero' => $pacienteData['genero'] ?? 'No especificado',
                            'latitud' => $pacienteData['latitud'] ?? null,
                            'longitud' => $pacienteData['longitud'] ?? null,
                            'idsede' => $pacienteData['idsede'] ?? null,
                        ]
                    );

                    if ($paciente->wasRecentlyCreated) {
                        $resultados['creados']++;
                        Log::info('✅ Paciente creado en sincronización:', ['id' => $paciente->id]);
                    } else {
                        $resultados['actualizados']++;
                        Log::info('✅ Paciente actualizado en sincronización:', ['id' => $paciente->id]);
                    }

                } catch (\Exception $e) {
                    $resultados['errores'][] = [
                        'id' => $pacienteData['id'] ?? 'desconocido',
                        'identificacion' => $pacienteData['identificacion'] ?? 'desconocido',
                        'error' => $e->getMessage()
                    ];
                    
                    Log::error('❌ Error sincronizando paciente:', [
                        'paciente' => $pacienteData,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Sincronización completada',
                'resultados' => $resultados
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Error en sincronización masiva de pacientes:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error en la sincronización: ' . $e->getMessage()
            ], 500);
        }
    }
}
