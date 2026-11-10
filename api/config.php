<?php
declare(strict_types=1);

/**
 * Configuración de la aplicación (OWASP A05: Security Misconfiguration).
 *
 * Los secretos NO viven en el código: se leen de variables de entorno o de un archivo .env
 * excluido del control de versiones (.gitignore) y bloqueado por .htaccess.
 * Lo ideal es guardar el .env FUERA de htdocs y apuntar a él con HALLOWEEN_ENV_FILE.
 */

/** Lee un .env sencillo (CLAVE=valor) sin sobrescribir variables de entorno reales. */
function cargar_env(string $ruta): array
{
    $vars = [];
    if (!is_file($ruta) || !is_readable($ruta)) {
        return $vars;
    }
    foreach (file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linea) {
        $linea = trim($linea);
        if ($linea === '' || $linea[0] === '#' || !str_contains($linea, '=')) {
            continue;
        }
        [$k, $v] = array_map('trim', explode('=', $linea, 2));
        $vars[$k] = trim($v, "\"'");
    }
    return $vars;
}

$__archivoEnv = getenv('HALLOWEEN_ENV_FILE') ?: dirname(__DIR__) . '/.env';
$__env = cargar_env($__archivoEnv);

function cfg(string $clave, ?string $defecto = null): ?string
{
    global $__env;
    $v = getenv($clave);
    if ($v !== false && $v !== '') {
        return $v;
    }
    return $__env[$clave] ?? $defecto;
}

const ENTORNOS = ['development', 'production'];
$__entorno = cfg('APP_ENV', 'development');
define('APP_ENV', in_array($__entorno, ENTORNOS, true) ? $__entorno : 'production');
define('ES_PRODUCCION', APP_ENV === 'production');

// En producción no existen valores por defecto: si falta un secreto, la app no arranca.
$__req = static function (string $clave, string $devDefecto): string {
    $v = cfg($clave);
    if ($v !== null) {
        return $v;
    }
    if (ES_PRODUCCION) {
        throw new RuntimeException("Falta la variable de configuración $clave");
    }
    return $devDefecto;
};

define('DB_HOST', $__req('DB_HOST', 'localhost'));
define('DB_NAME', $__req('DB_NAME', 'halloween'));
define('DB_USER', $__req('DB_USER', 'root'));   // en producción: usuario de mínimo privilegio
define('DB_PASS', $__req('DB_PASS', ''));       // XAMPP no trae contraseña para root
const DB_CHARSET = 'utf8mb4';

// Nombre del usuario administrador (CRUD de disfraces). Queda reservado: no se puede registrar.
const ADMIN_USER = 'admin';

// Subida de imágenes
const MAX_UPLOAD_BYTES = 2 * 1024 * 1024; // 2 MB
const MAX_JSON_BYTES   = 64 * 1024;       // tamaño máximo del cuerpo JSON

// Contraseñas (A02): bcrypt con coste explícito
const BCRYPT_COST = 12;

// Sesión (A07)
const SESION_INACTIVIDAD = 30 * 60;     // 30 min sin actividad
const SESION_DURACION_MAX = 8 * 3600;   // 8 h en total

// Fuerza bruta (A07): intentos fallidos dentro de la ventana
const VENTANA_INTENTOS   = 15 * 60;
const MAX_FALLOS_PAR     = 5;    // misma IP + mismo usuario
const MAX_FALLOS_IP      = 20;   // misma IP (cualquier usuario)
const MAX_FALLOS_USUARIO = 40;   // mismo usuario desde muchas IP (ataque distribuido)
const MAX_REGISTROS_IP   = 10;   // altas por IP y hora

// Logs de seguridad (A09). Carpeta bloqueada por .htaccess; en producción conviene moverla fuera de htdocs.
define('LOG_FILE', cfg('LOG_FILE', dirname(__DIR__) . '/logs/seguridad.log'));

unset($__archivoEnv, $__env, $__entorno, $__req);
