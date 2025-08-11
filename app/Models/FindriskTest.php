<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Carbon\Carbon;

class FindriskTest extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'idpaciente',
        'idsede',
        'vereda',
        'telefono',
        'actividad_fisica',
        'puntaje_actividad_fisica',
        'medicamentos_hipertension',
        'puntaje_medicamentos',
        'frecuencia_frutas_verduras',
        'puntaje_frutas_verduras',
        'azucar_alto_detectado',
        'puntaje_azucar_alto',
        'peso',
        'talla',
        'imc',
        'puntaje_imc',
        'perimetro_abdominal',
        'puntaje_perimetro',
        'antecedentes_familiares',
        'puntaje_antecedentes',
        'puntaje_edad',
        'puntaje_final',
        'conducta',
        'promotor_vida'
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function paciente()
    {
        return $this->belongsTo(Paciente::class, 'idpaciente');
    }

    public function sede()
    {
        return $this->belongsTo(Sede::class, 'idsede');
    }

    // Método para calcular IMC
    public function calcularIMC()
    {
        if ($this->peso && $this->talla) {
            $tallaMetros = $this->talla / 100;
            return round($this->peso / ($tallaMetros * $tallaMetros), 2);
        }
        return 0;
    }

    // Método para calcular edad basada en fecha de nacimiento del paciente
    public function calcularEdad()
    {
        if ($this->paciente && $this->paciente->fecnacimiento) {
            return Carbon::parse($this->paciente->fecnacimiento)->age;
        }
        return 0;
    }

    // Método para calcular puntaje de edad
    public function calcularPuntajeEdad($edad)
    {
        if ($edad < 45) return 0;
        if ($edad >= 45 && $edad <= 54) return 2;
        if ($edad >= 55 && $edad <= 64) return 3;
        return 4; // >= 65
    }

    // Método para calcular puntaje de IMC
    public function calcularPuntajeIMC($imc, $sexo)
    {
        if ($imc < 25) return 0;
        if ($imc >= 25 && $imc < 30) return 1;
        return 3; // >= 30
    }

    // Método para calcular puntaje de perímetro abdominal
    public function calcularPuntajePerimetro($perimetro, $sexo)
    {
        if ($sexo === 'masculino') {
            if ($perimetro < 94) return 0;
            if ($perimetro >= 94 && $perimetro <= 102) return 3;
            return 4; // > 102
        } else { // femenino
            if ($perimetro < 80) return 0;
            if ($perimetro >= 80 && $perimetro <= 88) return 3;
            return 4; // > 88
        }
    }

    // Método para calcular puntaje de actividad física
    public function calcularPuntajeActividad($actividad)
    {
        return $actividad === 'no' ? 2 : 0;
    }

    // Método para calcular puntaje de frutas y verduras
    public function calcularPuntajeFrutas($frecuencia)
    {
        return $frecuencia === 'no_diariamente' ? 1 : 0;
    }

    // Método para calcular puntaje de medicamentos
    public function calcularPuntajeMedicamentos($medicamentos)
    {
        return $medicamentos === 'si' ? 2 : 0;
    }

    // Método para calcular puntaje de azúcar alto
    public function calcularPuntajeAzucar($azucar)
    {
        return $azucar === 'si' ? 5 : 0;
    }

    // Método para calcular puntaje de antecedentes familiares
    public function calcularPuntajeAntecedentes($antecedentes)
    {
        switch ($antecedentes) {
            case 'no':
                return 0;
            case 'abuelos_tios_primos':
                return 3;
            case 'padres_hermanos_hijos':
                return 5;
            default:
                return 0;
        }
    }

    // Método para interpretar el riesgo según el puntaje final
    public function interpretarRiesgo($puntaje)
    {
        if ($puntaje < 7) {
            return [
                'nivel' => 'Bajo',
                'riesgo' => '1%',
                'descripcion' => 'Riesgo bajo de desarrollar diabetes tipo 2 en los próximos 10 años'
            ];
        } elseif ($puntaje >= 7 && $puntaje <= 11) {
            return [
                'nivel' => 'Ligeramente elevado',
                'riesgo' => '4%',
                'descripcion' => 'Riesgo ligeramente elevado de desarrollar diabetes tipo 2 en los próximos 10 años'
            ];
        } elseif ($puntaje >= 12 && $puntaje <= 14) {
            return [
                'nivel' => 'Moderado',
                'riesgo' => '17%',
                'descripcion' => 'Riesgo moderado de desarrollar diabetes tipo 2 en los próximos 10 años'
            ];
        } elseif ($puntaje >= 15 && $puntaje <= 20) {
            return [
                'nivel' => 'Alto',
                'riesgo' => '33%',
                'descripcion' => 'Riesgo alto de desarrollar diabetes tipo 2 en los próximos 10 años'
            ];
        } else {
            return [
                'nivel' => 'Muy alto',
                'riesgo' => '50%',
                'descripcion' => 'Riesgo muy alto de desarrollar diabetes tipo 2 en los próximos 10 años'
            ];
        }
    }
}
