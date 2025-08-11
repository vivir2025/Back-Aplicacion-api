<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Brigada extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'lugar_evento',
        'fecha_brigada',
        'nombre_conductor',
        'usuarios_hta',
        'usuarios_dn',
        'usuarios_hta_rcu',
        'usuarios_dm_rcu',
        'observaciones',
        'tema'
    ];

    protected $casts = [
        'fecha_brigada' => 'date',
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

    // 👥 RELACIÓN: Pacientes asignados a la brigada
    public function pacientes()
    {
        return $this->belongsToMany(Paciente::class, 'brigada_paciente');
    }

    // 💊 RELACIÓN: Medicamentos específicos de la brigada
    public function medicamentosPacientes()
    {
        return $this->hasMany(BrigadaPacienteMedicamento::class);
    }

    // 🔗 ALIAS para el controlador (ESTO FALTABA)
    public function medicamentoPacientes()
    {
        return $this->hasMany(BrigadaPacienteMedicamento::class);
    }

    // 🆕 MÉTODO HELPER: Obtener medicamentos agrupados por paciente
    public function getMedicamentosPorPaciente()
    {
        return $this->medicamentosPacientes()
                    ->with(['paciente', 'medicamento'])
                    ->get()
                    ->groupBy('paciente_id');
    }

    // 🆕 MÉTODO HELPER: Obtener resumen de medicamentos
    public function getResumenMedicamentos()
    {
        return $this->medicamentosPacientes()
                    ->with(['medicamento'])
                    ->selectRaw('medicamento_id, SUM(cantidad) as total_cantidad')
                    ->groupBy('medicamento_id')
                    ->get();
    }
}