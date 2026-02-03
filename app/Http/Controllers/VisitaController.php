<?php

namespace App\Http\Controllers;

use App\Models\Visita;
use App\Models\Paciente;
use App\Models\Medicamento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB; // ✅ AGREGAR ESTA LÍNEA

class VisitaController extends Controller
{
    public function index()
    {
        return Visita::with(['usuario', 'paciente'])->get();
    }

  public function store(Request $request)
{
    Log::info('=== RECIBIENDO DATOS DE VISITA ===');
    Log::info('Todos los campos:', $request->all());
    
    // ✅ LOGS ESPECÍFICOS PARA COORDENADAS - AGREGAR AQUÍ
    Log::info('🌍 DEBUG COORDENADAS:', [
        'latitud_raw' => $request->get('latitud'),
        'longitud_raw' => $request->get('longitud'),
        'latitud_exists' => $request->has('latitud'),
        'longitud_exists' => $request->has('longitud'),
        'latitud_filled' => $request->filled('latitud'),
        'longitud_filled' => $request->filled('longitud'),
        'all_keys' => array_keys($request->all()),
        'request_method' => $request->method(),
        'content_type' => $request->header('Content-Type')
    ]);
    
    // ✅ VERIFICAR SI LAS COORDENADAS ESTÁN EN LOS ARCHIVOS
    if ($request->hasFile('latitud')) {
        Log::info('🚨 Latitud viene como archivo!');
    }
    if ($request->hasFile('longitud')) {
        Log::info('🚨 Longitud viene como archivo!');
    }

    // ✅ VERIFICAR EN FORM DATA
    Log::info('🔍 Form Data completo:', [
        'form_data' => $_POST ?? 'No POST data',
        'files' => $_FILES ?? 'No FILES data'
    ]);
    
    if ($request->has('medicamentos')) {
        Log::info('Campo medicamentos (raw):', ['medicamentos' => $request->medicamentos]);
        
        if (is_string($request->medicamentos)) {
            $medicamentosDecoded = json_decode($request->medicamentos, true);
            Log::info('Medicamentos decodificados:', ['medicamentos_decoded' => $medicamentosDecoded]);
        }
    }

    // ✅ VALIDACIÓN COMPLETA CON COORDENADAS - CAMBIO: idpaciente ahora es nullable
    $request->validate([
        'nombre_apellido' => 'required|string',
        'identificacion' => 'required|string',
        'fecha' => 'required|date',
        'idusuario' => 'required|exists:usuarios,id',
        'idpaciente' => 'nullable|string',  // ✅ CAMBIADO: Ya no requiere que exista en la BD
        
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
        
        // ✅ AGREGAR VALIDACIONES FALTANTES
        'latitud' => 'sometimes|nullable|numeric',
        'longitud' => 'sometimes|nullable|numeric',
        'estado' => 'sometimes|nullable|string',
        'sync_status' => 'sometimes|nullable|integer',
        'observaciones_adicionales' => 'sometimes|nullable|string',
        'tipo_visita' => 'sometimes|nullable|string',
        
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

    // ✅ PROCESAR MEDICAMENTOS
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

    // ✅ BUSCAR O CREAR PACIENTE SI NO EXISTE
    if ($request->has('idpaciente') && !empty($request->idpaciente)) {
        $pacienteExiste = Paciente::find($request->idpaciente);
        
        if (!$pacienteExiste) {
            Log::warning('⚠️ Paciente no existe, creando automáticamente:', [
                'idpaciente' => $request->idpaciente,
                'identificacion' => $request->identificacion,
                'nombre_apellido' => $request->nombre_apellido
            ]);
            
            // Crear paciente con los datos mínimos disponibles
            try {
                $nombreCompleto = explode(' ', $request->nombre_apellido, 2);
                $pacienteExiste = Paciente::create([
                    'id' => $request->idpaciente,
                    'identificacion' => $request->identificacion,
                    'nombre' => $nombreCompleto[0] ?? 'Sin nombre',
                    'apellido' => $nombreCompleto[1] ?? 'Sin apellido',
                    'fecnacimiento' => now()->subYears(30), // Fecha temporal
                    'genero' => 'No especificado',
                    'latitud' => $request->latitud ?? null,
                    'longitud' => $request->longitud ?? null,
                    'idsede' => null
                ]);
                
                Log::info('✅ Paciente creado automáticamente:', ['id' => $pacienteExiste->id]);
            } catch (\Exception $e) {
                Log::error('❌ Error creando paciente automáticamente:', [
                    'error' => $e->getMessage(),
                    'idpaciente' => $request->idpaciente
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Error: El paciente no existe y no se pudo crear automáticamente. ' . $e->getMessage()
                ], 422);
            }
        }
    }

    // ✅ FORZAR COORDENADAS SI NO LLEGAN - AGREGAR AQUÍ
    if (!isset($visitaData['latitud']) || empty($visitaData['latitud'])) {
        Log::warning('⚠️ Latitud no recibida, usando valor por defecto');
        $visitaData['latitud'] = null; // o un valor por defecto
    }

    if (!isset($visitaData['longitud']) || empty($visitaData['longitud'])) {
        Log::warning('⚠️ Longitud no recibida, usando valor por defecto');
        $visitaData['longitud'] = null; // o un valor por defecto
    }

    Log::info('📍 Coordenadas finales:', [
        'latitud' => $visitaData['latitud'] ?? 'NULL',
        'longitud' => $visitaData['longitud'] ?? 'NULL'
    ]);

    // ✅ ASEGURAR VALORES POR DEFECTO
    if (!isset($visitaData['estado']) || empty($visitaData['estado'])) {
        $visitaData['estado'] = 'pendiente';
    }
    
    if (!isset($visitaData['sync_status'])) {
        $visitaData['sync_status'] = 0;
    }

    try {
        // ✅ USAR TRANSACCIÓN
        DB::beginTransaction();

        // Procesar archivos
        if ($request->hasFile('riesgo_fotografico')) {
            $this->processRiskPhotoFile($visitaData, $request->file('riesgo_fotografico'));
        } elseif ($request->has('riesgo_fotografico_base64') && !empty($request->riesgo_fotografico_base64)) {
            $this->processRiskPhotoBase64($visitaData, $request->riesgo_fotografico_base64);
        }

        if ($request->hasFile('firma')) {
            $this->processSignatureFile($visitaData, $request->file('firma'));
        } elseif ($request->has('firma_base64') && !empty($request->firma_base64)) {
            $this->processSignatureBase64($visitaData, $request->firma_base64);
        }

        Log::info('Datos finales para crear visita:', $visitaData);
        $visita = Visita::create($visitaData);
        Log::info('Visita creada:', ['id' => $visita->id]);

        // ✅ PROCESAR MEDICAMENTOS CON MÉTODO CORREGIDO
        if (!empty($medicamentosData)) {
            $this->processMedicamentos($visita, $medicamentosData);
        }

        DB::commit();

        $visitaCompleta = $visita->load(['usuario', 'paciente']);
        
        return response()->json([
            'success' => true,
            'data' => $visitaCompleta,
            'message' => 'Visita creada exitosamente'
        ], 201);

    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('Error al crear visita:', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'line' => $e->getLine(),
            'file' => $e->getFile()
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
            $visita = Visita::with(['usuario', 'paciente'])->findOrFail($id);
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
        Log::info('Datos recibidos para update:', $request->all());
        
        $request->validate([
            'nombre_apellido' => 'sometimes|required|string',
            'identificacion' => 'sometimes|required|string',
            'fecha' => 'sometimes|required|date',
            'idusuario' => 'sometimes|required|exists:usuarios,id',
            'idpaciente' => 'sometimes|nullable|string',  // ✅ CAMBIADO: Ya no requiere que exista en la BD
            
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
            
            // ✅ AGREGAR VALIDACIONES FALTANTES EN UPDATE
            'latitud' => 'sometimes|nullable|numeric',
            'longitud' => 'sometimes|nullable|numeric',
            'estado' => 'sometimes|nullable|string',
            'sync_status' => 'sometimes|nullable|integer',
            'observaciones_adicionales' => 'sometimes|nullable|string',
            'tipo_visita' => 'sometimes|nullable|string',
            
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
        
        // ✅ PROCESAR MEDICAMENTOS IGUAL QUE EN STORE
        $medicamentosData = [];
        if ($request->has('medicamentos') && !empty($request->medicamentos)) {
            try {
                $medicamentosData = json_decode($request->medicamentos, true);
                if (!is_array($medicamentosData)) {
                    Log::warning('Medicamentos no es un array válido en update:', ['medicamentos' => $request->medicamentos]);
                    $medicamentosData = [];
                }
                Log::info('Medicamentos decodificados en update:', ['medicamentos_decoded' => $medicamentosData]);
            } catch (\Exception $e) {
                Log::error('Error decodificando medicamentos en update:', ['error' => $e->getMessage()]);
                $medicamentosData = [];
            }
        }

        // ✅ OBTENER DATOS EXCLUYENDO CAMPOS ESPECIALES (IGUAL QUE EN STORE)
        $visitaData = $request->except(['medicamentos', 'riesgo_fotografico_base64', 'firma_base64']);

        // ✅ BUSCAR O CREAR PACIENTE SI NO EXISTE (igual que en store)
        if ($request->has('idpaciente') && !empty($request->idpaciente)) {
            $pacienteExiste = Paciente::find($request->idpaciente);
            
            if (!$pacienteExiste) {
                Log::warning('⚠️ Paciente no existe en update, creando automáticamente:', [
                    'idpaciente' => $request->idpaciente,
                    'identificacion' => $request->identificacion,
                    'nombre_apellido' => $request->nombre_apellido
                ]);
                
                try {
                    $nombreCompleto = explode(' ', $request->nombre_apellido, 2);
                    $pacienteExiste = Paciente::create([
                        'id' => $request->idpaciente,
                        'identificacion' => $request->identificacion,
                        'nombre' => $nombreCompleto[0] ?? 'Sin nombre',
                        'apellido' => $nombreCompleto[1] ?? 'Sin apellido',
                        'fecnacimiento' => now()->subYears(30),
                        'genero' => 'No especificado',
                        'latitud' => $request->latitud ?? null,
                        'longitud' => $request->longitud ?? null,
                        'idsede' => null
                    ]);
                    
                    Log::info('✅ Paciente creado automáticamente en update:', ['id' => $pacienteExiste->id]);
                } catch (\Exception $e) {
                    Log::error('❌ Error creando paciente en update:', [
                        'error' => $e->getMessage(),
                        'idpaciente' => $request->idpaciente
                    ]);
                    
                    return response()->json([
                        'success' => false,
                        'message' => 'Error: El paciente no existe y no se pudo crear automáticamente. ' . $e->getMessage()
                    ], 422);
                }
            }
        }

        // ✅ FORZAR COORDENADAS SI NO LLEGAN (IGUAL QUE EN STORE)
        if (!isset($visitaData['latitud']) || empty($visitaData['latitud'])) {
            Log::warning('⚠️ Latitud no recibida en update, manteniendo valor actual');
            // No sobreescribir si no viene
            unset($visitaData['latitud']);
        }

        if (!isset($visitaData['longitud']) || empty($visitaData['longitud'])) {
            Log::warning('⚠️ Longitud no recibida en update, manteniendo valor actual');
            // No sobreescribir si no viene
            unset($visitaData['longitud']);
        }

        Log::info('📍 Coordenadas finales en update:', [
            'latitud' => $visitaData['latitud'] ?? 'NO CAMBIA',
            'longitud' => $visitaData['longitud'] ?? 'NO CAMBIA'
        ]);

        // ✅ USAR TRANSACCIÓN EN UPDATE
        DB::beginTransaction();

        // Procesar archivos (mantener tu lógica actual)
        if ($request->hasFile('riesgo_fotografico')) {
            $this->deleteExistingFile($visita->riesgo_fotografico);
            $this->processRiskPhotoFile($visitaData, $request->file('riesgo_fotografico'));
        } elseif ($request->has('riesgo_fotografico_base64') && !empty($request->riesgo_fotografico_base64)) {
            $this->deleteExistingFile($visita->riesgo_fotografico);
            $this->processRiskPhotoBase64($visitaData, $request->riesgo_fotografico_base64);
        }
        
        if ($request->hasFile('firma')) {
            $this->deleteExistingFile($visita->firma);
            $this->processSignatureFile($visitaData, $request->file('firma'));
        } elseif ($request->has('firma_base64') && !empty($request->firma_base64)) {
            $this->deleteExistingFile($visita->firma);
            $this->processSignatureBase64($visitaData, $request->firma_base64);
        }
        
        Log::info('Datos finales para actualizar visita:', $visitaData);
        
        // ✅ ACTUALIZAR LA VISITA
        $visita->update($visitaData);

        // ✅ PROCESAR MEDICAMENTOS IGUAL QUE EN STORE (SIN RELACIÓN)
        if (!empty($medicamentosData)) {
            $this->processMedicamentos($visita, $medicamentosData, true);
        }

        DB::commit();

        Log::info('Visita actualizada exitosamente:', ['id' => $visita->id]);

        // ✅ CARGAR RELACIONES IGUAL QUE EN STORE
        $visitaCompleta = $visita->load(['usuario', 'paciente']);

        return response()->json([
            'success' => true,
            'data' => $visitaCompleta,
            'message' => 'Visita actualizada exitosamente'
        ]);

    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('Error al actualizar visita:', [
            'visita_id' => $id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'line' => $e->getLine(),
            'file' => $e->getFile()
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

    // ✅ MÉTODOS PARA SUBIR ARCHIVOS
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

    // ========== MÉTODOS PRIVADOS ==========

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

    // ✅ MÉTODO CLAVE CORREGIDO: PROCESAR MEDICAMENTOS SIN TIMESTAMPS
    private function processMedicamentos($visita, $medicamentosData, $isUpdate = false)
    {
        if ($isUpdate) {
            // Si es actualización, primero eliminamos los registros existentes
            DB::table('medicamento_visita')->where('visita_id', $visita->id)->delete();
        }
        
        $medicamentosGuardados = 0;
        
        foreach ($medicamentosData as $medicamento) {
            try {
                if (!isset($medicamento['id'])) {
                    Log::warning('Medicamento sin ID:', ['medicamento' => $medicamento]);
                    continue;
                }

                // Verificar que el medicamento existe
                $medicamentoExiste = Medicamento::find($medicamento['id']);
                if (!$medicamentoExiste) {
                    Log::warning('Medicamento no existe:', ['id' => $medicamento['id']]);
                    continue;
                }

                // ✅ INSERTAR DIRECTAMENTE CON DB::table() SIN TIMESTAMPS
                DB::table('medicamento_visita')->insert([
                    'medicamento_id' => $medicamento['id'],
                    'visita_id' => $visita->id,
                    'indicaciones' => $medicamento['indicaciones'] ?? null
                ]);
                
                $medicamentosGuardados++;
                
                Log::info('Medicamento guardado:', [
                    'visita_id' => $visita->id,
                    'medicamento_id' => $medicamento['id'],
                    'indicaciones' => $medicamento['indicaciones'] ?? null
                ]);
                
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
