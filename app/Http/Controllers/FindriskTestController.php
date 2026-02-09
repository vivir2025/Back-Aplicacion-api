<?php

namespace App\Http\Controllers;

use App\Models\FindriskTest;
use App\Models\Paciente;
use App\Models\Sede;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FindriskTestController extends Controller
{
    public function index()
    {
        return FindriskTest::with(['paciente', 'sede'])->get();
    }

    public function store(Request $request)
    {
        $request->validate([
            'idpaciente' => 'required|exists:pacientes,id',
            'idsede' => 'required|exists:sedes,id',
            'vereda' => 'nullable|string|max:100',
            'telefono' => 'nullable|string|max:20',
            'actividad_fisica' => 'required|in:si,no',
            'medicamentos_hipertension' => 'required|in:si,no',
            'frecuencia_frutas_verduras' => 'required|in:diariamente,no_diariamente',
            'azucar_alto_detectado' => 'required|in:si,no',
            'peso' => 'required|numeric|min:1|max:300',
            'talla' => 'required|numeric|min:50|max:250',
            'perimetro_abdominal' => 'required|numeric|min:30|max:200',
            'antecedentes_familiares' => 'required|in:no,abuelos_tios_primos,padres_hermanos_hijos',
            'conducta' => 'nullable|string',
            'promotor_vida' => 'nullable|string|max:100'
        ]);

        try {
            DB::beginTransaction();

            $paciente = Paciente::findOrFail($request->idpaciente);
            $sede = Sede::findOrFail($request->idsede);
            
            $findriskTest = new FindriskTest();
            $findriskTest->fill($request->all());

            // Calcular IMC
            $findriskTest->imc = $findriskTest->calcularIMC();

            // Calcular edad
            $edad = $findriskTest->calcularEdad();

            // Calcular todos los puntajes
            $findriskTest->puntaje_edad = $findriskTest->calcularPuntajeEdad($edad);
            $findriskTest->puntaje_imc = $findriskTest->calcularPuntajeIMC($findriskTest->imc, $paciente->genero);
            $findriskTest->puntaje_perimetro = $findriskTest->calcularPuntajePerimetro($findriskTest->perimetro_abdominal, $paciente->genero);
            $findriskTest->puntaje_actividad_fisica = $findriskTest->calcularPuntajeActividad($findriskTest->actividad_fisica);
            $findriskTest->puntaje_frutas_verduras = $findriskTest->calcularPuntajeFrutas($findriskTest->frecuencia_frutas_verduras);
            $findriskTest->puntaje_medicamentos = $findriskTest->calcularPuntajeMedicamentos($findriskTest->medicamentos_hipertension);
            $findriskTest->puntaje_azucar_alto = $findriskTest->calcularPuntajeAzucar($findriskTest->azucar_alto_detectado);
            $findriskTest->puntaje_antecedentes = $findriskTest->calcularPuntajeAntecedentes($findriskTest->antecedentes_familiares);

            // Calcular puntaje final
            $findriskTest->puntaje_final = 
                $findriskTest->puntaje_edad +
                $findriskTest->puntaje_imc +
                $findriskTest->puntaje_perimetro +
                $findriskTest->puntaje_actividad_fisica +
                $findriskTest->puntaje_frutas_verduras +
                $findriskTest->puntaje_medicamentos +
                $findriskTest->puntaje_azucar_alto +
                $findriskTest->puntaje_antecedentes;

            $findriskTest->save();

            DB::commit();

            // Cargar relaciones para la respuesta
            $findriskTest->load(['paciente', 'sede']);

            // Agregar interpretación del riesgo
            $interpretacion = $findriskTest->interpretarRiesgo($findriskTest->puntaje_final);

            return response()->json([
                'test' => $findriskTest,
                'interpretacion' => $interpretacion,
                'edad_calculada' => $edad,
                'paciente' => [
                    'id' => $paciente->id,
                    'identificacion' => $paciente->identificacion,
                    'nombre' => $paciente->nombre,
                    'apellido' => $paciente->apellido,
                    'genero' => $paciente->genero,
                    'fecnacimiento' => $paciente->fecnacimiento
                ],
                'sede' => [
                    'id' => $sede->id,
                    'nombre' => $sede->nombresede
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'message' => 'Error al crear el test FINDRISK',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        $test = FindriskTest::with(['paciente', 'sede'])->findOrFail($id);
        $interpretacion = $test->interpretarRiesgo($test->puntaje_final);
        $edad = $test->calcularEdad();

        return response()->json([
            'test' => $test,
            'interpretacion' => $interpretacion,
            'edad_calculada' => $edad,
            'paciente' => $test->paciente,
            'sede' => $test->sede
        ]);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'idsede' => 'sometimes|required|exists:sedes,id',
            'vereda' => 'nullable|string|max:100',
            'telefono' => 'nullable|string|max:20',
            'actividad_fisica' => 'sometimes|required|in:si,no',
            'medicamentos_hipertension' => 'sometimes|required|in:si,no',
            'frecuencia_frutas_verduras' => 'sometimes|required|in:diariamente,no_diariamente',
            'azucar_alto_detectado' => 'sometimes|required|in:si,no',
            'peso' => 'sometimes|required|numeric|min:1|max:300',
            'talla' => 'sometimes|required|numeric|min:50|max:250',
            'perimetro_abdominal' => 'sometimes|required|numeric|min:30|max:200',
            'antecedentes_familiares' => 'sometimes|required|in:no,abuelos_tios_primos,padres_hermanos_hijos',
            'conducta' => 'nullable|string',
            'promotor_vida' => 'nullable|string|max:100'
        ]);

        try {
            DB::beginTransaction();

            $findriskTest = FindriskTest::findOrFail($id);
            $findriskTest->fill($request->all());

            // Recalcular si se actualizaron peso o talla
            if ($request->has('peso') || $request->has('talla')) {
                $findriskTest->imc = $findriskTest->calcularIMC();
                $findriskTest->puntaje_imc = $findriskTest->calcularPuntajeIMC($findriskTest->imc, $findriskTest->paciente->genero);
            }

            // Recalcular puntajes según los campos actualizados
            if ($request->has('perimetro_abdominal')) {
                $findriskTest->puntaje_perimetro = $findriskTest->calcularPuntajePerimetro($findriskTest->perimetro_abdominal, $findriskTest->paciente->genero);
            }

            if ($request->has('actividad_fisica')) {
                $findriskTest->puntaje_actividad_fisica = $findriskTest->calcularPuntajeActividad($findriskTest->actividad_fisica);
            }

            if ($request->has('frecuencia_frutas_verduras')) {
                $findriskTest->puntaje_frutas_verduras = $findriskTest->calcularPuntajeFrutas($findriskTest->frecuencia_frutas_verduras);
            }

            if ($request->has('medicamentos_hipertension')) {
                $findriskTest->puntaje_medicamentos = $findriskTest->calcularPuntajeMedicamentos($findriskTest->medicamentos_hipertension);
            }

            if ($request->has('azucar_alto_detectado')) {
                $findriskTest->puntaje_azucar_alto = $findriskTest->calcularPuntajeAzucar($findriskTest->azucar_alto_detectado);
            }

            if ($request->has('antecedentes_familiares')) {
                $findriskTest->puntaje_antecedentes = $findriskTest->calcularPuntajeAntecedentes($findriskTest->antecedentes_familiares);
            }

            // Recalcular puntaje final
            $findriskTest->puntaje_final = 
                $findriskTest->puntaje_edad +
                $findriskTest->puntaje_imc +
                $findriskTest->puntaje_perimetro +
                $findriskTest->puntaje_actividad_fisica +
                $findriskTest->puntaje_frutas_verduras +
                $findriskTest->puntaje_medicamentos +
                $findriskTest->puntaje_azucar_alto +
                $findriskTest->puntaje_antecedentes;

            $findriskTest->save();

            DB::commit();

            $findriskTest->load(['paciente', 'sede']);
            $interpretacion = $findriskTest->interpretarRiesgo($findriskTest->puntaje_final);

            return response()->json([
                'test' => $findriskTest,
                'interpretacion' => $interpretacion,
                'paciente' => $findriskTest->paciente,
                'sede' => $findriskTest->sede
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'message' => 'Error al actualizar el test FINDRISK',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        FindriskTest::findOrFail($id)->delete();
        return response()->json(null, 204);
    }

    public function getByPaciente($idpaciente)
    {
        $tests = FindriskTest::where('idpaciente', $idpaciente)
                           ->with(['paciente', 'sede'])
                           ->orderBy('created_at', 'desc')
                           ->get();

        return response()->json($tests);
    }

    public function getBySede($idsede)
    {
        $tests = FindriskTest::where('idsede', $idsede)
                           ->with(['paciente', 'sede'])
                           ->orderBy('created_at', 'desc')
                           ->get();

        return response()->json($tests);
    }

    public function getEstadisticas(Request $request)
    {
        $usuario = $request->user();
        $usuarioId = $usuario->id;
        
        $estadisticas = [
            'total_tests' => FindriskTest::where('idusuario', $usuarioId)->count(),
            'riesgo_bajo' => FindriskTest::where('idusuario', $usuarioId)->where('puntaje_final', '<', 7)->count(),
            'riesgo_ligeramente_elevado' => FindriskTest::where('idusuario', $usuarioId)->whereBetween('puntaje_final', [7, 11])->count(),
            'riesgo_moderado' => FindriskTest::where('idusuario', $usuarioId)->whereBetween('puntaje_final', [12, 14])->count(),
            'riesgo_alto' => FindriskTest::where('idusuario', $usuarioId)->whereBetween('puntaje_final', [15, 20])->count(),
            'riesgo_muy_alto' => FindriskTest::where('idusuario', $usuarioId)->where('puntaje_final', '>', 20)->count(),
            'usuario' => [
                'id' => $usuario->id,
                'nombre' => $usuario->nombre,
            ]
        ];

        return response()->json($estadisticas);
    }

    public function getEstadisticasPorSede(Request $request, $idsede)
    {
        // Filtrar siempre por usuario logueado, no por sede
        $usuario = $request->user();
        $usuarioId = $usuario->id;
        
        $estadisticas = [
            'total_tests' => FindriskTest::where('idusuario', $usuarioId)->count(),
            'riesgo_bajo' => FindriskTest::where('idusuario', $usuarioId)->where('puntaje_final', '<', 7)->count(),
            'riesgo_ligeramente_elevado' => FindriskTest::where('idusuario', $usuarioId)->whereBetween('puntaje_final', [7, 11])->count(),
            'riesgo_moderado' => FindriskTest::where('idusuario', $usuarioId)->whereBetween('puntaje_final', [12, 14])->count(),
            'riesgo_alto' => FindriskTest::where('idusuario', $usuarioId)->whereBetween('puntaje_final', [15, 20])->count(),
            'riesgo_muy_alto' => FindriskTest::where('idusuario', $usuarioId)->where('puntaje_final', '>', 20)->count(),
            'usuario' => [
                'id' => $usuario->id,
                'nombre' => $usuario->nombre,
            ]
        ];

        return response()->json($estadisticas);
    }

    // Método para obtener pacientes con sus datos completos incluyendo sede
    public function getPacienteConSede($identificacion)
    {
        $paciente = Paciente::with('sede')
                          ->where('identificacion', $identificacion)
                          ->first();

        if (!$paciente) {
            return response()->json(['message' => 'Paciente no encontrado'], 404);
        }

        return response()->json([
            'paciente' => $paciente,
            'sede' => $paciente->sede
        ]);
    }
    
    public function getExportData(Request $request)
    {
        $fechaInicio = $request->input('fecha_inicio');
        $fechaFin = $request->input('fecha_fin');
        $sedeId = $request->input('sede_id');
        $nivelRiesgo = $request->input('nivel_riesgo');
    
        $query = FindriskTest::with(['paciente', 'sede'])
            ->whereBetween('created_at', [$fechaInicio . ' 00:00:00', $fechaFin . ' 23:59:59'])
            ->orderBy('created_at', 'desc');
    
        if ($sedeId) {
            $query->where('idsede', $sedeId);
        }
    
        // Filtrar por nivel de riesgo si se especifica
        if ($nivelRiesgo) {
            switch ($nivelRiesgo) {
                case 'bajo':
                    $query->where('puntaje_final', '<', 7);
                    break;
                case 'ligeramente_elevado':
                    $query->whereBetween('puntaje_final', [7, 11]);
                    break;
                case 'moderado':
                    $query->whereBetween('puntaje_final', [12, 14]);
                    break;
                case 'alto':
                    $query->whereBetween('puntaje_final', [15, 20]);
                    break;
                case 'muy_alto':
                    $query->where('puntaje_final', '>', 20);
                    break;
            }
        }
    
        $tests = $query->get();
    
        // Preparar datos para la exportación
        $testsData = [];
        foreach ($tests as $test) {
            $interpretacion = $test->interpretarRiesgo($test->puntaje_final);
            $edad = $test->calcularEdad();
            
            $testsData[] = [
                'id' => $test->id,
                'created_at' => $test->created_at,
                'imc' => $test->imc,
                'perimetro_abdominal' => $test->perimetro_abdominal,
                'actividad_fisica' => $test->actividad_fisica,
                'frecuencia_frutas_verduras' => $test->frecuencia_frutas_verduras,
                'medicamentos_hipertension' => $test->medicamentos_hipertension,
                'azucar_alto_detectado' => $test->azucar_alto_detectado,
                'antecedentes_familiares' => $test->antecedentes_familiares,
                'puntaje_final' => $test->puntaje_final,
                'promotor_vida' => $test->promotor_vida,
                'paciente' => [
                    'id' => $test->paciente->id,
                    'identificacion' => $test->paciente->identificacion,
                    'nombre' => $test->paciente->nombre,
                    'apellido' => $test->paciente->apellido,
                    'genero' => $test->paciente->genero,
                    'fecnacimiento' => $test->paciente->fecnacimiento
                ],
                'sede' => [
                    'id' => $test->sede->id,
                    'nombresede' => $test->sede->nombresede
                ],
                'interpretacion' => $interpretacion,
                'edad_calculada' => $edad
            ];
        }
    
        return response()->json($testsData);
    }

}
