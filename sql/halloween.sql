-- ============================================================
--  Base de datos del concurso de disfraces de Halloween
--  Importar desde phpMyAdmin (pestaña "Importar") o con:
--      mysql -u root < sql/halloween.sql
--  Es seguro volver a importarlo: no borra datos existentes.
-- ============================================================

CREATE DATABASE IF NOT EXISTS `halloween`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_general_ci;

USE `halloween`;

-- ------------------------------------------------------------
-- Estructura de tabla para la tabla disfraces
-- foto       -> nombre del archivo guardado en /uploads (14 hex + extension)
-- foto_blob  -> miniatura JPEG (< 64 KB, limite del tipo BLOB)
-- eliminado  -> borrado logico (0 = activo, 1 = eliminado)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `disfraces` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(50) NOT NULL,
  `descripcion` text NOT NULL,
  `votos` int(11) NOT NULL DEFAULT 0,
  `foto` varchar(20) NOT NULL DEFAULT '',
  `foto_blob` blob NOT NULL,
  `eliminado` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- Estructura de tabla para la tabla usuarios
-- clave guarda el hash generado con password_hash() (bcrypt), nunca la clave en texto plano.
-- El administrador es el usuario cuyo nombre coincide con ADMIN_USER (api/config.php); se crea con tools/crear_admin.php.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `usuarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(50) NOT NULL,
  `clave` text NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_usuarios_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- Estructura de tabla para la tabla votos
-- La clave unica (id_usuario, id_disfraz) impide votos duplicados a nivel de base de datos.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `votos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_usuario` int(11) NOT NULL,
  `id_disfraz` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_voto_usuario_disfraz` (`id_usuario`, `id_disfraz`),
  KEY `idx_votos_disfraz` (`id_disfraz`),
  CONSTRAINT `fk_votos_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_votos_disfraz` FOREIGN KEY (`id_disfraz`) REFERENCES `disfraces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- Intentos de acceso (A04/A07): limita fuerza bruta y altas masivas por IP/usuario.
-- Se guarda en BD (y no en la sesion) para que no se evada borrando la cookie.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `intentos_acceso` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `tipo` varchar(10) NOT NULL,
  `ip` varchar(45) NOT NULL,
  `nombre` varchar(50) DEFAULT NULL,
  `creado` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_intentos_ip` (`tipo`, `ip`, `creado`),
  KEY `idx_intentos_nombre` (`tipo`, `nombre`, `creado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- Datos iniciales
-- A02/A05: NO se incluye ninguna cuenta ni contrasena por defecto en el repositorio.
-- Crea el administrador con:   php tools/crear_admin.php
-- ------------------------------------------------------------

INSERT IGNORE INTO `disfraces` (`id`, `nombre`, `descripcion`, `votos`, `foto`, `foto_blob`, `eliminado`) VALUES
(1, 'Vampiro Elegante', 'Capa de terciopelo negro, colmillos afilados y un aire de nobleza centenaria. Cuidado con el cuello.', 0, '', '', 0),
(2, 'Bruja del Pantano', 'Sombrero torcido, pociones burbujeantes y una escoba que ya ha visto mejores noches de luna llena.', 0, '', '', 0),
(3, 'Momia Rockera', 'Vendas, guitarra eléctrica y mucha actitud. Sale de su sarcófago solo para los conciertos.', 0, '', '', 0),
(4, 'Fantasma Travieso', 'Sábana blanca, cadenas de utilería y una risa que te sigue por los pasillos.', 0, '', '', 0);
