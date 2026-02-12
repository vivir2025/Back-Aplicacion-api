<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class EnvioMuestra extends Model
{
    use HasFactory;

    protected $table = 'envio_muestras';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'codigo',
        'fecha',
        'version',
        'lugar_toma_muestras',
        'hora_salida',
        'fecha_salida',
        'temperatura_salida',
        'responsable_toma_id',
        'responsable_transporte_id', // Ahora es string
        'fecha_llegada',
        'hora_llegada',
        'temperatura_llegada',
        'lugar_llegada',
        'responsable_recepcion_id', // Ahora es string
        'observaciones',
        'enviado_por_correo',
        'idusuario',
        'idsede'
    ];

    protected $casts = [
        'fecha' => 'date',
        'fecha_salida' => 'date',
        'fecha_llegada' => 'date',
        'enviado_por_correo' => 'boolean',
        'hora_salida' => 'datetime:H:i',
        'hora_llegada' => 'datetime:H:i',
        'temperatura_salida' => 'decimal:2',
        'temperatura_llegada' => 'decimal:2',
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
    public function sede()
    {
        return $this->belongsTo(Sede::class, 'idsede');
    }

    // Solo responsable_toma_id mantiene relación con Usuario
    public function responsableToma()
    {
        return $this->belongsTo(Usuario::class, 'responsable_toma_id');
    }

    // Eliminar estas relaciones ya que ahora son campos de texto
    // public function responsableTransporte() - ELIMINADO
    // public function responsableRecepcion() - ELIMINADO

    public function detalles()
    {
        return $this->hasMany(DetalleEnvioMuestra::class, 'envio_muestra_id')->orderBy('numero_orden');
    }
}