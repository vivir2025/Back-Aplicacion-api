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
     * Obtener estadísticas generales del usuario logueado.
     * 
     * COMPORTAMIENTO:
     * - Sin filtro de fechas: muestra lo que el usuario hizo en el MES ACTUAL.
     *   Al cambiar de mes se resetea a cero automáticamente.
     * - Con filtro de fechas (fecha_inicio / fecha_fin): muestra el rango específico.
     * - Pacientes: siempre muestra TODOS (sin filtro de usuario ni fecha).
     * - Visitas, Tamizajes, Envíos de muestras, Encuestas: filtrados por usuario logueado.
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
                'fecha_fin'    => 'nullable|date_format:Y-m-d|after_or_equal:fecha_inicio',
            ]);

            $fechaInicioInput = $request->input('fecha_inicio');
            $fechaFinInput    = $request->input('fecha_fin');

            // ============================================
            // DETERMINAR RANGO DE FECHAS
            // ============================================
            // Si NO se envían fechas → usar el mes actual
            // Si se envían → usar el rango proporcionado
            $usandoMesActual = false;

            if ($fechaInicioInput && $fechaFinInput) {
                $fechaInicio = $fechaInicioInput;
                $fechaFin    = $fechaFinInput . ' 23:59:59';
            } else {
                // Por defecto: mes actual
                $usandoMesActual = true;
                $fechaInicio = Carbon::now()->startOfMonth()->toDateString();
                $fechaFin    = Carbon::now()->endOfMonth()->endOfDay()->toDateTimeString();
            }

            $usuarioId = $usuario->id;

            Log::info('Obteniendo estadísticas', [
                'usuario_id'       => $usuarioId,
                'usuario_nombre'   => $usuario->nombre,
                'usuario_rol'      => $usuario->rol,
                'sede_id'          => $usuario->idsede,
                'fecha_inicio'     => $fechaInicio,
                'fecha_fin'        => $fechaFin,
                'usando_mes_actual' => $usandoMesActual,
            ]);

            // ============================================
            // ESTADÍSTICAS
            // ============================================

            // 📊 Total de Pacientes (TODOS, sin filtro de usuario ni fecha)
            $totalPacientes = Paciente::count();

            // 📊 Total de Brigadas
            // La tabla brigadas no tiene campo idusuario, se retorna 0
            $totalBrigadas = 0;

            // 📊 Total de Visitas del usuario en el rango
            $totalVisitas = Visita::where('idusuario', $usuarioId)
                ->whereBetween('created_at', [$fechaInicio, $fechaFin])
                ->count();

            // 📊 Total de Tamizajes del usuario en el rango
            $totalTamizajes = Tamizaje::where('idusuario', $usuarioId)
                ->whereBetween('created_at', [$fechaInicio, $fechaFin])
                ->count();

            // 📊 Total de Envíos de Muestras del usuario en el rango
            $totalLaboratorios = EnvioMuestra::where('idusuario', $usuarioId)
                ->whereBetween('created_at', [$fechaInicio, $fechaFin])
                ->count();

            // 📊 Total de Encuestas del usuario en el rango
            $totalEncuestas = Encuesta::where('idusuario', $usuarioId)
                ->whereBetween('created_at', [$fechaInicio, $fechaFin])
                ->count();

            // ============================================
            // ESTADÍSTICAS DEL MES ACTUAL (siempre se calculan)
            // ============================================
            $inicioMes = Carbon::now()->startOfMonth()->toDateString();
            $finMes    = Carbon::now()->endOfMonth()->endOfDay()->toDateTimeString();

            $visitasMes = Visita::where('idusuario', $usuarioId)
                ->whereBetween('created_at', [$inicioMes, $finMes])
                ->count();

            $laboratoriosMes = EnvioMuestra::where('idusuario', $usuarioId)
                ->whereBetween('created_at', [$inicioMes, $finMes])
                ->count();

            // ============================================
            // RESPUESTA
            // ============================================
            $mesActualNombre = Carbon::now()->translatedFormat('F Y');

            return response()->json([
                'success' => true,
                'data' => [
                    'total_pacientes'      => $totalPacientes,
                    'total_brigadas'       => $totalBrigadas,
                    'total_visitas'        => $totalVisitas,
                    'total_tamizajes'      => $totalTamizajes,
                    'total_envio_muestras' => $totalLaboratorios,
                    'total_laboratorios'   => $totalLaboratorios,
                    'total_encuestas'      => $totalEncuestas,
                    'visitas_mes'          => $visitasMes,
                    'laboratorios_mes'     => $laboratoriosMes,
                    'fecha_consulta'       => now()->toIso8601String(),

                    'filtros_aplicados' => [
                        'usuario_id'        => $usuarioId,
                        'sede_id'           => $usuario->idsede,
                        'sede_nombre'       => $usuario->sede->nombresede ?? 'N/A',
                        'fecha_inicio'      => $fechaInicio,
                        'fecha_fin'         => $fechaFinInput ?? Carbon::now()->endOfMonth()->toDateString(),
                        'usando_mes_actual' => $usandoMesActual,
                        'mes_actual'        => $mesActualNombre,
                    ],
                    'usuario' => [
                        'id'     => $usuario->id,
                        'nombre' => $usuario->nombre,
                        'rol'    => $usuario->rol,
                    ]
                ],
                'message' => $usandoMesActual
                    ? "Estadísticas del mes actual ({$mesActualNombre}) del usuario"
                    : "Estadísticas del {$fechaInicioInput} al {$fechaFinInput} del usuario"
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors'  => $e->errors()
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
     */
    public function porSede(Request $request, $sedeId)
    {
        return $this->index($request);
    }
}
