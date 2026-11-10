<?php
declare(strict_types=1);

/**
 * Recurso: disfraces
 *   GET    /api/disfraces.php          -> lista (pública)
 *   GET    /api/disfraces.php/3        -> detalle (también ?id=3)
 *   POST   /api/disfraces.php          -> crear            (admin)  multipart: nombre, descripcion, foto?
 *   PUT    /api/disfraces.php/3        -> actualizar       (admin)  JSON o POST multipart con _method=PUT
 *   DELETE /api/disfraces.php/3        -> eliminar (lógico)(admin)
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/imagenes.php';

iniciar_endpoint(['GET', 'POST', 'PUT', 'DELETE']);

$id = id_recurso();

switch (metodo()) {
    case 'GET':
        $id !== null ? detalle($id) : listar();
        break;

    case 'POST':
        requerir_admin();
        crear();
        break;

    case 'PUT':
        requerir_admin();
        $id !== null || error_out('Falta el id del disfraz.', 400);
        actualizar($id);
        break;

    case 'DELETE':
        requerir_admin();
        $id !== null || error_out('Falta el id del disfraz.', 400);
        eliminar($id);
        break;
}

// ------------------------------------------------------------------

function formatear(array $f): array
{
    $tiene = $f['foto'] !== '';
    return [
        'id'          => (int) $f['id'],
        'nombre'      => $f['nombre'],
        'descripcion' => $f['descripcion'],
        'votos'       => (int) $f['votos'],
        'imagen'      => $tiene ? 'api/imagen.php?id=' . (int) $f['id'] . '&v=' . rawurlencode($f['foto']) : null,
        'votado'      => isset($f['votado']) ? (bool) $f['votado'] : false,
    ];
}

function consulta_base(): string
{
    return 'SELECT d.id, d.nombre, d.descripcion, d.votos, d.foto,
                   (v.id IS NOT NULL) AS votado
            FROM disfraces d
            LEFT JOIN votos v ON v.id_disfraz = d.id AND v.id_usuario = :usuario
            WHERE d.eliminado = 0';
}

function uid(): int
{
    return usuario_actual()['id'] ?? 0;
}

function listar(): void
{
    $st = db()->prepare(consulta_base() . ' ORDER BY d.votos DESC, d.id ASC');
    $st->execute([':usuario' => uid()]);
    json_out(array_map('formatear', $st->fetchAll()));
}

function detalle(int $id): void
{
    $st = db()->prepare(consulta_base() . ' AND d.id = :id');
    $st->execute([':usuario' => uid(), ':id' => $id]);
    $fila = $st->fetch();
    $fila !== false || error_out('Disfraz no encontrado.', 404);
    json_out(formatear($fila));
}

/** Valida nombre y descripción. */
function datos_disfraz(array $d): array
{
    $nombre = trim((string) ($d['nombre'] ?? ''));
    $desc   = trim((string) ($d['descripcion'] ?? ''));
    if ($nombre === '' || mb_strlen($nombre) > 50) {
        error_out('El nombre es obligatorio (máximo 50 caracteres).', 422);
    }
    if ($desc === '' || mb_strlen($desc) > 2000) {
        error_out('La descripción es obligatoria (máximo 2000 caracteres).', 422);
    }
    return [$nombre, $desc];
}

function crear(): void
{
    [$nombre, $desc] = datos_disfraz(cuerpo());

    $foto = '';
    $blob = '';
    if (hay_foto_subida()) {
        [$foto, $blob] = guardar_imagen($_FILES['foto']);
    }

    try {
        $st = db()->prepare(
            'INSERT INTO disfraces (nombre, descripcion, votos, foto, foto_blob, eliminado)
             VALUES (:n, :d, 0, :f, :b, 0)'
        );
        $st->bindValue(':n', $nombre);
        $st->bindValue(':d', $desc);
        $st->bindValue(':f', $foto);
        $st->bindValue(':b', $blob, PDO::PARAM_LOB);
        $st->execute();
    } catch (Throwable $e) {
        borrar_imagen($foto); // no dejar archivos huérfanos si falla el INSERT
        throw $e;
    }

    $nuevo = (int) db()->lastInsertId();
    log_evento('disfraz_creado', ['idDisfraz' => $nuevo]);
    header('Location: disfraces.php/' . $nuevo);
    detalle_con_estado($nuevo, 201);
}

function actualizar(int $id): void
{
    $st = db()->prepare('SELECT foto FROM disfraces WHERE id = ? AND eliminado = 0');
    $st->execute([$id]);
    $actual = $st->fetch();
    $actual !== false || error_out('Disfraz no encontrado.', 404);

    [$nombre, $desc] = datos_disfraz(cuerpo());
    $quitar = in_array((string) (cuerpo()['quitar_foto'] ?? ''), ['1', 'true', 'on'], true);

    $fotoVieja = $actual['foto'];
    $fotoNueva = null;

    if (hay_foto_subida()) {
        [$fotoNueva, $blob] = guardar_imagen($_FILES['foto']);
        $sql = 'UPDATE disfraces SET nombre = :n, descripcion = :d, foto = :f, foto_blob = :b WHERE id = :id';
    } elseif ($quitar) {
        $fotoNueva = '';
        $blob = '';
        $sql = 'UPDATE disfraces SET nombre = :n, descripcion = :d, foto = :f, foto_blob = :b WHERE id = :id';
    } else {
        $sql = 'UPDATE disfraces SET nombre = :n, descripcion = :d WHERE id = :id';
    }

    try {
        $st = db()->prepare($sql);
        $st->bindValue(':n', $nombre);
        $st->bindValue(':d', $desc);
        $st->bindValue(':id', $id, PDO::PARAM_INT);
        if ($fotoNueva !== null) {
            $st->bindValue(':f', $fotoNueva);
            $st->bindValue(':b', $blob, PDO::PARAM_LOB);
        }
        $st->execute();
    } catch (Throwable $e) {
        if ($fotoNueva !== null) {
            borrar_imagen($fotoNueva);
        }
        throw $e;
    }

    if ($fotoNueva !== null) {
        borrar_imagen($fotoVieja);
    }
    log_evento('disfraz_actualizado', ['idDisfraz' => $id]);
    detalle_con_estado($id, 200);
}

function eliminar(int $id): void
{
    $st = db()->prepare('UPDATE disfraces SET eliminado = 1 WHERE id = ? AND eliminado = 0');
    $st->execute([$id]);
    if ($st->rowCount() === 0) {
        error_out('Disfraz no encontrado.', 404);
    }
    log_evento('disfraz_eliminado', ['idDisfraz' => $id]);
    json_out(['ok' => true]);
}

function detalle_con_estado(int $id, int $estado): never
{
    $st = db()->prepare(consulta_base() . ' AND d.id = :id');
    $st->execute([':usuario' => uid(), ':id' => $id]);
    json_out(formatear($st->fetch()), $estado);
}
