<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento genérico de error para cualquier módulo.
 * Uso: event(new ModuloError('Visitas', 'Mensaje de error', $usuario, $sede));
 */
class ModuloError
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public array $datos;

    public function __construct(array $datos)
    {
        $this->datos = $datos;
    }
}
