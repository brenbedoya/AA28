<?php
declare(strict_types=1);

/**
 * Arranque común de todos los endpoints JSON:
 * cabeceras de seguridad, sesión endurecida, CSRF/Origin, manejo de errores y utilidades.
 */

// ---------- Errores (A05): nunca se muestran al usuario; se registran con un id de referencia ----------
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/seguridad.php';

set_exception_handler(function (Throwable $e): void {
    log_evento('error_interno', [
        'mensaje' => $e->getMessage(),
        'archivo' => basename($e->getFile()) . ':' . $e->getLine(),
    ]);
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo json_encode([
        'error'      => 'Error interno del servidor.',
        'codigo'     => 'interno',
        'referencia' => id_peticion(),
    ], JSON_UNESCAPED_UNICODE);
});

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
cabeceras_seguridad();

// ---------- Sesión (A07): cookie HttpOnly + Secure (con HTTPS) + SameSite=Strict ----------
$__https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
ini_set('session.use_strict_mode', '1');     // rechaza ids de sesión no generados por el servidor
ini_set('session.use_only_cookies', '1');    // nunca ids de sesión en la URL
ini_set('session.use_trans_sid', '0');
ini_set('session.sid_length', '48');
session_name('HALLOWEENSESS');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Strict',
    'secure'   => $__https,
]);
session_start();

/** Vacía la sesión y emite un identificador y un token CSRF nuevos. */
function reiniciar_sesion(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// Caducidad por inactividad y duración absoluta
if (isset($_SESSION['uid'])) {
    $ahora = time();
    $inicio = (int) ($_SESSION['inicio'] ?? $ahora);
    $ultimo = (int) ($_SESSION['ultimo'] ?? $ahora);
    if ($ahora - $ultimo > SESION_INACTIVIDAD || $ahora - $inicio > SESION_DURACION_MAX) {
        log_evento('sesion_expirada');
        reiniciar_sesion();
    } else {
        $_SESSION['ultimo'] = $ahora;
    }
}
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// ---------- Respuestas ----------
function json_out(mixed $datos, int $estado = 200): never
{
    http_response_code($estado);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function error_out(string $mensaje, int $estado = 400, ?string $codigo = null): never
{
    // A01/A09: todo rechazo de autenticación, permisos, CSRF o límite queda registrado.
    if (in_array($estado, [401, 403, 429], true)) {
        log_evento('acceso_denegado', ['status' => $estado, 'codigo' => $codigo]);
    }
    json_out(['error' => $mensaje, 'codigo' => $codigo], $estado);
}

// ---------- Petición ----------
/** Método HTTP real. Permite POST + _method=PUT|DELETE (los formularios con archivos no pueden usar PUT). */
function metodo(): string
{
    $m = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($m === 'POST' && isset($_POST['_method'])) {
        $override = strtoupper((string) $_POST['_method']);
        if ($override === 'PUT' || $override === 'DELETE') {
            return $override;
        }
    }
    return $m;
}

/** Cuerpo de la petición como arreglo (JSON, formulario o multipart), con tamaño máximo. */
function cuerpo(): array
{
    static $datos = null;
    if ($datos !== null) {
        return $datos;
    }
    $tipo = $_SERVER['CONTENT_TYPE'] ?? '';
    $crudo = null;
    if (stripos($tipo, 'application/json') !== false) {
        $texto = (string) file_get_contents('php://input', false, null, 0, MAX_JSON_BYTES + 1);
        if (strlen($texto) > MAX_JSON_BYTES) {
            error_out('La petición es demasiado grande.', 413);
        }
        $json = json_decode($texto, true);
        $datos = is_array($json) ? $json : [];
    } elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $datos = $_POST;
    } else {
        parse_str((string) file_get_contents('php://input', false, null, 0, MAX_JSON_BYTES), $crudo);
        $datos = is_array($crudo) ? $crudo : [];
    }
    return $datos;
}

/** Id del recurso: /endpoint.php/3  o  /endpoint.php?id=3 */
function id_recurso(string $parametro = 'id'): ?int
{
    $path = trim((string) ($_SERVER['PATH_INFO'] ?? ''), '/');
    if ($path !== '' && ctype_digit($path)) {
        return (int) $path;
    }
    $valor = $_GET[$parametro] ?? null;
    if (is_string($valor) && ctype_digit($valor) && (int) $valor > 0) {
        return (int) $valor;
    }
    return null;
}

/** Valida método permitido, origen y token CSRF en operaciones que modifican datos. */
function iniciar_endpoint(array $permitidos): void
{
    $m = metodo();
    if (!in_array($m, $permitidos, true)) {
        header('Allow: ' . implode(', ', $permitidos));
        error_out('Método no permitido.', 405);
    }
    if ($m !== 'GET' && $m !== 'HEAD') {
        // Defensa en profundidad: si el navegador envía Origin, debe ser el propio sitio.
        $origen = $_SERVER['HTTP_ORIGIN'] ?? null;
        if ($origen !== null) {
            $hostOrigen = parse_url($origen, PHP_URL_HOST);
            $puerto = parse_url($origen, PHP_URL_PORT);
            $hostOrigen = $hostOrigen !== null && $hostOrigen !== false
                ? $hostOrigen . ($puerto ? ':' . $puerto : '') : '';
            if (!hash_equals(strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')), strtolower($hostOrigen))) {
                error_out('Origen no permitido.', 403, 'origen');
            }
        }
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
            error_out('Sesión de seguridad inválida. Recarga la página e inténtalo de nuevo.', 403, 'csrf');
        }
    }
}

// ---------- Contraseñas (A02) ----------
function hashear_clave(string $clave): string
{
    return password_hash($clave, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
}

// ---------- Autenticación y autorización (A01 / A07) ----------
function usuario_actual(): ?array
{
    if (empty($_SESSION['uid'])) {
        return null;
    }
    $nombre = (string) $_SESSION['unombre'];
    return [
        'id'     => (int) $_SESSION['uid'],
        'nombre' => $nombre,
        'admin'  => !empty($_SESSION['admin']),
    ];
}

function iniciar_sesion(array $usuario): void
{
    session_regenerate_id(true);                 // evita fijación de sesión
    $_SESSION['uid']     = (int) $usuario['id'];
    $_SESSION['unombre'] = (string) $usuario['nombre'];
    // El rol se decide una sola vez, en el servidor, al autenticar.
    $_SESSION['admin']   = strcasecmp((string) $usuario['nombre'], ADMIN_USER) === 0;
    $_SESSION['inicio']  = $_SESSION['ultimo'] = time();
    $_SESSION['csrf']    = bin2hex(random_bytes(32));
}

function requerir_login(): array
{
    $u = usuario_actual();
    if ($u === null) {
        error_out('Debes iniciar sesión para hacer esto.', 401, 'no_autenticado');
    }
    return $u;
}

function requerir_admin(): array
{
    $u = requerir_login();
    if (!$u['admin']) {
        error_out('Solo el administrador puede hacer esto.', 403, 'no_admin');
    }
    // Revalida contra la BD: si la cuenta fue borrada, la sesión deja de valer.
    $st = db()->prepare('SELECT 1 FROM usuarios WHERE id = ? AND nombre = ?');
    $st->execute([$u['id'], $u['nombre']]);
    if ($st->fetchColumn() === false) {
        reiniciar_sesion();
        error_out('Debes iniciar sesión para hacer esto.', 401, 'no_autenticado');
    }
    return $u;
}
