<?php
// app/Http/Controllers/EnvioMuestraController.php - VERSIÓN CORREGIDA

namespace App\Http\Controllers;

use App\Models\EnvioMuestra;
use App\Models\DetalleEnvioMuestra;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class EnvioMuestraController extends Controller
{
    public function index()
    {
        return EnvioMuestra::with([
            'sede',
            'responsableToma',
            'detalles.paciente'
        ])->get();
    }

    public function store(Request $request)
    {
        $request->validate([
            'fecha' => 'required|date',
            'idsede' => 'required|exists:sedes,id',
            'detalles' => 'required|array|min:1',
            'detalles.*.paciente_id' => 'required|exists:pacientes,id',
            'detalles.*.numero_orden' => 'required|integer|min:1',
            'responsable_transporte_id' => 'nullable|string|max:255',
            'responsable_recepcion_id' => 'nullable|string|max:255',
        ]);

        try {
            DB::beginTransaction();

            $envioMuestra = EnvioMuestra::create([
                'fecha' => $request->fecha,
                'codigo' => $request->codigo ?? 'PM-CE-TM-F-01',
                'version' => $request->version ?? '1',
                'lugar_toma_muestras' => $request->lugar_toma_muestras,
                'hora_salida' => $request->hora_salida,
                'fecha_salida' => $request->fecha_salida,
                'temperatura_salida' => $request->temperatura_salida,
                'responsable_toma_id' => Auth::id(),
                'idusuario' => Auth::id(),
                'responsable_transporte_id' => $request->responsable_transporte_id,
                'fecha_llegada' => $request->fecha_llegada,
                'hora_llegada' => $request->hora_llegada,
                'temperatura_llegada' => $request->temperatura_llegada,
                'lugar_llegada' => $request->lugar_llegada,
                'responsable_recepcion_id' => $request->responsable_recepcion_id,
                'observaciones' => $request->observaciones,
                'idsede' => $request->idsede,
            ]);

            // ✅ FILTRAR CAMPOS ELIMINADOS
            foreach ($request->detalles as $detalle) {
                $detalleData = collect($detalle)->except([
                    'num_muestras_enviadas',
                    'tubo_lila', 
                    'tubo_amarillo',
                    'tubo_amarillo_forrado',
                    'orina_24h'
                ])->toArray();

                $detalleData['envio_muestra_id'] = $envioMuestra->id;

                DetalleEnvioMuestra::create($detalleData);
            }

            DB::commit();

            return response()->json($envioMuestra->load([
                'sede',
                'responsableToma',
                'detalles.paciente'
            ]), 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al crear el envío de muestras',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        return EnvioMuestra::with([
            'sede',
            'responsableToma',
            'detalles.paciente'
        ])->findOrFail($id);
    }

      public function update(Request $request, $id)
    {
        $envioMuestra = EnvioMuestra::findOrFail($id);

        $request->validate([
            'fecha' => 'sometimes|required|date',
            'idsede' => 'sometimes|required|exists:sedes,id',
            'responsable_transporte_id' => 'nullable|string|max:255',
            'responsable_recepcion_id' => 'nullable|string|max:255',
        ]);

        try {
            DB::beginTransaction();

            $dataToUpdate = $request->except(['detalles', 'responsable_toma_id', 'idusuario']);
            $envioMuestra->update($dataToUpdate);

            if ($request->has('detalles')) {
                $envioMuestra->detalles()->delete();
                
                // ✅ FILTRAR CAMPOS ELIMINADOS TAMBIÉN AQUÍ
                foreach ($request->detalles as $detalle) {
                    $detalleData = collect($detalle)->except([
                        'num_muestras_enviadas',
                        'tubo_lila', 
                        'tubo_amarillo',
                        'tubo_amarillo_forrado',
                        'orina_24h'
                    ])->toArray();

                    $detalleData['envio_muestra_id'] = $envioMuestra->id;

                    DetalleEnvioMuestra::create($detalleData);
                }
            }

            DB::commit();

            return response()->json($envioMuestra->load([
                'sede',
                'responsableToma',
                'detalles.paciente'
            ]));

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al actualizar el envío de muestras',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $envioMuestra = EnvioMuestra::findOrFail($id);
            $envioMuestra->delete();
            return response()->json(null, 204);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al eliminar el envío de muestras',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getEnviosPorSede($sedeId)
    {
        return EnvioMuestra::where('idsede', $sedeId)
                          ->with([
                              'sede',
                              'responsableToma',
                              'detalles.paciente'
                          ])
                          ->orderBy('fecha', 'desc')
                          ->get();
    }
    
    /**
     * Actualizar estado de correo (marcar como enviado/no enviado)
     */
    public function actualizarEstadoCorreo(Request $request, $id)
    {
        try {
            $envioMuestra = EnvioMuestra::findOrFail($id);

            $request->validate([
                'enviado_por_correo' => 'required|boolean'
            ]);

            $envioMuestra->update([
                'enviado_por_correo' => $request->enviado_por_correo
            ]);

            return response()->json([
                'success' => true,
                'message' => $request->enviado_por_correo 
                    ? 'Correo marcado como enviado' 
                    : 'Correo marcado como no enviado',
                'envio_muestra' => $envioMuestra->load(['sede', 'responsableToma', 'detalles.paciente'])
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Envío de muestra no encontrado'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar estado de correo',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getEnviosPorFechaSalida($fecha)
    {
        try {
            $envios = EnvioMuestra::where('fecha_salida', $fecha)
                                ->with([
                                    'sede',
                                    'responsableToma',
                                    'detalles.paciente'
                                ])
                                ->get();
            
            return response()->json($envios);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener envíos por fecha de salida',
                'error' => $e->getMessage()
            ], 500);
        }
    }


}