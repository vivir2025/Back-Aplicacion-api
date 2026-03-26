<?php

namespace App\Http\Controllers;

use App\Models\Visita;
use App\Models\Paciente;
use App\Models\Medicamento;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use App\Events\VisitaCreada;
use App\Events\ModuloError;

/**
 * @group Visitas Domiciliarias
 *
 * Gestión de visitas realizadas a pacientes en su domicilio, incluyendo toma de signos vitales, fotos y firmas.
 */
class VisitaController extends Controller
{
    /**
     * Listar todas las visitas
     * 
     * @authenticated
     */
    public function index()
    {
        return Visita::with(['usuario', 'paciente'])->get();
    }

  /**
   * Crear visita domiciliaria
   *
   * Permite registrar una visita, procesando coordenadas, fotos de riesgo y firmas en archivos o Base64.
   *
   * @authenticated
   * @bodyParam nombre_apellido string required Nombre del paciente contactado.
   * @bodyParam identificacion string required Cédula del paciente.
   * @bodyParam fecha date required Fecha de la visita.
   * @bodyParam idusuario string required ID del usuario (Hacedor).
   * @bodyParam idpaciente string ID del paciente (opcional, se busca por cédula si falta).
   * @bodyParam hta string Presión arterial (si/no).
   * @bodyParam dm string Diabetes (si/no).
   * @bodyParam peso number Peso (kg).
   * @bodyParam talla number Talla (cm).
   * @bodyParam tension_arterial string Valor TA (e.g. 120/80).
   * @bodyParam riesgo_fotografico_base64 string Imagen del riesgo en Base64.
   * @bodyParam firma_base64 string Firma en Base64.
   * @bodyParam medicamentos json JSON con lista de medicamentos e indicaciones. Example: [{"id": 1, "indicaciones": "Cena"}]
   */
  public function store(Request $request)
{
    Log::info('[POST] Visita Domiciliaria → Procesando', [
        'usuario_id' => $request->idusuario,
        'paciente_id' => $request->idpaciente,
    ]);

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
            // ✅ PRIMERO: Buscar por identificación (cédula)
            if ($request->has('identificacion') && !empty($request->identificacion)) {
                $pacientePorIdentificacion = Paciente::where('identificacion', $request->identificacion)->first();
                
                if ($pacientePorIdentificacion) {
                    Log::warning('[POST] Visita Domiciliaria → Advertencia', [
                        'detalle' => 'ID de paciente no encontrado, reasignado por cédula',
                        'id_real' => $pacientePorIdentificacion->id,
                    ]);
                    
                    // Usar el paciente encontrado
                    $pacienteExiste = $pacientePorIdentificacion;
                    // ✅ ACTUALIZAR TANTO REQUEST COMO visitaData
                    $request->merge(['idpaciente' => $pacientePorIdentificacion->id]);
                    $visitaData['idpaciente'] = $pacientePorIdentificacion->id;
                } else {
                    // El paciente NO existe ni por ID ni por identificación, crearlo
                    Log::warning('[POST] Visita Domiciliaria → Advertencia', [
                        'detalle' => 'Paciente no existe, creando automáticamente',
                    ]);
                    
                    try {
                        // ✅ Obtener la sede del usuario que está haciendo la visita
                        $usuario = Usuario::find($request->idusuario);
                        $idsedeUsuario = $usuario ? $usuario->idsede : null;
                        
                        $nombreCompleto = explode(' ', $request->nombre_apellido, 2);
                        $pacienteExiste = Paciente::create([
                            // NO usar el id offline, dejar que Laravel genere un UUID
                            'identificacion' => $request->identificacion,
                            'nombre' => $nombreCompleto[0] ?? 'Sin nombre',
                            'apellido' => $nombreCompleto[1] ?? 'Sin apellido',
                            'fecnacimiento' => now()->subYears(30),
                            'genero' => 'No especificado',
                            'latitud' => $request->latitud ?? null,
                            'longitud' => $request->longitud ?? null,
                            'idsede' => $idsedeUsuario
                        ]);
                        
                        // ✅ ACTUALIZAR visitaData con el ID del paciente creado
                        $visitaData['idpaciente'] = $pacienteExiste->id;
                        
                        Log::info('[POST] Visita Domiciliaria → Paciente auto-creado', ['paciente_id' => $pacienteExiste->id]);
                    } catch (\Exception $e) {
                        Log::error('[POST] Visita Domiciliaria → Error al auto-crear paciente', [
                            'mensaje' => $e->getMessage(),
                        ]);
                        
                        return response()->json([
                            'success' => false,
                            'message' => 'Error: El paciente no existe y no se pudo crear automáticamente. ' . $e->getMessage()
                        ], 422);
                    }
                }
            }
        } else {
            // ✅ El paciente ya existe con el ID proporcionado
            $visitaData['idpaciente'] = $pacienteExiste->id;
        }
    }

    // ✅ FORZAR COORDENADAS SI NO LLEGAN
    if (!isset($visitaData['latitud']) || empty($visitaData['latitud'])) {
        $visitaData['latitud'] = null;
    }

    if (!isset($visitaData['longitud']) || empty($visitaData['longitud'])) {
        $visitaData['longitud'] = null;
    }

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

        $visita = Visita::create($visitaData);

        // ✅ PROCESAR MEDICAMENTOS CON MÉTODO CORREGIDO
        if (!empty($medicamentosData)) {
            $this->processMedicamentos($visita, $medicamentosData);
        }

        DB::commit();

        $visitaCompleta = $visita->load(['usuario', 'paciente']);

        Log::info('[POST] Visita Domiciliaria → Exitosa', [
            'id'            => $visita->id,
            'paciente_id'   => $visita->idpaciente,
            'usuario_id'    => $visita->idusuario,
            'medicamentos'  => count($medicamentosData),
        ]);

        // 🔔 Notificación Telegram
        $usuarioNotif = Usuario::find($visitaCompleta->idusuario);
        event(new VisitaCreada([
            'sede'     => optional(optional($usuarioNotif)->sede)->nombresede ?? 'N/A',
            'paciente' => $visitaCompleta->nombre_apellido ?? 'N/A',
            'usuario'  => optional($usuarioNotif)->nombre ?? 'N/A',
            'fecha'    => optional($visitaCompleta->fecha)?->format('Y-m-d') ?? now()->format('Y-m-d'),
        ]));

        return response()->json([
            'success' => true,
            'data' => $visitaCompleta,
            'message' => 'Visita creada exitosamente'
        ], 201);

    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('[POST] Visita Domiciliaria → Error', [
            'mensaje' => $e->getMessage(),
            'linea'   => $e->getLine(),
        ]);

        // 🔔 Notificación error Telegram
        $usuarioErr = $request->user();
        event(new ModuloError([
            'modulo'  => 'Visitas',
            'mensaje' => $e->getMessage(),
            'usuario' => optional($usuarioErr)->nombre ?? 'N/A',
            'sede'    => optional(optional($usuarioErr)->sede)->nombresede ?? 'N/A',
        ]));

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
    /**
     * Actualizar visita
     * 
     * @authenticated
     * @urlParam id string required ID de la visita.
     */
    public function update(Request $request, $id)
{
    try {
        $visita = Visita::findOrFail($id);
        Log::info('[PUT] Visita Domiciliaria → Procesando', [
            'id'         => $id,
            'usuario_id' => $request->idusuario,
        ]);
        
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
                    Log::warning('[PUT] Visita Domiciliaria → Advertencia', [
                        'detalle' => 'Medicamentos no es un array válido',
                    ]);
                    $medicamentosData = [];
                }
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
                // ✅ PRIMERO: Buscar por identificación (cédula)
                if ($request->has('identificacion') && !empty($request->identificacion)) {
                    $pacientePorIdentificacion = Paciente::where('identificacion', $request->identificacion)->first();
                    
                    if ($pacientePorIdentificacion) {
                        Log::warning('[PUT] Visita Domiciliaria → Advertencia', [
                            'detalle' => 'ID de paciente no encontrado, reasignado por cédula',
                            'id_real' => $pacientePorIdentificacion->id,
                        ]);
                        
                        // Usar el paciente encontrado
                        $pacienteExiste = $pacientePorIdentificacion;
                        $request->merge(['idpaciente' => $pacientePorIdentificacion->id]);
                    } else {
                        // El paciente NO existe ni por ID ni por identificación, crearlo
                        Log::warning('[PUT] Visita Domiciliaria → Advertencia', [
                            'detalle' => 'Paciente no existe en update, creando automáticamente',
                        ]);
                        
                        try {
                            // ✅ Obtener la sede del usuario que está haciendo la visita
                            $usuario = Usuario::find($request->idusuario);
                            $idsedeUsuario = $usuario ? $usuario->idsede : null;
                            
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
                                'idsede' => $idsedeUsuario
                            ]);
                            
                            Log::info('[PUT] Visita Domiciliaria → Paciente auto-creado', ['paciente_id' => $pacienteExiste->id]);
                        } catch (\Exception $e) {
                            Log::error('[PUT] Visita Domiciliaria → Error al auto-crear paciente', [
                                'mensaje' => $e->getMessage(),
                            ]);
                            
                            return response()->json([
                                'success' => false,
                                'message' => 'Error: El paciente no existe y no se pudo crear automáticamente. ' . $e->getMessage()
                            ], 422);
                        }
                    }
                }
            }
        }

    // ✅ FORZAR COORDENADAS SI NO LLEGAN (IGUAL QUE EN STORE)
    if (!isset($visitaData['latitud']) || empty($visitaData['latitud'])) {
        // No sobreescribir si no viene
        unset($visitaData['latitud']);
    }

    if (!isset($visitaData['longitud']) || empty($visitaData['longitud'])) {
        // No sobreescribir si no viene
        unset($visitaData['longitud']);
    }

        // ✅ USAR TRANSACCIÓN EN UPDATE
        DB::beginTransaction();

        // Procesar archivos
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

        // ✅ ACTUALIZAR LA VISITA
        $visita->update($visitaData);

        if (!empty($medicamentosData)) {
            $this->processMedicamentos($visita, $medicamentosData, true);
        }

        DB::commit();

        $visitaCompleta = $visita->load(['usuario', 'paciente']);

        Log::info('[PUT] Visita Domiciliaria → Exitosa', [
            'id'           => $id,
            'medicamentos' => count($medicamentosData),
        ]);

        return response()->json([
            'success' => true,
            'data'    => $visitaCompleta,
            'message' => 'Visita actualizada exitosamente'
        ]);

    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('[PUT] Visita Domiciliaria → Error', [
            'id'      => $id,
            'mensaje' => $e->getMessage(),
            'linea'   => $e->getLine(),
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
        } catch (\Exception $e) {
            Log::error('Error procesando firma en Base64: ' . $e->getMessage());
            throw $e;
        }
    }

    private function processMedicamentos($visita, $medicamentosData, $isUpdate = false)
    {
        if ($isUpdate) {
            DB::table('medicamento_visita')->where('visita_id', $visita->id)->delete();
        }
        
        $medicamentosGuardados = 0;
        $medicamentosOmitidos = 0;
        
        foreach ($medicamentosData as $medicamento) {
            try {
                if (!isset($medicamento['id'])) {
                    $medicamentosOmitidos++;
                    continue;
                }

                $medicamentoExiste = Medicamento::find($medicamento['id']);
                if (!$medicamentoExiste) {
                    $medicamentosOmitidos++;
                    continue;
                }

                DB::table('medicamento_visita')->insert([
                    'medicamento_id' => $medicamento['id'],
                    'visita_id'      => $visita->id,
                    'indicaciones'   => $medicamento['indicaciones'] ?? null
                ]);
                
                $medicamentosGuardados++;
                
            } catch (\Exception $e) {
                Log::error('[Visita] Error guardando medicamento', [
                    'medicamento_id' => $medicamento['id'] ?? 'sin_id',
                    'mensaje'        => $e->getMessage(),
                ]);
            }
        }

        if ($medicamentosOmitidos > 0) {
            Log::warning('[Visita] Medicamentos omitidos', [
                'omitidos' => $medicamentosOmitidos,
                'total'    => count($medicamentosData),
            ]);
        }
    }

    private function deleteExistingFile($filePath)
    {
        if ($filePath && Storage::disk('public')->exists($filePath)) {
            Storage::disk('public')->delete($filePath);
        }
    }
}
