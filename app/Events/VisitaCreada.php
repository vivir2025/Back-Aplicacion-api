<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VisitaCreada
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public array $datos;

    public function __construct(array $datos)
    {
        $this->datos = $datos;
    }
}
