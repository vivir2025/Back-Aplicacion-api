<?php

namespace App\Http\Controllers;

use App\Models\Paciente;
use App\Models\Brigada;
use App\Models\Visita;
use App\Models\Tamizaje;
use App\Models\EnvioMuestra;
use App\Models\Encuesta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class EstadisticasController extends Controller
{
    /**
     * Obtener estadísticas generales filtradas por usuario y sede
     * 
     * Admite parámetros opcionales de fecha:
     * - fecha_inicio: Fecha de inicio en formato YYYY-MM-DD
     * - fecha_fin: Fecha fin en formato YYYY-MM-DD
     * 
     * Si el usuario es administrador, ve todas las estadísticas
     * Si el usuario pertenece a una sede, solo ve estadísticas de su sede
     */
    public function index(Request $request)
    {
        try {
            $usuario = $request->user();
            
            if (!$usuario) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado'
                ], 401);
            }

            // Validar fechas si se proporcionan
            $request->validate([
                'fecha_inicio' => 'nullable|date_format:Y-m-d',
                'fecha_fin' => 'nullable|date_format:Y-m-d|after_or_equal:fecha_inicio',
            ]);

            $fechaInicio = $request->input('fecha_inicio');
            $fechaFin = $request->input('fecha_fin');
            
            // Si se proporciona fecha_fin, agregar hora para incluir todo el día
            if ($fechaFin) {
                $fechaFin .= ' 23:59:59';
            }

            Log::info('Obteniendo estadísticas', [
                'usuario_id' => $usuario->id,
                'usuario_nombre' => $usuario->nombre,
                'usuario_rol' => $usuario->rol,
                'sede_id' => $usuario->idsede,
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => $fechaFin,
            ]);

            // Determinar si el usuario es administrador
            $esAdmin = in_array(strtolower($usuario->rol), ['administrador', 'admin', 'superadmin']);
            
            // Definir el scope de la consulta según el rol
            $sedeId = $esAdmin ? null : $usuario->idsede;

            // ============================================
            // OBTENER ESTADÍSTICAS FILTRADAS
            // ============================================

            // 📊 Total de Pacientes
            $queryPacientes = Paciente::query();
            if ($sedeId) {
                $queryPacientes->where('idsede', $sedeId);
            }
            if ($fechaInicio && $fechaFin) {
                $queryPacientes->whereBetween('created_at', [$fechaInicio, $fechaFin]);
            }
            $totalPacientes = $queryPacientes->count();

            // 📊 Total de Brigadas
            $queryBrigadas = Brigada::query();
            if ($sedeId) {
                $queryBrigadas->where('idsede', $sedeId);
            }
            if ($fechaInicio && $fechaFin) {
                $queryBrigadas->whereBetween('created_at', [$fechaInicio, $fechaFin]);
            }
            $totalBrigadas = $queryBrigadas->count();

            // 📊 Total de Visitas
            $queryVisitas = Visita::query();
            if ($sedeId) {
                $queryVisitas->whereHas('paciente', function($q) use ($sedeId) {
                    $q->where('idsede', $sedeId);
                });
            }
            if ($fechaInicio && $fechaFin) {
                $queryVisitas->whereBetween('created_at', [$fechaInicio, $fechaFin]);
            }
            $totalVisitas = $queryVisitas->count();

            // 📊 Total de Tamizajes
            $queryTamizajes = Tamizaje::query();
            if ($sedeId) {
                $queryTamizajes->whereHas('paciente', function($q) use ($sedeId) {
                    $q->where('idsede', $sedeId);
                });
            }
            if ($fechaInicio && $fechaFin) {
                $queryTamizajes->whereBetween('created_at', [$fechaInicio, $fechaFin]);
            }
            $totalTamizajes = $queryTamizajes->count();

            // 📊 Total de Laboratorios (Envío de Muestras)
            $queryLaboratorios = EnvioMuestra::query();
            if ($sedeId) {
                $queryLaboratorios->where('idsede', $sedeId);
            }
            if ($fechaInicio && $fechaFin) {
                $queryLaboratorios->whereBetween('created_at', [$fechaInicio, $fechaFin]);
            }
            $totalLaboratorios = $queryLaboratorios->count();

            // 📊 Total de Encuestas
            $queryEncuestas = Encuesta::query();
            if ($sedeId) {
                $queryEncuestas->where('idsede', $sedeId);
            }
            if ($fechaInicio && $fechaFin) {
                $queryEncuestas->whereBetween('created_at', [$fechaInicio, $fechaFin]);
            }
            $totalEncuestas = $queryEncuestas->count();

            // ============================================
            // ESTADÍSTICAS MENSUALES (MES ACTUAL)
            // ============================================
            
            $inicioMes = Carbon::now()->startOfMonth()->toDateString();
            $finMes = Carbon::now()->endOfMonth()->toDateTimeString();

            // Visitas del mes actual
            $queryVisitasMes = Visita::query();
            if ($sedeId) {
                $queryVisitasMes->whereHas('paciente', function($q) use ($sedeId) {
                    $q->where('idsede', $sedeId);
                });
            }
            $visitasMes = $queryVisitasMes->whereBetween('created_at', [$inicioMes, $finMes])->count();

            // Laboratorios del mes actual
            $queryLaboratoriosMes = EnvioMuestra::query();
            if ($sedeId) {
                $queryLaboratoriosMes->where('idsede', $sedeId);
            }
            $laboratoriosMes = $queryLaboratoriosMes->whereBetween('created_at', [$inicioMes, $finMes])->count();

            // ============================================
            // RESPUESTA
            // ============================================

            return response()->json([
                'success' => true,
                'data' => [
                    'total_pacientes' => $totalPacientes,
                    'total_brigadas' => $totalBrigadas,
                    'total_visitas' => $totalVisitas,
                    'total_tamizajes' => $totalTamizajes,
                    'total_envio_muestras' => $totalLaboratorios,
                    'total_laboratorios' => $totalLaboratorios, // Alias para compatibilidad
                    'total_encuestas' => $totalEncuestas,
                    'visitas_mes' => $visitasMes,
                    'laboratorios_mes' => $laboratoriosMes,
                    'fecha_consulta' => now()->toIso8601String(),
                    
                    // Información adicional
                    'filtros_aplicados' => [
                        'sede_id' => $sedeId,
                        'sede_nombre' => $sedeId ? $usuario->sede->nombresede ?? 'N/A' : 'Todas',
                        'es_administrador' => $esAdmin,
                        'fecha_inicio' => $fechaInicio,
                        'fecha_fin' => $request->input('fecha_fin'), // Sin hora
                    ],
                    'usuario' => [
                        'id' => $usuario->id,
                        'nombre' => $usuario->nombre,
                        'rol' => $usuario->rol,
                    ]
                ],
                'message' => 'Estadísticas obtenidas correctamente'
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
            
        } catch (\Exception $e) {
            Log::error('Error al obtener estadísticas: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estadísticas: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener estadísticas por sede específica (solo para administradores)
     */
    public function porSede(Request $request, $sedeId)
    {
        try {
            $usuario = $request->user();
            
            // Verificar que sea administrador
            $esAdmin = in_array(strtolower($usuario->rol), ['administrador', 'admin', 'superadmin']);
            
            if (!$esAdmin) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permisos para ver estadísticas de otras sedes'
                ], 403);
            }

            // Reutilizar la lógica del método index pero forzando la sede
            $request->merge(['force_sede' => $sedeId]);
            
            // Aquí podrías duplicar la lógica o refactorizar
            return response()->json([
                'success' => true,
                'message' => 'Funcionalidad en desarrollo'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estadísticas por sede: ' . $e->getMessage()
            ], 500);
        }
    }
}
