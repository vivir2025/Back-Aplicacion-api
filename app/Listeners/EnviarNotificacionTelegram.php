<?php

namespace App\Listeners;

use App\Services\TelegramService;
use App\Events\VisitaCreada;
use App\Events\EncuestaCreada;
use App\Events\TamizajeCreado;
use App\Events\BrigadaCreada;
use App\Events\PlanillaLaboratorioCreada;
use App\Events\LaboratorioEstadoActualizado;
use App\Events\AfinamientoCreado;
use App\Events\FindriskCreado;
use App\Events\ModuloError;
use Illuminate\Support\Facades\Log;

class EnviarNotificacionTelegram
{
    protected TelegramService $telegram;

    public function __construct(TelegramService $telegram)
    {
        $this->telegram = $telegram;
    }

    /**
     * Handle the event.
     * Laravel llama a este método con el evento correcto gracias al tipo del parámetro.
     */
    public function handle($event): void
    {
        try {
            match (true) {
                $event instanceof VisitaCreada               => $this->telegram->notificarVisitaCreada($event->datos),
                $event instanceof EncuestaCreada             => $this->telegram->notificarEncuestaCreada($event->datos),
                $event instanceof TamizajeCreado             => $this->telegram->notificarTamizajeCreado($event->datos),
                $event instanceof BrigadaCreada              => $this->telegram->notificarBrigadaCreada($event->datos),
                $event instanceof PlanillaLaboratorioCreada  => $this->telegram->notificarPlanillaLaboratorioCreada($event->datos),
                $event instanceof LaboratorioEstadoActualizado => $this->telegram->notificarLaboratorioEstadoActualizado($event->datos),
                $event instanceof AfinamientoCreado          => $this->telegram->notificarAfinamientoCreado($event->datos),
                $event instanceof FindriskCreado             => $this->telegram->notificarFindRiskCreado($event->datos),
                $event instanceof ModuloError                => $this->telegram->notificarError($event->datos),
                default => null,
            };
        } catch (\Throwable $e) {
            // NUNCA dejar que el listener rompa la respuesta de la API
            Log::warning('EnviarNotificacionTelegram: Error en listener.', [
                'error' => $e->getMessage(),
                'event' => get_class($event),
            ]);
        }
    }
}
