<?php
declare(strict_types=1);

/**
 * Recurso: usuarios
 *   POST /api/usuarios.php   -> registrar usuario { nombre, clave }  (inicia sesión automáticamente)
 */

require __DIR__ . '/bootstrap.php';

iniciar_endpoint(['POST']);

// A04: el alta masiva de cuentas también se limita por IP.
if (contar_intentos('registro', 3600, ip_cliente()) >= MAX_REGISTROS_IP) {
    header('Retry-After: 3600');
    error_out('Demasiados registros desde esta conexión. Inténtalo más tarde.', 429, 'limite');
}
registrar_intento('registro');

$d = cuerpo();
$nombre = trim((string) ($d['nombre'] ?? ''));
$clave  = (string) ($d['clave'] ?? '');

if (!preg_match('/^[\p{L}\p{N}_.\-]{3,50}$/u', $nombre)) {
    error_out('El usuario debe tener entre 3 y 50 caracteres: letras, números, punto, guion o guion bajo.', 422);
}
if (strcasecmp($nombre, ADMIN_USER) === 0) {
    error_out('Ese nombre de usuario está reservado.', 409);
}
// bcrypt solo usa los primeros 72 bytes, por eso ese es el máximo.
if (strlen($clave) < 8 || strlen($clave) > 72) {
    error_out('La contraseña debe tener entre 8 y 72 caracteres.', 422);
}
if (strcasecmp($clave, $nombre) === 0) {
    error_out('La contraseña no puede ser igual al usuario.', 422);
}

$pdo = db();
$st = $pdo->prepare('SELECT 1 FROM usuarios WHERE nombre = ? LIMIT 1');
$st->execute([$nombre]);
if ($st->fetchColumn()) {
    error_out('Ese nombre de usuario ya está en uso.', 409);
}

try {
    $pdo->prepare('INSERT INTO usuarios (nombre, clave) VALUES (?, ?)')
        ->execute([$nombre, hashear_clave($clave)]);
} catch (PDOException $e) {
    if (($e->errorInfo[1] ?? 0) === 1062) { // duplicado (carrera entre dos registros)
        error_out('Ese nombre de usuario ya está en uso.', 409);
    }
    throw $e;
}

iniciar_sesion(['id' => (int) $pdo->lastInsertId(), 'nombre' => $nombre]);
log_evento('registro_ok');
json_out([
    'autenticado' => true,
    'usuario'     => usuario_actual(),
    'csrf'        => $_SESSION['csrf'],
], 201);
