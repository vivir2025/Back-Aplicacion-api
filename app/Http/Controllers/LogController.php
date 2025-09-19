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
            'operation' => null,
            'status' => $this->determineStatus($logEntry['level'], $logEntry['message']),
            'entity_id' => null,
            'entity_type' => null,
            'error_details' => null,
            'full_content' => $logEntry['message'] . "\n" . implode("\n", $logEntry['additional_lines']),
            'line_number' => $logEntry['line_number'] ?? null,
            'has_stack_trace' => !empty($logEntry['additional_lines'])
        ];

        // Analizar el contenido del mensaje
        $this->analyzeLogContent($processed, $logEntry);

        return $processed;
    }

    private function analyzeLogContent(&$processed, $logEntry)
    {
        $message = $logEntry['message'];
        $fullContent = $processed['full_content'];

        // === ANÁLISIS DE VISITAS ===
        if (strpos($message, 'RECIBIENDO DATOS DE VISITA') !== false) {
            $processed['type'] = 'visita';
            $processed['operation'] = 'recibir_datos';
            $processed['status'] = 'processing';
        } 
        elseif (strpos($message, 'Visita creada') !== false) {
            $processed['type'] = 'visita';
            $processed['operation'] = 'crear';
            $processed['status'] = 'success';
            
            // Extraer ID de la visita
            if (preg_match('/"id":"([^"]+)"/', $message, $matches)) {
                $processed['entity_id'] = $matches[1];
                $processed['entity_type'] = 'visita';
            }
        }
        elseif (strpos($message, 'Error al crear visita') !== false) {
            $processed['type'] = 'visita';
            $processed['operation'] = 'crear';
            $processed['status'] = 'error';
            $processed['error_details'] = $this->extractErrorDetails($fullContent);
        }
        elseif (strpos($message, 'ACTUALIZANDO VISITA') !== false) {
            $processed['type'] = 'visita';
            $processed['operation'] = 'actualizar';
            $processed['status'] = 'processing';
            
            // Extraer ID de la visita
            if (preg_match('/"visita_id":"([^"]+)"/', $message, $matches)) {
                $processed['entity_id'] = $matches[1];
                $processed['entity_type'] = 'visita';
            }
        }
        elseif (strpos($message, 'Error al actualizar visita') !== false) {
            $processed['type'] = 'visita';
            $processed['operation'] = 'actualizar';
            $processed['status'] = 'error';
            $processed['error_details'] = $this->extractErrorDetails($fullContent);
            
            // Buscar ID en el contenido completo
            if (preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', $fullContent, $matches)) {
                $processed['entity_id'] = $matches[0];
                $processed['entity_type'] = 'visita';
            }
        }
        elseif (strpos($message, 'Datos finales para crear visita') !== false) {
            $processed['type'] = 'visita';
            $processed['operation'] = 'preparar_datos';
            $processed['status'] = 'processing';
            
            // Extraer ID de la visita
            if (preg_match('/"id":"([^"]+)"/', $message, $matches)) {
                $processed['entity_id'] = $matches[1];
                $processed['entity_type'] = 'visita';
            }
        }
        elseif (strpos($message, 'Todos los campos') !== false) {
            $processed['type'] = 'visita';
            $processed['operation'] = 'validar_campos';
            $processed['status'] = 'processing';
        }
        elseif (strpos($message, 'Form Data completo') !== false) {
            $processed['type'] = 'visita';
            $processed['operation'] = 'procesar_formulario';
            $processed['status'] = 'processing';
        }
        elseif (strpos($message, 'Firma subida') !== false) {
            $processed['type'] = 'visita';
            $processed['operation'] = 'subir_firma';
            $processed['status'] = 'success';
        }

        // === ANÁLISIS DE COORDENADAS ===
        elseif (strpos($message, 'Coordenadas del paciente actualizadas') !== false) {
            $processed['type'] = 'paciente';
            $processed['operation'] = 'actualizar_coordenadas';
            $processed['status'] = 'success';
            
            // Extraer ID del paciente
            if (preg_match('/"paciente_id":"([^"]+)"/', $message, $matches)) {
                $processed['entity_id'] = $matches[1];
                $processed['entity_type'] = 'paciente';
            }
        }
        elseif (strpos($message, 'DEBUG COORDENADAS') !== false) {
            $processed['type'] = 'debug';
            $processed['operation'] = 'coordenadas';
            $processed['status'] = 'processing';
        }
        elseif (strpos($message, 'Coordenadas finales') !== false) {
            $processed['type'] = 'visita';
            $processed['operation'] = 'coordenadas_finales';
            $processed['status'] = 'processing';
        }

        // === ANÁLISIS DE BRIGADAS ===
        elseif (strpos($message, 'Datos recibidos para crear brigada') !== false) {
            $processed['type'] = 'brigada';
            $processed['operation'] = 'crear';
            $processed['status'] = 'processing';
        } 
        elseif (strpos($message, 'Brigada creada') !== false) {
            $processed['type'] = 'brigada';
            $processed['operation'] = 'crear';
            $processed['status'] = 'success';
            
            if (preg_match('/"id":"([^"]+)"/', $message, $matches)) {
                $processed['entity_id'] = $matches[1];
                $processed['entity_type'] = 'brigada';
            }
        }

        // === ANÁLISIS DE MEDICAMENTOS ===
        elseif (strpos($message, 'Medicamento guardado') !== false) {
            $processed['type'] = 'medicamento';
            $processed['operation'] = 'asignar';
            $processed['status'] = 'success';
        }

        // === ANÁLISIS DE ERRORES ESPECÍFICOS ===
        if ($processed['level'] === 'ERROR') {
            $processed['status'] = 'error';
            
            // Error de duplicado (como en tu ejemplo)
            if (strpos($fullContent, 'Duplicate entry') !== false) {
                $processed['error_details'] = array_merge(
                    $processed['error_details'] ?? [],
                    [
                        'type' => 'duplicate_entry',
                        'description' => 'Intento de insertar un registro duplicado'
                    ]
                );
                
                // Extraer el ID duplicado
                if (preg_match("/Duplicate entry '([^']+)'/", $fullContent, $matches)) {
                    $processed['entity_id'] = $matches[1];
                }
            }
            
            // Error de constraint de integridad
            if (strpos($fullContent, 'Integrity constraint violation') !== false) {
                $processed['error_details']['constraint'] = 'integrity_violation';
            }
        }

        // === EXTRAER IDs ADICIONALES ===
        // Buscar UUIDs en el mensaje si no se ha encontrado ningún ID
        if (!$processed['entity_id'] && preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', $message, $matches)) {
            $processed['entity_id'] = $matches[0];
        }
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
                   strpos(strtolower($log['entity_id'] ?? ''), $search) !== false;
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
        $statuses = array_unique(array_column($logs, 'status'));
        $operations = array_unique(array_filter(array_column($logs, 'operation')));
        
        return [
            'types' => array_values($types),
            'statuses' => array_values($statuses),
            'operations' => array_values($operations)
        ];
    }

   public function show($id, Request $request)
{
    $logPath = storage_path('logs/laravel.log');
    
    if (!File::exists($logPath)) {
        return response()->json([
            'success' => false,
            'message' => 'Archivo de log no encontrado'
        ], 404);
    }

    $logs = $this->parseLogs($logPath, $request);
    $log = null;
    
    // Buscar el log por ID
    foreach ($logs['data'] as $logItem) {
        if ($logItem['id'] === $id) {
            $log = $logItem;
            break;
        }
    }
    
    if (!$log) {
        return response()->json([
            'success' => false,
            'message' => 'Log no encontrado'
        ], 404);
    }
    
    return response()->json([
        'success' => true,
        'data' => $log
    ]);
}


   public function stats()
{
    $logPath = storage_path('logs/laravel.log');
    
    if (!File::exists($logPath)) {
        return response()->json([
            'success' => false,
            'message' => 'Archivo de log no encontrado'
        ], 404);
    }

    $logs = $this->parseLogs($logPath, request());
    $data = $logs['data'];
    
    $stats = [
        'total' => count($data),
        'by_status' => [
            'success' => count(array_filter($data, function($log) {
                return $log['status'] === 'success';
            })),
            'error' => count(array_filter($data, function($log) {
                return $log['status'] === 'error';
            })),
            'processing' => count(array_filter($data, function($log) {
                return $log['status'] === 'processing';
            })),
        ],
        'by_type' => [],
        'recent_errors' => array_slice(
            array_filter($data, function($log) {
                return $log['status'] === 'error';
            }),
            0,
            10
        ),
        'today_activity' => count(array_filter($data, function($log) {
            return Carbon::parse($log['timestamp'])->isToday();
        }))
    ];
    
    // Contar por tipo
    foreach ($data as $log) {
        $type = $log['type'];
        if (!isset($stats['by_type'][$type])) {
            $stats['by_type'][$type] = 0;
        }
        $stats['by_type'][$type]++;
    }
    
    return response()->json([
        'success' => true,
        'data' => $stats
    ]);
}

}
