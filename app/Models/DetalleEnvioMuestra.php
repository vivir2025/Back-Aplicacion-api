<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DetalleEnvioMuestra extends Model
{
    use HasFactory;

    protected $table = 'detalle_envio_muestras';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'envio_muestra_id',
        'paciente_id',
        'numero_orden',
        'dm',
        'hta',
        'num_muestras_enviadas',
        'orina_esp',
        'orina_24h',
        'tubo_lila',
        'tubo_amarillo',
        'tubo_amarillo_forrado',
        'a',
        'm',
        'oe',
        'o24h',
        'po',
        'h3',
        'hba1c',
        'pth',
        'glu',
        'crea',
        'pl',
        'au',
        'bun',
        'relacion_crea_alb',
        'dcre24h',
        'alb24h',
        'buno24h',
        'fer',
        'tra',
        'fosfat',
        'alb',
        'fe',
        'tsh',
        'p',
        'ionograma',
        'b12',
        'acido_folico',
        'peso',
        'talla',
        'volumen',
        'microo',
        'creaori'
    ];

    protected $casts = [
        'dm' => 'string',
        'hta' => 'string',
        'orina_esp' => 'string',
        'orina_24h' => 'string',
        'tubo_lila' => 'string',
        'tubo_amarillo' => 'string',
        'tubo_amarillo_forrado' => 'string',
        'a' => 'string',
        'm' => 'string',
        'oe' => 'string',
        'po' => 'string',
        'h3' => 'string',
        'hba1c' => 'string',
        'pth' => 'string',
        'glu' => 'string',
        'crea' => 'string',
        'pl' => 'string',
        'au' => 'string',
        'bun' => 'string',
        'relacion_crea_alb' => 'string',
        'dcre24h' => 'string',
        'alb24h' => 'string',
        'buno24h' => 'string',
        'fer' => 'string',
        'tra' => 'string',
        'fosfat' => 'string',
        'alb' => 'string',
        'fe' => 'string',
        'tsh' => 'string',
        'p' => 'string',
        'ionograma' => 'string',
        'b12' => 'string',
        'acido_folico' => 'string',
        'peso' => 'decimal:2',
        'talla' => 'decimal:2',
        'volumen' => 'string',
        'microo' => 'string',
        'creaori' => 'string',
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
    public function envioMuestra()
    {
        return $this->belongsTo(EnvioMuestra::class, 'envio_muestra_id');
    }

    public function paciente()
    {
        return $this->belongsTo(Paciente::class, 'paciente_id');
    }
}
