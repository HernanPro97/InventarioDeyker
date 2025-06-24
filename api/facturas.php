<?php
// --- api/facturas.php (Actualizado para Anulación Lógica) ---
require_once 'session_check.php'; // Asegura que $currentUser está disponible
require_once 'db_connection.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS"); // PUT es para anulación ahora
header("Access-Control-Allow-Headers: Content-Type, Authorization");
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') { exit; }
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$response = ['success' => false, 'message' => 'Método no válido o error.', 'data' => null];
$debug_info_capture = [];

try {
    switch ($method) {
        case 'GET':
            // ... (Lógica GET sin cambios para esta funcionalidad, pero debe considerar el campo 'anulada')
            // Modificar la consulta para listar facturas para que no muestre anuladas por defecto,
            // o añadir un filtro para incluirlas si es necesario.
            if (isset($_GET['id']) && filter_var($_GET['id'], FILTER_VALIDATE_INT)) {
                $facturaId = (int)$_GET['id'];
                // Incluir campos de anulación al obtener una factura específica
                $sqlFactura = "SELECT f.id, f.fecha, f.cliente_id, c.nombre_razon_social as cliente_nombre, f.total,
                                      f.condicion_pago, f.fecha_vencimiento, f.saldo_pendiente, f.estado_pago,
                                      f.anulada, f.fecha_anulacion, f.motivo_anulacion, u_anul.username as usuario_anulacion_nombre
                               FROM facturas f
                               LEFT JOIN clientes c ON f.cliente_id = c.id
                               LEFT JOIN usuarios u_anul ON f.usuario_anulacion_id = u_anul.id
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
                $factura['anulada'] = (bool)$factura['anulada']; // Convertir a booleano
                foreach ($items as &$item) { 
                    $item['id'] = (int)$item['id']; $item['producto_id'] = (int)$item['producto_id'];
                    $item['cantidad'] = (float)$item['cantidad']; $item['precio_venta'] = (float)$item['precio_venta'];
                    $item['subtotal'] = (float)$item['subtotal']; $item['producto_codigo'] = (int)$item['producto_codigo'];
                } unset($item);
                $factura['items'] = $items;
                $response = ['success' => true, 'message' => '', 'data' => $factura];

            } else {
                $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
                $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 25;
                // ... (resto de parámetros de filtro y paginación) ...
                $filtro_texto = isset($_GET['filtro_texto']) ? trim($_GET['filtro_texto']) : null;
                $filtro_fecha_inicio = isset($_GET['filtro_fecha_inicio']) ? $_GET['filtro_fecha_inicio'] : null;
                $filtro_fecha_fin = isset($_GET['filtro_fecha_fin']) ? $_GET['filtro_fecha_fin'] : null;
                // Nuevo filtro para ver anuladas
                $ver_anuladas = isset($_GET['ver_anuladas']) && $_GET['ver_anuladas'] === '1';


                $params_for_where_clause = [];
                $whereConditions = [];

                if (!$ver_anuladas) { // Por defecto, no mostrar anuladas
                    $whereConditions[] = "f.anulada = 0";
                }

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
                // ... (construcción de $whereSql, $sqlCount, $sqlData, ejecución y respuesta como antes) ...
                // Asegúrate de incluir f.anulada en el SELECT de $sqlData si quieres usarlo en el frontend para estilizar
                $whereSql = "";
                if (count($whereConditions) > 0) {
                    $whereSql = "WHERE " . implode(" AND ", $whereConditions);
                }
                
                $sqlCount = "SELECT COUNT(f.id) FROM facturas f LEFT JOIN clientes c ON f.cliente_id = c.id $whereSql";
                $stmtCount = $pdo->prepare($sqlCount);
                // ... (bind y execute para stmtCount) ...
                if (!empty($params_for_where_clause)) {
                    $stmtCount->execute($params_for_where_clause);
                } else {
                    $stmtCount->execute();
                }
                $totalRecords = (int)$stmtCount->fetchColumn();

                $totalPages = ($limit > 0) ? ceil($totalRecords / $limit) : 0;
                if ($totalPages == 0 && $totalRecords > 0 && $limit > 0) $totalPages = 1;
                if ($page > $totalPages && $totalPages > 0) $page = $totalPages;
                $offset = ($page - 1) * $limit;

                $sqlData = "SELECT f.id, f.fecha, f.cliente_id, c.nombre_razon_social as cliente_nombre, f.total,
                                   f.condicion_pago, f.fecha_vencimiento, f.saldo_pendiente, f.estado_pago, f.anulada
                            FROM facturas f
                            LEFT JOIN clientes c ON f.cliente_id = c.id
                            $whereSql
                            ORDER BY f.anulada ASC, f.fecha DESC, f.id DESC 
                            LIMIT :limit_val OFFSET :offset_val";
                
                $stmtData = $pdo->prepare($sqlData);
                if (!empty($params_for_where_clause)) {
                    foreach ($params_for_where_clause as $key => $value) { $stmtData->bindValue($key, $value); }
                }
                $stmtData->bindValue(':limit_val', (int)$limit, PDO::PARAM_INT);
                $stmtData->bindValue(':offset_val', (int)$offset, PDO::PARAM_INT);
                $stmtData->execute();
                $facturas = $stmtData->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($facturas as &$f) { 
                    $f['id'] = (int)$f['id']; 
                    $f['cliente_id'] = isset($f['cliente_id']) ? (int)$f['cliente_id'] : null;
                    $f['total'] = (float)$f['total']; 
                    $f['saldo_pendiente'] = isset($f['saldo_pendiente']) ? (float)$f['saldo_pendiente'] : 0.00;
                    $f['anulada'] = (bool)$f['anulada'];
                } unset($f);

                $response = [
                    'success' => true, 'message' => '', 'data' => $facturas,
                    'pagination' => [ /* ... paginación ... */ 
                        'currentPage' => $page, 'limit' => $limit,
                        'totalRecords' => $totalRecords, 'totalPages' => $totalPages
                    ]
                ];
            }
            break;

        case 'POST': // Crear factura
            // ... (lógica POST sin cambios) ...
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

        case 'DELETE': // Ahora será ANULAR factura (usaremos PUT para esto semánticamente)
            // Este case 'DELETE' ya no se usará para anular. La anulación se hará con PUT.
            // Podrías dejarlo para borrado físico REAL si alguna vez se necesita y eres admin,
            // o simplemente eliminar este case y manejar todo con PUT.
            // Por ahora, lo comentaremos para evitar confusión.
            /*
            if (!isset($currentUser['role']) || $currentUser['role'] !== 'Administrador') {
                http_response_code(403); $response['message'] = 'Acceso prohibido.'; break;
            }
            // ... (lógica de borrado físico anterior) ...
            */
            http_response_code(405); $response['message'] = 'Método DELETE no soportado para anulación. Use PUT.';
            break;

        case 'PUT': // Usaremos PUT para anular una factura
            if (!isset($currentUser['role']) || $currentUser['role'] !== 'Administrador') {
                http_response_code(403); $response['message'] = 'Acceso prohibido para anular facturas.'; break;
            }
            if (!isset($_GET['id']) || !filter_var($_GET['id'], FILTER_VALIDATE_INT)) {
                http_response_code(400); $response['message'] = 'ID de factura inválido para anular.'; break;
            }
            $facturaIdAnular = (int)$_GET['id'];
            
            $input = json_decode(file_get_contents('php://input'), true);
            $motivoAnulacion = isset($input['motivo_anulacion']) ? trim($input['motivo_anulacion']) : null;

            if (empty($motivoAnulacion)) {
                http_response_code(400); $response['message'] = 'Se requiere un motivo para la anulación.'; break;
            }

            $pdo->beginTransaction();
            try {
                // 1. Verificar que la factura exista y no esté ya anulada
                $stmtCheck = $pdo->prepare("SELECT anulada, saldo_pendiente FROM facturas WHERE id = ?");
                $stmtCheck->execute([$facturaIdAnular]);
                $facturaActual = $stmtCheck->fetch();

                if (!$facturaActual) {
                    $pdo->rollBack(); http_response_code(404);
                    $response['message'] = 'Factura no encontrada.'; break;
                }
                if ($facturaActual['anulada']) {
                    $pdo->rollBack(); http_response_code(409); // Conflict
                    $response['message'] = 'Esta factura ya ha sido anulada previamente.'; break;
                }

                // 2. Marcar la factura como anulada
                $sqlAnularFactura = "UPDATE facturas SET 
                                        anulada = 1, 
                                        fecha_anulacion = NOW(), 
                                        motivo_anulacion = ?, 
                                        usuario_anulacion_id = ?,
                                        estado_pago = 'anulada', /* Opcional: actualizar estado_pago */
                                        saldo_pendiente = 0      /* Opcional: poner saldo a cero */
                                     WHERE id = ?";
                $stmtAnular = $pdo->prepare($sqlAnularFactura);
                $stmtAnular->execute([$motivoAnulacion, $currentUser['id'], $facturaIdAnular]);

                // 3. Eliminar los movimientos de inventario asociados a esta factura
                // (esto revierte el stock)
                $sqlDelMov = "DELETE FROM movimientos WHERE factura_id = ?";
                $stmtDelMov = $pdo->prepare($sqlDelMov);
                $stmtDelMov->execute([$facturaIdAnular]);
                
                // 4. Opcional: Si la factura tenía pagos, se podría decidir qué hacer con ellos.
                // Por ahora, los pagos permanecerán pero la factura estará anulada.
                // Podrías añadir lógica para "anular" también los pagos o marcarlos como no aplicables.
                // Por ejemplo, actualizando una columna 'anulado' en la tabla 'pagos_factura'.
                // $stmtAnularPagos = $pdo->prepare("UPDATE pagos_factura SET anulado = 1, motivo_anulacion_pago = ? WHERE factura_id = ?");
                // $stmtAnularPagos->execute(['Factura anulada: ' . $motivoAnulacion, $facturaIdAnular]);


                $pdo->commit();
                $response = ['success' => true, 'message' => "Factura ID {$facturaIdAnular} anulada correctamente.", 'data' => ['id' => $facturaIdAnular]];
            } catch (Exception $e) {
                 $pdo->rollBack(); http_response_code(500);
                 error_log("Error Factura TX ANULAR: " . $e->getMessage());
                 $response['message'] = 'Error al anular la factura: ' . $e->getMessage();
            }
            break; 

        default:
            http_response_code(405); $response['message'] = 'Método no permitido.'; break;
    }
} catch (PDOException $e) {
    http_response_code(500); 
    $detailed_error_message = 'Error DB (PDOException Fac): ' . $e->getMessage();
    // ... (logging y respuesta de error como antes) ...
    error_log($detailed_error_message . " | Debug Info: " . json_encode($debug_info_capture));
    $response = ['success' => false, 'message' => 'Error de base de datos (Fac). Consulte el log.', 'data' => null, 'debug_info' => $debug_info_capture];
} catch (Exception $e) {
    http_response_code(500); 
    $detailed_error_message = 'Error General (Exception Fac): ' . $e->getMessage();
    // ... (logging y respuesta de error como antes) ...
    error_log($detailed_error_message . " | Debug Info: " . json_encode($debug_info_capture));
    $response = ['success' => false, 'message' => 'Error general del servidor (Fac). Consulte el log.', 'data' => null, 'debug_info' => $debug_info_capture];
}

echo json_encode($response);
?>