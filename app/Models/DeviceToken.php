<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeviceToken extends Model
{
    use HasFactory;

    // ═══════════════════════════════════════════════════════════════
    // CONFIGURACIÓN BÁSICA
    // ═══════════════════════════════════════════════════════════════
    
    protected $table = 'device_tokens';
    
    protected $fillable = [
        'user_id',
        'fcm_token',
        'platform',
        'device_name',
        'is_active',
        'last_used_at',
    ];
    
    // ═══════════════════════════════════════════════════════════════
    // CASTING DE ATRIBUTOS
    // ═══════════════════════════════════════════════════════════════
    
    protected $casts = [
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    
    // ═══════════════════════════════════════════════════════════════
    // RELACIONES
    // ═══════════════════════════════════════════════════════════════
    
    /**
     * Relación con Usuario
     */
    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'user_id', 'id');
    }
    
    // ═══════════════════════════════════════════════════════════════
    // SCOPES
    // ═══════════════════════════════════════════════════════════════
    
    /**
     * Scope para tokens activos
     */
    public function scopeActivos($query)
    {
        return $query->where('is_active', true);
    }
    
    /**
     * Scope para filtrar por usuario
     */
    public function scopePorUsuario($query, $userId)
    {
        return $query->where('user_id', $userId);
    }
    
    /**
     * Scope para filtrar por plataforma
     */
    public function scopePorPlataforma($query, $platform)
    {
        return $query->where('platform', $platform);
    }
    
    // ═══════════════════════════════════════════════════════════════
    // MÉTODOS AUXILIARES
    // ═══════════════════════════════════════════════════════════════
    
    /**
     * Marcar token como usado
     */
    public function marcarComoUsado()
    {
        $this->update([
            'last_used_at' => now()
        ]);
    }
    
    /**
     * Desactivar token
     */
    public function desactivar()
    {
        $this->update([
            'is_active' => false
        ]);
    }
}
