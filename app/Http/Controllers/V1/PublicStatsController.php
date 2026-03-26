<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\Visita;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * @group Estadísticas Públicas (Landing Page)
 * 
 * Endpoints sin autenticación para mostrar el impacto en la landing page.
 */
class PublicStatsController extends Controller
{
    /**
     * Mapa de Calor (v1)
     * 
     * Retorna los puntos geográficos agrupados por zona de visita y sede para el mapa de calor de la landing.
     * Los datos están cacheados por 1 hora con Redis.
     * 
     * @unauthenticated
     */
    public function getMapaCalor()
    {
        try {
            // Cache por 60 minutos con Redis
            $data = Cache::remember('v1_mapa_calor_publico', 3600, function () {
                return Visita::join('pacientes', 'visitas.idpaciente', '=', 'pacientes.id')
                    ->join('sedes', 'pacientes.idsede', '=', 'sedes.id')
                    ->select(
                        'visitas.zona',
                        'sedes.nombresede as sede_principal',
                        DB::raw('ROUND(AVG(pacientes.latitud), 4) as lat'),
                        DB::raw('ROUND(AVG(pacientes.longitud), 4) as lng'),
                        DB::raw('COUNT(visitas.id) as impacto_visitas')
                    )
                    ->whereNotNull('pacientes.latitud')
                    ->whereNotNull('pacientes.longitud')
                    ->where('pacientes.latitud', '!=', 0)
                    ->where('pacientes.longitud', '!=', 0)
                    ->whereNotNull('visitas.zona')
                    ->groupBy('visitas.zona', 'sedes.nombresede')
                    ->get();
            });

            return response()->json([
                'success' => true,
                'data' => $data,
                'version' => 'v1',
                'cache' => true
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error en mapa de calor pública (v1): ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'No se pudo obtener la información geográfica.',
            ], 500);
        }
    }
}
