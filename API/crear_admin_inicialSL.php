<?php
// --- Configuración de la Base de Datos ---
// !!! AJUSTA ESTOS VALORES A TU CONFIGURACIÓN !!!
$dbHost = 'localhost'; // o tu host de BD
$dbUser = 'root';
$dbPass = '';
$dbName = 'inventario_app'; // El nombre de tu base de datos de inventario

// --- Datos del Administrador a Crear ---
// !!! CAMBIA ESTOS VALORES !!!
$adminUsername = 'lectura'; // Elige un nombre de usuario
$adminPassword = '1234h'; // Elige una contraseña FUERTE
$adminRole = 'Solo Lectura';

// --- Lógica ---
header('Content-Type: text/plain; charset=utf-8'); // Para mostrar salida simple

try {
    // Conexión PDO (recomendado)
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Verificar si ya existe el usuario
    $stmtCheck = $pdo->prepare("SELECT id FROM usuarios WHERE username = ?");
    $stmtCheck->execute([$adminUsername]);
    if ($stmtCheck->fetch()) {
        echo "ERROR: El usuario '$adminUsername' ya existe. No se creó nada.\n";
        exit;
    }

    // Hashear la contraseña
    $passwordHash = password_hash($adminPassword, PASSWORD_DEFAULT);
    if ($passwordHash === false) {
        throw new Exception("Error al hashear la contraseña.");
    }

    // Insertar el usuario
    $stmtInsert = $pdo->prepare("INSERT INTO usuarios (username, password_hash, role) VALUES (?, ?, ?)");
    $stmtInsert->execute([$adminUsername, $passwordHash, $adminRole]);

    echo "¡ÉXITO! Usuario Administrador '$adminUsername' creado correctamente.\n";
    echo "Recuerda eliminar este archivo (crear_admin_inicial.php) ahora.\n";

} catch (PDOException $e) {
    // No mostrar detalles sensibles en producción real
    error_log("Error de Base de Datos al crear admin inicial: " . $e->getMessage());
    echo "ERROR de Base de Datos. Revisa los logs del servidor.\n";
} catch (Exception $e) {
    error_log("Error General al crear admin inicial: " . $e->getMessage());
    echo "ERROR General. Revisa los logs del servidor.\n";
}
?>