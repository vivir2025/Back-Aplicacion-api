<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class BrigadaPacienteMedicamento extends Model
{
    use HasFactory;

    protected $table = 'brigada_paciente_medicamentos';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'brigada_id',
        'paciente_id', 
        'medicamento_id',
        'dosis',
        'cantidad',
        'indicaciones'
    ];

    protected $casts = [
        'cantidad' => 'integer',
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

    // 🏥 RELACIONES
    public function brigada()
    {
        return $this->belongsTo(Brigada::class, 'brigada_id', 'id');
    }

    public function paciente()
    {
        return $this->belongsTo(Paciente::class, 'paciente_id', 'id');
    }

    public function medicamento()
    {
        return $this->belongsTo(Medicamento::class, 'medicamento_id', 'id');
    }
}