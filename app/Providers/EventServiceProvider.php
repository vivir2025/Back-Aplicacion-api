<?php

namespace App\Providers;

use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

// Eventos de la aplicación
use App\Events\VisitaCreada;
use App\Events\EncuestaCreada;
use App\Events\TamizajeCreado;
use App\Events\BrigadaCreada;
use App\Events\PlanillaLaboratorioCreada;
use App\Events\LaboratorioEstadoActualizado;
use App\Events\AfinamientoCreado;
use App\Events\FindriskCreado;
use App\Events\ModuloError;

// Listener de Telegram
use App\Listeners\EnviarNotificacionTelegram;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        // Auth
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],

        // =====================================================
        //  Notificaciones a Telegram por módulo
        // =====================================================
        VisitaCreada::class => [
            EnviarNotificacionTelegram::class,
        ],
        EncuestaCreada::class => [
            EnviarNotificacionTelegram::class,
        ],
        TamizajeCreado::class => [
            EnviarNotificacionTelegram::class,
        ],
        BrigadaCreada::class => [
            EnviarNotificacionTelegram::class,
        ],
        PlanillaLaboratorioCreada::class => [
            EnviarNotificacionTelegram::class,
        ],
        LaboratorioEstadoActualizado::class => [
            EnviarNotificacionTelegram::class,
        ],
        AfinamientoCreado::class => [
            EnviarNotificacionTelegram::class,
        ],
        FindriskCreado::class => [
            EnviarNotificacionTelegram::class,
        ],
        ModuloError::class => [
            EnviarNotificacionTelegram::class,
        ],
    ];

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     *
     * @return bool
     */
    public function shouldDiscoverEvents()
    {
        return false;
    }
}
