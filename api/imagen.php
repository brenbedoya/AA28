<?php
declare(strict_types=1);

/**
 * GET /api/imagen.php?id=3   -> foto del disfraz (archivo de /uploads o, si falta, la miniatura de la BD)
 * Las imágenes se sirven siempre a través de este script; /uploads no es accesible directamente.
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/db.php';

$id = (isset($_GET['id']) && ctype_digit((string) $_GET['id'])) ? (int) $_GET['id'] : 0;
if ($id < 1) {
    http_response_code(400);
    exit;
}

try {
    $st = db()->prepare('SELECT foto, foto_blob FROM disfraces WHERE id = ? AND eliminado = 0');
    $st->execute([$id]);
    $fila = $st->fetch();
} catch (Throwable $e) {
    error_log('[halloween] imagen.php: ' . $e->getMessage());
    http_response_code(500);
    exit;
}

if ($fila === false || $fila['foto'] === '') {
    http_response_code(404);
    exit;
}

$tipos = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
$ext = strtolower(pathinfo($fila['foto'], PATHINFO_EXTENSION));
$ruta = dirname(__DIR__) . '/uploads/' . basename($fila['foto']);

// Cabeceras defensivas: el navegador nunca debe interpretar esto como HTML/script.
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Cross-Origin-Resource-Policy: same-origin');
header('Content-Disposition: inline');
header('Cache-Control: public, max-age=86400');

if (isset($tipos[$ext]) && is_file($ruta)) {
    header('Content-Type: ' . $tipos[$ext]);
    header('Content-Length: ' . filesize($ruta));
    readfile($ruta);
} elseif ($fila['foto_blob'] !== '' && $fila['foto_blob'] !== null) {
    header('Content-Type: image/jpeg');
    echo $fila['foto_blob'];
} else {
    http_response_code(404);
}
