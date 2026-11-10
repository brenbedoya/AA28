-- ============================================================
--  A05: Principio de minimo privilegio.
--  La aplicacion NO debe conectarse como root. Crea un usuario que solo pueda
--  operar sobre la base "halloween" (sin DROP, ALTER, GRANT ni acceso a otras bases).
--
--  1) Reemplaza CAMBIAR_ESTA_CLAVE por una clave larga y aleatoria.
--  2) Ejecuta este script como root (phpMyAdmin > SQL).
--  3) Pon DB_USER / DB_PASS en el archivo .env (ver .env.example).
-- ============================================================
CREATE USER IF NOT EXISTS 'halloween_app'@'localhost' IDENTIFIED BY 'CAMBIAR_ESTA_CLAVE';

GRANT SELECT, INSERT, UPDATE, DELETE ON `halloween`.* TO 'halloween_app'@'localhost';
FLUSH PRIVILEGES;
