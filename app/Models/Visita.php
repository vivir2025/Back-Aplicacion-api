<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class Visita extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'nombre_apellido',
        'identificacion',
        'hta',
        'dm',
        'fecha',
        'telefono',
        'zona',
        'peso',
        'talla',
        'imc',
        'perimetro_abdominal',
        'frecuencia_cardiaca',
        'frecuencia_respiratoria',
        'tension_arterial',
        'glucometria',
        'temperatura',
        'familiar',
        'riesgo_fotografico',         // Ruta del archivo de foto
        'riesgo_fotografico_url',     // URL completa de la foto
        'riesgo_fotografico_base64',  // Datos de la foto en Base64
        'abandono_social',
        'motivo',
        'medicamentos',
        'factores',
        'conductas',
        'novedades',
        'proximo_control',
        'firma',                      // Ruta del archivo de firma
        'firma_url',                  // URL completa de la firma
        'firma_base64',               // Datos de la firma en Base64
        'firma_path',                 // Ruta alternativa para firma
        'fotos_paths',                // Array de rutas de fotos (JSON)
        'fotos_base64',               // Array de fotos en Base64 (JSON)
        'archivos_adjuntos',          // Array de archivos adjuntos (JSON)
        'opciones_multiples',         // Opciones múltiples (JSON)
        'idusuario',
        'idpaciente',
        'latitud',
        'longitud',
        'sync_status',
        'estado',
        'observaciones_adicionales',
        'tipo_visita'
    ];

    protected $casts = [
        'fecha' => 'date',
        'proximo_control' => 'date',
        'fotos_paths' => 'array',
        'fotos_base64' => 'array',
        'archivos_adjuntos' => 'array',
        'opciones_multiples' => 'array',
        'sync_status' => 'integer'
    ];

    // Accessor para riesgo_fotografico_url
    public function getRiesgoFotograficoUrlAttribute($value)
    {
        if ($value) return $value;
        
        if ($this->riesgo_fotografico && Storage::disk('public')->exists($this->riesgo_fotografico)) {
            return url('storage/' . $this->riesgo_fotografico);
        }
        
        return null;
    }

    // Accessor para firma_url
    public function getFirmaUrlAttribute($value)
    {
        if ($value) return $value;
        
        if ($this->firma && Storage::disk('public')->exists($this->firma)) {
            return url('storage/' . $this->firma);
        }
        
        return null;
    }

    // Accessor para firma_path (compatibilidad)
    public function getFirmaPathAttribute($value)
    {
        if ($value) return $value;
        return $this->firma;
    }

    // Mutador para medicamentos (convertir a JSON)
    public function setMedicamentosAttribute($value)
    {
        $this->attributes['medicamentos'] = is_array($value) ? json_encode($value) : $value;
    }

    // Accessor para medicamentos (convertir a array)
    public function getMedicamentosAttribute($value)
    {
        return json_decode($value, true) ?? [];
    }

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
            
            // Establecer estado por defecto si no viene
            if (!isset($model->estado)) {
                $model->estado = 'pendiente';
            }
            
            // Establecer sync_status por defecto
            if (!isset($model->sync_status)) {
                $model->sync_status = 0; // 0 = no sincronizado
            }
        });
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'idusuario');
    }

    public function paciente()
    {
        return $this->belongsTo(Paciente::class, 'idpaciente');
    }

    public function medicamentos()
    {
        return $this->belongsToMany(Medicamento::class, 'medicamento_visita')
                    ->withPivot('indicaciones')
                    ->withTimestamps();
    }

    // Método para convertir a array para API
    public function toServerJson()
    {
        $data = $this->toArray();
        
        // Campos que deben ser strings JSON
        $jsonFields = ['medicamentos', 'fotos_paths', 'fotos_base64', 'archivos_adjuntos', 'opciones_multiples'];
        foreach ($jsonFields as $field) {
            if (isset($data[$field]) && is_array($data[$field])) {
                $data[$field] = json_encode($data[$field]);
            }
        }
        
        // Limpiar campos internos que no deben enviarse
        unset($data['created_at'], $data['updated_at'], $data['deleted_at']);
        
        return $data;
    }
}