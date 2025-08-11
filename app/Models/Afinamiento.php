<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Carbon\Carbon;

class Afinamiento extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'idpaciente',
        'idusuario',
        'procedencia',
        'fecha_tamizaje',
        'presion_arterial_tamiz',
        'primer_afinamiento_fecha',
        'presion_sistolica_1',
        'presion_diastolica_1',
        'segundo_afinamiento_fecha',
        'presion_sistolica_2',
        'presion_diastolica_2',
        'tercer_afinamiento_fecha',
        'presion_sistolica_3',
        'presion_diastolica_3',
        'presion_sistolica_promedio',
        'presion_diastolica_promedio',
        'conducta'
    ];

    protected $casts = [
        'fecha_tamizaje' => 'date',
        'primer_afinamiento_fecha' => 'date',
        'segundo_afinamiento_fecha' => 'date',
        'tercer_afinamiento_fecha' => 'date',
        'presion_sistolica_promedio' => 'decimal:2',
        'presion_diastolica_promedio' => 'decimal:2'
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });

        // Calcular promedios automáticamente antes de guardar
        static::saving(function ($model) {
            $model->calcularPromedios();
        });
    }

    public function paciente()
    {
        return $this->belongsTo(Paciente::class, 'idpaciente');
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'idusuario');
    }

    // Método para calcular promedios automáticamente
    public function calcularPromedios()
    {
        $sistolicas = array_filter([
            $this->presion_sistolica_1,
            $this->presion_sistolica_2,
            $this->presion_sistolica_3
        ]);

        $diastolicas = array_filter([
            $this->presion_diastolica_1,
            $this->presion_diastolica_2,
            $this->presion_diastolica_3
        ]);

        if (count($sistolicas) > 0) {
            $this->presion_sistolica_promedio = round(array_sum($sistolicas) / count($sistolicas), 2);
        }

        if (count($diastolicas) > 0) {
            $this->presion_diastolica_promedio = round(array_sum($diastolicas) / count($diastolicas), 2);
        }
    }

    // Accessor para obtener la edad del paciente
    public function getEdadPacienteAttribute()
    {
        if ($this->paciente && $this->paciente->fecnacimiento) {
            return Carbon::parse($this->paciente->fecnacimiento)->age;
        }
        return null;
    }
}
