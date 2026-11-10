<?php
declare(strict_types=1);

/**
 * Utilidades de seguridad compartidas:
 *   - Identificador de petición (correlación)          -> A09
 *   - Logs estructurados en JSON sin datos sensibles   -> A09
 *   - Limitación de intentos persistente en BD         -> A04 / A07
 *   - Cabeceras de seguridad                           -> A02 / A05
 */

/** Id único por petición: permite correlacionar logs y respuestas de error. */
function id_peticion(): string
{
    static $id = null;
    return $id ??= bin2hex(random_bytes(6));
}

function ip_cliente(): string
{
    // Solo REMOTE_ADDR: X-Forwarded-For lo controla el cliente y permitiría evadir el límite.
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

/**
 * Escribe una línea JSON en el log de seguridad.
 * NUNCA incluir contraseñas, hashes, tokens CSRF ni identificadores de sesión.
 * json_encode escapa saltos de línea y comillas, lo que impide inyectar líneas falsas en el log.
 */
function log_evento(string $evento, array $contexto = []): void
{
    $registro = [
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        'event'     => $evento,
        'requestId' => id_peticion(),
        'userId'    => isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : null,
        'ip'        => ip_cliente(),
        'method'    => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
        'path'      => substr(parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '', 0, 120),
    ] + $contexto;

    $archivo = defined('LOG_FILE') ? LOG_FILE : dirname(__DIR__) . '/logs/seguridad.log';
    $linea = json_encode($registro, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($linea === false || @file_put_contents($archivo, $linea . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
        error_log('[halloween] ' . $linea); // respaldo: log de PHP
    }
}

function cabeceras_seguridad(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
    header('Cross-Origin-Resource-Policy: same-origin');
    header('X-Request-Id: ' . id_peticion());
    // Sin Access-Control-Allow-Origin: la API es solo para el mismo origen (política CORS cerrada).
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// ---------- Limitación de intentos (persistente: no se evade borrando la cookie de sesión) ----------

function registrar_intento(string $tipo, ?string $nombre = null): void
{
    $st = db()->prepare('INSERT INTO intentos_acceso (tipo, ip, nombre) VALUES (?, ?, ?)');
    $st->execute([$tipo, ip_cliente(), $nombre !== null ? mb_substr($nombre, 0, 50) : null]);

    if (random_int(1, 50) === 1) { // limpieza ocasional
        db()->exec('DELETE FROM intentos_acceso WHERE creado < (NOW() - INTERVAL 1 DAY)');
    }
}

function contar_intentos(string $tipo, int $ventanaSeg, ?string $ip = null, ?string $nombre = null): int
{
    $sql = 'SELECT COUNT(*) FROM intentos_acceso WHERE tipo = ? AND creado > (NOW() - INTERVAL ' . (int) $ventanaSeg . ' SECOND)';
    $par = [$tipo];
    if ($ip !== null) {
        $sql .= ' AND ip = ?';
        $par[] = $ip;
    }
    if ($nombre !== null) {
        $sql .= ' AND nombre = ?';
        $par[] = mb_substr($nombre, 0, 50);
    }
    $st = db()->prepare($sql);
    $st->execute($par);
    return (int) $st->fetchColumn();
}

function limpiar_intentos(string $tipo, string $ip, string $nombre): void
{
    db()->prepare('DELETE FROM intentos_acceso WHERE tipo = ? AND ip = ? AND nombre = ?')
        ->execute([$tipo, $ip, mb_substr($nombre, 0, 50)]);
}
