<?php
// --- api/facturas.php (Actualizado para CXC, Paginación Historial) ---
require_once 'session_check.php';
require_once 'db_connection.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') { exit; }
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$response = ['success' => false, 'message' => 'Método no válido o error.', 'data' => null];
$debug_info_capture = [];

try {
    switch ($method) {
        case 'GET':
            if (isset($_GET['id']) && filter_var($_GET['id'], FILTER_VALIDATE_INT)) {
                // Obtener una factura específica por ID (sin cambios en esta parte)
                $facturaId = (int)$_GET['id'];
                $sqlFactura = "SELECT f.id, f.fecha, f.cliente_id, c.nombre_razon_social as cliente_nombre, f.total,
                                      f.condicion_pago, f.fecha_vencimiento, f.saldo_pendiente, f.estado_pago
                               FROM facturas f
                               LEFT JOIN clientes c ON f.cliente_id = c.id
                               WHERE f.id = ?";
                $stmtFactura = $pdo->prepare($sqlFactura);
                $stmtFactura->execute([$facturaId]);
                $factura = $stmtFactura->fetch(PDO::FETCH_ASSOC);

                if (!$factura) { http_response_code(404); $response['message'] = 'Factura no encontrada.'; break; }

                $sqlItems = "SELECT fi.id, fi.producto_id, fi.cantidad, fi.precio_venta, fi.subtotal,
                                    p.codigo as producto_codigo, p.descripcion as producto_descripcion, p.medida as producto_medida
                             FROM factura_items fi
                             JOIN productos p ON fi.producto_id = p.id
                             WHERE fi.factura_id = ?";
                $stmtItems = $pdo->prepare($sqlItems);
                $stmtItems->execute([$facturaId]);
                $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

                $factura['id'] = (int)$factura['id']; 
                $factura['cliente_id'] = isset($factura['cliente_id']) ? (int)$factura['cliente_id'] : null;
                $factura['total'] = (float)$factura['total'];
                $factura['saldo_pendiente'] = isset($factura['saldo_pendiente']) ? (float)$factura['saldo_pendiente'] : 0.00;
                foreach ($items as &$item) { 
                    $item['id'] = (int)$item['id']; $item['producto_id'] = (int)$item['producto_id'];
                    $item['cantidad'] = (float)$item['cantidad']; $item['precio_venta'] = (float)$item['precio_venta'];
                    $item['subtotal'] = (float)$item['subtotal']; $item['producto_codigo'] = (int)$item['producto_codigo'];
                } unset($item);
                $factura['items'] = $items;
                $response = ['success' => true, 'message' => '', 'data' => $factura];

            } else {
                // Listar todas las facturas (con paginación y filtros para Historial de Facturas)
                $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
                $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 25;
                if ($page < 1) $page = 1;
                if ($limit < 1) $limit = 10;
                if ($limit > 200) $limit = 200;

                $filtro_texto = isset($_GET['filtro_texto']) ? trim($_GET['filtro_texto']) : null;
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
                    $whereConditions[] = "(CAST(f.id AS CHAR) LIKE :filtro_texto_val OR c.nombre_razon_social LIKE :filtro_texto_val)";
                    $params_for_where_clause[':filtro_texto_val'] = "%".$filtro_texto."%";
                }
                if ($filtro_fecha_inicio) {
                    $whereConditions[] = "f.fecha >= :filtro_fecha_inicio_val";
                    $params_for_where_clause[':filtro_fecha_inicio_val'] = $filtro_fecha_inicio;
                }
                if ($filtro_fecha_fin) {
                    $whereConditions[] = "f.fecha <= :filtro_fecha_fin_val";
                    $params_for_where_clause[':filtro_fecha_fin_val'] = $filtro_fecha_fin;
                }

                $whereSql = "";
                if (count($whereConditions) > 0) {
                    $whereSql = "WHERE " . implode(" AND ", $whereConditions);
                }
                $debug_info_capture['whereSql_fact_hist'] = $whereSql;
                $debug_info_capture['params_where_fact_hist'] = $params_for_where_clause;
                
                $sqlCount = "SELECT COUNT(f.id) FROM facturas f LEFT JOIN clientes c ON f.cliente_id = c.id $whereSql";
                $debug_info_capture['query_count_fact_hist'] = $sqlCount;

                $stmtCount = $pdo->prepare($sqlCount);
                if (!$stmtCount) { throw new Exception("PDO::prepare() failed for invoice count query."); }
                if (!empty($params_for_where_clause)) {
                    foreach ($params_for_where_clause as $key => $value) { $stmtCount->bindValue($key, $value); }
                }
                if (!$stmtCount->execute()) { $err = $stmtCount->errorInfo(); throw new PDOException("Execute failed for invoice count: ".($err[2] ?? "Unknown error"), $err[1] ?? 0); }
                $totalRecords = (int)$stmtCount->fetchColumn();
                $debug_info_capture['totalRecords_fact_hist'] = $totalRecords;

                $totalPages = ($limit > 0) ? ceil($totalRecords / $limit) : 0;
                if ($totalPages == 0 && $totalRecords > 0 && $limit > 0) $totalPages = 1;
                if ($page > $totalPages && $totalPages > 0) $page = $totalPages;
                $offset = ($page - 1) * $limit;

                $sqlData = "SELECT f.id, f.fecha, f.cliente_id, c.nombre_razon_social as cliente_nombre, f.total,
                                   f.condicion_pago, f.fecha_vencimiento, f.saldo_pendiente, f.estado_pago
                            FROM facturas f
                            LEFT JOIN clientes c ON f.cliente_id = c.id
                            $whereSql
                            ORDER BY f.fecha DESC, f.id DESC 
                            LIMIT :limit_val OFFSET :offset_val";
                $debug_info_capture['query_data_fact_hist'] = $sqlData;

                $stmtData = $pdo->prepare($sqlData);
                if (!$stmtData) { throw new Exception("PDO::prepare() failed for invoice data query."); }
                if (!empty($params_for_where_clause)) {
                    foreach ($params_for_where_clause as $key => $value) { $stmtData->bindValue($key, $value); }
                }
                $stmtData->bindValue(':limit_val', (int)$limit, PDO::PARAM_INT);
                $stmtData->bindValue(':offset_val', (int)$offset, PDO::PARAM_INT);
                $debug_info_capture['params_data_fact_hist_bound'] = array_merge($params_for_where_clause, [':limit_val' => (int)$limit, ':offset_val' => (int)$offset]);

                if (!$stmtData->execute()) { $err = $stmtData->errorInfo(); throw new PDOException("Execute failed for invoice data: ".($err[2] ?? "Unknown error"), $err[1] ?? 0); }
                $facturas = $stmtData->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($facturas as &$f) { 
                    $f['id'] = (int)$f['id']; 
                    $f['cliente_id'] = isset($f['cliente_id']) ? (int)$f['cliente_id'] : null;
                    $f['total'] = (float)$f['total']; 
                    $f['saldo_pendiente'] = isset($f['saldo_pendiente']) ? (float)$f['saldo_pendiente'] : 0.00;
                } unset($f);

                $response = [
                    'success' => true, 
                    'message' => '', 
                    'data' => $facturas,
                    'pagination' => [
                        'currentPage' => $page,
                        'limit' => $limit,
                        'totalRecords' => $totalRecords,
                        'totalPages' => $totalPages
                    ]
                ];
                if (!empty($debug_info_capture)) $response['debug_info'] = $debug_info_capture;
            }
            break;

        case 'POST':
            // ... (código POST sin cambios sustanciales para esta funcionalidad)
            if (!isset($currentUser['role']) || $currentUser['role'] !== 'Administrador') { 
                http_response_code(403); $response['message'] = 'Acceso prohibido.'; break;
            }
            $input = json_decode(file_get_contents('php://input'), true);

            if (!isset($input['fecha'], $input['clienteId'], $input['items'], $input['condicion_pago']) || 
                !DateTime::createFromFormat('Y-m-d', $input['fecha']) || 
                !filter_var($input['clienteId'], FILTER_VALIDATE_INT) || 
                !is_array($input['items']) || empty($input['items']) ||
                !in_array($input['condicion_pago'], ['contado', 'credito'])) {
                http_response_code(400); 
                $response['message'] = 'Datos de factura incompletos/inválidos. Fecha, Cliente, Items y Cond. Pago son req.'; 
                break;
            }

            $fecha = $input['fecha']; 
            $clienteId = (int)$input['clienteId'];
            $items = $input['items'];
            $condicionPago = $input['condicion_pago'];
            $fechaVencimiento = null;
            $saldoPendiente = 0.00;
            $estadoPago = 'pagada'; 

            if ($condicionPago === 'credito') {
                if (empty($input['fecha_vencimiento']) || !DateTime::createFromFormat('Y-m-d', $input['fecha_vencimiento'])) {
                    http_response_code(400); 
                    $response['message'] = 'Fecha de vencimiento inválida o no proporcionada para factura a crédito.'; 
                    break;
                }
                $fechaFacturaObj = new DateTime($fecha);
                $fechaVencimientoObj = new DateTime($input['fecha_vencimiento']);
                if ($fechaVencimientoObj < $fechaFacturaObj) {
                    http_response_code(400);
                    $response['message'] = 'La fecha de vencimiento no puede ser anterior a la fecha de la factura.';
                    break;
                }
                $fechaVencimiento = $input['fecha_vencimiento'];
                $estadoPago = 'pendiente'; 
            }
            
            $totalFacturaCalculado = 0; $itemsValidos = [];
            $stmtCheckCliente = $pdo->prepare("SELECT nombre_razon_social FROM clientes WHERE id = ? AND activo = 1");
            $stmtCheckCliente->execute([$clienteId]);
            $clienteData = $stmtCheckCliente->fetch();
            if (!$clienteData) { http_response_code(404); $response['message'] = 'Cliente no encontrado o inactivo.'; break; }
            $nombreClienteFactura = $clienteData['nombre_razon_social'];

            foreach ($items as $item) { 
                 if (!isset($item['productoId'], $item['cantidad'], $item['precio']) || !filter_var($item['productoId'], FILTER_VALIDATE_INT) || !is_numeric($item['cantidad']) || $item['cantidad'] <= 0 || !is_numeric($item['precio']) || $item['precio'] < 0) {
                     http_response_code(400); $response['message'] = 'Item inválido en la factura.'; break 2;
                }
                $subtotal = (float)$item['cantidad'] * (float)$item['precio'];
                $totalFacturaCalculado += $subtotal;
                $itemsValidos[] = [ 'productoId' => (int)$item['productoId'], 'cantidad' => (float)$item['cantidad'], 'precio_venta' => (float)$item['precio'], 'subtotal' => $subtotal ];
            }

            if ($condicionPago === 'credito') {
                $saldoPendiente = $totalFacturaCalculado;
                if ($saldoPendiente <= 0) { 
                    $estadoPago = 'pagada';
                    $saldoPendiente = 0.00;
                }
            } 

            $pdo->beginTransaction();
            try {
                $sqlFactura = "INSERT INTO facturas (fecha, cliente_id, total, condicion_pago, fecha_vencimiento, saldo_pendiente, estado_pago) 
                               VALUES (?, ?, ?, ?, ?, ?, ?)";
                $stmtFactura = $pdo->prepare($sqlFactura);
                $stmtFactura->execute([$fecha, $clienteId, $totalFacturaCalculado, $condicionPago, $fechaVencimiento, $saldoPendiente, $estadoPago]);
                $newFacturaId = $pdo->lastInsertId();

                $sqlItem = "INSERT INTO factura_items (factura_id, producto_id, cantidad, precio_venta, subtotal) VALUES (?, ?, ?, ?, ?)";
                $stmtItem = $pdo->prepare($sqlItem);
                $sqlMovimiento = "INSERT INTO movimientos (producto_id, tipo, cantidad, fecha, cliente, factura_id) VALUES (?, 'salida', ?, ?, ?, ?)";
                $stmtMovimiento = $pdo->prepare($sqlMovimiento);

                foreach ($itemsValidos as $itemValidado) {
                    $stmtItem->execute([ $newFacturaId, $itemValidado['productoId'], $itemValidado['cantidad'], $itemValidado['precio_venta'], $itemValidado['subtotal'] ]);
                    $stmtMovimiento->execute([ $itemValidado['productoId'], $itemValidado['cantidad'], $fecha, $nombreClienteFactura, $newFacturaId ]);
                }
                $pdo->commit();
                $response = ['success' => true, 'message' => "Factura ID {$newFacturaId} creada.", 'data' => ['id' => (int)$newFacturaId]];
            } catch (Exception $e) {
                 $pdo->rollBack(); http_response_code(500); error_log("Error Factura TX POST: " . $e->getMessage());
                 $response['message'] = 'Error al procesar la factura: ' . $e->getMessage();
            }
            break; 

        case 'DELETE':
            // ... (código DELETE sin cambios sustanciales para esta funcionalidad)
            if (!isset($currentUser['role']) || $currentUser['role'] !== 'Administrador') {
                http_response_code(403); $response['message'] = 'Acceso prohibido.'; break;
            }
            if (!isset($_GET['id']) || !filter_var($_GET['id'], FILTER_VALIDATE_INT)) {
                http_response_code(400); $response['message'] = 'ID de factura inválido.'; break;
            }
            $facturaId = (int)$_GET['id'];

            $pdo->beginTransaction();
            try {
                $sqlDelMov = "DELETE FROM movimientos WHERE factura_id = ?";
                $stmtDelMov = $pdo->prepare($sqlDelMov);
                $stmtDelMov->execute([$facturaId]);
                
                $sqlDelItems = "DELETE FROM factura_items WHERE factura_id = ?";
                $stmtDelItems = $pdo->prepare($sqlDelItems);
                $stmtDelItems->execute([$facturaId]);
                
                $sqlDelFactura = "DELETE FROM facturas WHERE id = ?";
                $stmtDelFactura = $pdo->prepare($sqlDelFactura);
                $stmtDelFactura->execute([$facturaId]);
                $facturaEliminadaCount = $stmtDelFactura->rowCount();

                if ($facturaEliminadaCount === 0) {
                    $pdo->rollBack(); http_response_code(404);
                    $response['message'] = 'Error: Factura no encontrada.'; break;
                }
                $pdo->commit();
                $response = ['success' => true, 'message' => "Factura ID {$facturaId} y sus movimientos asociados eliminados.", 'data' => ['id' => $facturaId]];
            } catch (Exception $e) {
                 $pdo->rollBack(); http_response_code(500);
                 error_log("Error Factura TX DELETE: " . $e->getMessage());
                 $response['message'] = 'Error al eliminar la factura: ' . $e->getMessage();
            }
            break; 

        default:
            http_response_code(405); $response['message'] = 'Método no permitido.'; break;
    }
} catch (PDOException $e) {
    http_response_code(500); 
    $detailed_error_message = 'Error DB (PDOException Fac): ' . $e->getMessage();
    $exception_code_to_log = $e->getCode(); 
    if (isset($e->errorInfo[1])) { $exception_code_to_log = $e->errorInfo[1]; }
    $debug_info_capture['exception_message'] = $detailed_error_message;
    $debug_info_capture['exception_code'] = $exception_code_to_log;
    error_log($detailed_error_message . " | SQLSTATE from PDO: " . $e->getCode() . " | Driver-specific error code: " . ($e->errorInfo[1] ?? 'N/A') . " | Debug Info: " . json_encode($debug_info_capture));
    $response = ['success' => false, 'message' => 'Error de base de datos (Fac). Consulte el log.', 'data' => null, 'debug_info' => $debug_info_capture];
} catch (Exception $e) {
    http_response_code(500); 
    $detailed_error_message = 'Error General (Exception Fac): ' . $e->getMessage();
    $debug_info_capture['exception_message'] = $detailed_error_message;
    error_log($detailed_error_message . " | Debug Info: " . json_encode($debug_info_capture));
    $response = ['success' => false, 'message' => 'Error general del servidor (Fac). Consulte el log.', 'data' => null, 'debug_info' => $debug_info_capture];
}

echo json_encode($response);
?>