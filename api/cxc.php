<?php
// --- api/cxc.php (CORREGIDO Y COMPLETO) ---
require_once 'session_check.php';
require_once 'db_connection.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') { exit; }
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$response = ['success' => false, 'message' => 'Error desconocido.', 'data' => null];

try {
    switch ($method) {
        case 'GET':
            // *** INICIO DE LA CORRECCIÓN: Distinguir entre las dos solicitudes GET ***
            if (isset($_GET['historial_pagos_factura_id']) && filter_var($_GET['historial_pagos_factura_id'], FILTER_VALIDATE_INT)) {
                // *** CASO 1: OBTENER HISTORIAL DE PAGOS PARA UNA FACTURA ESPECÍFICA ***
                $facturaIdParaHistorial = (int)$_GET['historial_pagos_factura_id'];
                
                $sqlPagos = "SELECT pf.id, pf.fecha_pago, pf.monto_pagado, pf.metodo_pago, 
                                   pf.referencia_pago, pf.observaciones, pf.fecha_registro_pago,
                                   u.username as usuario_registro_nombre
                            FROM pagos_factura pf
                            LEFT JOIN usuarios u ON pf.usuario_registro_id = u.id
                            WHERE pf.factura_id = ?
                            ORDER BY pf.fecha_pago DESC, pf.id DESC";
                $stmtPagos = $pdo->prepare($sqlPagos);
                $stmtPagos->execute([$facturaIdParaHistorial]);
                $pagos = $stmtPagos->fetchAll(PDO::FETCH_ASSOC);

                foreach ($pagos as &$p) {
                    $p['id'] = (int)$p['id'];
                    $p['monto_pagado'] = isset($p['monto_pagado']) ? (float)$p['monto_pagado'] : 0.0;
                    // Las fechas se envían como string, el frontend JS las parseará.
                }
                unset($p);
                $response = ['success' => true, 'data' => $pagos]; // Devuelve array de PAGOS

            } else {
                // *** CASO 2: OBTENER LISTA DE FACTURAS PARA LA TABLA CXC (Lógica que ya tenías) ***
                $sql = "SELECT f.id, f.fecha, f.cliente_id, c.nombre_razon_social as cliente_nombre, 
                               c.codigo_cliente AS cliente_codigo_alfanumerico,
                               f.total, f.condicion_pago, f.fecha_vencimiento, 
                               f.saldo_pendiente, f.estado_pago,
                               IF(f.fecha_vencimiento IS NOT NULL AND f.estado_pago != 'pagada', DATEDIFF(CURDATE(), f.fecha_vencimiento), NULL) as dias_vencidos
                        FROM facturas f
                        JOIN clientes c ON f.cliente_id = c.id
                        WHERE f.condicion_pago = 'credito' AND f.estado_pago IN ('pendiente', 'parcialmente_pagada')";
                
                $params = [];
                if (isset($_GET['cliente_id']) && filter_var($_GET['cliente_id'], FILTER_VALIDATE_INT)) {
                    $sql .= " AND f.cliente_id = ?";
                    $params[] = (int)$_GET['cliente_id'];
                }
                // Renombrado el parámetro para evitar confusión con el del historial de pagos
                if (isset($_GET['factura_id_en_cxc_lista']) && filter_var($_GET['factura_id_en_cxc_lista'], FILTER_VALIDATE_INT)) {
                    $sql .= " AND f.id = ?"; 
                    $params[] = (int)$_GET['factura_id_en_cxc_lista'];
                }
                if (isset($_GET['estado_pago_filtro']) && in_array($_GET['estado_pago_filtro'], ['pendiente', 'parcialmente_pagada'])) {
                     $sql .= " AND f.estado_pago = ?";
                     $params[] = $_GET['estado_pago_filtro'];
                }
                if (isset($_GET['estado_vencimiento']) && $_GET['estado_vencimiento'] === 'vencida') {
                    $sql .= " AND f.fecha_vencimiento IS NOT NULL AND f.fecha_vencimiento < CURDATE() AND f.estado_pago != 'pagada'";
                }
                
                $sql .= " ORDER BY CASE WHEN f.fecha_vencimiento IS NULL THEN 1 ELSE 0 END, f.fecha_vencimiento ASC, f.id ASC";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $facturas_cxc = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($facturas_cxc as &$fcxc) {
                    $fcxc['id'] = (int)$fcxc['id'];
                    $fcxc['cliente_id'] = (int)$fcxc['cliente_id'];
                    $fcxc['total'] = (float)$fcxc['total'];
                    $fcxc['saldo_pendiente'] = (float)$fcxc['saldo_pendiente'];
                    $fcxc['dias_vencidos'] = ($fcxc['dias_vencidos'] !== null && $fcxc['dias_vencidos'] > 0) ? (int)$fcxc['dias_vencidos'] : 0;
                }
                unset($fcxc);
                $response = ['success' => true, 'data' => $facturas_cxc]; // Devuelve array de FACTURAS
            }
            // *** FIN DE LA CORRECCIÓN ***
            break;

        case 'POST': // Registrar un pago/abono (Tu lógica de POST está bien, la mantengo)
            $puedeRegistrarPagos = (isset($currentUser['role']) && ($currentUser['role'] === 'Administrador' || $currentUser['role'] === 'Cajero'));
            if (!$puedeRegistrarPagos) {
                http_response_code(403); $response['message'] = 'Acceso prohibido. Permiso insuficiente.'; break;
            }
            $input = json_decode(file_get_contents('php://input'), true);

            if (!isset($input['factura_id'], $input['monto_pagado'], $input['fecha_pago']) ||
                !filter_var($input['factura_id'], FILTER_VALIDATE_INT) ||
                !is_numeric($input['monto_pagado']) || $input['monto_pagado'] <= 0 ||
                !DateTime::createFromFormat('Y-m-d', $input['fecha_pago'])) {
                http_response_code(400); $response['message'] = 'Datos de pago inválidos.'; break;
            }

            $facturaId = (int)$input['factura_id'];
            $montoPagado = round((float)$input['monto_pagado'], 2);
            $fechaPago = $input['fecha_pago'];
            $metodoPago = isset($input['metodo_pago']) ? trim($input['metodo_pago']) : null;
            $referenciaPago = isset($input['referencia_pago']) ? trim($input['referencia_pago']) : null;
            $observaciones = isset($input['observaciones']) ? trim($input['observaciones']) : null;
            $usuarioRegistroId = $currentUser['id'];

            $pdo->beginTransaction();
            try {
                $stmtFactura = $pdo->prepare("SELECT cliente_id, saldo_pendiente, condicion_pago FROM facturas WHERE id = ? FOR UPDATE");
                $stmtFactura->execute([$facturaId]);
                $facturaData = $stmtFactura->fetch();

                if (!$facturaData) { $pdo->rollBack(); http_response_code(404); $response['message'] = 'Factura no encontrada.'; break; }
                if ($facturaData['condicion_pago'] !== 'credito') { $pdo->rollBack(); http_response_code(400); $response['message'] = 'Solo se pueden registrar pagos a facturas a crédito.'; break; }
                
                $saldoActual = round((float)$facturaData['saldo_pendiente'], 2);
                if ($saldoActual <= 0) { $pdo->rollBack(); http_response_code(400); $response['message'] = 'Esta factura ya está pagada.'; break; }
                if ($montoPagado > $saldoActual) {
                    $pdo->rollBack(); http_response_code(400);
                    $response['message'] = 'El monto pagado (' . number_format($montoPagado,2) . ') excede el saldo pendiente (' . number_format($saldoActual,2) . ').'; break;
                }
                $clienteIdFactura = $facturaData['cliente_id'];

                $sqlPago = "INSERT INTO pagos_factura (factura_id, cliente_id, fecha_pago, monto_pagado, metodo_pago, referencia_pago, observaciones, usuario_registro_id)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $stmtPago = $pdo->prepare($sqlPago);
                $stmtPago->execute([$facturaId, $clienteIdFactura, $fechaPago, $montoPagado, $metodoPago, $referenciaPago, $observaciones, $usuarioRegistroId]);
                $newPagoId = $pdo->lastInsertId();

                $nuevoSaldo = round($saldoActual - $montoPagado, 2);
                $nuevoEstadoPago = ($nuevoSaldo <= 0) ? 'pagada' : 'parcialmente_pagada';
                if ($nuevoSaldo < 0) $nuevoSaldo = 0.00;

                $sqlUpdFactura = "UPDATE facturas SET saldo_pendiente = ?, estado_pago = ? WHERE id = ?";
                $stmtUpdFactura = $pdo->prepare($sqlUpdFactura);
                $stmtUpdFactura->execute([$nuevoSaldo, $nuevoEstadoPago, $facturaId]);
                
                $pdo->commit();
                $response = ['success' => true, 'message' => 'Pago registrado.', 'data' => ['pago_id' => (int)$newPagoId, 'nuevo_saldo' => $nuevoSaldo]];
            } catch (Exception $e) {
                $pdo->rollBack(); http_response_code(500);
                error_log("Error CXC TX POST Pago: " . $e->getMessage());
                $response['message'] = 'Error al procesar el pago: ' . $e->getMessage();
            }
            break;

        default:
            http_response_code(405); $response['message'] = "Método {$method} no permitido."; break;
    }
} catch (PDOException $e) {
    http_response_code(500); error_log("DB Error CXC: " . $e->getMessage());
    $response['message'] = 'Error de BD (CXC). ' . $e->getMessage();
} catch (Exception $e) {
    http_response_code(500); error_log("General Error CXC: " . $e->getMessage());
    $response['message'] = 'Error general del servidor (CXC). ' . $e->getMessage();
}

echo json_encode($response);
?>