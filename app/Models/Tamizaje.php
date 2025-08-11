<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Tamizaje extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'idpaciente',
        'idusuario',
        'vereda_residencia',
        'telefono',
        'brazo_toma',
        'posicion_persona',
        'reposo_cinco_minutos',
        'fecha_primera_toma',
        'pa_sistolica',
        'pa_diastolica',
        'conducta'
    ];

    protected $casts = [
        'fecha_primera_toma' => 'date',
        'pa_sistolica' => 'integer',
        'pa_diastolica' => 'integer',
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

    // Relaciones
    public function paciente()
    {
        return $this->belongsTo(Paciente::class, 'idpaciente');
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'idusuario');
    }

    // Accessor para mostrar la presión arterial completa
    public function getPresionArterialAttribute()
    {
        return $this->pa_sistolica . '/' . $this->pa_diastolica;
    }

    // Accessor para calcular la edad del paciente
    public function getEdadPacienteAttribute()
    {
        return $this->paciente ? $this->paciente->fecnacimiento->age : null;
    }
}
