<?php
// app/Http/Controllers/LogController.php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Carbon\Carbon;

/**
 * @group Auditoría y Logs
 *
 * Módulo para el análisis y monitoreo de los logs del sistema (laravel.log), con filtros avanzados por módulo y prioridad.
 */
class LogController extends Controller
{
    /**
     * Listar y filtrar logs
     * 
     * @authenticated
     * @queryParam search string Busqueda por texto.
     * @queryParam type string Filtrar por tipo (visita, brigada, etc).
     * @queryParam status string Filtrar por estado (success, error, warning).
     */
    public function index(Request $request)
    {
        $logPath = storage_path('logs/laravel.log');
        
        if (!File::exists($logPath)) {
            return response()->json([
                'success' => false,
                'message' => 'Archivo de log no encontrado'
            ], 404);
        }

        $logs = $this->parseLogs($logPath, $request);
        
        return response()->json([
            'success' => true,
            'data' => $logs['data'],
            'pagination' => $logs['pagination'],
            'filters' => $logs['filters']
        ]);
    }

    private function parseLogs($logPath, $request)
    {
        $content = File::get($logPath);
        $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
        $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $content);
        $lines = explode("\n", $content);
        
        $logs = [];
        $currentLog = null;
        
        foreach ($lines as $lineNumber => $line) {
            if (empty(trim($line))) continue;
            
            // Patrón para logs de Laravel: [2025-09-01 19:11:08] Produccion.INFO: mensaje
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] (\w+)\.(\w+): (.+)$/', $line, $matches)) {
                // Guardar log anterior si existe
                if ($currentLog) {
                    $logs[] = $this->processLogEntry($currentLog);
                }
                
                // Iniciar nuevo log
                $currentLog = [
                    'timestamp' => $matches[1],
                    'environment' => $matches[2],
                    'level' => $matches[3],
                    'message' => $matches[4],
                    'additional_lines' => [],
                    'line_number' => $lineNumber + 1
                ];
            } else {
                // Línea adicional del log actual (stack traces, etc.)
                if ($currentLog) {
                    $currentLog['additional_lines'][] = $line;
                }
            }
        }
        
        // Procesar último log
        if ($currentLog) {
            $logs[] = $this->processLogEntry($currentLog);
        }
        
        // Ordenar por timestamp descendente (más recientes primero)
        usort($logs, function($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });
        
        // Filtrar logs
        $filteredLogs = $this->filterLogs($logs, $request);
        
        // Paginación
        $page = $request->get('page', 1);
        $perPage = $request->get('per_page', 50);
        $total = count($filteredLogs);
        
        $paginatedLogs = array_slice($filteredLogs, ($page - 1) * $perPage, $perPage);
        
        return [
            'data' => $paginatedLogs,
            'pagination' => [
                'current_page' => (int)$page,
                'per_page' => (int)$perPage,
                'total' => $total,
                'last_page' => ceil($total / $perPage) ?: 1,
                'from' => $total > 0 ? ($page - 1) * $perPage + 1 : 0,
                'to' => min($page * $perPage, $total)
            ],
            'filters' => $this->getAvailableFilters($logs)
        ];
    }

    private function processLogEntry($logEntry)
    {
        $processed = [
            'id' => uniqid(),
            'timestamp' => $logEntry['timestamp'],
            'formatted_date' => Carbon::parse($logEntry['timestamp'])->format('d/m/Y H:i:s'),
            'relative_time' => Carbon::parse($logEntry['timestamp'])->diffForHumans(),
            'environment' => $logEntry['environment'],
            'level' => $logEntry['level'],
            'message' => $logEntry['message'],
            'type' => 'general',
            'category' => 'system',
            'operation' => null,
            'http_method' => null,
            'endpoint' => null,
            'status' => $this->determineStatus($logEntry['level'], $logEntry['message']),
            'entity_id' => null,
            'entity_type' => null,
            'user_id' => null,
            'ip_address' => null,
            'user_agent' => null,
            'duration' => null,
            'error_details' => null,
            'request_data' => null,
            'response_data' => null,
            'full_content' => $logEntry['message'] . "\n" . implode("\n", $logEntry['additional_lines']),
            'line_number' => $logEntry['line_number'] ?? null,
            'has_stack_trace' => !empty($logEntry['additional_lines']),
            'priority' => $this->determinePriority($logEntry['level'], $logEntry['message']),
            'tags' => []
        ];

        // Analizar el contenido del mensaje
        $this->analyzeLogContent($processed, $logEntry);

        return $processed;
    }

    private function analyzeLogContent(&$processed, $logEntry)
    {
        $message = $logEntry['message'];
        $fullContent = $processed['full_content'];

        // === NUEVO FORMATO: [MÉTODO] Módulo → Estado {contexto} ===
        // Ejemplo: [POST] Visita Domiciliaria → Exitosa {"id":"abc","paciente_id":"xyz"}
        if (preg_match('/^\[(\w+)\]\s+(.+?)\s+→\s+(.+?)(?:\s+(\{.+\}))?$/', $message, $m)) {
            $httpMethod  = $m[1];
            $module      = trim($m[2]);
            $stateRaw    = trim($m[3]);
            $contextJson = $m[4] ?? null;

            $processed['http_method'] = $httpMethod;

            // Determinar tipo/categoría/operación según módulo
            $this->mapModuleToProcessed($processed, $module, $stateRaw);

            // Parsear JSON de contexto si existe
            if ($contextJson) {
                $ctx = json_decode($contextJson, true);
                if (json_last_error() === JSON_ERROR_NONE && $ctx) {
                    // IDs relevantes
                    foreach (['id', 'visita_id', 'brigada_id', 'tamizaje_id', 'encuesta_id', 'paciente_id', 'muestra_id'] as $key) {
                        if (!empty($ctx[$key])) {
                            $processed['entity_id'] = $ctx[$key];
                            break;
                        }
                    }
                    // Usuario
                    foreach (['usuario_id', 'user_id', 'idusuario'] as $key) {
                        if (!empty($ctx[$key])) {
                            $processed['user_id'] = $ctx[$key];
                            break;
                        }
                    }
                    $processed['request_data'] = $ctx;
                }
            }

            // Determinar status según estado textual
            $stateLower = strtolower($stateRaw);
            if (str_contains($stateLower, 'exitos') || str_contains($stateLower, 'creada') || str_contains($stateLower, 'creado')) {
                $processed['status']   = 'success';
                $processed['priority'] = 'high';
                $processed['tags'][]   = 'success';
            } elseif (str_contains($stateLower, 'error')) {
                $processed['status']   = 'error';
                $processed['priority'] = 'critical';
                $processed['tags'][]   = 'error';
                // Extraer mensaje/línea de error del contexto
                if (isset($ctx['mensaje'])) {
                    $processed['error_details']['error_message'] = $ctx['mensaje'];
                }
                if (isset($ctx['linea'])) {
                    $processed['error_details']['line'] = $ctx['linea'];
                }
            } elseif (str_contains($stateLower, 'advertencia') || str_contains($stateLower, 'advertencia')) {
                $processed['status']   = 'warning';
                $processed['priority'] = 'medium';
                $processed['tags'][]   = 'warning';
            } elseif (str_contains($stateLower, 'procesando')) {
                $processed['status']   = 'processing';
                $processed['priority'] = 'low';
            }

            return; // Procesado por nuevo formato, salir
        }

        // === FALLBACK: formato antiguo y logs de sistema ===

        // Detectar método HTTP
        if (preg_match('/\b(GET|POST|PUT|PATCH|DELETE)\b/', $message, $matches)) {
            $processed['http_method'] = $matches[1];
        }

        // Detectar endpoint
        if (preg_match('/\/api\/[^\s"\']+/', $message, $matches)) {
            $processed['endpoint'] = $matches[0];
        }

        // === ANÁLISIS DE ERRORES ===
        if ($processed['level'] === 'ERROR') {
            $processed['status']   = 'error';
            $processed['priority'] = 'critical';

            if (strpos($fullContent, 'Duplicate entry') !== false) {
                $processed['error_details']['type']        = 'duplicate_entry';
                $processed['error_details']['description'] = 'Intento de insertar un registro duplicado';
                $processed['tags'][] = 'duplicate_error';
                if (preg_match("/Duplicate entry '([^']+)'/", $fullContent, $idMatch)) {
                    $processed['entity_id'] = $idMatch[1];
                }
            }
            if (strpos($fullContent, 'Integrity constraint violation') !== false) {
                $processed['error_details']['constraint'] = 'integrity_violation';
                $processed['tags'][] = 'constraint_error';
            }
            // Extraer mensaje/línea del JSON de contexto
            if (preg_match('/"mensaje":"([^"]+)"/', $message, $msgMatch)) {
                $processed['error_details']['error_message'] = $msgMatch[1];
            }
            if (preg_match('/"linea":(\d+)/', $message, $lineMatch)) {
                $processed['error_details']['line'] = (int)$lineMatch[1];
            }
        }

        // === EXTRAER IDs si no se encontraron ===
        if (!$processed['entity_id'] && preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', $message, $matches)) {
            $processed['entity_id'] = $matches[0];
        }
        if (!$processed['user_id'] && preg_match('/"(?:usuario_id|user_id)":"?([^",}]+)"?/', $message, $matches)) {
            $processed['user_id'] = $matches[1];
        }
    }

    /**
     * Mapea el módulo del log al tipo/categoría/operación/endpoint del log procesado.
     */
    private function mapModuleToProcessed(&$processed, string $module, string $state): void
    {
        $stateLower = strtolower($state);
        $isUpdate   = in_array($processed['http_method'], ['PUT', 'PATCH']);
        $isDelete   = $processed['http_method'] === 'DELETE';

        $operation = $isDelete ? 'eliminar' : ($isUpdate ? 'actualizar' : 'crear');
        if (str_contains($stateLower, 'consulta') || $processed['http_method'] === 'GET') {
            $operation = 'consultar';
        }
        if (str_contains($stateLower, 'procesando')) {
            $operation = $isUpdate ? 'actualizar' : 'crear';
        }

        $moduleMap = [
            'Visita Domiciliaria'    => ['type' => 'visita',      'category' => 'medical',   'endpoint' => '/api/visitas'],
            'Brigada'                => ['type' => 'brigada',     'category' => 'medical',   'endpoint' => '/api/brigadas'],
            'Tamizaje'               => ['type' => 'tamizaje',    'category' => 'medical',   'endpoint' => '/api/tamizajes'],
            'Encuesta'               => ['type' => 'encuesta',    'category' => 'medical',   'endpoint' => '/api/encuestas'],
            'Envío de Muestra'       => ['type' => 'muestra',     'category' => 'lab',       'endpoint' => '/api/muestras'],
            'Findrisk'               => ['type' => 'findrisk',    'category' => 'medical',   'endpoint' => '/api/findrisk'],
            'Afinamiento'            => ['type' => 'afinamiento', 'category' => 'medical',   'endpoint' => '/api/efinamientos'],
            'Usuarios'               => ['type' => 'usuario',     'category' => 'admin',     'endpoint' => '/api/usuarios'],
            'Notificaciones'         => ['type' => 'notificacion','category' => 'system',    'endpoint' => '/api/notificaciones'],
            'Visita'                 => ['type' => 'visita',      'category' => 'medical',   'endpoint' => '/api/visitas'],
        ];

        foreach ($moduleMap as $key => $mapping) {
            if (str_contains($module, $key)) {
                $processed['type']      = $mapping['type'];
                $processed['category']  = $mapping['category'];
                $processed['endpoint']  = $mapping['endpoint'];
                $processed['operation'] = $operation;
                return;
            }
        }

        // Fallback genérico
        $processed['type']      = 'general';
        $processed['category']  = 'system';
        $processed['operation'] = $operation;
    }

    private function extractHttpInfo(&$processed, $message, $fullContent)
    {
        if (preg_match('/\b(GET|POST|PUT|PATCH|DELETE)\b/', $message, $matches)) {
            $processed['http_method'] = $matches[1];
        }
        if (preg_match('/\/api\/[^\s"\']+/', $message, $matches)) {
            $processed['endpoint'] = $matches[0];
        }
        if (preg_match('/(\d+)ms/', $message, $matches)) {
            $processed['duration'] = (int)$matches[1];
        }
    }

    private function extractUserInfo(&$processed, $message, $fullContent)
    {
        if (preg_match('/"(?:user_id|usuario_id)":"?([^",}]+)"?/', $message, $matches)) {
            $processed['user_id'] = $matches[1];
        }
        if (preg_match('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', $message, $matches)) {
            $processed['ip_address'] = $matches[0];
        }
    }

    private function extractRequestData(&$processed, $message)
    {
        if (preg_match('/\{.*\}/', $message, $matches)) {
            $jsonData = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $processed['request_data'] = $jsonData;
            }
        }
    }

    private function extractResponseData(&$processed, $message)
    {
        if (preg_match('/\{.*\}/', $message, $matches)) {
            $jsonData = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $processed['response_data'] = $jsonData;
            }
        }
    }

    private function determinePriority($level, $message)
    {
        if ($level === 'ERROR') return 'critical';

        // Nuevo formato: → Exitosa / → Creada / → Actualizada
        if (preg_match('/→\s*(Exitosa|Creada|Creado|Actualizada|Actualizado)/i', $message)) {
            return 'high';
        }
        if (preg_match('/→\s*(Advertencia|Advertencia)/i', $message)) {
            return 'medium';
        }
        if ($level === 'WARNING') return 'medium';

        return 'low';
    }

    private function determineStatus($level, $message)
    {
        if ($level === 'ERROR') return 'error';
        if ($level === 'WARNING') return 'warning';

        // Nuevo formato
        if (preg_match('/→\s*(Exitosa|Creada|Creado|Actualizada|Actualizado|Consultadas?|Registrado)/i', $message)) {
            return 'success';
        }
        if (preg_match('/→\s*(Procesando|Advertencia)/i', $message)) {
            return 'processing';
        }
        if (preg_match('/→\s*Error/i', $message)) {
            return 'error';
        }

        return 'success';
    }


    private function extractErrorDetails($content)
    {
        $details = [];
        
        // Error SQL
        if (preg_match('/SQLSTATE\[(\w+)\]: ([^(]+)/', $content, $matches)) {
            $details['sql_state'] = $matches[1];
            $details['sql_error'] = trim($matches[2]);
        }
        
        // Mensaje de error específico
        if (preg_match('/"error":"([^"]+)"/', $content, $matches)) {
            $details['error_message'] = $matches[1];
        }
        
        // Línea del error
        if (preg_match('/"line":(\d+)/', $content, $matches)) {
            $details['line'] = (int)$matches[1];
        }
        
        // Archivo del error
        if (preg_match('/"file":"([^"]+)"/', $content, $matches)) {
            $details['file'] = basename($matches[1]);
        }
        
        // Stack trace resumido
        if (strpos($content, '#0') !== false) {
            $details['has_stack_trace'] = true;
            // Extraer primera línea del stack trace
            if (preg_match('/#0 ([^\n]+)/', $content, $matches)) {
                $details['stack_trace_first'] = $matches[1];
            }
        }
        
        // Extraer trace JSON si existe
        if (preg_match('/"trace":"([^"]+)"/', $content, $matches)) {
            $trace = explode('\\n', $matches[1]);
            $details['trace'] = array_slice($trace, 0, 3);
        }
        
        return $details;
    }

    private function filterLogs($logs, $request)
    {
        $filtered = $logs;
        
        // Filtro por tipo
        if ($request->has('type') && $request->type !== '') {
            $filtered = array_filter($filtered, function($log) use ($request) {
                return $log['type'] === $request->type;
            });
        }
        
        // Filtro por categoría
        if ($request->has('category') && $request->category !== '') {
            $filtered = array_filter($filtered, function($log) use ($request) {
                return $log['category'] === $request->category;
            });
        }
        
        // Filtro por estado
        if ($request->has('status') && $request->status !== '') {
            $filtered = array_filter($filtered, function($log) use ($request) {
                return $log['status'] === $request->status;
            });
        }
        
        // Filtro por operación
        if ($request->has('operation') && $request->operation !== '') {
            $filtered = array_filter($filtered, function($log) use ($request) {
                return $log['operation'] === $request->operation;
            });
        }
        
        // Filtro por método HTTP
        if ($request->has('http_method') && $request->http_method !== '') {
            $filtered = array_filter($filtered, function($log) use ($request) {
                return $log['http_method'] === $request->http_method;
            });
        }
        
        // Filtro por prioridad
        if ($request->has('priority') && $request->priority !== '') {
            $filtered = array_filter($filtered, function($log) use ($request) {
                return $log['priority'] === $request->priority;
            });
        }
        
        // Filtro por fecha
        if ($request->has('date_from')) {
            $dateFrom = Carbon::parse($request->date_from)->startOfDay();
            $filtered = array_filter($filtered, function($log) use ($dateFrom) {
                return Carbon::parse($log['timestamp'])->gte($dateFrom);
            });
        }
        
        if ($request->has('date_to')) {
            $dateTo = Carbon::parse($request->date_to)->endOfDay();
            $filtered = array_filter($filtered, function($log) use ($dateTo) {
                return Carbon::parse($log['timestamp'])->lte($dateTo);
            });
        }
        
        // Filtro por búsqueda de texto
        if ($request->has('search') && $request->search !== '') {
            $search = strtolower($request->search);
            $filtered = array_filter($filtered, function($log) use ($search) {
                return strpos(strtolower($log['message']), $search) !== false ||
                       strpos(strtolower($log['entity_id'] ?? ''), $search) !== false ||
                       strpos(strtolower($log['endpoint'] ?? ''), $search) !== false;
            });
        }
        
        // Filtro por tags
        if ($request->has('tag') && $request->tag !== '') {
            $filtered = array_filter($filtered, function($log) use ($request) {
                return in_array($request->tag, $log['tags']);
            });
        }
        
        // Ordenar por timestamp descendente
        usort($filtered, function($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });
        
        return array_values($filtered);
    }

    private function getAvailableFilters($logs)
    {
        $types = array_unique(array_column($logs, 'type'));
        $categories = array_unique(array_column($logs, 'category'));
        $statuses = array_unique(array_column($logs, 'status'));
        $operations = array_unique(array_filter(array_column($logs, 'operation')));
        $httpMethods = array_unique(array_filter(array_column($logs, 'http_method')));
                $priorities = array_unique(array_column($logs, 'priority'));
        
        // Extraer todos los tags únicos
        $allTags = [];
        foreach ($logs as $log) {
            $allTags = array_merge($allTags, $log['tags']);
        }
        $tags = array_unique($allTags);
        
        return [
            'types' => array_values($types),
            'categories' => array_values($categories),
            'statuses' => array_values($statuses),
            'operations' => array_values($operations),
            'http_methods' => array_values($httpMethods),
            'priorities' => array_values($priorities),
            'tags' => array_values($tags)
        ];
    }

    public function show($id)
    {
        $logPath = storage_path('logs/laravel.log');
        
        if (!File::exists($logPath)) {
            return response()->json([
                'success' => false,
                'message' => 'Archivo de log no encontrado'
            ], 404);
        }

        $logs = $this->parseLogs($logPath, request());
        
        $log = collect($logs['data'])->firstWhere('id', $id);
        
        if (!$log) {
            return response()->json([
                'success' => false,
                'message' => 'Log no encontrado'
            ], 404);
        }

        // Agregar información adicional para la vista detallada
        $log['related_logs'] = $this->findRelatedLogs($logs['data'], $log);
        $log['context'] = $this->getLogContext($logs['data'], $log);
        
        return response()->json([
            'success' => true,
            'data' => $log
        ]);
    }

    private function findRelatedLogs($allLogs, $currentLog)
    {
        $related = [];
        
        // Buscar logs relacionados por entity_id
        if ($currentLog['entity_id']) {
            $related = array_filter($allLogs, function($log) use ($currentLog) {
                return $log['entity_id'] === $currentLog['entity_id'] && 
                       $log['id'] !== $currentLog['id'];
            });
        }
        
        // Buscar logs relacionados por endpoint
        if (empty($related) && $currentLog['endpoint']) {
            $related = array_filter($allLogs, function($log) use ($currentLog) {
                return $log['endpoint'] === $currentLog['endpoint'] && 
                       $log['id'] !== $currentLog['id'] &&
                       abs(strtotime($log['timestamp']) - strtotime($currentLog['timestamp'])) < 300; // 5 minutos
            });
        }
        
        // Buscar logs relacionados por user_id en el mismo periodo
        if (empty($related) && $currentLog['user_id']) {
            $related = array_filter($allLogs, function($log) use ($currentLog) {
                return $log['user_id'] === $currentLog['user_id'] && 
                       $log['id'] !== $currentLog['id'] &&
                       abs(strtotime($log['timestamp']) - strtotime($currentLog['timestamp'])) < 600; // 10 minutos
            });
        }
        
        // Ordenar por timestamp y limitar a 10
        usort($related, function($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });
        
        return array_slice(array_values($related), 0, 10);
    }

    private function getLogContext($allLogs, $currentLog)
    {
        $context = [
            'before' => [],
            'after' => []
        ];
        
        $currentTime = strtotime($currentLog['timestamp']);
        
        // Obtener logs 5 minutos antes y después
        foreach ($allLogs as $log) {
            $logTime = strtotime($log['timestamp']);
            $timeDiff = $logTime - $currentTime;
            
            if ($timeDiff < 0 && $timeDiff > -300) { // 5 minutos antes
                $context['before'][] = $log;
            } elseif ($timeDiff > 0 && $timeDiff < 300) { // 5 minutos después
                $context['after'][] = $log;
            }
        }
        
        // Ordenar contexto
        usort($context['before'], function($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });
        
        usort($context['after'], function($a, $b) {
            return strtotime($a['timestamp']) - strtotime($b['timestamp']);
        });
        
        // Limitar a 5 logs antes y después
        $context['before'] = array_slice($context['before'], 0, 5);
        $context['after'] = array_slice($context['after'], 0, 5);
        
        return $context;
    }

    public function stats(Request $request)
    {
        $logPath = storage_path('logs/laravel.log');
        
        if (!File::exists($logPath)) {
            return response()->json([
                'success' => false,
                'message' => 'Archivo de log no encontrado'
            ], 404);
        }

        $logs = $this->parseLogs($logPath, $request);
        $data = $logs['data'];
        
        // Estadísticas generales
        $stats = [
            'total_logs' => count($data),
            'by_level' => [],
            'by_type' => [],
            'by_category' => [],
            'by_status' => [],
            'by_priority' => [],
            'by_hour' => [],
            'by_day' => [],
            'recent_errors' => [],
            'top_operations' => [],
            'top_endpoints' => [],
            'response_times' => [],
            'error_trends' => []
        ];
        
        // Agrupar por nivel
        foreach ($data as $log) {
            $level = $log['level'];
            $stats['by_level'][$level] = ($stats['by_level'][$level] ?? 0) + 1;
        }
        
        // Agrupar por tipo
        foreach ($data as $log) {
            $type = $log['type'];
            $stats['by_type'][$type] = ($stats['by_type'][$type] ?? 0) + 1;
        }
        
        // Agrupar por categoría
        foreach ($data as $log) {
            $category = $log['category'];
            $stats['by_category'][$category] = ($stats['by_category'][$category] ?? 0) + 1;
        }
        
        // Agrupar por estado
        foreach ($data as $log) {
            $status = $log['status'];
            $stats['by_status'][$status] = ($stats['by_status'][$status] ?? 0) + 1;
        }
        
        // Agrupar por prioridad
        foreach ($data as $log) {
            $priority = $log['priority'];
            $stats['by_priority'][$priority] = ($stats['by_priority'][$priority] ?? 0) + 1;
        }
        
        // Agrupar por hora
        foreach ($data as $log) {
            $hour = Carbon::parse($log['timestamp'])->format('H:00');
            $stats['by_hour'][$hour] = ($stats['by_hour'][$hour] ?? 0) + 1;
        }
        
        // Agrupar por día (últimos 7 días)
        foreach ($data as $log) {
            $day = Carbon::parse($log['timestamp'])->format('Y-m-d');
            $stats['by_day'][$day] = ($stats['by_day'][$day] ?? 0) + 1;
        }
        
        // Errores recientes (últimas 24 horas)
        $yesterday = Carbon::now()->subDay();
        $stats['recent_errors'] = array_filter($data, function($log) use ($yesterday) {
            return $log['status'] === 'error' && 
                   Carbon::parse($log['timestamp'])->gte($yesterday);
        });
        $stats['recent_errors'] = array_slice(array_values($stats['recent_errors']), 0, 20);
        
        // Top operaciones
        $operations = [];
        foreach ($data as $log) {
            if ($log['operation']) {
                $operations[$log['operation']] = ($operations[$log['operation']] ?? 0) + 1;
            }
        }
        arsort($operations);
        $stats['top_operations'] = array_slice($operations, 0, 10, true);
        
        // Top endpoints
        $endpoints = [];
        foreach ($data as $log) {
            if ($log['endpoint']) {
                $endpoints[$log['endpoint']] = ($endpoints[$log['endpoint']] ?? 0) + 1;
            }
        }
        arsort($endpoints);
        $stats['top_endpoints'] = array_slice($endpoints, 0, 10, true);
        
        // Tiempos de respuesta promedio
        $responseTimes = array_filter(array_column($data, 'duration'));
        if (!empty($responseTimes)) {
            $stats['response_times'] = [
                'average' => round(array_sum($responseTimes) / count($responseTimes), 2),
                'min' => min($responseTimes),
                'max' => max($responseTimes),
                'count' => count($responseTimes)
            ];
        }
        
        // Tendencias de errores (por día en los últimos 7 días)
        $last7Days = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i)->format('Y-m-d');
            $errorCount = count(array_filter($data, function($log) use ($date) {
                return $log['status'] === 'error' && 
                       Carbon::parse($log['timestamp'])->format('Y-m-d') === $date;
            }));
            $last7Days[$date] = $errorCount;
        }
        $stats['error_trends'] = $last7Days;
        
        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    public function export(Request $request)
    {
        $logPath = storage_path('logs/laravel.log');
        
        if (!File::exists($logPath)) {
            return response()->json([
                'success' => false,
                'message' => 'Archivo de log no encontrado'
            ], 404);
        }

        $logs = $this->parseLogs($logPath, $request);
        $data = $logs['data'];
        
        $format = $request->get('format', 'json');
        
        switch ($format) {
            case 'csv':
                return $this->exportToCsv($data);
            case 'excel':
                return $this->exportToExcel($data);
            case 'json':
            default:
                return response()->json([
                    'success' => true,
                    'data' => $data,
                    'total' => count($data),
                    'exported_at' => now()->toISOString()
                ]);
        }
    }

    private function exportToCsv($data)
    {
        $filename = 'logs_' . date('Y-m-d_H-i-s') . '.csv';
        
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];
        
        $callback = function() use ($data) {
            $file = fopen('php://output', 'w');
            
            // Encabezados CSV
            fputcsv($file, [
                'ID', 'Timestamp', 'Level', 'Type', 'Category', 'Operation', 
                'Status', 'Priority', 'HTTP Method', 'Endpoint', 'Entity ID', 
                'Entity Type', 'User ID', 'IP Address', 'Duration', 'Message'
            ]);
            
            // Datos
            foreach ($data as $log) {
                fputcsv($file, [
                    $log['id'],
                    $log['timestamp'],
                    $log['level'],
                    $log['type'],
                    $log['category'],
                    $log['operation'] ?? '',
                    $log['status'],
                    $log['priority'],
                    $log['http_method'] ?? '',
                    $log['endpoint'] ?? '',
                    $log['entity_id'] ?? '',
                    $log['entity_type'] ?? '',
                    $log['user_id'] ?? '',
                    $log['ip_address'] ?? '',
                    $log['duration'] ?? '',
                    $log['message']
                ]);
            }
            
            fclose($file);
        };
        
        return response()->stream($callback, 200, $headers);
    }

    private function exportToExcel($data)
    {
        // Nota: Requiere la librería PhpSpreadsheet
        // composer require phpoffice/phpspreadsheet
        
        return response()->json([
            'success' => false,
            'message' => 'Exportación a Excel no implementada. Instalar phpoffice/phpspreadsheet'
        ], 501);
    }

    public function clear(Request $request)
    {
        $request->validate([
            'confirm' => 'required|boolean|accepted'
        ]);
        
        $logPath = storage_path('logs/laravel.log');
        
        if (!File::exists($logPath)) {
            return response()->json([
                'success' => false,
                'message' => 'Archivo de log no encontrado'
            ], 404);
        }
        
        // Crear backup antes de limpiar
        $backupPath = storage_path('logs/laravel_backup_' . date('Y-m-d_H-i-s') . '.log');
        File::copy($logPath, $backupPath);
        
        // Limpiar el archivo de log
        File::put($logPath, '');
        
        return response()->json([
            'success' => true,
            'message' => 'Logs limpiados correctamente',
            'backup_created' => $backupPath
        ]);
    }

    public function download()
    {
        $logPath = storage_path('logs/laravel.log');
        
        if (!File::exists($logPath)) {
            return response()->json([
                'success' => false,
                'message' => 'Archivo de log no encontrado'
            ], 404);
        }
        
        return response()->download($logPath, 'laravel_' . date('Y-m-d_H-i-s') . '.log');
    }
}

