<?php
// login.php
header('Content-Type: application/json; charset=utf-8');
session_start(); // Iniciar la sesión

// Configuración de la base de datos (¡Ajusta estos valores!)
$dbHost = 'localhost';
$dbUser = 'root'; // Usar el usuario que configuraste
$dbPass = ''; // Usar la contraseña del usuario
$dbName = 'inventario_app'; // Reemplaza con el nombre de tu base de datos

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error de conexión a la base de datos: ' . $e->getMessage()]);
    exit;
}

// Recibir datos del formulario (JSON)
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// Validar que se recibieron los datos
if (!isset($data['username']) || !isset($data['password'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Faltan datos: username y password son requeridos.']);
    exit;
}

$username = $data['username'];
$password = $data['password'];

// Buscar el usuario en la base de datos
$stmt = $pdo->prepare("SELECT id, username, password_hash, role FROM usuarios WHERE username = ?");
$stmt->execute([$username]);
$user = $stmt->fetch();

// Verificar si el usuario existe
if (!$user) {
    http_response_code(401); // No autorizado
    echo json_encode(['success' => false, 'message' => 'Credenciales inválidas.']);
    exit;
}

// Verificar la contraseña
if (!password_verify($password, $user['password_hash'])) {
    http_response_code(401); // No autorizado
    echo json_encode(['success' => false, 'message' => 'Credenciales inválidas.']);
    exit;
}

// Autenticación exitosa
$_SESSION['user_id'] = $user['id'];
$_SESSION['username'] = $user['username'];
$_SESSION['role'] = $user['role'];

// Devolver información del usuario (sin la contraseña hasheada)
echo json_encode([
    'success' => true,
    'message' => 'Inicio de sesión exitoso.',
    'data' => [
        'id' => $user['id'],
        'username' => $user['username'],
        'role' => $user['role']
    ]
]);
?>