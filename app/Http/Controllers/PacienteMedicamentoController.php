<?php

namespace App\Http\Controllers;

use App\Models\Paciente;
use App\Models\Medicamento;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * @group Medicamentos de Paciente
 *
 * Gestión de la relación entre pacientes y los medicamentos asignados para su tratamiento.
 */
class PacienteMedicamentoController extends Controller
{
    /**
     * Asignar medicamentos a paciente
     * 
     * @authenticated
     * @urlParam id string required ID del paciente.
     * @bodyParam medicamentos object[] required Lista de medicamentos.
     * @bodyParam medicamentos.*.id string required ID del medicamento.
     * @bodyParam medicamentos.*.dosis string Dosis asignada.
     * @bodyParam medicamentos.*.cantidad integer Cantidad entregada/asignada.
     */
    public function assignMedicamentos(Request $request, string $id): JsonResponse
    {
        try {
            $paciente = Paciente::findOrFail($id);
            
            $validated = $request->validate([
                'medicamentos' => 'required|array',
                'medicamentos.*.id' => 'required|exists:medicamentos,id',
                'medicamentos.*.dosis' => 'nullable|string',
                'medicamentos.*.cantidad' => 'nullable|integer'
            ]);

            $medicamentosData = [];
            foreach ($validated['medicamentos'] as $med) {
                $medicamentosData[$med['id']] = [
                    'dosis' => $med['dosis'] ?? null,
                    'cantidad' => $med['cantidad'] ?? null
                ];
            }

            $paciente->medicamentos()->attach($medicamentosData);

            return response()->json([
                'success' => true,
                'message' => 'Medicamentos asignados exitosamente',
                'data' => $paciente->load('medicamentos')
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Datos de validación incorrectos',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al asignar medicamentos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updateMedicamentos(Request $request, string $id): JsonResponse
    {
        try {
            $paciente = Paciente::findOrFail($id);
            
            $validated = $request->validate([
                'medicamentos' => 'required|array',
                'medicamentos.*.id' => 'required|exists:medicamentos,id',
                'medicamentos.*.dosis' => 'nullable|string',
                'medicamentos.*.cantidad' => 'nullable|integer'
            ]);

            $medicamentosData = [];
            foreach ($validated['medicamentos'] as $med) {
                $medicamentosData[$med['id']] = [
                    'dosis' => $med['dosis'] ?? null,
                    'cantidad' => $med['cantidad'] ?? null
                ];
            }

            $paciente->medicamentos()->sync($medicamentosData);

            return response()->json([
                'success' => true,
                'message' => 'Medicamentos actualizados exitosamente',
                'data' => $paciente->load('medicamentos')
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Datos de validación incorrectos',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar medicamentos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function removeMedicamentos(Request $request, string $id): JsonResponse
    {
        try {
            $paciente = Paciente::findOrFail($id);
            
            $validated = $request->validate([
                'medicamentos' => 'required|array',
                'medicamentos.*' => 'exists:medicamentos,id'
            ]);

            $paciente->medicamentos()->detach($validated['medicamentos']);

            return response()->json([
                'success' => true,
                'message' => 'Medicamentos removidos exitosamente',
                'data' => $paciente->load('medicamentos')
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Datos de validación incorrectos',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al remover medicamentos',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    public function getMedicamentos(string $id): JsonResponse
    {
        try {
            $paciente = Paciente::with('medicamentos')->findOrFail($id);
            
            return response()->json([
                'success' => true,
                'data' => $paciente->medicamentos
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener medicamentos del paciente',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}