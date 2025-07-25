<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Conexión a la base de datos principal con manejo de errores
try {
    $mysqli = new mysqli("localhost", "root", "q1w2e3r4!!.2464", "asteriskcdrdb");
    
    if ($mysqli->connect_error) {
        throw new Exception("Error de conexión: " . $mysqli->connect_error);
    }
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// Conexión a la base de datos callerid
try {
    $mysqli_callerid = new mysqli("localhost", "root", "q1w2e3r4!!.2464", "callerid");
    
    if ($mysqli_callerid->connect_error) {
        throw new Exception("Error de conexión a callerid: " . $mysqli_callerid->connect_error);
    }
} catch (Exception $e) {
    die("Error callerid: " . $e->getMessage());
}

// Función para obtener información del caller desde la base de datos callerid
function getCallerInfo($origen) {
    global $mysqli_callerid;
    
    $callerInfo = array('nombre' => '', 'apellido' => '', 'grupo' => '');
    
    if (empty($origen)) {
        return $callerInfo;
    }
    
    try {
        // Preparar la consulta para hacer match del origen con el campo numero en la tabla clientes
        $stmt = $mysqli_callerid->prepare("SELECT nombre, apellido, grupo FROM clientes WHERE numero = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $origen);
            $stmt->execute();
            
            // Usar bind_result en lugar de get_result para compatibilidad
            $stmt->bind_result($nombre, $apellido, $grupo);
            
            if ($stmt->fetch()) {
                $callerInfo['nombre'] = isset($nombre) ? $nombre : '';
                $callerInfo['apellido'] = isset($apellido) ? $apellido : '';
                $callerInfo['grupo'] = isset($grupo) ? $grupo : '';
            }
            
            $stmt->close();
        }
    } catch (Exception $e) {
        error_log("Error al obtener información del caller: " . $e->getMessage());
    }
    
    return $callerInfo;
}

// Definir fechas con valores predeterminados seguros
$fecha_inicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : date("Y-m-d", strtotime("-7 days"));
$fecha_fin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : date("Y-m-d");

// Validar formato de fechas
if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $fecha_inicio) || !preg_match("/^\d{4}-\d{2}-\d{2}$/", $fecha_fin)) {
    $fecha_inicio = date("Y-m-d", strtotime("-7 days"));
    $fecha_fin = date("Y-m-d");
}

// Configuración de paginación
$registros_por_pagina = 25;
$pagina_actual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
if ($pagina_actual < 1) $pagina_actual = 1;
$offset = ($pagina_actual - 1) * $registros_por_pagina;

// Directorio base para las grabaciones
$recording_base_dir = "/var/spool/asterisk/monitor/";

// Función para encontrar la ruta real del archivo
function findRecordingFile($filename) {
    global $recording_base_dir;
    
    if (empty($filename)) return false;
    
    // Lista de posibles ubicaciones para buscar el archivo
    $possiblePaths = [
        $recording_base_dir . $filename,                    // Ruta directa
        $recording_base_dir . basename($filename),          // Solo el nombre del archivo
        $recording_base_dir . date("Y/m/d/") . $filename,   // Con estructura de carpetas por fecha
        $recording_base_dir . date("Y-m-d/") . $filename,   // Formato alternativo de fecha
        $recording_base_dir . "out/" . $filename,           // Subcarpeta "out"
        $recording_base_dir . "in/" . $filename,           // Subcarpeta "in"
        $recording_base_dir . "../monitor/" . $filename,    // Un nivel arriba
        "/var/spool/asterisk/monitor/" . $filename,         // Ruta absoluta
        "/var/spool/asterisk/monitor/out/" . $filename,     // Ruta absoluta con subcarpeta
        "/var/spool/asterisk/monitor/in/" . $filename,      // Ruta absoluta con subcarpeta
    ];
    
    // Buscar en todas las posibles ubicaciones
    foreach ($possiblePaths as $path) {
        if (file_exists($path)) {
            return $path;
        }
    }
    
    // Buscar por coincidencia parcial (solo el nombre del archivo sin la ruta)
    $baseFilename = basename($filename);
    if (is_dir($recording_base_dir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($recording_base_dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() === $baseFilename) {
                return $file->getPathname();
            }
        }
    }
    
    return false;
}

// Función para servir el archivo de audio
if (isset($_GET['play']) || isset($_GET['download'])) {
    $file = isset($_GET['play']) ? $_GET['play'] : $_GET['download'];
    
    // Validar el nombre del archivo para evitar directory traversal
    if (preg_match('/^[a-zA-Z0-9_\-\.\/]+$/', $file)) {
        // Intentar encontrar la ruta real del archivo
        $filePath = findRecordingFile($file);
        
        if ($filePath && file_exists($filePath)) {
            // Establecer las cabeceras adecuadas
            header('Content-Type: audio/wav');
            header('Content-Length: ' . filesize($filePath));
            
            if (isset($_GET['download'])) {
                // Extraer el nombre del archivo sin la ruta
                $fileName = basename($file);
                header('Content-Disposition: attachment; filename="' . $fileName . '"');
            } else {
                header('Content-Disposition: inline');
            }
            
            // Leer y enviar el archivo
            readfile($filePath);
            exit;
        } else {
            // Registrar error para depuración
            error_log("Archivo no encontrado en ninguna ubicación: " . $file);
            
            // Intentar crear un enlace simbólico para acceso directo
            $directAccessPath = "audio_access/" . basename($file);
            if (!is_dir("audio_access")) {
                mkdir("audio_access", 0755, true);
            }
            
            // Crear un script PHP para acceso directo
            $accessScript = "audio_access/" . basename($file) . ".php";
            file_put_contents($accessScript, '<?php
                header("Content-Type: audio/wav");
                readfile("/var/spool/asterisk/monitor/' . $file . '");
            ?>');
            chmod($accessScript, 0755);
            
            // Redirigir al script de acceso directo
            header("Location: " . $accessScript);
            exit;
        }
    }
    
    // Si llegamos aquí, el archivo no existe o no es válido
    header("HTTP/1.0 404 Not Found");
    echo "Archivo no encontrado";
    exit;
}

// Consulta principal con prepared statements
try {
    // Filtro por tipo de llamada
    $tipo_llamada = isset($_GET['tipo_llamada']) ? $_GET['tipo_llamada'] : 'todas';
    $tipo_llamada_sql = '';
    // Añadir este código:
    $estado_llamada = isset($_GET['estado_llamada']) ? $_GET['estado_llamada'] : 'todas';
    $estado_llamada_sql = '';
    
    // Ajustar la consulta según el estado de la llamada
    if ($estado_llamada === 'contestadas') {
        $estado_llamada_sql = " AND disposition = 'ANSWERED' ";
    } elseif ($estado_llamada === 'no_contestadas') {
        $estado_llamada_sql = " AND disposition != 'ANSWERED' ";
    }
    
    // Ajustar la consulta según el tipo de llamada
    if ($tipo_llamada === 'entrante') {
        $tipo_llamada_sql = " AND dcontext IN ('from-trunk', 'ext-did', 'from-pstn') ";
    } elseif ($tipo_llamada === 'saliente') {
        $tipo_llamada_sql = " AND dcontext = 'from-internal' ";
    }
    
    // Primero, contar total de registros
    $count_sql = "
        SELECT COUNT(*) 
        FROM cdr 
        WHERE DATE(calldate) BETWEEN ? AND ? $tipo_llamada_sql $estado_llamada_sql
    ";
    $count_query = $mysqli->prepare($count_sql);
    $count_query->bind_param("ss", $fecha_inicio, $fecha_fin);
    $count_query->execute();
    $count_query->bind_result($total_records);
    $count_query->fetch();
    $count_query->close();
    
    // Calcular paginación
    $total_paginas = ceil($total_records / $registros_por_pagina);
    if ($pagina_actual > $total_paginas && $total_paginas > 0) {
        $pagina_actual = $total_paginas;
        $offset = ($pagina_actual - 1) * $registros_por_pagina;
    }
    
    // Consulta principal
    $query_sql = "
        SELECT calldate, src, dst, duration, disposition, recordingfile, dcontext 
        FROM cdr 
        WHERE DATE(calldate) BETWEEN ? AND ? $tipo_llamada_sql $estado_llamada_sql
        ORDER BY calldate DESC
        LIMIT ?, ?
    ";
    $query = $mysqli->prepare($query_sql);
    $query->bind_param("ssii", $fecha_inicio, $fecha_fin, $offset, $registros_por_pagina);
    $query->execute();
    $query->bind_result($calldate, $src, $dst, $duration, $disposition, $recordingfile, $dcontext);
    
    // Armar resultados con información del caller
    $results = array();
    while ($query->fetch()) {
        $realPath = !empty($recordingfile) ? findRecordingFile($recordingfile) : false;
        
        $is_incoming = in_array($dcontext, ['from-trunk', 'ext-did', 'from-pstn']);
        $is_outgoing = $dcontext === 'from-internal';
        
        // Obtener información del caller usando el campo src como origen
        $callerInfo = getCallerInfo($src);
        
        $results[] = array(
            'calldate' => $calldate,
            'src' => $src,
            'dst' => $dst,
            'duration' => $duration,
            'disposition' => $disposition,
            'recordingfile' => $recordingfile,
            'realPath' => $realPath,
            'is_incoming' => $is_incoming,
            'is_outgoing' => $is_outgoing,
            'caller_nombre' => $callerInfo['nombre'],
            'caller_apellido' => $callerInfo['apellido'],
            'caller_grupo' => $callerInfo['grupo'],
            'caller_full_name' => trim($callerInfo['nombre'] . ' ' . $callerInfo['apellido'])
        );
    }
    $query->close();
    
    // Consulta para obtener datos por día para el gráfico
    $chart_query = $mysqli->prepare("
        SELECT 
            DATE(calldate) as call_date,
            COUNT(*) as total_calls,
            SUM(CASE WHEN disposition = 'ANSWERED' THEN 1 ELSE 0 END) as answered_calls,
            SUM(CASE WHEN disposition != 'ANSWERED' THEN 1 ELSE 0 END) as missed_calls
        FROM cdr 
        WHERE DATE(calldate) BETWEEN ? AND ? $tipo_llamada_sql $estado_llamada_sql
        GROUP BY DATE(calldate)
        ORDER BY DATE(calldate)
    ");
    
    if (!$chart_query) {
        throw new Exception("Error en la preparación de la consulta del gráfico: " . $mysqli->error);
    }
    
    $chart_query->bind_param("ss", $fecha_inicio, $fecha_fin);
    $chart_query->execute();
    $chart_query->bind_result($call_date, $total_calls, $answered_calls, $missed_calls);
    
    $chart_labels = array();
    $chart_answered = array();
    $chart_missed = array();
    
    while ($chart_query->fetch()) {
        $chart_labels[] = date('d/m', strtotime($call_date));
        $chart_answered[] = $answered_calls;
        $chart_missed[] = $missed_calls;
    }
    
    $chart_query->close();
    
    // Consulta para obtener datos de duración promedio por día
    $duration_query = $mysqli->prepare("
        SELECT 
            DATE(calldate) as call_date,
            AVG(duration) as avg_duration
        FROM cdr 
        WHERE DATE(calldate) BETWEEN ? AND ? AND disposition = 'ANSWERED' $tipo_llamada_sql $estado_llamada_sql
        GROUP BY DATE(calldate)
        ORDER BY DATE(calldate)
    ");
    
    if (!$duration_query) {
        throw new Exception("Error en la preparación de la consulta de duración: " . $mysqli->error);
    }
    
    $duration_query->bind_param("ss", $fecha_inicio, $fecha_fin);
    $duration_query->execute();
    $duration_query->bind_result($duration_date, $avg_duration);
    
    $duration_data = array();
    
    while ($duration_query->fetch()) {
        $duration_data[] = round($avg_duration / 60, 1); // Convertir a minutos
    }
    
    $duration_query->close();
    
    // Consulta para obtener distribución por hora
    $hourly_query = $mysqli->prepare("
        SELECT 
            HOUR(calldate) as call_hour,
            COUNT(*) as total_calls
        FROM cdr 
        WHERE DATE(calldate) BETWEEN ? AND ? $tipo_llamada_sql $estado_llamada_sql
        GROUP BY HOUR(calldate)
        ORDER BY HOUR(calldate)
    ");
    
    if (!$hourly_query) {
        throw new Exception("Error en la preparación de la consulta por hora: " . $mysqli->error);
    }
    
    $hourly_query->bind_param("ss", $fecha_inicio, $fecha_fin);
    $hourly_query->execute();
    $hourly_query->bind_result($call_hour, $hourly_total_calls);
    
    $hourly_labels = array();
    $hourly_data = array();
    
    // Inicializar array con ceros para todas las horas
    for ($i = 0; $i < 24; $i++) {
        $hourly_labels[] = sprintf("%02d:00", $i);
        $hourly_data[$i] = 0;
    }
    
    while ($hourly_query->fetch()) {
        $hourly_data[$call_hour] = $hourly_total_calls;
    }
    
    $hourly_query->close();
    
    // Consulta para obtener estadísticas básicas para todos los registros (no solo la página actual)
    $stats_query = $mysqli->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN disposition = 'ANSWERED' THEN 1 ELSE 0 END) as answered,
            SUM(CASE WHEN disposition != 'ANSWERED' THEN 1 ELSE 0 END) as missed,
            SUM(CASE WHEN disposition = 'ANSWERED' THEN duration ELSE 0 END) as total_duration,
            MAX(CASE WHEN disposition = 'ANSWERED' THEN duration ELSE 0 END) as max_duration,
            MIN(CASE WHEN disposition = 'ANSWERED' AND duration > 0 THEN duration ELSE NULL END) as min_duration,
            SUM(CASE WHEN dcontext IN ('from-trunk', 'ext-did', 'from-pstn') THEN 1 ELSE 0 END) as incoming,
            SUM(CASE WHEN dcontext = 'from-internal' THEN 1 ELSE 0 END) as outgoing
        FROM cdr 
        WHERE DATE(calldate) BETWEEN ? AND ? $tipo_llamada_sql $estado_llamada_sql
    ");
    
    if (!$stats_query) {
        throw new Exception("Error en la preparación de la consulta de estadísticas: " . $mysqli->error);
    }
    
    $stats_query->bind_param("ss", $fecha_inicio, $fecha_fin);
    $stats_query->execute();
    $stats_query->bind_result(
        $stats_total, 
        $stats_answered, 
        $stats_missed, 
        $stats_total_duration, 
        $stats_max_duration, 
        $stats_min_duration, 
        $stats_incoming, 
        $stats_outgoing
    );
    $stats_query->fetch();
    $stats_query->close();
    
    $stats = array(
        'total' => $stats_total,
        'answered' => $stats_answered,
        'missed' => $stats_missed,
        'total_duration' => $stats_total_duration,
        'max_duration' => $stats_max_duration ? $stats_max_duration : 0,
        'min_duration' => $stats_min_duration ? $stats_min_duration : 0,
        'incoming' => $stats_incoming,
        'outgoing' => $stats_outgoing,
        'avg_duration' => $stats_answered > 0 ? $stats_total_duration / $stats_total : 0,
        'avg_duration_answered' => $stats_answered > 0 ? $stats_total_duration / $stats_answered : 0,
        'answer_rate' => $stats_total > 0 ? ($stats_answered / $stats_total) * 100 : 0
    );
    
} catch (Exception $e) {
    die("Error en la consulta: " . $e->getMessage());
}

// Función para formatear la duración
function formatDuration($seconds) {
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    
    if ($hours > 0) {
        return sprintf("%02d:%02d:%02d", $hours, $minutes, $secs);
    } else {
        return sprintf("%02d:%02d", $minutes, $secs);
    }
}

// Función para obtener el tamaño del archivo en formato legible
function getFileSize($filePath) {
    if (empty($filePath) || !file_exists($filePath)) return "0 KB";
    
    $bytes = filesize($filePath);
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, 2) . ' ' . $units[$pow];
}

// Función para generar enlaces de paginación con parámetros actuales
function generarEnlacePaginacion($pagina, $texto = null) {
    global $fecha_inicio, $fecha_fin, $tipo_llamada, $estado_llamada;
    if ($texto === null) $texto = $pagina;
    $params = array(
        'pagina' => $pagina,
        'fecha_inicio' => $fecha_inicio,
        'fecha_fin' => $fecha_fin,
        'tipo_llamada' => $tipo_llamada,
        'estado_llamada' => $estado_llamada
    );
    foreach ($_GET as $key => $value) {
        if (!in_array($key, array('pagina', 'fecha_inicio', 'fecha_fin', 'play', 'download', 'tipo_llamada', 'estado_llamada'))) {
            $params[$key] = $value;
        }
    }
    return '?' . http_build_query($params);
}

// Crear un script para acceso directo a los archivos
$accessScript = "direct_access.php";
file_put_contents($accessScript, '<?php
    if (isset($_GET["file"])) {
        $file = $_GET["file"];
        if (preg_match(\'/^[a-zA-Z0-9_\\-\\.\\s\\/]+$/\', $file)) {
            $filePath = "/var/spool/asterisk/monitor/" . $file;
            if (file_exists($filePath)) {
                header("Content-Type: audio/wav");
                header("Content-Length: " . filesize($filePath));
                if (isset($_GET["download"])) {
                    header("Content-Disposition: attachment; filename=\"" . basename($file) . "\"");
                } else {
                    header("Content-Disposition: inline");
                }
                readfile($filePath);
                exit;
            }
        }
    }
    header("HTTP/1.0 404 Not Found");
    echo "Archivo no encontrado";
?>');
chmod($accessScript, 0755);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CRM Telefónico - Cybermatica</title>
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Plyr para reproductor de audio mejorado -->
    <link rel="stylesheet" href="https://cdn.plyr.io/3.7.8/plyr.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        :root {
            /* Paleta de colores profesional */
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --primary-light: #3b82f6;
            --secondary: #64748b;
            --accent: #06b6d4;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #0ea5e9;
            
            /* Grises profesionales */
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-400: #94a3b8;
            --gray-500: #64748b;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1e293b;
            --gray-900: #0f172a;
            
            /* Efectos y sombras */
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
            --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
            
            /* Bordes y radios */
            --radius-sm: 0.375rem;
            --radius: 0.5rem;
            --radius-md: 0.75rem;
            --radius-lg: 1rem;
            --radius-xl: 1.5rem;
            
            /* Transiciones */
            --transition: all 0.15s ease-in-out;
            --transition-slow: all 0.3s ease-in-out;
        }
        
        * {
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: var(--gray-50);
            color: var(--gray-900);
            line-height: 1.6;
            font-size: 14px;
            margin: 0;
            padding: 0;
            min-height: 100vh;
        }
        
        /* Header profesional */
        .crm-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 1rem 0;
            box-shadow: var(--shadow-lg);
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        
        .crm-header .container-fluid {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 1.5rem;
        }
        
        .crm-brand {
            display: flex;
            align-items: center;
            font-weight: 700;
            font-size: 1.25rem;
            text-decoration: none;
            color: white;
        }
        
        .crm-brand:hover {
            color: white;
            opacity: 0.9;
        }
        
        .crm-brand i {
            margin-right: 0.75rem;
            font-size: 1.5rem;
        }
        
        .crm-nav {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .crm-nav-item {
            padding: 0.5rem 1rem;
            border-radius: var(--radius);
            background: rgba(255, 255, 255, 0.1);
            color: white;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.875rem;
            transition: var(--transition);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .crm-nav-item:hover {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            transform: translateY(-1px);
        }
        
        /* Container principal */
        .crm-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem 1.5rem;
        }
        
        /* Título de sección */
        .section-header {
            margin-bottom: 2rem;
        }
        
        .section-title {
            font-size: 1.875rem;
            font-weight: 800;
            color: var(--gray-900);
            margin: 0 0 0.5rem 0;
            letter-spacing: -0.025em;
        }
        
        .section-subtitle {
            color: var(--gray-600);
            font-size: 1rem;
            margin: 0;
        }
        
        /* Cards profesionales */
        .crm-card {
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray-200);
            transition: var(--transition-slow);
            overflow: hidden;
        }
        
        .crm-card:hover {
            box-shadow: var(--shadow-lg);
            transform: translateY(-2px);
        }
        
        .crm-card-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            background: var(--gray-50);
        }
        
        .crm-card-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--gray-900);
            margin: 0;
            display: flex;
            align-items: center;
        }
        
        .crm-card-title i {
            margin-right: 0.75rem;
            color: var(--primary);
            font-size: 1.25rem;
        }
        
        .crm-card-body {
            padding: 1.5rem;
        }
        
        /* Filtros mejorados */
        .filters-card {
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray-200);
            margin-bottom: 2rem;
        }
        
        .filters-header {
            padding: 1.5rem 1.5rem 0 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            margin-bottom: 1.5rem;
        }
        
        .filters-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--gray-900);
            margin: 0 0 1rem 0;
            display: flex;
            align-items: center;
        }
        
        .filters-title i {
            margin-right: 0.75rem;
            color: var(--primary);
        }
        
        .filters-body {
            padding: 0 1.5rem 1.5rem 1.5rem;
        }
        
        /* Form controls mejorados */
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-label {
            display: block;
            font-weight: 500;
            color: var(--gray-700);
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
        }
        
        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--gray-300);
            border-radius: var(--radius);
            font-size: 0.875rem;
            transition: var(--transition);
            background: white;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        
        .form-select {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='m6 8 4 4 4-4'/%3e%3c/svg%3e");
            background-position: right 0.75rem center;
            background-repeat: no-repeat;
            background-size: 1.5em 1.5em;
            padding-right: 2.5rem;
        }
        
        /* Botones profesionales */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.75rem 1.5rem;
            border-radius: var(--radius);
            font-weight: 500;
            font-size: 0.875rem;
            text-decoration: none;
            border: 1px solid transparent;
            cursor: pointer;
            transition: var(--transition);
            line-height: 1;
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
            color: white;
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }
        
        .btn-secondary {
            background: var(--gray-100);
            color: var(--gray-700);
            border-color: var(--gray-300);
        }
        
        .btn-secondary:hover {
            background: var(--gray-200);
            color: var(--gray-800);
        }
        
        .btn i {
            margin-right: 0.5rem;
        }
        
        /* Pills de filtro */
        .filter-pills {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }

        .filter-pill {
            padding: 0.5rem 1rem;
            border-radius: var(--radius-xl);
            background: var(--gray-100);
            color: var(--gray-700);
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            border: 1px solid var(--gray-200);
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
        }
        
        .filter-pill:hover,
        .filter-pill.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
            transform: translateY(-1px);
        }
        
        /* KPI Cards */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .kpi-card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--gray-200);
            position: relative;
            overflow: hidden;
            transition: var(--transition-slow);
        }
        
        .kpi-card:hover {
            box-shadow: var(--shadow-lg);
            transform: translateY(-2px);
        }
        
        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
        }
        
        .kpi-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
        }
        
        .kpi-title {
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--gray-600);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .kpi-icon {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: var(--radius);
            background: linear-gradient(135deg, var(--primary), var(--accent));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.25rem;
        }
        
        .kpi-value {
            font-size: 2.25rem;
            font-weight: 800;
            color: var(--gray-900);
            line-height: 1;
            margin-bottom: 0.5rem;
        }
        
        .kpi-subtitle {
            font-size: 0.875rem;
            color: var(--gray-500);
            display: flex;
            align-items: center;
        }
        
        .kpi-subtitle i {
            margin-right: 0.5rem;
            color: var(--success);
        }
        
        /* Gráficos */
        .charts-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .chart-card {
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray-200);
            overflow: hidden;
        }
        
        .chart-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            background: var(--gray-50);
        }
        
        .chart-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--gray-900);
            margin: 0;
            display: flex;
            align-items: center;
        }
        
        .chart-title i {
            margin-right: 0.75rem;
            color: var(--primary);
            font-size: 1.25rem;
        }
        
        .chart-body {
            padding: 1.5rem;
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }
        
        /* Tabla profesional */
        .table-card {
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray-200);
            overflow: hidden;
        }
        
        .table-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            background: var(--gray-50);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .table-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--gray-900);
            margin: 0;
            display: flex;
            align-items: center;
        }
        
        .table-title i {
            margin-right: 0.75rem;
            color: var(--primary);
        }
        
        .table-badge {
            background: var(--primary);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: var(--radius-xl);
            font-size: 0.875rem;
            font-weight: 500;
        }
        
        .table-container {
            overflow-x: auto;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table th {
            background: var(--gray-50);
            color: var(--gray-700);
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--gray-200);
            white-space: nowrap;
        }
        
        .data-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--gray-100);
            font-size: 0.875rem;
            vertical-align: middle;
        }
        
        .data-table tbody tr {
            transition: var(--transition);
        }
        
        .data-table tbody tr:hover {
            background: var(--gray-50);
        }
        
        /* Status badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.375rem 0.75rem;
            border-radius: var(--radius-xl);
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .status-badge i {
            margin-right: 0.375rem;
            font-size: 0.875rem;
        }
        
        .status-answered {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .status-missed {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }
        
        .status-incoming {
            background: rgba(14, 165, 233, 0.1);
            color: var(--info);
        }
        
        .status-outgoing {
            background: rgba(37, 99, 235, 0.1);
            color: var(--primary);
        }
        
        /* Cliente info */
        .client-info {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        
        .client-name {
            font-weight: 600;
            color: var(--gray-900);
            font-size: 0.875rem;
        }
        
        .client-group {
            font-size: 0.75rem;
            color: var(--accent);
            background: rgba(6, 182, 212, 0.1);
            padding: 0.25rem 0.5rem;
            border-radius: var(--radius);
            display: inline-block;
            max-width: fit-content;
        }
        
        /* Audio player */
        .audio-container {
            background: var(--gray-50);
            border-radius: var(--radius);
            padding: 1rem;
            border: 1px solid var(--gray-200);
        }
        
        .audio-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 0.75rem;
        }
        
        .file-info {
            font-size: 0.75rem;
            color: var(--gray-500);
            display: flex;
            align-items: center;
        }
        
        .file-info i {
            margin-right: 0.375rem;
        }
        
        .download-btn {
            background: var(--primary);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: var(--radius);
            text-decoration: none;
            font-size: 0.75rem;
            font-weight: 500;
            transition: var(--transition);
        }
        
        .download-btn:hover {
            background: var(--primary-dark);
            color: white;
            transform: translateY(-1px);
        }
        
        /* Paginación */
        .pagination-container {
            padding: 1.5rem;
            border-top: 1px solid var(--gray-200);
            background: var(--gray-50);
            display: flex;
            justify-content: between;
            align-items: center;
        }
        
        .pagination-info {
            color: var(--gray-600);
            font-size: 0.875rem;
        }
        
        .pagination {
            display: flex;
            list-style: none;
            margin: 0;
            padding: 0;
            gap: 0.25rem;
        }
        
        .pagination .page-item {
            display: flex;
        }
        
        .pagination .page-link {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 2.5rem;
            height: 2.5rem;
            padding: 0.5rem;
            border-radius: var(--radius);
            color: var(--gray-700);
            background: white;
            border: 1px solid var(--gray-300);
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            transition: var(--transition);
        }
        
        .pagination .page-link:hover {
            background: var(--gray-100);
            border-color: var(--gray-400);
            color: var(--gray-900);
        }
        
        .pagination .page-item.active .page-link {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        .pagination .page-item.disabled .page-link {
            color: var(--gray-400);
            pointer-events: none;
            background: var(--gray-100);
            border-color: var(--gray-200);
        }
        
        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
        }
        
        .empty-state i {
            font-size: 4rem;
            color: var(--gray-300);
            margin-bottom: 1.5rem;
        }
        
        .empty-state h4 {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 1rem;
        }
        
        .empty-state p {
            color: var(--gray-500);
            max-width: 600px;
            margin: 0 auto;
        }
        
        /* Responsive */
        @media (max-width: 1024px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }
            
            .kpi-grid {
                grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            }
        }
        
        @media (max-width: 768px) {
            .crm-container {
                padding: 1rem;
            }
            
            .section-title {
                font-size: 1.5rem;
            }
            
            .kpi-grid {
                grid-template-columns: 1fr;
            }
            
            .chart-container {
                height: 250px;
            }
            
            .data-table {
                font-size: 0.75rem;
            }
            
            .data-table th,
            .data-table td {
                padding: 0.75rem 0.5rem;
            }
        }
        
        /* Animaciones */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .animate-fade-in {
            animation: fadeInUp 0.5s ease-out forwards;
        }
        
        .delay-1 { animation-delay: 0.1s; }
        .delay-2 { animation-delay: 0.2s; }
        .delay-3 { animation-delay: 0.3s; }
        .delay-4 { animation-delay: 0.4s; }
    </style>
</head>
<body>
    <!-- Header CRM -->
    <header class="crm-header">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center">
                <a href="#" class="crm-brand">
                    <i class="fas fa-phone-volume"></i>
                    <span>Cybermatica CRM</span>
                </a>
               
                    </a>
                </nav>
            </div>
        </div>
    </header>

    <div class="crm-container">
        <!-- Header de sección -->
        <div class="section-header">
            <h1 class="section-title">Centro de Llamadas</h1>
            <p class="section-subtitle">Gestión integral de comunicaciones telefónicas y análisis de rendimiento</p>
        </div>
        
        <!-- Filtros -->
        <div class="filters-card">
            <div class="filters-header">
                <h3 class="filters-title">
                    <i class="fas fa-filter"></i>
                    Filtros de Búsqueda
                </h3>
                
                <!-- Pills de filtro rápido -->
                <div class="filter-pills" style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem;">
    <span style="color: var(--gray-600); font-weight: 500; font-size: 0.875rem; white-space: nowrap;">
        <i class="fas fa-calendar-alt me-1"></i>Período:
    </span>
    <div class="filter-pill <?php echo (!isset($_GET['fecha_inicio']) || $_GET['fecha_inicio'] == date('Y-m-d')) ? 'active' : ''; ?>" onclick="setDateRange('today')">
        Hoy
    </div>
    <div class="filter-pill" onclick="setDateRange('yesterday')">
        Ayer
    </div>
    <div class="filter-pill" onclick="setDateRange('week')">
        Esta semana
    </div>
    <div class="filter-pill" onclick="setDateRange('month')">
        Este mes
    </div>
    <div class="filter-pill" onclick="setDateRange('quarter')">
        Trimestre
    </div>
</div>

<div class="filter-pills" style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.5rem;">
    <span style="color: var(--gray-600); font-weight: 500; font-size: 0.875rem; white-space: nowrap;">
        <i class="fas fa-phone-slash me-1"></i>Estado:
    </span>
    <div class="filter-pill <?php echo ($estado_llamada == 'todas') ? 'active' : ''; ?>" onclick="setEstadoLlamada('todas')">
        Todas
    </div>
    <div class="filter-pill <?php echo ($estado_llamada == 'contestadas') ? 'active' : ''; ?>" onclick="setEstadoLlamada('contestadas')">
        Contestadas
    </div>
    <div class="filter-pill <?php echo ($estado_llamada == 'no_contestadas') ? 'active' : ''; ?>" onclick="setEstadoLlamada('no_contestadas')">
        No Contestadas
    </div>
</div>
            </div>
            
            <div class="filters-body">
                <form class="row g-3" method="get">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label for="fecha_inicio" class="form-label">
                                <i class="fas fa-calendar-alt me-1"></i> Fecha Inicial
                            </label>
                            <input type="date" class="form-control" id="fecha_inicio" name="fecha_inicio" value="<?php echo htmlspecialchars($fecha_inicio); ?>">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label for="fecha_fin" class="form-label">
                                <i class="fas fa-calendar-alt me-1"></i> Fecha Final
                            </label>
                            <input type="date" class="form-control" id="fecha_fin" name="fecha_fin" value="<?php echo htmlspecialchars($fecha_fin); ?>">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label for="tipo_llamada" class="form-label">
                                <i class="fas fa-exchange-alt me-1"></i> Tipo de Llamada
                            </label>
                            <select class="form-control form-select" id="tipo_llamada" name="tipo_llamada">
                                <option value="todas" <?php if ($tipo_llamada === 'todas') echo 'selected'; ?>>Todas las llamadas</option>
                                <option value="entrante" <?php if ($tipo_llamada === 'entrante') echo 'selected'; ?>>Llamadas entrantes</option>
                                <option value="saliente" <?php if ($tipo_llamada === 'saliente') echo 'selected'; ?>>Llamadas salientes</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label for="estado_llamada" class="form-label">
                                <i class="fas fa-phone-slash me-1"></i> Estado
                            </label>
                            <select class="form-control form-select" id="estado_llamada" name="estado_llamada">
                                <option value="todas" <?php if ($estado_llamada === 'todas') echo 'selected'; ?>>Todos los estados</option>
                                <option value="contestadas" <?php if ($estado_llamada === 'contestadas') echo 'selected'; ?>>Contestadas</option>
                                <option value="no_contestadas" <?php if ($estado_llamada === 'no_contestadas') echo 'selected'; ?>>No contestadas</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i>
                            Aplicar Filtros
                        </button>
                        <input type="hidden" name="pagina" value="1">
                    </div>
                </form>
            </div>
        </div>
        
        <!-- KPIs -->
        <div class="kpi-grid">
            <div class="kpi-card animate-fade-in delay-1">
                <div class="kpi-header">
                    <div class="kpi-title">Total de Llamadas</div>
                    <div class="kpi-icon">
                        <i class="fas fa-phone-volume"></i>
                    </div>
                </div>
                <div class="kpi-value"><?php echo number_format($stats['total']); ?></div>
                <div class="kpi-subtitle">
                    <i class="fas fa-calendar-day"></i>
                    <?php echo date('d/m/Y', strtotime($fecha_inicio)); ?> - <?php echo date('d/m/Y', strtotime($fecha_fin)); ?>
                </div>
            </div>
            
            <div class="kpi-card animate-fade-in delay-2">
                <div class="kpi-header">
                    <div class="kpi-title">Llamadas Contestadas</div>
                    <div class="kpi-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
                <div class="kpi-value"><?php echo number_format($stats['answered']); ?></div>
                <div class="kpi-subtitle">
                    <i class="fas fa-arrow-up"></i>
                    <?php echo number_format($stats['answer_rate'], 1); ?>% tasa de respuesta
                </div>
            </div>
            
            <div class="kpi-card animate-fade-in delay-3">
                <div class="kpi-header">
                    <div class="kpi-title">Duración Promedio</div>
                    <div class="kpi-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                </div>
                <div class="kpi-value"><?php echo formatDuration($stats['avg_duration_answered']); ?></div>
                <div class="kpi-subtitle">
                    <i class="fas fa-stopwatch"></i>
                    Máximo: <?php echo formatDuration($stats['max_duration']); ?>
                </div>
            </div>
            
            <div class="kpi-card animate-fade-in delay-4">
                <div class="kpi-header">
                    <div class="kpi-title">Distribución</div>
                    <div class="kpi-icon">
                        <i class="fas fa-exchange-alt"></i>
                    </div>
                </div>
                <div class="kpi-value"><?php echo number_format($stats['incoming']); ?> / <?php echo number_format($stats['outgoing']); ?></div>
                <div class="kpi-subtitle">
                    <i class="fas fa-chart-pie"></i>
                    Entrantes / Salientes
                </div>
            </div>
        </div>
        
        <!-- Gráficos -->
        <div class="charts-grid">
            <div class="chart-card">
                <div class="chart-header">
                    <h3 class="chart-title">
                        <i class="fas fa-chart-line"></i>
                        Tendencia de Llamadas
                    </h3>
                </div>
                <div class="chart-body">
                    <div class="chart-container">
                        <canvas id="callTrendsChart"></canvas>
                    </div>
                </div>
            </div>
            
            <div class="chart-card">
                <div class="chart-header">
                    <h3 class="chart-title">
                        <i class="fas fa-chart-pie"></i>
                        Distribución
                    </h3>
                </div>
                <div class="chart-body">
                    <div class="chart-container">
                        <canvas id="callDistributionChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="charts-grid">
            <div class="chart-card">
                <div class="chart-header">
                    <h3 class="chart-title">
                        <i class="fas fa-clock"></i>
                        Duración por Día
                    </h3>
                </div>
                <div class="chart-body">
                    <div class="chart-container">
                        <canvas id="durationChart"></canvas>
                    </div>
                </div>
            </div>
            
            <div class="chart-card">
                <div class="chart-header">
                    <h3 class="chart-title">
                        <i class="fas fa-hourglass-half"></i>
                        Actividad por Hora
                    </h3>
                </div>
                <div class="chart-body">
                    <div class="chart-container">
                        <canvas id="hourlyDistributionChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Tabla de datos -->
        <div class="table-card">
            <div class="table-header">
                <h3 class="table-title">
                    <i class="fas fa-list"></i>
                    Registro de Llamadas
                </h3>
                <div class="table-badge">
                    <?php echo date('d/m/Y', strtotime($fecha_inicio)); ?> - <?php echo date('d/m/Y', strtotime($fecha_fin)); ?>
                </div>
            </div>
            
            <?php if (!empty($results)): ?>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Fecha y Hora</th>
                                <th>Origen</th>
                                <th>Cliente</th>
                                <th>Destino</th>
                                <th>Duración</th>
                                <th>Estado</th>
                                <th>Tipo</th>
                                <th>Grabación</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $row): ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 500; color: var(--gray-900);">
                                            <?php echo date('d/m/Y', strtotime($row['calldate'])); ?>
                                        </div>
                                        <div style="font-size: 0.75rem; color: var(--gray-500);">
                                            <?php echo date('H:i:s', strtotime($row['calldate'])); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span style="font-family: monospace; font-weight: 500;">
                                            <?php echo htmlspecialchars($row['src']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['caller_nombre']) || !empty($row['caller_apellido'])): ?>
                                            <div class="client-info">
                                                <div class="client-name">
                                                    <?php echo htmlspecialchars(trim($row['caller_nombre'] . ' ' . $row['caller_apellido'])); ?>
                                                </div>
                                                <?php if (!empty($row['caller_grupo'])): ?>
                                                    <div class="client-group">
                                                        <?php echo htmlspecialchars($row['caller_grupo']); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span style="color: var(--gray-400);">Cliente no identificado</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span style="font-family: monospace; font-weight: 500;">
                                            <?php echo htmlspecialchars($row['dst']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span style="font-weight: 500; color: var(--gray-900);">
                                            <?php echo formatDuration($row['duration']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if($row['disposition'] == 'ANSWERED'): ?>
                                            <span class="status-badge status-answered">
                                                <i class="fas fa-check-circle"></i>
                                                Contestada
                                            </span>
                                        <?php else: ?>
                                            <span class="status-badge status-missed">
                                                <i class="fas fa-times-circle"></i>
                                                No Contestada
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if($row['is_incoming']): ?>
                                            <span class="status-badge status-incoming">
                                                <i class="fas fa-arrow-down"></i>
                                                Entrante
                                            </span>
                                        <?php endif; ?>
                                        <?php if($row['is_outgoing']): ?>
                                            <span class="status-badge status-outgoing">
                                                <i class="fas fa-arrow-up"></i>
                                                Saliente
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['recordingfile'])): ?>
                                            <?php if ($row['realPath']): ?>
                                                <div class="audio-container">
                                                    <audio class="audio-player" controls style="width: 100%; max-width: 300px;">
                                                        <source src="?play=<?php echo urlencode($row['recordingfile']); ?>" type="audio/wav">
                                                        Tu navegador no soporta el elemento de audio.
                                                    </audio>
                                                    <div class="audio-controls">
                                                        <div class="file-info">
                                                            <i class="fas fa-file-audio"></i>
                                                            <?php echo getFileSize($row['realPath']); ?>
                                                        </div>
                                                        <a href="?download=<?php echo urlencode($row['recordingfile']); ?>" class="download-btn">
                                                            <i class="fas fa-download"></i>
                                                            Descargar
                                                        </a>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <div style="padding: 1rem; background: var(--gray-50); border-radius: var(--radius); border: 1px solid var(--gray-200);">
                                                    <div style="color: var(--warning); font-weight: 500; margin-bottom: 0.5rem;">
                                                        <i class="fas fa-exclamation-triangle"></i>
                                                        Archivo no encontrado
                                                    </div>
                                                    <div style="display: flex; gap: 0.5rem;">
                                                        <a href="direct_access.php?file=<?php echo urlencode($row['recordingfile']); ?>" class="btn btn-secondary" style="font-size: 0.75rem; padding: 0.375rem 0.75rem;" target="_blank">
                                                            <i class="fas fa-play-circle"></i>
                                                            Reproducir
                                                        </a>
                                                        <a href="direct_access.php?file=<?php echo urlencode($row['recordingfile']); ?>&download=1" class="btn btn-secondary" style="font-size: 0.75rem; padding: 0.375rem 0.75rem;">
                                                            <i class="fas fa-download"></i>
                                                            Descargar
                                                        </a>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color: var(--gray-400); font-size: 0.875rem;">
                                                <i class="fas fa-ban"></i>
                                                Sin grabación
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Paginación -->
                <div class="pagination-container">
                    <div class="pagination-info">
                        Mostrando <?php echo count($results); ?> de <?php echo number_format($total_records); ?> registros 
                        (Página <?php echo $pagina_actual; ?> de <?php echo $total_paginas; ?>)
                    </div>
                    <nav aria-label="Navegación de páginas">
                        <ul class="pagination">
                            <!-- Botón Anterior -->
                            <li class="page-item <?php echo ($pagina_actual <= 1) ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo ($pagina_actual > 1) ? generarEnlacePaginacion($pagina_actual - 1) : '#'; ?>" aria-label="Anterior">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            </li>
                            
                            <?php
                            // Determinar el rango de páginas a mostrar
                            $rango = 2;
                            $inicio_rango = max(1, $pagina_actual - $rango);
                            $fin_rango = min($total_paginas, $pagina_actual + $rango);
                            
                            // Mostrar primera página si no está en el rango
                            if ($inicio_rango > 1) {
                                echo '<li class="page-item"><a class="page-link" href="' . generarEnlacePaginacion(1) . '">1</a></li>';
                                if ($inicio_rango > 2) {
                                    echo '<li class="page-item disabled"><a class="page-link" href="#">...</a></li>';
                                }
                            }
                            
                            // Mostrar páginas en el rango
                            for ($i = $inicio_rango; $i <= $fin_rango; $i++) {
                                echo '<li class="page-item ' . (($i == $pagina_actual) ? 'active' : '') . '"><a class="page-link" href="' . generarEnlacePaginacion($i) . '">' . $i . '</a></li>';
                            }
                            
                            // Mostrar última página si no está en el rango
                            if ($fin_rango < $total_paginas) {
                                if ($fin_rango < $total_paginas - 1) {
                                    echo '<li class="page-item disabled"><a class="page-link" href="#">...</a></li>';
                                }
                                echo '<li class="page-item"><a class="page-link" href="' . generarEnlacePaginacion($total_paginas) . '">' . $total_paginas . '</a></li>';
                            }
                            ?>
                            
                            <!-- Botón Siguiente -->
                            <li class="page-item <?php echo ($pagina_actual >= $total_paginas) ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo ($pagina_actual < $total_paginas) ? generarEnlacePaginacion($pagina_actual + 1) : '#'; ?>" aria-label="Siguiente">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-search"></i>
                    <h4>No se encontraron llamadas</h4>
                    <p>No hay registros de llamadas para el período y filtros seleccionados. Intenta ajustar los criterios de búsqueda.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Plyr JS -->
    <script src="https://cdn.plyr.io/3.7.8/plyr.polyfilled.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Inicializar reproductores de audio
            const players = Array.from(document.querySelectorAll('.audio-player')).map(p => new Plyr(p, {
                controls: ['play', 'progress', 'current-time', 'mute', 'volume'],
                settings: []
            }));
            
            // Configuración de colores para gráficos
            const chartColors = {
                primary: '#2563eb',
                secondary: '#64748b',
                accent: '#06b6d4',
                success: '#10b981',
                warning: '#f59e0b',
                danger: '#ef4444',
                info: '#0ea5e9',
                primaryTransparent: 'rgba(37, 99, 235, 0.1)',
                accentTransparent: 'rgba(6, 182, 212, 0.1)',
                successTransparent: 'rgba(16, 185, 129, 0.1)',
                dangerTransparent: 'rgba(239, 68, 68, 0.1)'
            };
            
            // Gráfico de tendencias
            const callTrendsCtx = document.getElementById('callTrendsChart').getContext('2d');
            new Chart(callTrendsCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($chart_labels); ?>,
                    datasets: [
                        {
                            label: 'Contestadas',
                            data: <?php echo json_encode($chart_answered); ?>,
                            backgroundColor: chartColors.successTransparent,
                            borderColor: chartColors.success,
                            borderWidth: 3,
                            tension: 0.4,
                            fill: true,
                            pointBackgroundColor: chartColors.success,
                            pointBorderColor: '#fff',
                            pointBorderWidth: 2,
                            pointRadius: 5
                        },
                        {
                            label: 'No Contestadas',
                            data: <?php echo json_encode($chart_missed); ?>,
                            backgroundColor: chartColors.dangerTransparent,
                            borderColor: chartColors.danger,
                            borderWidth: 3,
                            tension: 0.4,
                            fill: true,
                            pointBackgroundColor: chartColors.danger,
                            pointBorderColor: '#fff',
                            pointBorderWidth: 2,
                            pointRadius: 5
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                usePointStyle: true,
                                padding: 20,
                                font: {
                                    size: 12,
                                    weight: '500'
                                }
                            }
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            titleColor: '#fff',
                            bodyColor: '#fff',
                            borderColor: chartColors.primary,
                            borderWidth: 1
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            },
                            ticks: {
                                precision: 0,
                                font: {
                                    size: 11
                                }
                            }
                        },
                        x: {
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            },
                            ticks: {
                                font: {
                                    size: 11
                                }
                            }
                        }
                    }
                }
            });
            
            // Gráfico de distribución
            const callDistributionCtx = document.getElementById('callDistributionChart').getContext('2d');
            new Chart(callDistributionCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Contestadas', 'No Contestadas'],
                    datasets: [{
                        data: [<?php echo $stats['answered']; ?>, <?php echo $stats['missed']; ?>],
                        backgroundColor: [chartColors.success, chartColors.danger],
                        borderWidth: 0,
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                usePointStyle: true,
                                padding: 20,
                                font: {
                                    size: 12,
                                    weight: '500'
                                }
                            }
                        }
                    },
                    cutout: '60%'
                }
            });
            
            // Gráfico de duración
            const durationCtx = document.getElementById('durationChart').getContext('2d');
            new Chart(durationCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($chart_labels); ?>,
                    datasets: [{
                        label: 'Duración Promedio (min)',
                        data: <?php echo json_encode($duration_data); ?>,
                        backgroundColor: chartColors.primaryTransparent,
                        borderColor: chartColors.primary,
                        borderWidth: 2,
                        borderRadius: 4,
                        borderSkipped: false
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            },
                            ticks: {
                                font: {
                                    size: 11
                                }
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                font: {
                                    size: 11
                                }
                            }
                        }
                    }
                }
            });
            
            // Gráfico por hora
            const hourlyCtx = document.getElementById('hourlyDistributionChart').getContext('2d');
            new Chart(hourlyCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($hourly_labels); ?>,
                    datasets: [{
                        label: 'Llamadas por Hora',
                        data: <?php echo json_encode(array_values($hourly_data)); ?>,
                        backgroundColor: chartColors.accentTransparent,
                        borderColor: chartColors.accent,
                        borderWidth: 2,
                        borderRadius: 4,
                        borderSkipped: false
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            },
                            ticks: {
                                precision: 0,
                                font: {
                                    size: 11
                                }
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                font: {
                                    size: 11
                                }
                            }
                        }
                    }
                }
            });
        });
        
        // Funciones de filtro
        function setDateRange(range) {
            const today = new Date();
            let startDate = new Date();
            let endDate = new Date();
            
            switch(range) {
                case 'today':
                    break;
                case 'yesterday':
                    startDate.setDate(today.getDate() - 1);
                    endDate.setDate(today.getDate() - 1);
                    break;
                case 'week':
                    startDate.setDate(today.getDate() - 7);
                    break;
                case 'month':
                    startDate.setDate(today.getDate() - 30);
                    break;
                case 'quarter':
                    startDate.setDate(today.getDate() - 90);
                    break;
            }
            
            document.getElementById('fecha_inicio').value = startDate.toISOString().split('T')[0];
            document.getElementById('fecha_fin').value = endDate.toISOString().split('T')[0];
            
            // Actualizar pills activos
            document.querySelectorAll('.filter-pill').forEach(pill => {
                pill.classList.remove('active');
            });
            event.target.classList.add('active');
            
            document.querySelector('input[name="pagina"]').value = 1;
            document.querySelector('form').submit();
        }

        function setEstadoLlamada(estado) {
            document.getElementById('estado_llamada').value = estado;
            
            // Actualizar pills activos
            document.querySelectorAll('.filter-pills:last-child .filter-pill').forEach(pill => {
                pill.classList.remove('active');
            });
            event.target.classList.add('active');
            
            document.querySelector('input[name="pagina"]').value = 1;
            document.querySelector('form').submit();
        }
    </script>
</body>
</html>

