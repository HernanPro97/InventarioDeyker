<?php
require_once 'session_check.php';
// --- api/productos.php (con paginación, filtros, DELETE y opción para todos) ---
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
$debug_info_capture = [];


function getNextCodigo($pdo_conn) {
    try {
        $stmt = $pdo_conn->query("SELECT MAX(codigo) as max_codigo FROM productos");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return ($row && $row['max_codigo'] !== null) ? $row['max_codigo'] + 1 : 1;
    } catch (PDOException $e) { return 1; }
}

try {
    switch ($method) {
        case 'GET':
            // ***** NUEVA CONDICIÓN PARA OBTENER TODOS LOS PRODUCTOS *****
            $obtener_todos = isset($_GET['todos']) && $_GET['todos'] === '1';
            // ***********************************************************

            if ($obtener_todos) {
                 $sqlData = "
                    SELECT
                        p.id, p.codigo, p.departamento, p.descripcion, p.medida,
                        (COALESCE(SUM(CASE WHEN m.tipo = 'entrada' THEN m.cantidad ELSE 0 END), 0) - COALESCE(SUM(CASE WHEN m.tipo = 'salida' THEN m.cantidad ELSE 0 END), 0)) AS stock_actual,
                        (
                            SELECT m_lp.precio_unitario
                            FROM movimientos m_lp
                            WHERE m_lp.producto_id = p.id AND m_lp.tipo = 'entrada' AND m_lp.precio_unitario IS NOT NULL
                            ORDER BY m_lp.fecha DESC, m_lp.id DESC
                            LIMIT 1
                        ) AS ultimo_precio_compra
                    FROM productos p
                    LEFT JOIN movimientos m ON p.id = m.producto_id
                    GROUP BY p.id, p.codigo, p.departamento, p.descripcion, p.medida
                    ORDER BY p.codigo ASC
                ";
                $stmtData = $pdo->query($sqlData);
                $productos = $stmtData->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($productos as &$p) {
                    $p['id'] = (int)$p['id'];
                    $p['codigo'] = (int)$p['codigo'];
                    $p['stock_actual'] = (float)$p['stock_actual'];
                    $p['ultimo_precio_compra'] = ($p['ultimo_precio_compra'] !== null) ? (float)$p['ultimo_precio_compra'] : null;
                }
                unset($p);
                $response = ['success' => true, 'message' => '', 'data' => $productos]; // Sin paginación

            } else {
                // Lógica de paginación y filtros existente
                $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
                $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 25; 
                if ($page < 1) $page = 1;
                if ($limit < 1) $limit = 10;
                if ($limit > 200) $limit = 200;
                
                $filtro_texto = isset($_GET['filtro_texto']) ? trim($_GET['filtro_texto']) : null;
                $filtro_stock = isset($_GET['filtro_stock']) ? $_GET['filtro_stock'] : null;

                $params_for_where_clause = [];
                $whereConditions = [];
                $havingConditions = []; 

                if ($filtro_texto && $filtro_texto !== '') {
                    $whereConditions[] = "(CAST(p.codigo AS CHAR) LIKE :filtro_texto_val OR p.departamento LIKE :filtro_texto_val OR p.descripcion LIKE :filtro_texto_val)";
                    $params_for_where_clause[':filtro_texto_val'] = "%".$filtro_texto."%";
                }

                $stock_calculation_sql = "(COALESCE(SUM(CASE WHEN m.tipo = 'entrada' THEN m.cantidad ELSE 0 END), 0) - COALESCE(SUM(CASE WHEN m.tipo = 'salida' THEN m.cantidad ELSE 0 END), 0))";

                if ($filtro_stock && $filtro_stock !== 'todos' && $filtro_stock !== '') {
                    switch ($filtro_stock) {
                        case 'positivo': $havingConditions[] = "$stock_calculation_sql > 0"; break;
                        case 'cero': $havingConditions[] = "$stock_calculation_sql = 0"; break;
                        case 'negativo': $havingConditions[] = "$stock_calculation_sql < 0"; break;
                    }
                }
                
                $whereSql = ""; if (count($whereConditions) > 0) { $whereSql = "WHERE " . implode(" AND ", $whereConditions); }
                $havingSql = ""; if (count($havingConditions) > 0) { $havingSql = "HAVING " . implode(" AND ", $havingConditions); }

                // $debug_info_capture['whereSql_productos'] = $whereSql; // Opcional para log
                // $debug_info_capture['havingSql_productos'] = $havingSql; // Opcional para log
                // $debug_info_capture['params_where_productos'] = $params_for_where_clause; // Opcional para log

                $sqlCountBase = "
                    SELECT p.id, $stock_calculation_sql AS stock_para_filtro
                    FROM productos p
                    LEFT JOIN movimientos m ON p.id = m.producto_id
                    $whereSql
                    GROUP BY p.id 
                    $havingSql
                ";
                $sqlCount = "SELECT COUNT(*) FROM ($sqlCountBase) AS subquery_count";
                // $debug_info_capture['query_count_productos'] = $sqlCount; // Opcional para log

                $stmtCount = $pdo->prepare($sqlCount);
                if (!$stmtCount) { throw new Exception("PDO::prepare() failed for product count query."); }
                
                if (!empty($params_for_where_clause)) {
                    foreach ($params_for_where_clause as $key => $value) { $stmtCount->bindValue($key, $value); }
                }
                if (!$stmtCount->execute()) { $err = $stmtCount->errorInfo(); throw new PDOException("Execute failed for product count: " . ($err[2] ?? "Unknown error"), $err[1] ?? 0); }
                $totalRecords = (int)$stmtCount->fetchColumn();
                // $debug_info_capture['totalRecords_productos'] = $totalRecords; // Opcional para log

                $totalPages = ($limit > 0) ? ceil($totalRecords / $limit) : 0;
                if ($totalPages == 0 && $totalRecords > 0 && $limit > 0) $totalPages = 1;
                if ($page > $totalPages && $totalPages > 0) $page = $totalPages;
                $offset = ($page - 1) * $limit;

                $sqlData = "
                    SELECT
                        p.id, p.codigo, p.departamento, p.descripcion, p.medida,
                        $stock_calculation_sql AS stock_actual,
                        (
                            SELECT m_lp.precio_unitario
                            FROM movimientos m_lp
                            WHERE m_lp.producto_id = p.id AND m_lp.tipo = 'entrada' AND m_lp.precio_unitario IS NOT NULL
                            ORDER BY m_lp.fecha DESC, m_lp.id DESC
                            LIMIT 1
                        ) AS ultimo_precio_compra
                    FROM productos p
                    LEFT JOIN movimientos m ON p.id = m.producto_id
                    $whereSql
                    GROUP BY p.id, p.codigo, p.departamento, p.descripcion, p.medida
                    $havingSql
                    ORDER BY p.codigo ASC
                    LIMIT :limit_val OFFSET :offset_val
                ";
                // $debug_info_capture['query_data_productos'] = $sqlData; // Opcional para log
                
                $stmtData = $pdo->prepare($sqlData);
                if (!$stmtData) { throw new Exception("PDO::prepare() failed for product data query."); }

                if (!empty($params_for_where_clause)) {
                    foreach ($params_for_where_clause as $key => $value) { $stmtData->bindValue($key, $value); }
                }
                $stmtData->bindValue(':limit_val', (int)$limit, PDO::PARAM_INT);
                $stmtData->bindValue(':offset_val', (int)$offset, PDO::PARAM_INT);
                // $debug_info_capture['params_data_productos_bound'] = array_merge($params_for_where_clause, [':limit_val' => (int)$limit, ':offset_val' => (int)$offset]); // Opcional

                if (!$stmtData->execute()) { $err = $stmtData->errorInfo(); throw new PDOException("Execute failed for product data: " . ($err[2] ?? "Unknown error"), $err[1] ?? 0); }
                $productos = $stmtData->fetchAll(PDO::FETCH_ASSOC);

                foreach ($productos as &$p) {
                    $p['id'] = (int)$p['id']; $p['codigo'] = (int)$p['codigo'];
                    $p['stock_actual'] = (float)$p['stock_actual'];
                    $p['ultimo_precio_compra'] = ($p['ultimo_precio_compra'] !== null) ? (float)$p['ultimo_precio_compra'] : null;
                }
                unset($p);

                $response = [
                    'success' => true, 'message' => '', 'data' => $productos,
                    'pagination' => [
                        'currentPage' => $page, 'limit' => $limit,
                        'totalRecords' => $totalRecords, 'totalPages' => $totalPages
                    ]
                ];
                // if (!empty($debug_info_capture)) $response['debug_info'] = $debug_info_capture; // Opcional
            }
            break;

        case 'POST':
            // ... (código POST sin cambios)
            if (!isset($currentUser['role']) || $currentUser['role'] !== 'Administrador') {
                http_response_code(403);
                $response['message'] = 'Acceso prohibido. Se requiere rol de Administrador.';
                break;
            }
            $input = json_decode(file_get_contents('php://input'), true);
            if (!isset($input['departamento'], $input['descripcion'], $input['medida'])) {
                http_response_code(400); $response['message'] = 'Faltan datos.'; break;
            }
            $departamento = trim($input['departamento']); $descripcion = trim($input['descripcion']); $medida = trim($input['medida']);
            if (empty($departamento) || empty($descripcion) || empty($medida)) {
                http_response_code(400); $response['message'] = 'Campos vacíos.'; break;
            }
            $codigo = getNextCodigo($pdo);
            $sql = "INSERT INTO productos (codigo, departamento, descripcion, medida) VALUES (?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $success = $stmt->execute([$codigo, $departamento, $descripcion, $medida]);
            if ($success) {
                $newId = $pdo->lastInsertId();
                $response = ['success' => true, 'message' => "Producto [{$codigo}] guardado.", 'data' => ['id' => (int)$newId, 'codigo' => $codigo]];
            } else {
                http_response_code(500); $errorInfo = $stmt->errorInfo();
                $response['message'] = ($stmt->errorCode() == '23000') ? 'Error: Código duplicado.' : 'Error DB: ' . ($errorInfo[2] ?? 'Desconocido');
            }
            break;

        case 'DELETE':
            // ... (código DELETE sin cambios)
            if (!isset($currentUser['role']) || $currentUser['role'] !== 'Administrador') {
                http_response_code(403);
                $response['message'] = 'Acceso prohibido. Se requiere rol de Administrador.';
                break;
            }
             if (!isset($_GET['id']) || !filter_var($_GET['id'], FILTER_VALIDATE_INT)) {
                http_response_code(400); $response['message'] = 'ID de producto inválido o no proporcionado.'; break;
            }
            $productId = (int)$_GET['id'];

            $checkSqlMov = "SELECT COUNT(*) FROM movimientos WHERE producto_id = ?";
            $stmtCheckMov = $pdo->prepare($checkSqlMov);
            $stmtCheckMov->execute([$productId]);
            if ($stmtCheckMov->fetchColumn() > 0) {
                 http_response_code(409); 
                 $response['message'] = 'Error: No se puede eliminar el producto porque tiene movimientos asociados.';
                 break;
            }

            $checkSqlFac = "SELECT COUNT(*) FROM factura_items WHERE producto_id = ?";
            $stmtCheckFac = $pdo->prepare($checkSqlFac);
            $stmtCheckFac->execute([$productId]);
            if ($stmtCheckFac->fetchColumn() > 0) {
                 http_response_code(409); 
                 $response['message'] = 'Error: No se puede eliminar el producto porque está incluido en facturas.';
                 break;
            }

            $sql = "DELETE FROM productos WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $success = $stmt->execute([$productId]);

            if ($success && $stmt->rowCount() > 0) {
                 $response = ['success' => true, 'message' => 'Producto eliminado correctamente.', 'data' => ['id' => $productId]];
            } elseif ($success && $stmt->rowCount() === 0) {
                 http_response_code(404);
                 $response['message'] = 'Error: Producto no encontrado.';
            } else {
                 http_response_code(500); $errorInfo = $stmt->errorInfo();
                 $response['message'] = 'Error al eliminar el producto: ' . ($errorInfo[2] ?? 'Desconocido');
            }
            break;

        default:
            http_response_code(405); $response['message'] = 'Método no permitido.'; break;
    }
} catch (PDOException $e) {
    http_response_code(500); 
    $detailed_error_message = 'Error DB (PDOException Prod): ' . $e->getMessage();
    $exception_code_to_log = $e->getCode(); 
    if (isset($e->errorInfo[1])) { $exception_code_to_log = $e->errorInfo[1]; }
    // $debug_info_capture['exception_message'] = $detailed_error_message; // Opcional
    // $debug_info_capture['exception_code'] = $exception_code_to_log; // Opcional
    error_log($detailed_error_message . " | SQLSTATE from PDO: " . $e->getCode() . " | Driver-specific error code: " . ($e->errorInfo[1] ?? 'N/A') /*. " | Debug Info: " . json_encode($debug_info_capture)*/);
    $response = ['success' => false, 'message' => 'Error de base de datos (Prod). Consulte el log.', 'data' => null /*, 'debug_info' => $debug_info_capture*/];
} catch (Exception $e) {
    http_response_code(500); 
    $detailed_error_message = 'Error General (Exception Prod): ' . $e->getMessage();
    // $debug_info_capture['exception_message'] = $detailed_error_message; // Opcional
    error_log($detailed_error_message /*. " | Debug Info: " . json_encode($debug_info_capture)*/);
    $response = ['success' => false, 'message' => 'Error general del servidor (Prod). Consulte el log.', 'data' => null /*, 'debug_info' => $debug_info_capture*/];
}

echo json_encode($response);
?>