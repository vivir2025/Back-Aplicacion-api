<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\Usuario;
use App\Models\DeviceToken;
use App\Services\FirebaseNotificationService;

class NotificationController extends Controller
{
    private $firebaseService;
    
    public function __construct(FirebaseNotificationService $firebaseService)
    {
        $this->firebaseService = $firebaseService;
    }
    
    // ═══════════════════════════════════════════════════════════════
    // REGISTRAR TOKEN (Cuando el usuario hace login en Flutter)
    // ═══════════════════════════════════════════════════════════════
    
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
            
            Log::info('✅ Token FCM registrado', [
                'user_id' => $validated['user_id'],
                'platform' => $validated['platform'],
                'token_id' => $deviceToken->id
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Token registrado correctamente',
                'token_id' => $deviceToken->id
            ]);
            
        } catch (\Exception $e) {
            Log::error('❌ Error registrando token', [
                'error' => $e->getMessage(),
                'user_id' => $validated['user_id']
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
            
            // Obtener tokens activos del usuario
            $tokens = $usuario->fcm_tokens; // Usa el accessor que creamos
            
            if (empty($tokens)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no tiene dispositivos registrados'
                ], 404);
            }
            
            Log::info('📤 Enviando notificación', [
                'user_id' => $validated['user_id'],
                'usuario' => $usuario->nombre,
                'devices' => count($tokens),
                'title' => $validated['title']
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
            Log::error('❌ Error enviando notificación', [
                'user_id' => $validated['user_id'],
                'error' => $e->getMessage()
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
            
            Log::info('📢 Enviando notificación masiva (broadcast)', [
                'total_devices' => $totalDevices,
                'title' => $validated['title'],
                'sent_by' => $request->user()->id ?? 'unknown'
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
            Log::error('❌ Error enviando notificación masiva', [
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error al enviar notificación masiva'
            ], 500);
        }
    }
    
    // ═══════════════════════════════════════════════════════════════
    // LISTAR USUARIOS CON TOKENS REGISTRADOS
    // ═══════════════════════════════════════════════════════════════
    
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
            }, 'sede:id,nombre'])
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
                    'sede' => $usuario->sede ? $usuario->sede->nombre : null,
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
            Log::error('❌ Error listando usuarios con tokens', [
                'error' => $e->getMessage()
            ]);
            
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
            Log::error('❌ Error obteniendo estadísticas', [
                'error' => $e->getMessage()
            ]);
            
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
            Log::error('❌ Error obteniendo tokens del usuario', [
                'user_id' => $userId,
                'error' => $e->getMessage()
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
                
                Log::info('🔕 Token desactivado', [
                    'user_id' => $deviceToken->user_id,
                    'token_id' => $deviceToken->id
                ]);
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Token desactivado correctamente'
            ]);
            
        } catch (\Exception $e) {
            Log::error('❌ Error desactivando token', [
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error al desactivar token'
            ], 500);
        }
    }
}
