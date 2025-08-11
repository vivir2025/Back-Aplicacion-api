<?php
// app/Http/Controllers/EncuestaController.php

namespace App\Http\Controllers;

use App\Models\Encuesta;
use App\Models\Paciente;
use App\Models\Sede;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

class EncuestaController extends Controller
{
    public function index()
    {
        try {
           
            $encuestas = Encuesta::with(['paciente', 'sede', 'usuario'])->orderBy('fecha', 'desc')->get();
            
            return response()->json([
                'success' => true,
                'data' => $encuestas
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener encuestas: ' . $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|string|unique:encuestas,id',
            'idpaciente' => 'required|exists:pacientes,id',
            'idsede' => 'required|exists:sedes,id',
           
            'domicilio' => 'required|string|max:255',
            'entidad_afiliada' => 'nullable|string|max:100',
            'fecha' => 'required|date',
            'respuestas_calificacion' => 'required|json',
            'respuestas_adicionales' => 'required|json',
            'sugerencias' => 'nullable|string|max:1000'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Datos de validación incorrectos',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
          
            $respuestasCalificacion = json_decode($request->respuestas_calificacion, true);
            $respuestasAdicionales = json_decode($request->respuestas_adicionales, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return response()->json([
                    'success' => false,
                    'message' => 'Formato JSON inválido en las respuestas'
                ], 400);
            }

            $data = $request->all();
            $data['entidad_afiliada'] = $data['entidad_afiliada'] ?? 'ASMET';
            
          
            $data['idusuario'] = Auth::id();

            $encuesta = Encuesta::create($data);

            return response()->json([
                'success' => true,
                'message' => 'Encuesta creada exitosamente',
                'data' => $encuesta->load(['paciente', 'sede', 'usuario'])
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear encuesta: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            
            $encuesta = Encuesta::with(['paciente', 'sede', 'usuario'])->findOrFail($id);
            
            return response()->json([
                'success' => true,
                'data' => $encuesta
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Encuesta no encontrada'
            ], 404);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $encuesta = Encuesta::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'idpaciente' => 'sometimes|exists:pacientes,id',
                'idsede' => 'sometimes|exists:sedes,id',
              
                'domicilio' => 'sometimes|string|max:255',
                'entidad_afiliada' => 'sometimes|string|max:100',
                'fecha' => 'sometimes|date',
                'respuestas_calificacion' => 'sometimes|json',
                'respuestas_adicionales' => 'sometimes|json',
                'sugerencias' => 'sometimes|nullable|string|max:1000'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Datos de validación incorrectos',
                    'errors' => $validator->errors()
                ], 400);
            }

          
            $updateData = $request->except(['idusuario']);
            $encuesta->update($updateData);

            return response()->json([
                'success' => true,
                'message' => 'Encuesta actualizada exitosamente',
                'data' => $encuesta->load(['paciente', 'sede', 'usuario']) 
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar encuesta: ' . $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $encuesta = Encuesta::findOrFail($id);
            $encuesta->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Encuesta eliminada exitosamente'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar encuesta: ' . $e->getMessage()
            ], 500);
        }
    }

    public function encuestasPorPaciente($idpaciente)
    {
        try {
          
            $paciente = Paciente::findOrFail($idpaciente);
            
          
            $encuestas = Encuesta::with(['sede', 'usuario'])
                ->where('idpaciente', $idpaciente)
                ->orderBy('fecha', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $encuestas,
                'paciente' => $paciente
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener encuestas del paciente: ' . $e->getMessage()
            ], 500);
        }
    }

    public function encuestasPorSede($idsede)
    {
        try {
           
            $sede = Sede::findOrFail($idsede);
            
           
            $encuestas = Encuesta::with(['paciente', 'usuario'])
                ->where('idsede', $idsede)
                ->orderBy('fecha', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $encuestas,
                'sede' => $sede
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener encuestas de la sede: ' . $e->getMessage()
            ], 500);
        }
    }

 
    public function misEncuestas()
    {
        try {
            $encuestas = Encuesta::with(['paciente', 'sede'])
                ->where('idusuario', Auth::id())
                ->orderBy('fecha', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $encuestas,
                'usuario' => Auth::user()->load('sede')
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener mis encuestas: ' . $e->getMessage()
            ], 500);
        }
    }

   
    public function encuestasPorUsuario($idusuario)
    {
        try {
            $usuario = Usuario::findOrFail($idusuario);
            
            $encuestas = Encuesta::with(['paciente', 'sede'])
                ->where('idusuario', $idusuario)
                ->orderBy('fecha', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $encuestas,
                'usuario' => $usuario->load('sede')
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener encuestas del usuario: ' . $e->getMessage()
            ], 500);
        }
    }

    public function estadisticas()
    {
        try {
            $totalEncuestas = Encuesta::count();
            
           
            $encuestasPorUsuario = Encuesta::with('usuario')
                ->selectRaw('idusuario, COUNT(*) as total')
                ->groupBy('idusuario')
                ->get();
                
            $encuestasPorSede = Encuesta::with('sede')
                ->selectRaw('idsede, COUNT(*) as total')
                ->groupBy('idsede')
                ->get();
            
            $encuestasPorMes = Encuesta::selectRaw('YEAR(fecha) as año, MONTH(fecha) as mes, COUNT(*) as total')
                ->groupBy('año', 'mes')
                ->orderBy('año', 'desc')
                ->orderBy('mes', 'desc')
                ->limit(12)
                ->get();

            // Estadísticas de satisfacción (basado en respuestas de calificación)
            $encuestasConRespuestas = Encuesta::whereNotNull('respuestas_calificacion')->get();
            $estadisticasSatisfaccion = $this->calcularEstadisticasSatisfaccion($encuestasConRespuestas);

            return response()->json([
                'success' => true,
                'data' => [
                    'total_encuestas' => $totalEncuestas,
                    'encuestas_por_usuario' => $encuestasPorUsuario, 
                    'encuestas_por_sede' => $encuestasPorSede,
                    'encuestas_por_mes' => $encuestasPorMes,
                    'estadisticas_satisfaccion' => $estadisticasSatisfaccion
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estadísticas: ' . $e->getMessage()
            ], 500);
        }
    }

    private function calcularEstadisticasSatisfaccion($encuestas)
    {
        $contadores = [
            'Excelente' => 0,
            'Bueno' => 0,
            'Regular' => 0,
            'Malo' => 0
        ];

        $totalRespuestas = 0;

        foreach ($encuestas as $encuesta) {
            $respuestas = json_decode($encuesta->respuestas_calificacion, true);
            if ($respuestas) {
                foreach ($respuestas as $respuesta) {
                    if (isset($contadores[$respuesta])) {
                        $contadores[$respuesta]++;
                        $totalRespuestas++;
                    }
                }
            }
        }

        // Calcular porcentajes
        $porcentajes = [];
        foreach ($contadores as $nivel => $cantidad) {
            $porcentajes[$nivel] = $totalRespuestas > 0 ? round(($cantidad / $totalRespuestas) * 100, 2) : 0;
        }

        return [
            'contadores' => $contadores,
            'porcentajes' => $porcentajes,
            'total_respuestas' => $totalRespuestas
        ];
    }
}
