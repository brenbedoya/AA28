<?php
declare(strict_types=1);

/**
 * Recurso: votos (requiere sesión iniciada)
 *   GET    /api/votos.php                 -> ids de los disfraces por los que votó el usuario
 *   POST   /api/votos.php                 -> votar          { id_disfraz }
 *   DELETE /api/votos.php?id_disfraz=3    -> retirar voto
 *
 * Un usuario solo puede votar una vez por cada disfraz (clave única en la tabla votos).
 * A04 (idempotencia): repetir la misma petición (reintento, doble clic) no duplica el efecto
 * y devuelve el estado final con 200, en lugar de un error.
 * A01: el usuario sale siempre de la sesión del servidor, nunca de un parámetro del cliente.
 */

require __DIR__ . '/bootstrap.php';

iniciar_endpoint(['GET', 'POST', 'DELETE']);
$usuario = requerir_login();
$pdo = db();

function total_votos(PDO $pdo, int $idDisfraz): int
{
    $st = $pdo->prepare('SELECT votos FROM disfraces WHERE id = ?');
    $st->execute([$idDisfraz]);
    return (int) $st->fetchColumn();
}

switch (metodo()) {
    case 'GET':
        $st = $pdo->prepare('SELECT id_disfraz FROM votos WHERE id_usuario = ?');
        $st->execute([$usuario['id']]);
        json_out(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));

    case 'POST':
        $idDisfraz = filter_var(cuerpo()['id_disfraz'] ?? null, FILTER_VALIDATE_INT);
        if ($idDisfraz === false || $idDisfraz < 1) {
            error_out('Disfraz inválido.', 422);
        }

        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT id FROM disfraces WHERE id = ? AND eliminado = 0 FOR UPDATE');
        $st->execute([$idDisfraz]);
        if ($st->fetchColumn() === false) {
            $pdo->rollBack();
            error_out('Ese disfraz no existe.', 404);
        }

        // INSERT IGNORE + UNIQUE(id_usuario, id_disfraz): si ya existía, rowCount() = 0.
        $st = $pdo->prepare('INSERT IGNORE INTO votos (id_usuario, id_disfraz) VALUES (?, ?)');
        $st->execute([$usuario['id'], $idDisfraz]);
        $nuevo = $st->rowCount() === 1;

        if ($nuevo) {
            $pdo->prepare('UPDATE disfraces SET votos = votos + 1 WHERE id = ?')->execute([$idDisfraz]);
        }
        $total = total_votos($pdo, $idDisfraz);
        $pdo->commit();

        if ($nuevo) {
            log_evento('voto_registrado', ['idDisfraz' => $idDisfraz]);
        }
        json_out(['ok' => true, 'id_disfraz' => $idDisfraz, 'votos' => $total, 'nuevo' => $nuevo], $nuevo ? 201 : 200);

    case 'DELETE':
        $idDisfraz = id_recurso('id_disfraz');
        $idDisfraz !== null || error_out('Falta id_disfraz.', 400);

        $pdo->beginTransaction();
        $st = $pdo->prepare('DELETE FROM votos WHERE id_usuario = ? AND id_disfraz = ?');
        $st->execute([$usuario['id'], $idDisfraz]);
        $quitado = $st->rowCount() > 0;

        if ($quitado) {
            $pdo->prepare('UPDATE disfraces SET votos = votos - 1 WHERE id = ? AND votos > 0')->execute([$idDisfraz]);
        }
        $total = total_votos($pdo, $idDisfraz);
        $pdo->commit();

        if ($quitado) {
            log_evento('voto_retirado', ['idDisfraz' => $idDisfraz]);
        }
        json_out(['ok' => true, 'id_disfraz' => $idDisfraz, 'votos' => $total, 'quitado' => $quitado]);
}
