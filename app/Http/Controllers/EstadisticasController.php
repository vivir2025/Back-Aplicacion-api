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

            $usuarioId = $usuario->id;

            // ============================================
            // OBTENER ESTADÍSTICAS
            // ============================================

            // 📊 Total de Pacientes (TODOS, sin filtro de usuario)
            $queryPacientes = Paciente::query();
            if ($fechaInicio && $fechaFin) {
                $queryPacientes->whereBetween('created_at', [$fechaInicio, $fechaFin]);
            }
            $totalPacientes = $queryPacientes->count();

            // 📊 Total de Brigadas (FILTRADAS por usuario - NOTA: requiere campo idusuario en tabla)
            // Por ahora retorna 0 porque la tabla brigadas no tiene campo idusuario
            $totalBrigadas = 0;

            // 📊 Total de Visitas (FILTRADAS por usuario)
            $queryVisitas = Visita::where('idusuario', $usuarioId);
            if ($fechaInicio && $fechaFin) {
                $queryVisitas->whereBetween('created_at', [$fechaInicio, $fechaFin]);
            }
            $totalVisitas = $queryVisitas->count();

            // 📊 Total de Tamizajes (FILTRADAS por usuario)
            $queryTamizajes = Tamizaje::where('idusuario', $usuarioId);
            if ($fechaInicio && $fechaFin) {
                $queryTamizajes->whereBetween('created_at', [$fechaInicio, $fechaFin]);
            }
            $totalTamizajes = $queryTamizajes->count();

            // 📊 Total de Envíos de Muestras (FILTRADAS por usuario)
            $queryLaboratorios = EnvioMuestra::where('idusuario', $usuarioId);
            if ($fechaInicio && $fechaFin) {
                $queryLaboratorios->whereBetween('created_at', [$fechaInicio, $fechaFin]);
            }
            $totalLaboratorios = $queryLaboratorios->count();

            // 📊 Total de Encuestas (FILTRADAS por usuario)
            $queryEncuestas = Encuesta::where('idusuario', $usuarioId);
            if ($fechaInicio && $fechaFin) {
                $queryEncuestas->whereBetween('created_at', [$fechaInicio, $fechaFin]);
            }
            $totalEncuestas = $queryEncuestas->count();

            // ============================================
            // ESTADÍSTICAS MENSUALES (MES ACTUAL)
            // ============================================
            
            $inicioMes = Carbon::now()->startOfMonth()->toDateString();
            $finMes = Carbon::now()->endOfMonth()->toDateTimeString();

            // Visitas del mes actual (FILTRADAS por usuario)
            $visitasMes = Visita::where('idusuario', $usuarioId)
                ->whereBetween('created_at', [$inicioMes, $finMes])
                ->count();

            // Envíos de muestras del mes actual (FILTRADAS por usuario)
            $laboratoriosMes = EnvioMuestra::where('idusuario', $usuarioId)
                ->whereBetween('created_at', [$inicioMes, $finMes])
                ->count();

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
                'message' => 'Estadísticas obtenidas correctamente (Pacientes: TODOS | Visitas, Tamizajes, Envíos, Encuestas: filtradas por usuario)'
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
     * Obtener estadísticas por sede específica
     * NOTA: Redirige al método index ya que las estadísticas ahora se basan en el usuario logueado
     */
    public function porSede(Request $request, $sedeId)
    {
        return $this->index($request);
    }
}
