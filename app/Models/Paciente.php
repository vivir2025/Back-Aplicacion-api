<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Paciente extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'identificacion',
        'fecnacimiento',
        'nombre',
        'apellido',
        'genero',
        'longitud',
        'latitud',
        'idsede'
    ];
    
    
      protected $casts = [
        'fecnacimiento' => 'date',
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

    public function sede()
    {
        return $this->belongsTo(Sede::class, 'idsede');
    }

    public function visitas()
    {
        return $this->hasMany(Visita::class, 'idpaciente');
    }
    
    public function brigadas()
    {
        return $this->belongsToMany(Brigada::class, 'brigada_paciente');
    }
    public function afinamientos()
    {
        return $this->hasMany(Afinamiento::class, 'idpaciente');
    }
    public function tamizajes()
    {
        return $this->hasMany(Tamizaje::class, 'idpaciente');
    }


    public function getEdadAttribute()
    {
        return Carbon::parse($this->fecnacimiento)->age;
    }
    
    public function medicamentos()
    {
        return $this->belongsToMany(Medicamento::class, 'medicamento_paciente')
                    ->using(MedicamentoPaciente::class)
                    ->withPivot('dosis', 'cantidad')
                    ->withTimestamps();
    }
}