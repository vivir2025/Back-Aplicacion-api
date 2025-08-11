<?php

namespace App\Http\Controllers;

use App\Models\Visita;
use App\Models\Paciente;
use App\Models\Medicamento;
use App\Models\MedicamentoVisita; // ✅ AGREGAR ESTE MODELO
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class VisitaController extends Controller
{
    public function index()
    {
        return Visita::with(['usuario', 'paciente', 'medicamentos'])->get();
    }

    public function store(Request $request)
    {
        Log::info('=== RECIBIENDO DATOS DE VISITA ===');
        Log::info('Todos los campos:', $request->all());
        
        if ($request->has('medicamentos')) {
            Log::info('Campo medicamentos (raw):', ['medicamentos' => $request->medicamentos]);
            
            if (is_string($request->medicamentos)) {
                $medicamentosDecoded = json_decode($request->medicamentos, true);
                Log::info('Medicamentos decodificados:', ['medicamentos_decoded' => $medicamentosDecoded]);
            }
        }

        // ✅ VALIDACIÓN IGUAL QUE EL PRIMER CONTROLADOR
        $request->validate([
            'nombre_apellido' => 'required|string',
            'identificacion' => 'required|string',
            'fecha' => 'required|date',
            'idusuario' => 'required|exists:usuarios,id',
            'idpaciente' => 'required|exists:pacientes,id',
            
            // Campos opcionales
            'id' => 'sometimes|string',
            'hta' => 'sometimes|nullable|string',
            'dm' => 'sometimes|nullable|string',
            'telefono' => 'sometimes|nullable|string',
            'zona' => 'sometimes|nullable|string',
            'peso' => 'sometimes|nullable|numeric',
            'talla' => 'sometimes|nullable|numeric',
            'imc' => 'sometimes|nullable|numeric',
            'perimetro_abdominal' => 'sometimes|nullable|numeric',
            'frecuencia_cardiaca' => 'sometimes|nullable|integer',
            'frecuencia_respiratoria' => 'sometimes|nullable|integer',
            'tension_arterial' => 'sometimes|nullable|string',
            'glucometria' => 'sometimes|nullable|numeric',
            'temperatura' => 'sometimes|nullable|numeric',
            'familiar' => 'sometimes|nullable|string',
            
            // Archivos
            'riesgo_fotografico' => 'sometimes|nullable|file|image|mimes:jpeg,png,jpg,gif|max:2048',
            'riesgo_fotografico_url' => 'sometimes|nullable|string',
            'riesgo_fotografico_base64' => 'sometimes|nullable|string',
            
            'abandono_social' => 'sometimes|nullable|string',
            'motivo' => 'sometimes|nullable|string',
            'factores' => 'sometimes|nullable|string',
            'conductas' => 'sometimes|nullable|string',
            'novedades' => 'sometimes|nullable|string',
            'proximo_control' => 'sometimes|nullable|date',
            
            'firma' => 'sometimes|nullable|file|image|mimes:jpeg,png,jpg,gif|max:2048',
            'firma_url' => 'sometimes|nullable|string',
            'firma_base64' => 'sometimes|nullable|string',
            
            'medicamentos' => 'sometimes|string',
        ]);

        // ✅ PROCESAR MEDICAMENTOS IGUAL QUE EL PRIMER CONTROLADOR
        $medicamentosData = [];
        if ($request->has('medicamentos') && !empty($request->medicamentos)) {
            try {
                $medicamentosData = json_decode($request->medicamentos, true);
                if (!is_array($medicamentosData)) {
                    Log::warning('Medicamentos no es un array válido:', ['medicamentos' => $request->medicamentos]);
                    $medicamentosData = [];
                }
            } catch (\Exception $e) {
                Log::error('Error decodificando medicamentos JSON:', ['error' => $e->getMessage()]);
                $medicamentosData = [];
            }
        }

        $visitaData = $request->except(['medicamentos', 'riesgo_fotografico_base64', 'firma_base64']);
        
        if ($request->has('id')) {
            $visitaData['id'] = $request->id;
        }

        try {
            // ✅ PROCESAR ARCHIVOS IGUAL QUE EL PRIMER CONTROLADOR
            // Procesar foto de riesgo (archivo o base64)
            if ($request->hasFile('riesgo_fotografico')) {
                $this->processRiskPhotoFile($visitaData, $request->file('riesgo_fotografico'));
            } elseif ($request->has('riesgo_fotografico_base64') && !empty($request->riesgo_fotografico_base64)) {
                $this->processRiskPhotoBase64($visitaData, $request->riesgo_fotografico_base64);
            }

            // Procesar firma (archivo o base64)
            if ($request->hasFile('firma')) {
                $this->processSignatureFile($visitaData, $request->file('firma'));
            } elseif ($request->has('firma_base64') && !empty($request->firma_base64)) {
                $this->processSignatureBase64($visitaData, $request->firma_base64);
            }

            $visita = Visita::create($visitaData);
            Log::info('Visita creada:', ['id' => $visita->id]);

            // ✅ PROCESAR MEDICAMENTOS IGUAL QUE EL PRIMER CONTROLADOR
            if (!empty($medicamentosData)) {
                $this->processMedicamentos($visita, $medicamentosData);
            }

            $visitaCompleta = $visita->load(['usuario', 'paciente', 'medicamentos']);
            
            return response()->json([
                'success' => true,
                'data' => $visitaCompleta,
                'message' => 'Visita creada exitosamente'
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error al crear visita:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error al crear visita: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $visita = Visita::with(['usuario', 'paciente', 'medicamentos'])->findOrFail($id);
            return response()->json(['success' => true, 'data' => $visita]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Visita no encontrada'], 404);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $visita = Visita::findOrFail($id);
            Log::info('=== ACTUALIZANDO VISITA ===', ['visita_id' => $id]);
            
            $request->validate([
                'nombre_apellido' => 'sometimes|required|string',
                'identificacion' => 'sometimes|required|string',
                'fecha' => 'sometimes|required|date',
                'idusuario' => 'sometimes|required|exists:usuarios,id',
                'idpaciente' => 'sometimes|required|exists:pacientes,id',
                
                // Campos opcionales
                'hta' => 'sometimes|nullable|string',
                'dm' => 'sometimes|nullable|string',
                'telefono' => 'sometimes|nullable|string',
                'zona' => 'sometimes|nullable|string',
                'peso' => 'sometimes|nullable|numeric',
                'talla' => 'sometimes|nullable|numeric',
                'imc' => 'sometimes|nullable|numeric',
                'perimetro_abdominal' => 'sometimes|nullable|numeric',
                'frecuencia_cardiaca' => 'sometimes|nullable|integer',
                'frecuencia_respiratoria' => 'sometimes|nullable|integer',
                'tension_arterial' => 'sometimes|nullable|string',
                'glucometria' => 'sometimes|nullable|numeric',
                'temperatura' => 'sometimes|nullable|numeric',
                'familiar' => 'sometimes|nullable|string',
                
                'riesgo_fotografico' => 'sometimes|nullable|file|image|mimes:jpeg,png,jpg,gif|max:2048',
                'riesgo_fotografico_url' => 'sometimes|nullable|string',
                'riesgo_fotografico_base64' => 'sometimes|nullable|string',
                
                'abandono_social' => 'sometimes|nullable|string',
                'motivo' => 'sometimes|nullable|string',
                'factores' => 'sometimes|nullable|string',
                'conductas' => 'sometimes|nullable|string',
                'novedades' => 'sometimes|nullable|string',
                'proximo_control' => 'sometimes|nullable|date',
                
                'firma' => 'sometimes|nullable|file|image|mimes:jpeg,png,jpg,gif|max:2048',
                'firma_url' => 'sometimes|nullable|string',
                'firma_base64' => 'sometimes|nullable|string',
                
                'medicamentos' => 'sometimes|string',
            ]);
            
            // ✅ PROCESAR MEDICAMENTOS IGUAL QUE EL PRIMER CONTROLADOR
            $medicamentosData = [];
            if ($request->has('medicamentos') && !empty($request->medicamentos)) {
                try {
                    $medicamentosData = json_decode($request->medicamentos, true);
                } catch (\Exception $e) {
                    Log::error('Error decodificando medicamentos en update:', ['error' => $e->getMessage()]);
                }
            }

            $visitaData = $request->except(['medicamentos', 'riesgo_fotografico_base64', 'firma_base64']);

            // ✅ PROCESAR ARCHIVOS IGUAL QUE EL PRIMER CONTROLADOR
            // Procesar foto de riesgo (archivo o base64)
            if ($request->hasFile('riesgo_fotografico')) {
                $this->deleteExistingFile($visita->riesgo_fotografico);
                $this->processRiskPhotoFile($visitaData, $request->file('riesgo_fotografico'));
            } elseif ($request->has('riesgo_fotografico_base64') && !empty($request->riesgo_fotografico_base64)) {
                $this->deleteExistingFile($visita->riesgo_fotografico);
                $this->processRiskPhotoBase64($visitaData, $request->riesgo_fotografico_base64);
            }
            
            // Procesar firma (archivo o base64)
            if ($request->hasFile('firma')) {
                $this->deleteExistingFile($visita->firma);
                $this->processSignatureFile($visitaData, $request->file('firma'));
            } elseif ($request->has('firma_base64') && !empty($request->firma_base64)) {
                $this->deleteExistingFile($visita->firma);
                $this->processSignatureBase64($visitaData, $request->firma_base64);
            }
            
            $visita->update($visitaData);

            // ✅ PROCESAR MEDICAMENTOS EN UPDATE IGUAL QUE EL PRIMER CONTROLADOR
            if (!empty($medicamentosData)) {
                $this->processMedicamentos($visita, $medicamentosData, true);
            }

            $visitaCompleta = $visita->load(['usuario', 'paciente', 'medicamentos']);

            return response()->json([
                'success' => true,
                'data' => $visitaCompleta,
                'message' => 'Visita actualizada exitosamente'
            ]);

        } catch (\Exception $e) {
            Log::error('Error al actualizar visita:', [
                'visita_id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar visita: ' . $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $visita = Visita::findOrFail($id);
            
            $this->deleteExistingFile($visita->riesgo_fotografico);
            $this->deleteExistingFile($visita->firma);
            
            $visita->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Visita eliminada exitosamente'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar visita'
            ], 500);
        }
    }

    public function buscarPaciente($identificacion)
    {
        $paciente = Paciente::where('identificacion', $identificacion)->first();

        if (!$paciente) {
            return response()->json([
                'success' => false,
                'message' => 'Paciente no encontrado'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'nombre' => $paciente->nombre . ' ' . $paciente->apellido,
                'fecnacimiento' => $paciente->fecnacimiento,
                'idpaciente' => $paciente->id,
                // ✅ INCLUIR COORDENADAS DEL PACIENTE
                'latitud' => $paciente->latitud,
                'longitud' => $paciente->longitud
            ]
        ]);
    }

    // ✅ MÉTODOS PARA SUBIR ARCHIVOS (mantener los que ya tienes)
    public function uploadRiskPhoto(Request $request)
    {
        try {
            $request->validate([
                'riesgo_fotografico' => 'required|file|image|mimes:jpeg,png,jpg,gif|max:5120',
            ]);

            $file = $request->file('riesgo_fotografico');
            $filename = 'risk_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('risk_photos', $filename, 'public');
            $url = Storage::url($path);
            
            return response()->json([
                'success' => true,
                'message' => 'Foto de riesgo subida exitosamente',
                'url' => $url,
                'path' => $path
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error subiendo foto de riesgo: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al subir foto de riesgo'
            ], 500);
        }
    }

    public function uploadSignature(Request $request)
    {
        try {
            $request->validate([
                'firma' => 'required|file|image|mimes:jpeg,png,jpg,gif|max:2048',
            ]);

            $file = $request->file('firma');
            $filename = 'signature_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('signatures', $filename, 'public');
            $url = Storage::url($path);
            
            return response()->json([
                'success' => true,
                'message' => 'Firma subida exitosamente',
                'url' => $url,
                'path' => $path
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error subiendo firma: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al subir firma'
            ], 500);
        }
    }

    public function uploadPhoto(Request $request)
    {
        try {
            $request->validate([
                'photo' => 'required|file|image|mimes:jpeg,png,jpg,gif|max:5120',
            ]);

            $file = $request->file('photo');
            $filename = 'photo_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('photos', $filename, 'public');
            $url = Storage::url($path);
            
            return response()->json([
                'success' => true,
                'message' => 'Foto subida exitosamente',
                'url' => $url,
                'path' => $path
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error subiendo foto: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al subir foto'
            ], 500);
        }
    }
    
    public function uploadRiskPhotoBase64(Request $request)
    {
        try {
            $request->validate([
                'riesgo_fotografico_base64' => 'required|string',
                'visita_id' => 'required|string|exists:visitas,id',
            ]);

            $visita = Visita::findOrFail($request->visita_id);
            $this->deleteExistingFile($visita->riesgo_fotografico);
            
            $data = [];
            $this->processRiskPhotoBase64($data, $request->riesgo_fotografico_base64);
            
            $visita->update([
                'riesgo_fotografico' => $data['riesgo_fotografico'],
                'riesgo_fotografico_url' => $data['riesgo_fotografico_url']
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Foto de riesgo subida exitosamente',
                'url' => $data['riesgo_fotografico_url']
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error procesando foto de riesgo en Base64: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al procesar foto de riesgo'
            ], 500);
        }
    }
    
    public function uploadSignatureBase64(Request $request)
    {
        try {
            $request->validate([
                'firma_base64' => 'required|string',
                'visita_id' => 'required|string|exists:visitas,id',
            ]);

            $visita = Visita::findOrFail($request->visita_id);
            $this->deleteExistingFile($visita->firma);
            
            $data = [];
            $this->processSignatureBase64($data, $request->firma_base64);
            
            $visita->update([
                'firma' => $data['firma'],
                'firma_url' => $data['firma_url']
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Firma subida exitosamente',
                'url' => $data['firma_url']
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error procesando firma en Base64: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al procesar firma'
            ], 500);
        }
    }

    // ========== MÉTODOS PRIVADOS DEL PRIMER CONTROLADOR ==========

    private function processRiskPhotoFile(&$data, $file)
    {
        $filename = 'risk_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('visitas/fotos', $filename, 'public');
        
        $data['riesgo_fotografico'] = $path;
        $data['riesgo_fotografico_url'] = url(Storage::url($path));
        
        Log::info('Foto de riesgo subida:', ['ruta' => $path, 'url' => $data['riesgo_fotografico_url']]);
    }

    private function processRiskPhotoBase64(&$data, $base64Data)
    {
        try {
            if (strpos($base64Data, ';base64,') !== false) {
                $base64Data = explode(';base64,', $base64Data)[1];
            }

            $imageData = base64_decode($base64Data);
            if (!$imageData) {
                throw new \Exception('Datos Base64 inválidos');
            }

            $filename = 'risk_' . time() . '_' . uniqid() . '.jpg';
            $path = 'visitas/fotos/' . $filename;
            
            Storage::disk('public')->put($path, $imageData);
            
            $data['riesgo_fotografico'] = $path;
            $data['riesgo_fotografico_url'] = url(Storage::url($path));
            
            Log::info('Foto de riesgo en Base64 procesada:', ['ruta' => $path, 'url' => $data['riesgo_fotografico_url']]);
        } catch (\Exception $e) {
            Log::error('Error procesando foto de riesgo en Base64: ' . $e->getMessage());
            throw $e;
        }
    }

    private function processSignatureFile(&$data, $file)
    {
        $filename = 'signature_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('visitas/firmas', $filename, 'public');
        
        $data['firma'] = $path;
        $data['firma_url'] = url(Storage::url($path));
        
        Log::info('Firma subida:', ['ruta' => $path, 'url' => $data['firma_url']]);
    }

    private function processSignatureBase64(&$data, $base64Data)
    {
        try {
            if (strpos($base64Data, ';base64,') !== false) {
                $base64Data = explode(';base64,', $base64Data)[1];
            }

            $imageData = base64_decode($base64Data);
            if (!$imageData) {
                throw new \Exception('Datos Base64 inválidos');
            }

            $filename = 'signature_' . time() . '_' . uniqid() . '.png';
            $path = 'visitas/firmas/' . $filename;
            
            Storage::disk('public')->put($path, $imageData);
            
            $data['firma'] = $path;
            $data['firma_url'] = url(Storage::url($path));
            
            Log::info('Firma en Base64 procesada:', ['ruta' => $path, 'url' => $data['firma_url']]);
        } catch (\Exception $e) {
            Log::error('Error procesando firma en Base64: ' . $e->getMessage());
            throw $e;
        }
    }

    // ✅ MÉTODO CLAVE: PROCESAR MEDICAMENTOS IGUAL QUE EL PRIMER CONTROLADOR
    private function processMedicamentos($visita, $medicamentosData, $isUpdate = false)
    {
        if ($isUpdate) {
            // Si es actualización, primero desvinculamos todos los medicamentos
            $visita->medicamentos()->detach();
        }
        
        $medicamentosGuardados = 0;
        
        foreach ($medicamentosData as $medicamento) {
            try {
                if (!isset($medicamento['id'])) {
                    Log::warning('Medicamento sin ID:', ['medicamento' => $medicamento]);
                    continue;
                }

                $medicamentoExiste = Medicamento::find($medicamento['id']);
                if (!$medicamentoExiste) {
                    Log::warning('Medicamento no existe:', ['id' => $medicamento['id']]);
                    continue;
                }

                $visita->medicamentos()->attach($medicamento['id'], [
                    'indicaciones' => $medicamento['indicaciones'] ?? null
                ]);
                
                $medicamentosGuardados++;
            } catch (\Exception $e) {
                Log::error('Error guardando medicamento:', [
                    'medicamento' => $medicamento,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        Log::info("Total medicamentos guardados: $medicamentosGuardados de " . count($medicamentosData));
    }

    private function deleteExistingFile($filePath)
    {
        if ($filePath && Storage::disk('public')->exists($filePath)) {
            Storage::disk('public')->delete($filePath);
            Log::info('Archivo eliminado:', ['ruta' => $filePath]);
        }
    }
}