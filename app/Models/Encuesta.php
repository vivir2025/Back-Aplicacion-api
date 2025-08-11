<?php
// app/Models/Encuesta.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Encuesta extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'idpaciente',
        'idsede',
        'idusuario',
        'domicilio',
        'entidad_afiliada',
        'fecha',
        'respuestas_calificacion',
        'respuestas_adicionales',
        'sugerencias'
    ];

    protected $casts = [
        'fecha' => 'date',
        'respuestas_calificacion' => 'array',
        'respuestas_adicionales' => 'array'
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
    
    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'idusuario');
    }
}