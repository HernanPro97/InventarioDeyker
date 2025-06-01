<?php
// --- api/clientes.php (Actualizado para ID como código, nombre único y Deuda Total) ---
require_once 'session_check.php';
require_once 'db_connection.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') { exit; }
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$response = ['success' => false, 'message' => 'Error desconocido.', 'data' => null];

try {
    switch ($method) {
        case 'GET':
            if (isset($_GET['id']) && filter_var($_GET['id'], FILTER_VALIDATE_INT)) {
                $clienteId = (int)$_GET['id'];
                // Obtener cliente individual con su deuda
                $sql = "SELECT 
                            c.id, 
                            c.codigo_cliente AS codigo_alfanumerico, 
                            c.nombre_razon_social, 
                            c.identificacion_fiscal, 
                            c.direccion, 
                            c.telefono, 
                            c.email, 
                            c.activo,
                            COALESCE(SUM(CASE 
                                WHEN f.condicion_pago = 'credito' AND f.estado_pago != 'pagada' 
                                THEN f.saldo_pendiente 
                                ELSE 0 
                            END), 0) AS deuda_total_pendiente
                        FROM clientes c
                        LEFT JOIN facturas f ON c.id = f.cliente_id 
                                            AND f.condicion_pago = 'credito' 
                                            AND f.estado_pago != 'pagada'
                        WHERE c.id = :cliente_id
                        GROUP BY c.id, c.codigo_cliente, c.nombre_razon_social, c.identificacion_fiscal, c.direccion, c.telefono, c.email, c.activo";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':cliente_id' => $clienteId]);
                $cliente = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($cliente) {
                    $cliente['id'] = (int)$cliente['id'];
                    $cliente['activo'] = (bool)$cliente['activo'];
                    $cliente['deuda_total_pendiente'] = (float)$cliente['deuda_total_pendiente'];
                    $response = ['success' => true, 'data' => $cliente];
                } else {
                    http_response_code(404);
                    $response['message'] = 'Cliente no encontrado.';
                }
            } else {
                // Listar todos los clientes con su deuda
                $soloActivos = isset($_GET['activo']) && $_GET['activo'] === '1';
                
                $sql = "SELECT 
                            c.id, 
                            c.codigo_cliente AS codigo_alfanumerico, 
                            c.nombre_razon_social, 
                            c.identificacion_fiscal, 
                            c.telefono, 
                            c.email, 
                            c.activo,
                            COALESCE(SUM(CASE 
                                WHEN f.condicion_pago = 'credito' AND f.estado_pago != 'pagada' 
                                THEN f.saldo_pendiente 
                                ELSE 0 
                            END), 0) AS deuda_total_pendiente
                        FROM clientes c
                        LEFT JOIN facturas f ON c.id = f.cliente_id 
                                            AND f.condicion_pago = 'credito' 
                                            AND f.estado_pago != 'pagada'";
                
                if ($soloActivos) {
                    $sql .= " WHERE c.activo = 1";
                }
                $sql .= " GROUP BY c.id, c.codigo_cliente, c.nombre_razon_social, c.identificacion_fiscal, c.telefono, c.email, c.activo";
                $sql .= " ORDER BY c.id ASC"; 
                
                $stmt = $pdo->query($sql);
                $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
                 foreach($clientes as &$cli) {
                    $cli['id'] = (int)$cli['id'];
                    $cli['activo'] = (bool)$cli['activo'];
                    $cli['deuda_total_pendiente'] = (float)$cli['deuda_total_pendiente'];
                 }
                 unset($cli);
                $response = ['success' => true, 'data' => $clientes];
            }
            break;

        case 'POST':
            if ($currentUser['role'] !== 'Administrador') {
                http_response_code(403); $response['message'] = 'Acceso prohibido.'; break;
            }
            $input = json_decode(file_get_contents('php://input'), true);

            if (!isset($input['nombre_razon_social']) || empty(trim($input['nombre_razon_social']))) {
                http_response_code(400); $response['message'] = 'El nombre o razón social es obligatorio.'; break;
            }
            
            $sql = "INSERT INTO clientes (codigo_cliente, nombre_razon_social, identificacion_fiscal, direccion, telefono, email, activo) 
                    VALUES (:codigo_cliente, :nombre_razon_social, :identificacion_fiscal, :direccion, :telefono, :email, :activo)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':codigo_cliente' => isset($input['codigo_cliente_alfanumerico']) && !empty(trim($input['codigo_cliente_alfanumerico'])) ? trim($input['codigo_cliente_alfanumerico']) : null,
                ':nombre_razon_social' => trim($input['nombre_razon_social']),
                ':identificacion_fiscal' => isset($input['identificacion_fiscal']) ? trim($input['identificacion_fiscal']) : null,
                ':direccion' => isset($input['direccion']) ? trim($input['direccion']) : null,
                ':telefono' => isset($input['telefono']) ? trim($input['telefono']) : null,
                ':email' => isset($input['email']) ? trim($input['email']) : null,
                ':activo' => isset($input['activo']) ? (bool)$input['activo'] : true
            ]);
            $newId = $pdo->lastInsertId(); 
            $response = ['success' => true, 'message' => 'Cliente creado (ID: '.$newId.').', 'data' => ['id' => (int)$newId]];
            break;

        case 'PUT':
            if ($currentUser['role'] !== 'Administrador') {
                http_response_code(403); $response['message'] = 'Acceso prohibido.'; break;
            }
            if (!isset($_GET['id']) || !filter_var($_GET['id'], FILTER_VALIDATE_INT)) {
                http_response_code(400); $response['message'] = 'ID de cliente inválido.'; break;
            }
            $clienteIdToUpdate = (int)$_GET['id'];
            $input = json_decode(file_get_contents('php://input'), true);

            if (!isset($input['nombre_razon_social']) || empty(trim($input['nombre_razon_social']))) {
                http_response_code(400); $response['message'] = 'El nombre es obligatorio.'; break;
            }

            $sql = "UPDATE clientes SET 
                        codigo_cliente = :codigo_cliente, 
                        nombre_razon_social = :nombre_razon_social, 
                        identificacion_fiscal = :identificacion_fiscal, 
                        direccion = :direccion, 
                        telefono = :telefono, 
                        email = :email,
                        activo = :activo
                    WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $updateSuccess = $stmt->execute([
                ':codigo_cliente' => isset($input['codigo_cliente_alfanumerico']) && !empty(trim($input['codigo_cliente_alfanumerico'])) ? trim($input['codigo_cliente_alfanumerico']) : null,
                ':nombre_razon_social' => trim($input['nombre_razon_social']),
                ':identificacion_fiscal' => isset($input['identificacion_fiscal']) ? trim($input['identificacion_fiscal']) : null,
                ':direccion' => isset($input['direccion']) ? trim($input['direccion']) : null,
                ':telefono' => isset($input['telefono']) ? trim($input['telefono']) : null,
                ':email' => isset($input['email']) ? trim($input['email']) : null,
                ':activo' => isset($input['activo']) ? (bool)$input['activo'] : true,
                ':id' => $clienteIdToUpdate
            ]);

            if ($updateSuccess && $stmt->rowCount() > 0) {
                $response = ['success' => true, 'message' => 'Cliente actualizado.'];
            } elseif ($updateSuccess && $stmt->rowCount() === 0) {
                $response = ['success' => true, 'message' => 'No se realizaron cambios (datos iguales o cliente no encontrado).'];
            } else { 
                http_response_code(500); $response['message'] = 'Error al actualizar el cliente.';
            }
            break;

        case 'DELETE': 
            if ($currentUser['role'] !== 'Administrador') {
                http_response_code(403); $response['message'] = 'Acceso prohibido.'; break;
            }
            if (!isset($_GET['id']) || !filter_var($_GET['id'], FILTER_VALIDATE_INT)) {
                http_response_code(400); $response['message'] = 'ID de cliente inválido.'; break;
            }
            $clienteIdToToggle = (int)$_GET['id'];

            $stmtCheck = $pdo->prepare("SELECT activo FROM clientes WHERE id = ?");
            $stmtCheck->execute([$clienteIdToToggle]);
            $currentStatus = $stmtCheck->fetchColumn();

            if ($currentStatus === false) { 
                http_response_code(404); $response['message'] = 'Cliente no encontrado.'; break;
            }
            
            $newStatus = ($currentStatus == 1) ? 0 : 1; 
            $actionText = ($newStatus == 1) ? 'activado' : 'desactivado';

            $sql = "UPDATE clientes SET activo = ? WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $toggleSuccess = $stmt->execute([$newStatus, $clienteIdToToggle]);

            if ($toggleSuccess && $stmt->rowCount() > 0) {
                $response = ['success' => true, 'message' => "Cliente {$actionText}."];
            } else {
                http_response_code(500); $response['message'] = "Error al {$actionText} el cliente.";
            }
            break;

        default:
            http_response_code(405); $response['message'] = "Método {$method} no permitido."; break;
    }
} catch (PDOException $e) {
    http_response_code(500); 
    $errorMessage = 'Error de BD (Clientes).';
    if ($e->getCode() == '23000') { 
        if (strpos(strtolower($e->getMessage()), 'uq_nombre_razon_social') !== false) {
            http_response_code(409); 
            $errorMessage = 'Error: Ya existe un cliente con ese nombre o razón social.';
        } elseif (strpos(strtolower($e->getMessage()), 'codigo_cliente_unique') !== false || strpos(strtolower($e->getMessage()), 'for key \'codigo_cliente\'') !== false) { 
             http_response_code(409);
             $errorMessage = 'Error: El código alfanumérico de cliente proporcionado ya existe.';
        } else {
            http_response_code(409);
            $errorMessage = 'Error: Valor duplicado. Verifique los datos.';
        }
    }
    error_log("PDOException (Clientes) [{$e->getCode()}]: " . $e->getMessage());
    $response['message'] = $errorMessage;
} catch (Exception $e) {
    http_response_code(500);
    error_log("Exception (Clientes): " . $e->getMessage());
    $response['message'] = 'Error general del servidor (Clientes).';
}

echo json_encode($response);
?>