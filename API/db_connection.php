<?php
// --- api/db_connection.php ---

// Configuración de la conexión a la base de datos
$db_host = 'localhost';     // El servidor de la base de datos (generalmente localhost si está en la misma máquina)
$db_name = 'inventario_app';// El nombre de la base de datos que creamos
$db_user = 'root';          // El usuario de la base de datos (por defecto en XAMPP es 'root')
$db_pass = '';              // La contraseña del usuario (por defecto en XAMPP es vacía '')

// Opciones de PDO para mejorar la conexión y el manejo de errores
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Lanzar excepciones en caso de error SQL
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Devolver resultados como arrays asociativos por defecto
    PDO::ATTR_EMULATE_PREPARES   => true,                  // Usar preparaciones nativas de la base de datos (más seguro)
];

// Intento de conexión
try {
    // Crear una nueva instancia de PDO (PHP Data Objects)
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, $options);
    // ¡Conexión exitosa! La variable $pdo está lista para ser usada en otros scripts.
    // echo "Conexión a la base de datos ($db_name) exitosa."; // Puedes descomentar esta línea temporalmente para probar

} catch (PDOException $e) {
    // Si la conexión falla, mostrar un mensaje de error genérico y detener el script.
    // En una aplicación real, registrarías el error detallado ($e->getMessage()) en un archivo de log,
    // pero no lo mostrarías directamente al usuario por seguridad.
    http_response_code(500); // Internal Server Error
    die("Error: No se pudo conectar a la base de datos. Por favor, intente más tarde.");
    // O para depuración: die("Error de conexión a la BD: " . $e->getMessage());
}

?>