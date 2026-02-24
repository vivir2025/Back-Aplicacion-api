<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;

class TelegramService
{
    protected string $token;
    protected string $chatId;
    protected Client $client;

    public function __construct()
    {
        $this->token  = config('services.telegram.bot_token', '');
        $this->chatId = config('services.telegram.chat_id', '');
        $this->client = new Client(['timeout' => 10]);
    }

    /**
     * Envía un mensaje de texto al canal/grupo de Telegram.
     */
    public function sendMessage(string $message): bool
    {
        if (empty($this->token) || empty($this->chatId)) {
            Log::warning('TelegramService: Token o Chat ID no configurados.');
            return false;
        }

        try {
            $url = "https://api.telegram.org/bot{$this->token}/sendMessage";

            $this->client->post($url, [
                'json' => [
                    'chat_id'    => $this->chatId,
                    'text'       => $message,
                    'parse_mode' => 'Markdown',
                ],
            ]);

            return true;

        } catch (\Exception $e) {
            // El error de Telegram nunca debe romper el flujo de la API
            Log::warning('TelegramService: Error enviando mensaje.', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    // ===============================================================
    //  Métodos de formato por módulo
    // ===============================================================

    public function notificarVisitaCreada(array $data): void
    {
        $mensaje = "🏠 *NUEVA VISITA DOMICILIARIA*\n";
        $mensaje .= "📍 Sede: " . ($data['sede'] ?? 'N/A') . "\n";
        $mensaje .= "👤 Paciente: " . ($data['paciente'] ?? 'N/A') . "\n";
        $mensaje .= "👨‍⚕️ Creado por: " . ($data['usuario'] ?? 'N/A') . "\n";
        $mensaje .= "📅 Fecha: " . ($data['fecha'] ?? now()->format('Y-m-d')) . "\n";
        $mensaje .= "🕐 Hora: " . now()->format('H:i') . "\n";

        $this->sendMessage($mensaje);
    }

    public function notificarEncuestaCreada(array $data): void
    {
        $mensaje = "📋 *NUEVA ENCUESTA REGISTRADA*\n";
        $mensaje .= "📍 Sede: " . ($data['sede'] ?? 'N/A') . "\n";
        $mensaje .= "👤 Paciente: " . ($data['paciente'] ?? 'N/A') . "\n";
        $mensaje .= "👨‍⚕️ Registrado por: " . ($data['usuario'] ?? 'N/A') . "\n";
        $mensaje .= "🕐 Hora: " . now()->format('H:i') . "\n";

        $this->sendMessage($mensaje);
    }

    public function notificarTamizajeCreado(array $data): void
    {
        $mensaje = "🔬 *NUEVO TAMIZAJE CREADO*\n";
        $mensaje .= "📍 Sede: " . ($data['sede'] ?? 'N/A') . "\n";
        $mensaje .= "👤 Paciente: " . ($data['paciente'] ?? 'N/A') . "\n";
        $mensaje .= "👨‍⚕️ Registrado por: " . ($data['usuario'] ?? 'N/A') . "\n";
        $mensaje .= "🕐 Hora: " . now()->format('H:i') . "\n";

        $this->sendMessage($mensaje);
    }

    public function notificarBrigadaCreada(array $data): void
    {
        $mensaje = "👥 *BRIGADA CREADA EXITOSAMENTE*\n";
        $mensaje .= "📍 Lugar: " . ($data['lugar'] ?? 'N/A') . "\n";
        $mensaje .= "📅 Fecha: " . ($data['fecha'] ?? 'N/A') . "\n";
        $mensaje .= "👨‍⚕️ Creado por: " . ($data['usuario'] ?? 'N/A') . "\n";
        $mensaje .= "👤 Total pacientes: " . ($data['total_pacientes'] ?? 0) . "\n";
        $mensaje .= "🕐 Hora: " . now()->format('H:i') . "\n";

        $this->sendMessage($mensaje);
    }

    public function notificarPlanillaLaboratorioCreada(array $data): void
    {
        $estado = ($data['enviado'] ?? false) ? '✅ ENVIADO' : '⏳ PENDIENTE';

        $mensaje = "🧪 *PLANILLA DE LABORATORIO CREADA*\n";
        $mensaje .= "📍 Sede: " . ($data['sede'] ?? 'N/A') . "\n";
        $mensaje .= "📋 Código: " . ($data['codigo'] ?? 'N/A') . "\n";
        $mensaje .= "👨‍⚕️ Responsable: " . ($data['usuario'] ?? 'N/A') . "\n";
        $mensaje .= "📦 Estado envío: " . $estado . "\n";
        $mensaje .= "📅 Fecha: " . ($data['fecha'] ?? now()->format('Y-m-d')) . "\n";
        $mensaje .= "🕐 Hora: " . now()->format('H:i') . "\n";

        $this->sendMessage($mensaje);
    }

    public function notificarLaboratorioEstadoActualizado(array $data): void
    {
        $enviado = $data['enviado_por_correo'] ?? false;
        $icono   = $enviado ? '✅' : '🔄';
        $estado  = $enviado ? 'ENVIADO POR CORREO' : 'MARCADO COMO NO ENVIADO';

        $mensaje = "{$icono} *LABORATORIO - ESTADO ACTUALIZADO*\n";
        $mensaje .= "📍 Sede: " . ($data['sede'] ?? 'N/A') . "\n";
        $mensaje .= "📋 Código: " . ($data['codigo'] ?? 'N/A') . "\n";
        $mensaje .= "📦 Nuevo estado: {$estado}\n";
        $mensaje .= "🕐 Hora: " . now()->format('H:i') . "\n";

        $this->sendMessage($mensaje);
    }

    public function notificarAfinamientoCreado(array $data): void
    {
        $mensaje = "⚙️ *NUEVO AFINAMIENTO REGISTRADO*\n";
        $mensaje .= "📍 Sede: " . ($data['sede'] ?? 'N/A') . "\n";
        $mensaje .= "👤 Paciente: " . ($data['paciente'] ?? 'N/A') . "\n";
        $mensaje .= "👨‍⚕️ Registrado por: " . ($data['usuario'] ?? 'N/A') . "\n";
        $mensaje .= "🕐 Hora: " . now()->format('H:i') . "\n";

        $this->sendMessage($mensaje);
    }

    public function notificarFindRiskCreado(array $data): void
    {
        $mensaje = "❤️ *NUEVO TEST FINDRISK REGISTRADO*\n";
        $mensaje .= "📍 Sede: " . ($data['sede'] ?? 'N/A') . "\n";
        $mensaje .= "👤 Paciente: " . ($data['paciente'] ?? 'N/A') . "\n";
        $mensaje .= "📊 Puntaje: " . ($data['puntaje'] ?? 'N/A') . "\n";
        $mensaje .= "👨‍⚕️ Registrado por: " . ($data['usuario'] ?? 'N/A') . "\n";
        $mensaje .= "🕐 Hora: " . now()->format('H:i') . "\n";

        $this->sendMessage($mensaje);
    }

    public function notificarError(array $data): void
    {
        $mensaje = "❌ *ERROR EN " . strtoupper($data['modulo'] ?? 'MÓDULO') . "*\n";
        $mensaje .= "⚠️ " . ($data['mensaje'] ?? 'Error desconocido') . "\n";
        $mensaje .= "👨‍⚕️ Usuario: " . ($data['usuario'] ?? 'N/A') . "\n";
        $mensaje .= "📍 Sede: " . ($data['sede'] ?? 'N/A') . "\n";
        $mensaje .= "🕐 Hora: " . now()->format('H:i') . "\n";

        $this->sendMessage($mensaje);
    }
}
