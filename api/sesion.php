<?php
declare(strict_types=1);

/**
 * Recurso: sesión
 *   GET    /api/sesion.php   -> estado de la sesión + token CSRF
 *   POST   /api/sesion.php   -> iniciar sesión   { nombre, clave }
 *   DELETE /api/sesion.php   -> cerrar sesión (destruye la sesión en el servidor)
 */

require __DIR__ . '/bootstrap.php';

iniciar_endpoint(['GET', 'POST', 'DELETE']);

// Hash de relleno (mismo coste que los reales): se usa cuando el usuario no existe
// para que el tiempo de respuesta no revele qué usuarios existen.
const HASH_RELLENO = '$2y$12$mUVYqrsa8vzKWe91bE9ffOggTqZ.ABZt02vYTl6X4QXjJqrXXxBbu';

switch (metodo()) {
    case 'GET':
        $u = usuario_actual();
        json_out([
            'autenticado' => $u !== null,
            'usuario'     => $u,
            'csrf'        => $_SESSION['csrf'],
        ]);

    case 'POST':
        $d = cuerpo();
        $nombre = trim((string) ($d['nombre'] ?? ''));
        $clave  = (string) ($d['clave'] ?? '');

        if ($nombre === '' || mb_strlen($nombre) > 50 || $clave === '' || strlen($clave) > 200) {
            error_out('Escribe tu usuario y tu contraseña.', 422);
        }

        // Fricción progresiva (A07): límites persistentes en BD, no en la sesión
        // (la sesión se evade descartando la cookie).
        $ip = ip_cliente();
        if (
            contar_intentos('login', VENTANA_INTENTOS, $ip, $nombre) >= MAX_FALLOS_PAR
            || contar_intentos('login', VENTANA_INTENTOS, $ip) >= MAX_FALLOS_IP
            || contar_intentos('login', VENTANA_INTENTOS, null, $nombre) >= MAX_FALLOS_USUARIO
        ) {
            header('Retry-After: ' . VENTANA_INTENTOS);
            error_out('Demasiados intentos fallidos. Inténtalo de nuevo más tarde.', 429, 'limite');
        }

        $st = db()->prepare('SELECT id, nombre, clave FROM usuarios WHERE nombre = ? LIMIT 1');
        $st->execute([$nombre]);
        $usuario = $st->fetch() ?: null;

        $hash = $usuario !== null ? $usuario['clave'] : HASH_RELLENO;
        $ok = password_verify($clave, $hash) && $usuario !== null;

        if (!$ok) {
            registrar_intento('login', $nombre);
            log_evento('login_failed', ['userIdObjetivo' => $usuario !== null ? (int) $usuario['id'] : null]);
            // Mensaje genérico: no revela si el usuario existe.
            error_out('Usuario o contraseña incorrectos.', 401);
        }

        // Si cambia el algoritmo o el coste, se actualiza el hash de forma transparente.
        if (password_needs_rehash($usuario['clave'], PASSWORD_BCRYPT, ['cost' => BCRYPT_COST])) {
            db()->prepare('UPDATE usuarios SET clave = ? WHERE id = ?')
                ->execute([hashear_clave($clave), $usuario['id']]);
        }

        limpiar_intentos('login', $ip, $nombre);
        iniciar_sesion($usuario);
        log_evento('login_ok');
        json_out([
            'autenticado' => true,
            'usuario'     => usuario_actual(),
            'csrf'        => $_SESSION['csrf'],
        ]);

    case 'DELETE':
        // Invalidación explícita (A07): se destruye la sesión en el servidor, no solo la interfaz.
        log_evento('logout');
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 3600,
                'path'     => $p['path'],
                'httponly' => true,
                'samesite' => 'Strict',
                'secure'   => $p['secure'],
            ]);
        }
        session_destroy();
        json_out(['ok' => true]);
}
