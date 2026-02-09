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

            // ✅ FILTRAR SIEMPRE POR USUARIO LOGUEADO (incluso administradores)
            $usuarioId = $usuario->id;

            // ============================================
            // OBTENER ESTADÍSTICAS FILTRADAS POR USUARIO
            // ============================================

            // 📊 Total de Pacientes (únicos que el usuario ha atendido)
            $queryPacientes = Paciente::query();
            $queryPacientes->whereHas('visitas', function($q) use ($usuarioId) {
                $q->where('idusuario', $usuarioId);
            });
            if ($fechaInicio && $fechaFin) {
                $queryPacientes->whereBetween('created_at', [$fechaInicio, $fechaFin]);
            }
            $totalPacientes = $queryPacientes->distinct()->count('id');

            // 📊 Total de Brigadas (las brigadas no tienen idusuario, se retorna 0)
            $totalBrigadas = 0;

            // 📊 Total de Visitas del usuario
            $queryVisitas = Visita::query();
            $queryVisitas->where('idusuario', $usuarioId);
            if ($fechaInicio && $fechaFin) {
                $queryVisitas->whereBetween('created_at', [$fechaInicio, $fechaFin]);
            }
            $totalVisitas = $queryVisitas->count();

            // 📊 Total de Tamizajes del usuario
            $queryTamizajes = Tamizaje::query();
            $queryTamizajes->where('idusuario', $usuarioId);
            if ($fechaInicio && $fechaFin) {
                $queryTamizajes->whereBetween('created_at', [$fechaInicio, $fechaFin]);
            }
            $totalTamizajes = $queryTamizajes->count();

            // 📊 Total de Laboratorios del usuario
            $queryLaboratorios = EnvioMuestra::query();
            $queryLaboratorios->where('idusuario', $usuarioId);
            if ($fechaInicio && $fechaFin) {
                $queryLaboratorios->whereBetween('created_at', [$fechaInicio, $fechaFin]);
            }
            $totalLaboratorios = $queryLaboratorios->count();

            // 📊 Total de Encuestas del usuario
            $queryEncuestas = Encuesta::query();
            $queryEncuestas->where('idusuario', $usuarioId);
            if ($fechaInicio && $fechaFin) {
                $queryEncuestas->whereBetween('created_at', [$fechaInicio, $fechaFin]);
            }
            $totalEncuestas = $queryEncuestas->count();

            // ============================================
            // ESTADÍSTICAS MENSUALES (MES ACTUAL)
            // ============================================
            
            $inicioMes = Carbon::now()->startOfMonth()->toDateString();
            $finMes = Carbon::now()->endOfMonth()->toDateTimeString();

            // Visitas del mes actual del usuario
            $queryVisitasMes = Visita::query();
            $queryVisitasMes->where('idusuario', $usuarioId);
            $visitasMes = $queryVisitasMes->whereBetween('created_at', [$inicioMes, $finMes])->count();

            // Laboratorios del mes actual del usuario
            $queryLaboratoriosMes = EnvioMuestra::query();
            $queryLaboratoriosMes->where('idusuario', $usuarioId);
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
                        'usuario_id' => $usuarioId,
                        'sede_id' => $usuario->idsede,
                        'sede_nombre' => $usuario->sede->nombresede ?? 'N/A',
                        'fecha_inicio' => $fechaInicio,
                        'fecha_fin' => $request->input('fecha_fin'), // Sin hora
                    ],
                    'usuario' => [
                        'id' => $usuario->id,
                        'nombre' => $usuario->nombre,
                        'rol' => $usuario->rol,
                    ]
                ],
                'message' => 'Estadísticas obtenidas correctamente (filtradas por usuario)'
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
     * Obtener estadísticas por sede específica (AHORA filtra por usuario logueado)
     */
    public function porSede(Request $request, $sedeId)
    {
        // Redirigir al método index que ya filtra por usuario
        return $this->index($request);
    }
}
