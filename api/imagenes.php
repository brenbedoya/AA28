<?php
declare(strict_types=1);

/** Utilidades para subir y borrar imágenes de disfraces. */

const MIME_PERMITIDOS = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];

function dir_uploads(): string
{
    return dirname(__DIR__) . '/uploads';
}

/** ¿Vino un archivo en el campo "foto"? */
function hay_foto_subida(): bool
{
    return isset($_FILES['foto']) && is_array($_FILES['foto'])
        && ($_FILES['foto']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
}

/**
 * Valida y guarda la imagen subida.
 * Devuelve [nombre_de_archivo (<= 20 caracteres), miniatura_binaria (< 64 KB o '')].
 */
function guardar_imagen(array $archivo): array
{
    $err = $archivo['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        error_out('La imagen es demasiado grande (máximo 2 MB).', 413);
    }
    if ($err !== UPLOAD_ERR_OK) {
        error_out('No se pudo subir la imagen. Inténtalo de nuevo.', 400);
    }
    if (($archivo['size'] ?? 0) > MAX_UPLOAD_BYTES) {
        error_out('La imagen es demasiado grande (máximo 2 MB).', 413);
    }
    if (!is_uploaded_file($archivo['tmp_name'])) {
        error_out('Archivo inválido.', 400);
    }

    // Se valida por el contenido real, no por la extensión que envía el navegador.
    $info = @getimagesize($archivo['tmp_name']);
    $mime = is_array($info) ? ($info['mime'] ?? '') : '';
    if (!isset(MIME_PERMITIDOS[$mime])) {
        log_evento('subida_rechazada', ['motivo' => 'formato']);
        error_out('Formato no permitido. Usa JPG, PNG, GIF o WEBP.', 415);
    }
    if ($info[0] * $info[1] > 25_000_000) {
        error_out('Las dimensiones de la imagen son demasiado grandes.', 422);
    }

    $dir = dir_uploads();
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        throw new RuntimeException('No se pudo crear la carpeta uploads');
    }

    // 14 hex + "." + extensión (<= 4)  => máximo 19 caracteres, cabe en varchar(20)
    $nombre = bin2hex(random_bytes(7)) . '.' . MIME_PERMITIDOS[$mime];
    $destino = $dir . '/' . $nombre;
    if (!move_uploaded_file($archivo['tmp_name'], $destino)) {
        throw new RuntimeException('No se pudo guardar la imagen en uploads');
    }

    return [$nombre, miniatura($destino)];
}

/** Miniatura JPEG para la columna foto_blob (el tipo BLOB admite como máximo 65 535 bytes). */
function miniatura(string $ruta): string
{
    if (!function_exists('imagecreatefromstring')) {
        return ''; // extensión GD no disponible
    }
    $datos = @file_get_contents($ruta);
    $origen = $datos === false ? false : @imagecreatefromstring($datos);
    if ($origen === false) {
        return '';
    }
    $w = imagesx($origen);
    $h = imagesy($origen);
    $escala = min(1.0, 200 / max($w, $h));
    $nw = max(1, (int) round($w * $escala));
    $nh = max(1, (int) round($h * $escala));

    $mini = imagecreatetruecolor($nw, $nh);
    imagefill($mini, 0, 0, imagecolorallocate($mini, 255, 255, 255));
    imagecopyresampled($mini, $origen, 0, 0, 0, 0, $nw, $nh, $w, $h);

    ob_start();
    imagejpeg($mini, null, 72);
    $binario = (string) ob_get_clean();
    imagedestroy($origen);
    imagedestroy($mini);

    return strlen($binario) <= 65000 ? $binario : '';
}

/** Borra un archivo de uploads (solo si el nombre tiene el formato que generamos). */
function borrar_imagen(string $nombre): void
{
    if ($nombre !== '' && preg_match('/^[a-f0-9]{14}\.(jpg|png|gif|webp)$/', $nombre)) {
        $ruta = dir_uploads() . '/' . $nombre;
        if (is_file($ruta)) {
            @unlink($ruta);
        }
    }
}
