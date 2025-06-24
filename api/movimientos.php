<?php
require_once 'session_check.php';
// --- api/movimientos.php ---
error_reporting(E_ALL);
ini_set('display_errors', 1); 

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') { exit; }

require_once 'db_connection.php';
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$response = ['success' => false, 'message' => 'Método no válido o error.', 'data' => null];
// $debug_info_capture = []; // Puedes mantener esto si quieres seguir logueando así internamente

try {
    switch ($method) {
        case 'GET':
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
            if ($page < 1) $page = 1;
            if ($limit < 1) $limit = 10;
            if ($limit > 200) $limit = 200;
            
            $filtro_texto = isset($_GET['filtro_texto']) ? trim($_GET['filtro_texto']) : null;
            $filtro_tipo = isset($_GET['filtro_tipo']) && in_array($_GET['filtro_tipo'], ['entrada', 'salida']) ? $_GET['filtro_tipo'] : null;
            
            $filtro_fecha_inicio_raw = isset($_GET['filtro_fecha_inicio']) ? $_GET['filtro_fecha_inicio'] : null;
            $filtro_fecha_inicio = null;
            if ($filtro_fecha_inicio_raw && DateTime::createFromFormat('Y-m-d', $filtro_fecha_inicio_raw) !== false) {
                $filtro_fecha_inicio = $filtro_fecha_inicio_raw;
            }

            $filtro_fecha_fin_raw = isset($_GET['filtro_fecha_fin']) ? $_GET['filtro_fecha_fin'] : null;
            $filtro_fecha_fin = null;
            if ($filtro_fecha_fin_raw && DateTime::createFromFormat('Y-m-d', $filtro_fecha_fin_raw) !== false) {
                $filtro_fecha_fin = $filtro_fecha_fin_raw;
            }
            
            $params_for_where_clause = []; 
            $whereConditions = [];

            if ($filtro_texto && $filtro_texto !== '') {
                $whereConditions[] = "(CONCAT(p.codigo, '') LIKE :filtro_texto_val OR p.descripcion LIKE :filtro_texto_val)";
                $params_for_where_clause[':filtro_texto_val'] = "%".$filtro_texto."%";
            }
            if ($filtro_tipo) {
                $whereConditions[] = "m.tipo = :filtro_tipo_val";
                $params_for_where_clause[':filtro_tipo_val'] = $filtro_tipo;
            }
            if ($filtro_fecha_inicio) {
                $whereConditions[] = "m.fecha >= :filtro_fecha_inicio_val";
                $params_for_where_clause[':filtro_fecha_inicio_val'] = $filtro_fecha_inicio;
            }
            if ($filtro_fecha_fin) {
                $whereConditions[] = "m.fecha <= :filtro_fecha_fin_val";
                $params_for_where_clause[':filtro_fecha_fin_val'] = $filtro_fecha_fin;
            }

            $whereSql = "";
            if (count($whereConditions) > 0) {
                $whereSql = "WHERE " . implode(" AND ", $whereConditions);
            }
            // $debug_info_capture['whereSql_constructed'] = $whereSql; // Depuración opcional
            // $debug_info_capture['params_for_where_clause'] = $params_for_where_clause; // Depuración opcional

            $sqlCount = "SELECT COUNT(m.id)
                         FROM movimientos m
                         JOIN productos p ON m.producto_id = p.id
                         $whereSql";
            // $debug_info_capture['query_count'] = $sqlCount;  // Depuración opcional

            $stmtCount = $pdo->prepare($sqlCount);
            if (!$stmtCount) {
                $pdo_error_info = $pdo->errorInfo();
                throw new Exception("PDO::prepare() failed for count query: " . ($pdo_error_info[2] ?? "Unknown error"));
            }
            
            if (!empty($params_for_where_clause)) {
                foreach ($params_for_where_clause as $key => $value) {
                    $stmtCount->bindValue($key, $value);
                }
            }
            
            $execute_count_success = $stmtCount->execute(); 
             if (!$execute_count_success) {
                 $stmt_error_info = $stmtCount->errorInfo();
                 throw new PDOException("PDOStatement::execute() failed for count query: " . ($stmt_error_info[2] ?? "Unknown error"), $stmt_error_info[1] ?? 0);
            }
            $totalRecords = (int)$stmtCount->fetchColumn();
            // $debug_info_capture['totalRecords_found'] = $totalRecords; // Depuración opcional
            
            $totalPages = ($limit > 0) ? ceil($totalRecords / $limit) : 0;
            if ($totalPages == 0 && $totalRecords > 0 && $limit > 0) $totalPages = 1; 
            if ($page > $totalPages && $totalPages > 0) $page = $totalPages; 
            $offset = ($page - 1) * $limit; 

            $baseSqlData = "SELECT m.id, m.producto_id, m.tipo, m.cantidad, m.precio_unitario,
                               m.fecha, m.proveedor, m.cliente, m.factura_id, m.fecha_registro,
                               p.codigo as producto_codigo, p.descripcion as producto_descripcion, p.medida as producto_medida
                        FROM movimientos m
                        JOIN productos p ON m.producto_id = p.id";
            
            $orderByAndLimitSql = "ORDER BY m.fecha DESC, m.id DESC
                                   LIMIT :limit_val OFFSET :offset_val";

            $sqlData = $baseSqlData . " " . $whereSql . " " . $orderByAndLimitSql;
            // $debug_info_capture['query_data_constructed'] = $sqlData; // Depuración opcional

            $stmtData = $pdo->prepare($sqlData);
            if (!$stmtData) {
                $pdo_error_info = $pdo->errorInfo();
                throw new Exception("PDO::prepare() failed for data query: " . ($pdo_error_info[2] ?? "Unknown error"));
            }
            
            if (!empty($params_for_where_clause)) {
                foreach ($params_for_where_clause as $key => $value) {
                    $stmtData->bindValue($key, $value);
                }
            }
            $stmtData->bindValue(':limit_val', (int)$limit, PDO::PARAM_INT);
            $stmtData->bindValue(':offset_val', (int)$offset, PDO::PARAM_INT);
            
            // $debug_info_capture['params_data_bound'] = array_merge($params_for_where_clause, [':limit_val' => (int)$limit, ':offset_val' => (int)$offset]); // Depuración opcional

            $execute_data_success = $stmtData->execute(); 
            if (!$execute_data_success) {
                 $stmt_error_info = $stmtData->errorInfo();
                 throw new PDOException("PDOStatement::execute() failed for data query: " . ($stmt_error_info[2] ?? "Unknown error"), $stmt_error_info[1] ?? 0);
            }

            $movimientos = $stmtData->fetchAll(PDO::FETCH_ASSOC);
            
             foreach ($movimientos as &$m) {
                 $m['id'] = (int)$m['id'];
                 $m['producto_id'] = (int)$m['producto_id'];
                 $m['cantidad'] = (float)$m['cantidad'];
                 $m['precio_unitario'] = ($m['precio_unitario'] !== null) ? (float)$m['precio_unitario'] : null;
                 $m['factura_id'] = ($m['factura_id'] !== null) ? (int)$m['factura_id'] : null;
                 $m['producto_codigo'] = (isset($m['producto_codigo'])) ? (int)$m['producto_codigo'] : null;
             }
             unset($m);

            $response = [
                'success' => true,
                'message' => '',
                'data' => $movimientos,
                'pagination' => [
                    'currentPage' => $page,
                    'limit' => $limit,
                    'totalRecords' => $totalRecords,
                    'totalPages' => $totalPages
                ]
                // 'debug_info_on_success' => $debug_info_capture // Opcional: eliminar si no se necesita
            ];
            break;

        case 'POST':
            // ... (código POST)
            if (!isset($currentUser['role']) || $currentUser['role'] !== 'Administrador') {
                http_response_code(403);
                $response['message'] = 'Acceso prohibido. Se requiere rol de Administrador.';
                break;
            }
            $input = json_decode(file_get_contents('php://input'), true);

            if (!isset($input['productoId'], $input['tipo'], $input['cantidad'], $input['fecha']) ||
                !filter_var($input['productoId'], FILTER_VALIDATE_INT) ||
                !in_array($input['tipo'], ['entrada', 'salida']) ||
                !is_numeric($input['cantidad']) || $input['cantidad'] <= 0 ||
                !DateTime::createFromFormat('Y-m-d', $input['fecha']))
            {
                http_response_code(400); $response['message'] = 'Datos de movimiento inválidos.'; break;
            }

            $productoId = (int)$input['productoId'];
            $tipo = $input['tipo'];
            $cantidad = (float)$input['cantidad'];
            $fecha = $input['fecha'];
            $precio = null;
            $proveedor = null;
            $cliente = null;

            if ($tipo === 'entrada') {
                if (!isset($input['precio']) || !is_numeric($input['precio']) || $input['precio'] < 0) {
                     http_response_code(400); $response['message'] = 'Precio inválido para entrada.'; break;
                }
                 $precio = (float)$input['precio'];
                 $proveedor = isset($input['proveedor']) ? trim($input['proveedor']) : null;
                 if (empty($proveedor)) $proveedor = null;
            } else { 
                 $cliente = isset($input['cliente']) ? trim($input['cliente']) : null;
                 if (empty($cliente)) $cliente = null;
            }

            $sql = "INSERT INTO movimientos (producto_id, tipo, cantidad, precio_unitario, fecha, proveedor, cliente)
                    VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $success = $stmt->execute([$productoId, $tipo, $cantidad, $precio, $fecha, $proveedor, $cliente]);

            if ($success) {
                 $newId = $pdo->lastInsertId();
                 $response = ['success' => true, 'message' => 'Movimiento registrado.', 'data' => ['id' => (int)$newId]];
            } else {
                 http_response_code(500); $errorInfo = $stmt->errorInfo();
                 $response['message'] = 'Error al guardar movimiento: ' . ($errorInfo[2] ?? 'Desconocido');
            }
            break;

        case 'DELETE':
            // ... (código DELETE)
             if (!isset($currentUser['role']) || $currentUser['role'] !== 'Administrador') {
                http_response_code(403);
                $response['message'] = 'Acceso prohibido. Se requiere rol de Administrador.';
                break;
            }
             if (!isset($_GET['id']) || !filter_var($_GET['id'], FILTER_VALIDATE_INT)) {
                 http_response_code(400); $response['message'] = 'ID de movimiento inválido.'; break;
             }
             $movimientoId = (int)$_GET['id'];

            $checkFacturaSql = "SELECT factura_id FROM movimientos WHERE id = ?";
            $stmtCheck = $pdo->prepare($checkFacturaSql);
            $stmtCheck->execute([$movimientoId]);
            $movData = $stmtCheck->fetch();

            if ($movData && $movData['factura_id'] !== null) {
                http_response_code(400);
                $response['message'] = 'Error: Este movimiento pertenece a una factura (ID: '.$movData['factura_id'].') y no puede eliminarse individualmente. Elimine la factura completa si es necesario.';
                break;
            }

            $sql = "DELETE FROM movimientos WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $success = $stmt->execute([$movimientoId]);

            if ($success && $stmt->rowCount() > 0) {
                 $response = ['success' => true, 'message' => 'Movimiento eliminado.', 'data' => ['id' => $movimientoId]];
            } elseif ($success && $stmt->rowCount() === 0) {
                 http_response_code(404); $response['message'] = 'Movimiento no encontrado o ya eliminado.';
            } else {
                 http_response_code(500); $errorInfo = $stmt->errorInfo();
                 $response['message'] = 'Error al eliminar movimiento: ' . ($errorInfo[2] ?? 'Desconocido');
            }
            break;

        default:
            http_response_code(405); $response['message'] = 'Método no permitido.'; break;
    }
} catch (PDOException $e) {
    http_response_code(500); 
    $detailed_error_message = 'Error DB (PDOException): ' . $e->getMessage();
    // $debug_info_capture['exception_message'] = $detailed_error_message; // Opcional si mantienes $debug_info_capture
    // $debug_info_capture['exception_code'] = $e->getCode(); // Opcional
    error_log($detailed_error_message . " | SQLSTATE from PDO: " . $e->getCode() . " | Driver-specific error code: " . ($e->errorInfo[1] ?? 'N/A') /*. " | Debug Info: " . json_encode($debug_info_capture)*/); // Opcional: $debug_info_capture
    $response = ['success' => false, 'message' => 'Error de base de datos (Mov). Consulte el log.', 'data' => null];
} catch (Exception $e) {
    http_response_code(500); 
    $detailed_error_message = 'Error General (Exception): ' . $e->getMessage();
    // $debug_info_capture['exception_message'] = $detailed_error_message; // Opcional
    error_log($detailed_error_message /*. " | Debug Info: " . json_encode($debug_info_capture)*/); // Opcional: $debug_info_capture
    $response = ['success' => false, 'message' => 'Error general del servidor (Mov). Consulte el log.', 'data' => null];
}

echo json_encode($response);
?>