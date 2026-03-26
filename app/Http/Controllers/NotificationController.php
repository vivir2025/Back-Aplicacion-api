<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\Usuario;
use App\Models\DeviceToken;
use App\Services\FirebaseNotificationService;

/**
 * @group Notificaciones Push (FCM)
 *
 * Gestión de tokens de dispositivo y envío de notificaciones push a través de Firebase Cloud Messaging (FCM).
 */
class NotificationController extends Controller
{
    /**
     * Registrar dispositivo
     * 
     * Registra el token FCM del dispositivo para recibir notificaciones push.
     * 
     * @authenticated
     * @bodyParam user_id string required ID del usuario.
     * @bodyParam fcm_token string required Token generado por Firebase en el móvil.
     * @bodyParam platform string required android, ios, web. Example: android
     */
    public function registerDevice(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|string|exists:usuarios,id', // ← Tu tabla
            'fcm_token' => 'required|string',
            'platform' => 'required|in:android,ios,web',
            'device_name' => 'nullable|string'
        ]);
        
        try {
            // Buscar o crear el token
            $deviceToken = DeviceToken::updateOrCreate(
                ['fcm_token' => $validated['fcm_token']], // Buscar por token
                [
                    'user_id' => $validated['user_id'],
                    'platform' => $validated['platform'],
                    'device_name' => $validated['device_name'] ?? null,
                    'is_active' => true,
                    'last_used_at' => now()
                ]
            );
            
            Log::info('[POST] Notificaciones → Token registrado', [
                'user_id'   => $validated['user_id'],
                'platform'  => $validated['platform'],
                'token_id'  => $deviceToken->id
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Token registrado correctamente',
                'token_id' => $deviceToken->id
            ]);
            
        } catch (\Exception $e) {
            Log::error('[POST] Notificaciones → Error registrando token', [
                'user_id' => $validated['user_id'],
                'mensaje' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error al registrar token'
            ], 500);
        }
    }
    
    // ═══════════════════════════════════════════════════════════════
    // ENVIAR NOTIFICACIÓN A UN USUARIO
    // ═══════════════════════════════════════════════════════════════
    
    public function sendToUser(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|string|exists:usuarios,id',
            'title' => 'required|string|max:255',
            'body' => 'required|string',
            'data' => 'nullable|array'
        ]);
        
        try {
            // Obtener usuario
            $usuario = Usuario::find($validated['user_id']);
            
            if (!$usuario) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no encontrado'
                ], 404);
            }
            
            // Obtener tokens activos directamente desde DeviceToken
            $tokens = DeviceToken::where('user_id', $validated['user_id'])
                ->where('is_active', true)
                ->pluck('fcm_token')
                ->toArray();
            
            if (empty($tokens)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no tiene dispositivos registrados',
                    'user_id' => $validated['user_id'],
                    'usuario' => $usuario->nombre
                ], 404);
            }
            
            Log::info('[POST] Notificaciones → Enviando a usuario', [
                'user_id' => $validated['user_id'],
                'devices' => count($tokens),
            ]);
            
            // Enviar usando Firebase
            $result = $this->firebaseService->sendToTokens(
                $tokens,
                $validated['title'],
                $validated['body'],
                $validated['data'] ?? []
            );
            
            return response()->json([
                'success' => true,
                'message' => 'Notificación enviada',
                'usuario' => $usuario->nombre,
                'devices_sent' => count($tokens),
                'success_count' => $result['success'],
                'failure_count' => $result['failure']
            ]);
            
        } catch (\Exception $e) {
            Log::error('[POST] Notificaciones → Error enviando a usuario', [
                'user_id' => $validated['user_id'],
                'mensaje' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error al enviar notificación'
            ], 500);
        }
    }
    
    // ═══════════════════════════════════════════════════════════════
    // ENVIAR NOTIFICACIÓN A TODOS LOS USUARIOS (Broadcast)
    // ═══════════════════════════════════════════════════════════════
    
    /**
     * Notificación masiva (Broadcast)
     * 
     * Envía una notificación push a todos los dispositivos registrados en el sistema.
     * 
     * @authenticated
     * @bodyParam title string required Título de la notificación.
     * @bodyParam body string required Contenido del mensaje.
     * @bodyParam data object Datos adicionales (key-value).
     */
    public function sendToAll(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'body' => 'required|string',
            'data' => 'nullable|array'
        ]);
        
        try {
            // Contar dispositivos activos antes de enviar
            $totalDevices = DeviceToken::where('is_active', true)->count();
            
            if ($totalDevices === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'No hay dispositivos registrados'
                ], 404);
            }
            
            Log::info('[POST] Notificaciones → Broadcast', [
                'total_devices' => $totalDevices,
                'sent_by'       => $request->user()->id ?? 'unknown',
            ]);
            
            // Enviar a todos los dispositivos activos
            $result = $this->firebaseService->sendToAll(
                $validated['title'],
                $validated['body'],
                $validated['data'] ?? []
            );
            
            return response()->json([
                'success' => true,
                'message' => 'Notificación masiva enviada',
                'total_devices' => $result['total_devices'],
                'success_count' => $result['success'],
                'failure_count' => $result['failure'],
                'batches_sent' => $result['batches_sent'] ?? 1
            ]);
            
        } catch (\Exception $e) {
            Log::error('[POST] Notificaciones → Error en broadcast', ['mensaje' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error al enviar notificación masiva'
            ], 500);
        }
    }
    
    // ═══════════════════════════════════════════════════════════════
    // LISTAR USUARIOS CON TOKENS REGISTRADOS
    // ═══════════════════════════════════════════════════════════════
    
    /**
     * Listar usuarios con tokens
     * 
     * Obtiene una lista paginada de usuarios que tienen tokens de FCM registrados.
     * 
     * @authenticated
     * @queryParam per_page integer Resultados por página. Default: 15.
     * @queryParam search string Buscar por nombre, correo o usuario.
     * @queryParam platform string Filtrar por plataforma (android, ios, web).
     */
    public function getUsersWithTokens(Request $request)
    {
        try {
            // Parámetros de búsqueda y paginación
            $perPage = $request->input('per_page', 15);
            $search = $request->input('search', '');
            $platform = $request->input('platform', ''); // android, ios, web
            
            // Consultar usuarios que tienen tokens activos
            $usuarios = Usuario::whereHas('deviceTokens', function($query) use ($platform) {
                $query->where('is_active', true);
                if ($platform) {
                    $query->where('platform', $platform);
                }
            })
            ->with(['deviceTokens' => function($query) use ($platform) {
                $query->where('is_active', true);
                if ($platform) {
                    $query->where('platform', $platform);
                }
                $query->select('id', 'user_id', 'platform', 'device_name', 'last_used_at', 'created_at');
            }, 'sede:id,nombresede'])
            ->when($search, function($query, $search) {
                $query->where(function($q) use ($search) {
                    $q->where('nombre', 'like', "%{$search}%")
                      ->orWhere('correo', 'like', "%{$search}%")
                      ->orWhere('usuario', 'like', "%{$search}%");
                });
            })
            ->select('id', 'usuario', 'nombre', 'correo', 'rol', 'idsede')
            ->paginate($perPage);
            
            // Formatear respuesta
            $usuarios->getCollection()->transform(function($usuario) {
                return [
                    'id' => $usuario->id,
                    'usuario' => $usuario->usuario,
                    'nombre' => $usuario->nombre,
                    'correo' => $usuario->correo,
                    'rol' => $usuario->rol,
                    'sede' => $usuario->sede ? $usuario->sede->nombresede : null,
                    'total_dispositivos' => $usuario->deviceTokens->count(),
                    'dispositivos' => $usuario->deviceTokens->map(function($token) {
                        return [
                            'id' => $token->id,
                            'platform' => $token->platform,
                            'device_name' => $token->device_name,
                            'last_used_at' => $token->last_used_at?->format('Y-m-d H:i:s'),
                            'registered_at' => $token->created_at?->format('Y-m-d H:i:s')
                        ];
                    })
                ];
            });
            
            return response()->json([
                'success' => true,
                'usuarios' => $usuarios->items(),
                'total' => $usuarios->total(),
                'current_page' => $usuarios->currentPage(),
                'per_page' => $usuarios->perPage(),
                'last_page' => $usuarios->lastPage()
            ]);
            
        } catch (\Exception $e) {
            Log::error('[GET] Notificaciones → Error listando usuarios con tokens', ['mensaje' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error al listar usuarios'
            ], 500);
        }
    }
    
    // ═══════════════════════════════════════════════════════════════
    // OBTENER ESTADÍSTICAS DE TOKENS
    // ═══════════════════════════════════════════════════════════════
    
    public function getTokenStats()
    {
        try {
            $stats = [
                'total_usuarios_con_tokens' => Usuario::whereHas('deviceTokens', function($query) {
                    $query->where('is_active', true);
                })->count(),
                
                'total_tokens_activos' => DeviceToken::where('is_active', true)->count(),
                
                'por_plataforma' => DeviceToken::where('is_active', true)
                    ->selectRaw('platform, COUNT(*) as count')
                    ->groupBy('platform')
                    ->pluck('count', 'platform'),
                
                'tokens_recientes' => DeviceToken::where('is_active', true)
                    ->where('created_at', '>=', now()->subDays(7))
                    ->count(),
                    
                'ultimo_token_registrado' => DeviceToken::where('is_active', true)
                    ->with('usuario:id,nombre')
                    ->latest()
                    ->first()
            ];
            
            return response()->json([
                'success' => true,
                'stats' => $stats
            ]);
            
        } catch (\Exception $e) {
            Log::error('[GET] Notificaciones → Error obteniendo estadísticas', ['mensaje' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estadísticas'
            ], 500);
        }
    }
    
    // ═══════════════════════════════════════════════════════════════
    // OBTENER TOKENS DE UN USUARIO ESPECÍFICO
    // ═══════════════════════════════════════════════════════════════
    
    public function getUserTokens($userId)
    {
        try {
            $usuario = Usuario::with(['deviceTokens' => function($query) {
                $query->select('id', 'user_id', 'fcm_token', 'platform', 'device_name', 'is_active', 'last_used_at', 'created_at')
                      ->orderBy('is_active', 'desc')
                      ->orderBy('last_used_at', 'desc');
            }])
            ->find($userId);
            
            if (!$usuario) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no encontrado'
                ], 404);
            }
            
            return response()->json([
                'success' => true,
                'usuario' => [
                    'id' => $usuario->id,
                    'nombre' => $usuario->nombre,
                    'correo' => $usuario->correo
                ],
                'tokens' => $usuario->deviceTokens->map(function($token) {
                    return [
                        'id' => $token->id,
                        'platform' => $token->platform,
                        'device_name' => $token->device_name,
                        'is_active' => $token->is_active,
                        'fcm_token' => substr($token->fcm_token, 0, 30) . '...', // Truncado por seguridad
                        'last_used_at' => $token->last_used_at?->format('Y-m-d H:i:s'),
                        'created_at' => $token->created_at?->format('Y-m-d H:i:s')
                    ];
                }),
                'total_tokens' => $usuario->deviceTokens->count(),
                'tokens_activos' => $usuario->deviceTokens->where('is_active', true)->count()
            ]);
            
        } catch (\Exception $e) {
            Log::error('[GET] Notificaciones → Error obteniendo tokens de usuario', [
                'user_id' => $userId,
                'mensaje' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener tokens'
            ], 500);
        }
    }
    
    // ═══════════════════════════════════════════════════════════════
    // DESREGISTRAR TOKEN (Cuando el usuario hace logout)
    // ═══════════════════════════════════════════════════════════════
    
    public function unregisterDevice(Request $request)
    {
        $validated = $request->validate([
            'fcm_token' => 'required|string'
        ]);
        
        try {
            $deviceToken = DeviceToken::where('fcm_token', $validated['fcm_token'])->first();
            
            if ($deviceToken) {
                $deviceToken->desactivar();
                
                Log::info('[POST] Notificaciones → Token desactivado', [
                    'user_id'  => $deviceToken->user_id,
                    'token_id' => $deviceToken->id,
                ]);
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Token desactivado correctamente'
            ]);
            
        } catch (\Exception $e) {
            Log::error('[DELETE] Notificaciones → Error desactivando token', ['mensaje' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error al desactivar token'
            ], 500);
        }
    }
}
