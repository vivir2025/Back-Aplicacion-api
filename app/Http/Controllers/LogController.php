<?php
// app/Http/Controllers/LogController.php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Carbon\Carbon;

class LogController extends Controller
{
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

        // === DETECTAR MÉTODO HTTP Y ENDPOINT ===
        $this->extractHttpInfo($processed, $message, $fullContent);

        // === DETECTAR INFORMACIÓN DE USUARIO ===
        $this->extractUserInfo($processed, $message, $fullContent);

        // === ANÁLISIS DE VISITAS ===
        if (strpos($message, 'RECIBIENDO DATOS DE VISITA') !== false) {
            $processed['type'] = 'visita';
            $processed['category'] = 'medical';
            $processed['operation'] = 'recibir_datos';
            $processed['http_method'] = 'POST';
            $processed['endpoint'] = '/api/visitas';
            $processed['status'] = 'processing';
            $processed['tags'][] = 'data_reception';
            
            // Extraer datos de la request
            $this->extractRequestData($processed, $message);
        } 
        elseif (strpos($message, 'Visita creada') !== false) {
            $processed['type'] = 'visita';
            $processed['category'] = 'medical';
            $processed['operation'] = 'crear';
            $processed['http_method'] = 'POST';
            $processed['endpoint'] = '/api/visitas';
            $processed['status'] = 'success';
            $processed['priority'] = 'high';
            $processed['tags'][] = 'creation_success';
            
            // Extraer ID de la visita y datos de respuesta
            if (preg_match('/"id":"([^"]+)"/', $message, $matches)) {
                $processed['entity_id'] = $matches[1];
                $processed['entity_type'] = 'visita';
            }
            $this->extractResponseData($processed, $message);
        }
        elseif (strpos($message, 'Error al crear visita') !== false) {
            $processed['type'] = 'visita';
            $processed['category'] = 'medical';
            $processed['operation'] = 'crear';
            $processed['http_method'] = 'POST';
            $processed['endpoint'] = '/api/visitas';
            $processed['status'] = 'error';
            $processed['priority'] = 'critical';
            $processed['tags'][] = 'creation_error';
            $processed['error_details'] = $this->extractErrorDetails($fullContent);
        }
        elseif (strpos($message, 'ACTUALIZANDO VISITA') !== false) {
            $processed['type'] = 'visita';
            $processed['category'] = 'medical';
            $processed['operation'] = 'actualizar';
            $processed['http_method'] = 'PUT';
            $processed['status'] = 'processing';
            $processed['tags'][] = 'update_process';
            
            // Extraer ID de la visita
            if (preg_match('/"visita_id":"([^"]+)"/', $message, $matches)) {
                $processed['entity_id'] = $matches[1];
                $processed['entity_type'] = 'visita';
                $processed['endpoint'] = '/api/visitas/' . $matches[1];
            }
        }
        elseif (strpos($message, 'Visita actualizada') !== false) {
            $processed['type'] = 'visita';
            $processed['category'] = 'medical';
            $processed['operation'] = 'actualizar';
            $processed['http_method'] = 'PUT';
            $processed['status'] = 'success';
            $processed['priority'] = 'high';
            $processed['tags'][] = 'update_success';
            
            if (preg_match('/"id":"([^"]+)"/', $message, $matches)) {
                $processed['entity_id'] = $matches[1];
                $processed['entity_type'] = 'visita';
                $processed['endpoint'] = '/api/visitas/' . $matches[1];
            }
        }
        elseif (strpos($message, 'Error al actualizar visita') !== false) {
            $processed['type'] = 'visita';
            $processed['category'] = 'medical';
            $processed['operation'] = 'actualizar';
            $processed['http_method'] = 'PUT';
            $processed['status'] = 'error';
            $processed['priority'] = 'critical';
            $processed['tags'][] = 'update_error';
            $processed['error_details'] = $this->extractErrorDetails($fullContent);
            
            // Buscar ID en el contenido completo
            if (preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', $fullContent, $matches)) {
                $processed['entity_id'] = $matches[0];
                $processed['entity_type'] = 'visita';
                $processed['endpoint'] = '/api/visitas/' . $matches[0];
            }
        }
        elseif (strpos($message, 'Datos finales para crear visita') !== false) {
            $processed['type'] = 'visita';
            $processed['category'] = 'medical';
            $processed['operation'] = 'preparar_datos';
            $processed['status'] = 'processing';
            $processed['tags'][] = 'data_preparation';
            
            // Extraer ID de la visita
            if (preg_match('/"id":"([^"]+)"/', $message, $matches)) {
                $processed['entity_id'] = $matches[1];
                $processed['entity_type'] = 'visita';
            }
        }
        elseif (strpos($message, 'Todos los campos') !== false) {
            $processed['type'] = 'visita';
            $processed['category'] = 'validation';
            $processed['operation'] = 'validar_campos';
            $processed['status'] = 'processing';
            $processed['tags'][] = 'field_validation';
        }
        elseif (strpos($message, 'Form Data completo') !== false) {
            $processed['type'] = 'visita';
            $processed['category'] = 'form';
            $processed['operation'] = 'procesar_formulario';
            $processed['status'] = 'processing';
            $processed['tags'][] = 'form_processing';
        }
        elseif (strpos($message, 'Firma subida') !== false) {
            $processed['type'] = 'visita';
            $processed['category'] = 'file';
            $processed['operation'] = 'subir_firma';
            $processed['http_method'] = 'POST';
            $processed['endpoint'] = '/api/firmas';
            $processed['status'] = 'success';
            $processed['tags'][] = 'file_upload';
        }

        // === ANÁLISIS DE COORDENADAS ===
        elseif (strpos($message, 'Coordenadas del paciente actualizadas') !== false) {
            $processed['type'] = 'paciente';
            $processed['category'] = 'location';
            $processed['operation'] = 'actualizar_coordenadas';
            $processed['http_method'] = 'PUT';
            $processed['status'] = 'success';
            $processed['tags'][] = 'location_update';
            
            // Extraer ID del paciente
            if (preg_match('/"paciente_id":"([^"]+)"/', $message, $matches)) {
                $processed['entity_id'] = $matches[1];
                $processed['entity_type'] = 'paciente';
                $processed['endpoint'] = '/api/pacientes/' . $matches[1] . '/coordenadas';
            }
        }
        elseif (strpos($message, 'DEBUG COORDENADAS') !== false) {
            $processed['type'] = 'debug';
            $processed['category'] = 'location';
            $processed['operation'] = 'coordenadas';
            $processed['status'] = 'processing';
            $processed['tags'][] = 'debug';
        }
        elseif (strpos($message, 'Coordenadas finales') !== false) {
            $processed['type'] = 'visita';
            $processed['category'] = 'location';
            $processed['operation'] = 'coordenadas_finales';
            $processed['status'] = 'processing';
            $processed['tags'][] = 'location_final';
        }

        // === ANÁLISIS DE BRIGADAS ===
        elseif (strpos($message, 'Datos recibidos para crear brigada') !== false) {
            $processed['type'] = 'brigada';
            $processed['category'] = 'medical';
            $processed['operation'] = 'crear';
            $processed['http_method'] = 'POST';
            $processed['endpoint'] = '/api/brigadas';
            $processed['status'] = 'processing';
            $processed['tags'][] = 'data_reception';
        } 
        elseif (strpos($message, 'Brigada creada') !== false) {
            $processed['type'] = 'brigada';
            $processed['category'] = 'medical';
            $processed['operation'] = 'crear';
            $processed['http_method'] = 'POST';
            $processed['endpoint'] = '/api/brigadas';
            $processed['status'] = 'success';
            $processed['priority'] = 'high';
            $processed['tags'][] = 'creation_success';
            
            if (preg_match('/"id":"([^"]+)"/', $message, $matches)) {
                $processed['entity_id'] = $matches[1];
                $processed['entity_type'] = 'brigada';
            }
        }

        // === ANÁLISIS DE MEDICAMENTOS ===
        elseif (strpos($message, 'Medicamento guardado') !== false) {
            $processed['type'] = 'medicamento';
            $processed['category'] = 'medical';
            $processed['operation'] = 'asignar';
            $processed['http_method'] = 'POST';
            $processed['endpoint'] = '/api/medicamentos';
            $processed['status'] = 'success';
            $processed['tags'][] = 'medication_assigned';
        }
        elseif (strpos($message, 'Medicamento creado') !== false) {
            $processed['type'] = 'medicamento';
            $processed['category'] = 'medical';
            $processed['operation'] = 'crear';
            $processed['http_method'] = 'POST';
            $processed['endpoint'] = '/api/medicamentos';
            $processed['status'] = 'success';
            $processed['tags'][] = 'creation_success';
        }
        elseif (strpos($message, 'Medicamento actualizado') !== false) {
            $processed['type'] = 'medicamento';
            $processed['category'] = 'medical';
            $processed['operation'] = 'actualizar';
            $processed['http_method'] = 'PUT';
            $processed['status'] = 'success';
            $processed['tags'][] = 'update_success';
        }

        // === ANÁLISIS DE PACIENTES ===
        elseif (strpos($message, 'Paciente creado') !== false) {
            $processed['type'] = 'paciente';
            $processed['category'] = 'medical';
            $processed['operation'] = 'crear';
            $processed['http_method'] = 'POST';
            $processed['endpoint'] = '/api/pacientes';
            $processed['status'] = 'success';
            $processed['priority'] = 'high';
            $processed['tags'][] = 'creation_success';
        }
        elseif (strpos($message, 'Paciente actualizado') !== false) {
            $processed['type'] = 'paciente';
            $processed['category'] = 'medical';
            $processed['operation'] = 'actualizar';
            $processed['http_method'] = 'PUT';
            $processed['status'] = 'success';
            $processed['tags'][] = 'update_success';
        }

        // === ANÁLISIS DE AUTENTICACIÓN ===
        elseif (strpos($message, 'Usuario autenticado') !== false || strpos($message, 'Login successful') !== false) {
            $processed['type'] = 'auth';
            $processed['category'] = 'security';
            $processed['operation'] = 'login';
            $processed['http_method'] = 'POST';
            $processed['endpoint'] = '/api/auth/login';
            $processed['status'] = 'success';
            $processed['tags'][] = 'authentication';
        }
        elseif (strpos($message, 'Logout') !== false || strpos($message, 'Usuario desconectado') !== false) {
            $processed['type'] = 'auth';
            $processed['category'] = 'security';
            $processed['operation'] = 'logout';
            $processed['http_method'] = 'POST';
            $processed['endpoint'] = '/api/auth/logout';
            $processed['status'] = 'success';
            $processed['tags'][] = 'authentication';
        }
        elseif (strpos($message, 'Token inválido') !== false || strpos($message, 'Unauthorized') !== false) {
            $processed['type'] = 'auth';
            $processed['category'] = 'security';
            $processed['operation'] = 'token_validation';
            $processed['status'] = 'error';
            $processed['priority'] = 'high';
            $processed['tags'][] = 'authentication_error';
        }

        // === ANÁLISIS DE ERRORES ESPECÍFICOS ===
        if ($processed['level'] === 'ERROR') {
            $processed['status'] = 'error';
            $processed['priority'] = $processed['priority'] ?? 'high';
            
            // Error de duplicado
            if (strpos($fullContent, 'Duplicate entry') !== false) {
                $processed['error_details'] = array_merge(
                    $processed['error_details'] ?? [],
                    [
                        'type' => 'duplicate_entry',
                        'description' => 'Intento de insertar un registro duplicado'
                    ]
                );
                $processed['tags'][] = 'duplicate_error';
                
                // Extraer el ID duplicado
                if (preg_match("/Duplicate entry '([^']+)'/", $fullContent, $matches)) {
                    $processed['entity_id'] = $matches[1];
                }
            }
            
            // Error de constraint de integridad
            if (strpos($fullContent, 'Integrity constraint violation') !== false) {
                $processed['error_details']['constraint'] = 'integrity_violation';
                $processed['tags'][] = 'constraint_error';
            }
            
            // Error de validación
            if (strpos($fullContent, 'validation') !== false || strpos($fullContent, 'required') !== false) {
                $processed['tags'][] = 'validation_error';
                $processed['category'] = 'validation';
            }
        }

        // === EXTRAER IDs ADICIONALES ===
        // Buscar UUIDs en el mensaje si no se ha encontrado ningún ID
        if (!$processed['entity_id'] && preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', $message, $matches)) {
            $processed['entity_id'] = $matches[0];
        }
        
        // Buscar IDs numéricos
        if (!$processed['entity_id'] && preg_match('/"id":(\d+)/', $message, $matches)) {
            $processed['entity_id'] = $matches[1];
        }
    }

    private function extractHttpInfo(&$processed, $message, $fullContent)
    {
        // Detectar método HTTP en el mensaje
        if (preg_match('/\b(GET|POST|PUT|PATCH|DELETE)\b/', $message, $matches)) {
            $processed['http_method'] = $matches[1];
        }
        
        // Detectar endpoint/ruta
        if (preg_match('/\/api\/[^\s"\']+/', $message, $matches)) {
            $processed['endpoint'] = $matches[0];
        }
        
        // Detectar duración de request
        if (preg_match('/(\d+)ms/', $message, $matches)) {
            $processed['duration'] = (int)$matches[1];
        }
    }

    private function extractUserInfo(&$processed, $message, $fullContent)
    {
        // Extraer user_id
        if (preg_match('/"user_id":"?([^",}]+)"?/', $message, $matches)) {
            $processed['user_id'] = $matches[1];
        }
        
        // Extraer IP
        if (preg_match('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', $message, $matches)) {
            $processed['ip_address'] = $matches[0];
        }
        
        // Extraer User-Agent (simplificado)
        if (preg_match('/"user_agent":"([^"]+)"/', $message, $matches)) {
            $processed['user_agent'] = $matches[1];
        }
    }

    private function extractRequestData(&$processed, $message)
    {
        // Extraer datos JSON de la request
        if (preg_match('/\{.*\}/', $message, $matches)) {
            $jsonData = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $processed['request_data'] = $jsonData;
            }
        }
    }

    private function extractResponseData(&$processed, $message)
    {
        // Extraer datos JSON de la response
        if (preg_match('/\{.*\}/', $message, $matches)) {
            $jsonData = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $processed['response_data'] = $jsonData;
            }
        }
    }

    private function determinePriority($level, $message)
    {
        if ($level === 'ERROR') {
            return 'critical';
        }
        
        if (strpos($message, 'creada') !== false || 
            strpos($message, 'creado') !== false ||
            strpos($message, 'actualizada') !== false || 
            strpos($message, 'actualizado') !== false) {
            return 'high';
        }
        
        if (strpos($message, 'DEBUG') !== false) {
            return 'low';
        }
        
        return 'medium';
    }

    private function determineStatus($level, $message)
    {
        if ($level === 'ERROR') {
            return 'error';
        }
        
        if (strpos($message, 'creada') !== false || 
            strpos($message, 'creado') !== false ||
            strpos($message, 'actualizada') !== false || 
            strpos($message, 'actualizado') !== false ||
            strpos($message, 'subida') !== false ||
            strpos($message, 'guardado') !== false) {
            return 'success';
        }
        
        if (strpos($message, 'RECIBIENDO') !== false || 
            strpos($message, 'DEBUG') !== false || 
            strpos($message, 'procesando') !== false ||
            strpos($message, 'ACTUALIZANDO') !== false) {
            return 'processing';
        }
        
        return $level === 'INFO' ? 'success' : 'processing';
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

