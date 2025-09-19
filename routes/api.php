<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\SedeController;
use App\Http\Controllers\UsuarioController;
use App\Http\Controllers\EncuestaController;
use App\Http\Controllers\PacienteController;
use App\Http\Controllers\MedicamentoController;
use App\Http\Controllers\PacienteMedicamentoController;
use App\Http\Controllers\VisitaController;
use App\Http\Controllers\BrigadaController;
use App\Http\Controllers\EnvioMuestraController;
use App\Http\Controllers\FindriskTestController;
use App\Http\Controllers\AfinamientoController;
use App\Http\Controllers\TamizajeController;
use App\Http\Controllers\LogController;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

// AGREGAR RUTA DE HEALTH CHECK
Route::get('/health', function () {
    return response()->json(['status' => 'ok', 'timestamp' => now()]);
});

// Alternativa: usar la raíz como health check
Route::get('/', function () {
    return response()->json(['status' => 'API funcionando', 'timestamp' => now()]);
});

Route::middleware('auth:sanctum')->group(function () {
    
   Route::prefix('logs')->group(function () {
        Route::get('/', [LogController::class, 'index']);
        Route::get('/stats', [LogController::class, 'stats']);
        Route::get('/{id}', [LogController::class, 'show']);
    });
    // Perfil de usuario
    Route::get('/perfil', [AuthController::class, 'perfil']);
    Route::put('/perfil', [AuthController::class, 'actualizarPerfil']);
    
    // Usuarios
    Route::apiResource('usuarios', UsuarioController::class);
    Route::get('/usuarios/rol/{rol}', [UsuarioController::class, 'getUsuariosPorRol']);
    Route::get('/auxiliares', [UsuarioController::class, 'getAuxiliares']);
    
    // Sedes
    Route::apiResource('sedes', SedeController::class);
    
    // Pacientes
    Route::apiResource('pacientes', PacienteController::class);
    Route::get('/pacientes/buscar/{identificacion}', [PacienteController::class, 'buscarPorIdentificacion']);
    
    // Medicamentos
    Route::apiResource('medicamentos', MedicamentoController::class);
    Route::get('/medicamentos/buscar', [MedicamentoController::class, 'index']);
    ///Medicamentos Pendientes
    Route::apiResource('brigadas', BrigadaController::class);
    Route::post('brigadas/{id}/pacientes', [BrigadaController::class, 'assignPacientes']);
    Route::delete('brigadas/{id}/pacientes', [BrigadaController::class, 'removePacientes']);
    Route::get('brigadas/{id}/medicamentos', [BrigadaController::class, 'getMedicamentos']);
    Route::post('brigadas/{id}/medicamentos', [BrigadaController::class, 'assignMedicamentos']);
    
    // Visitas
    Route::apiResource('visitas', VisitaController::class);
    Route::post('/visitas', [VisitaController::class, 'store']);
    Route::get('/visitas/{id}', [VisitaController::class, 'show']);
    Route::put('/visitas/{id}', [VisitaController::class, 'update']);
    Route::delete('/visitas/{id}', [VisitaController::class, 'destroy']);
    Route::get('/visitas/buscar-paciente/{identificacion}', [VisitaController::class, 'buscarPaciente']);
    Route::post('/upload-risk-photo', [VisitaController::class, 'uploadRiskPhoto']);
    Route::post('/upload-signature', [VisitaController::class, 'uploadSignature']);
    Route::post('/upload-photo', [VisitaController::class, 'uploadPhoto']);
    // Rutas para subir archivos en Base64
    Route::post('/visitas/upload-risk-photo-base64', [VisitaController::class, 'uploadRiskPhotoBase64']);
    Route::post('/visitas/upload-signature-base64', [VisitaController::class, 'uploadSignatureBase64']);
    // En routes/api.php
    Route::put('/pacientes/{id}/coordenadas', [PacienteController::class, 'updateCoordenadas'])->middleware('auth:sanctum');
    // Rutas para envío de muestras
    Route::apiResource('envio-muestras', EnvioMuestraController::class);
    Route::get('envio-muestras/sede/{sedeId}', [EnvioMuestraController::class, 'getEnviosPorSede']);
    Route::get('responsables', [EnvioMuestraController::class, 'getResponsables']);
    // Agregar esta línea junto con las demás rutas de envío de muestras
    Route::get('envio-muestras/por-fecha-salida/{fecha}', [EnvioMuestraController::class, 'getEnviosPorFechaSalida']);

    // Rutas para medicamentos de pacientes
    Route::apiResource('brigadas', BrigadaController::class);
    Route::post('pacientes/{id}/medicamentos', [PacienteMedicamentoController::class, 'assignMedicamentos']);
    Route::put('pacientes/{id}/medicamentos', [PacienteMedicamentoController::class, 'updateMedicamentos']);
    Route::delete('pacientes/{id}/medicamentos', [PacienteMedicamentoController::class, 'removeMedicamentos']);
    Route::get('pacientes/{id}/medicamentos', [PacienteMedicamentoController::class, 'getMedicamentos']);
    
    Route::apiResource('encuestas', EncuestaController::class);
     Route::get('mis-encuestas', [EncuestaController::class, 'misEncuestas']);
        Route::get('encuestas/paciente/{idpaciente}', [EncuestaController::class, 'encuestasPorPaciente']);
        Route::get('encuestas/usuario/{idusuario}', [EncuestaController::class, 'encuestasPorUsuario']);
        Route::get('encuestas/sede/{idsede}', [EncuestaController::class, 'encuestasPorSede']);
        Route::get('encuestas-estadisticas', [EncuestaController::class, 'estadisticas']);
        
        
        // Rutas para FINDRISK Tests
    Route::apiResource('findrisk', FindriskTestController::class);
    Route::get('findrisk-estadisticas', [FindriskTestController::class, 'getEstadisticas']);
    Route::get('findrisk-estadisticas/sede/{idsede}', [FindriskTestController::class, 'getEstadisticasPorSede']);
    Route::get('findrisk/paciente/{idpaciente}', [FindriskTestController::class, 'getByPaciente']);
    Route::get('findrisk/sede/{idsede}', [FindriskTestController::class, 'getBySede']);
    Route::get('findrisk/paciente-sede/{identificacion}', [FindriskTestController::class, 'getPacienteConSede']);
   
// ❌ COMENTANDO ESTAS LÍNEAS PROBLEMÁTICAS
// Route::get('/{id}', [FindriskTestController::class, 'show']);
// Route::put('/{id}', [FindriskTestController::class, 'update']);
// Route::delete('/{id}', [FindriskTestController::class, 'destroy']);

    Route::get('findrisk-tests/export', [FindriskTestController::class, 'getExportData']);

    
    
     // Rutas de afinamientos
// ✅ ORDEN CORRECTO  
Route::apiResource('afinamientos', AfinamientoController::class);
Route::get('mis-afinamientos', [AfinamientoController::class, 'getMisAfinamientos']);
Route::get('afinamientos/paciente/{pacienteId}', [AfinamientoController::class, 'getAfinamientosPorPaciente']);




    


    
     //  RUTAS DE TAMIZAJES
  
    Route::prefix('tamizajes')->group(function () {
        Route::get('/', [TamizajeController::class, 'index']);
        Route::post('/', [TamizajeController::class, 'store']);
        Route::get('/estadisticas', [TamizajeController::class, 'estadisticas']);
        Route::get('/paciente/{pacienteId}', [TamizajeController::class, 'tamizajesPorPaciente']);
        Route::get('/mis-tamizajes', [TamizajeController::class, 'getMisTamizajes']); // Nueva ruta
        Route::get('/{id}', [TamizajeController::class, 'show']);
        Route::put('/{id}', [TamizajeController::class, 'update']);
        Route::delete('/{id}', [TamizajeController::class, 'destroy']);
       
    });
    
});