<?php

namespace App\Services;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Illuminate\Support\Facades\Log;
use App\Models\DeviceToken;

class FirebaseNotificationService
{
    private $messaging;
    
    public function __construct()
    {
        // ✅ Ruta al archivo JSON de Firebase
        $credentialsPath = storage_path('app/firebase/bornive-26b48-fac9f5526730.json');
        
        // Verificar que el archivo existe
        if (!file_exists($credentialsPath)) {
            throw new \Exception("Archivo de credenciales de Firebase no encontrado en: {$credentialsPath}");
        }
        
        $factory = (new Factory)->withServiceAccount($credentialsPath);
        $this->messaging = $factory->createMessaging();
    }
    
    /**
     * Enviar notificación a múltiples tokens
     */
    public function sendToTokens(array $tokens, string $title, string $body, array $data = [])
    {
        try {
            if (empty($tokens)) {
                Log::warning('⚠️ No hay tokens para enviar notificación');
                return [
                    'success' => 0,
                    'failure' => 0
                ];
            }
            
            Log::info('📤 Preparando notificación FCM', [
                'tokens_count' => count($tokens),
                'title' => $title
            ]);
            
            // Crear notificación
            $notification = Notification::create($title, $body);
            
            // Crear mensaje
            $message = CloudMessage::new()
                ->withNotification($notification)
                ->withData($data);
            
            // Enviar a múltiples dispositivos
            $report = $this->messaging->sendMulticast($message, $tokens);
            
            Log::info('✅ Notificaciones enviadas', [
                'total' => count($tokens),
                'success' => $report->successes()->count(),
                'failure' => $report->failures()->count()
            ]);
            
            // Procesar tokens inválidos
            if ($report->hasFailures()) {
                foreach ($report->failures()->getItems() as $failure) {
                    $invalidToken = $failure->target()->value();
                    
                    Log::warning('🗑️ Token inválido detectado', [
                        'token' => substr($invalidToken, 0, 20) . '...',
                        'error' => $failure->error()->getMessage()
                    ]);
                    
                    // Desactivar token en la base de datos
                    DeviceToken::where('fcm_token', $invalidToken)
                        ->update(['is_active' => false]);
                }
            }
            
            return [
                'success' => $report->successes()->count(),
                'failure' => $report->failures()->count()
            ];
            
        } catch (\Exception $e) {
            Log::error('❌ Error enviando notificaciones FCM', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Enviar notificación a un solo token
     */
    public function sendToToken(string $token, string $title, string $body, array $data = [])
    {
        return $this->sendToTokens([$token], $title, $body, $data);
    }
    
    /**
     * Enviar notificación a un usuario (por ID)
     */
    public function sendToUser(string $userId, string $title, string $body, array $data = [])
    {
        // Obtener tokens activos del usuario
        $tokens = DeviceToken::where('user_id', $userId)
            ->where('is_active', true)
            ->pluck('fcm_token')
            ->toArray();
        
        if (empty($tokens)) {
            Log::warning('⚠️ Usuario sin tokens activos', [
                'user_id' => $userId
            ]);
            
            return [
                'success' => 0,
                'failure' => 0
            ];
        }
        
        return $this->sendToTokens($tokens, $title, $body, $data);
    }
    
    /**
     * Enviar notificación a múltiples usuarios
     */
    public function sendToUsers(array $userIds, string $title, string $body, array $data = [])
    {
        // Obtener todos los tokens de los usuarios
        $tokens = DeviceToken::whereIn('user_id', $userIds)
            ->where('is_active', true)
            ->pluck('fcm_token')
            ->toArray();
        
        if (empty($tokens)) {
            Log::warning('⚠️ Usuarios sin tokens activos', [
                'user_ids' => $userIds
            ]);
            
            return [
                'success' => 0,
                'failure' => 0
            ];
        }
        
        return $this->sendToTokens($tokens, $title, $body, $data);
    }
    
    /**
     * Enviar notificación a TODOS los dispositivos activos (broadcast)
     */
    public function sendToAll(string $title, string $body, array $data = [])
    {
        // Obtener TODOS los tokens activos
        $tokens = DeviceToken::where('is_active', true)
            ->pluck('fcm_token')
            ->toArray();
        
        if (empty($tokens)) {
            Log::warning('⚠️ No hay dispositivos activos para enviar notificación masiva');
            return [
                'success' => 0,
                'failure' => 0,
                'total_devices' => 0
            ];
        }
        
        Log::info('📢 Enviando notificación masiva (broadcast)', [
            'total_devices' => count($tokens),
            'title' => $title
        ]);
        
        // FCM sendMulticast tiene límite de 500 tokens por llamada
        $chunks = array_chunk($tokens, 500);
        $totalSuccess = 0;
        $totalFailure = 0;
        
        foreach ($chunks as $index => $chunk) {
            Log::info("📦 Enviando batch " . ($index + 1) . "/" . count($chunks), [
                'tokens_in_batch' => count($chunk)
            ]);
            
            $result = $this->sendToTokens($chunk, $title, $body, $data);
            $totalSuccess += $result['success'];
            $totalFailure += $result['failure'];
        }
        
        return [
            'success' => $totalSuccess,
            'failure' => $totalFailure,
            'total_devices' => count($tokens),
            'batches_sent' => count($chunks)
        ];
    }
}
