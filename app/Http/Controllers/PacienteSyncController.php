<?php

namespace App\Http\Controllers;

use App\Models\Paciente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * @group Gestión de Pacientes
 *
 * Endpoints para la sincronización masiva de datos entre dispositivos y el servidor.
 */
class PacienteSyncController extends Controller
{
    /**
     * Sincronización masiva (Offline-first)
     *
     * Permite sincronizar un lote de pacientes desde la aplicación móvil. El sistema identifica si debe crear o actualizar basándose en el ID.
     * 
     * @authenticated
     * @bodyParam pacientes object[] required Lista de pacientes a sincronizar.
     * @bodyParam pacientes[].id string required UUID del paciente en el dispositivo. Example: uuid-mobile-1
     * @bodyParam pacientes[].identificacion string required Documento. Example: 12345
     * @bodyParam pacientes[].nombre string required Nombre. Example: Pedro
     * @bodyParam pacientes[].apellido string required Apellido. Example: Picapiedra
     * @bodyParam pacientes[].fecnacimiento string Fecha (Y-m-d). Example: 1980-01-01
     * 
     * @response 200 {
     *  "success": true,
     *  "message": "Sincronización completada",
     *  "resultados": {
     *    "creados": 1,
     *    "actualizados": 0,
     *    "errores": []
     *  }
     * }
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
