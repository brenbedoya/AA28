# 🎃 Noche de Disfraces — Desafío Halloween

Aplicación web con **API REST en PHP + MySQL** para ver disfraces de Halloween y votar por ellos.
Listo para ejecutarse en **XAMPP**.

## Instalación en XAMPP

1. Copia la carpeta `halloween` dentro de `C:\xampp\htdocs\` (en Linux/Mac: `/opt/lampp/htdocs/`).
2. Abre el **panel de XAMPP** y arranca **Apache** y **MySQL**.
3. Entra a <http://localhost/phpmyadmin>, ve a la pestaña **Importar**, elige `SQL/halloween.sql` y pulsa **Importar**.
   (Crea la base `halloween`, las tablas `disfraces`, `usuarios`, `votos` e `intentos_acceso`, y datos de ejemplo.
   Si ya tenías la base, vuelve a importarlo: no borra datos y añade la tabla nueva `intentos_acceso`.)
4. **Usuario de base de datos con mínimo privilegio** (recomendado): edita la clave en `SQL/usuario_minimo_privilegio.sql` y ejecútalo desde phpMyAdmin.
5. **Configuración**: copia `.env.example` a `.env` y completa `DB_USER` / `DB_PASS`. El `.env` está en `.gitignore` y bloqueado por `.htaccess`.
   Sin `.env`, en `APP_ENV=development` se usan los valores de XAMPP (`root` sin clave); con `APP_ENV=production` la app **no arranca** si falta un secreto.
6. **Crea el administrador** desde la consola (ya no hay contraseña por defecto en el repositorio):

```bash
php tools/crear_admin.php
```

7. Abre <http://localhost/halloween/> y entra con `admin` en `login.html`; aparecerá el enlace **Administración**.

## Páginas

| Archivo          | Qué hace                                                                 |
|------------------|--------------------------------------------------------------------------|
| `index.html`     | Lista de disfraces (nombre, descripción, foto, votos) + botón **Votar**  |
| `registro.html`  | Registro de usuarios (inicia sesión automáticamente al terminar)         |
| `login.html`     | Inicio de sesión                                                         |
| `admin.html`     | CRUD de disfraces con subida de foto (solo administrador)                |

## API REST (`/api`)

Todas las respuestas son JSON. Las operaciones que modifican datos exigen la cabecera `X-CSRF-Token`
(el token se obtiene con `GET sesion.php`).

| Método | Endpoint                      | Acceso   | Descripción                                   |
|--------|-------------------------------|----------|-----------------------------------------------|
| GET    | `disfraces.php`               | público  | Lista de disfraces (ordenados por votos)      |
| GET    | `disfraces.php/{id}`          | público  | Detalle de un disfraz                         |
| POST   | `disfraces.php`               | admin    | Crear (multipart: `nombre`, `descripcion`, `foto`) |
| PUT    | `disfraces.php/{id}`          | admin    | Actualizar (JSON, o POST multipart con `_method=PUT`) |
| DELETE | `disfraces.php/{id}`          | admin    | Eliminar (borrado lógico: `eliminado = 1`)    |
| POST   | `usuarios.php`                | público  | Registrar usuario `{nombre, clave}`           |
| GET    | `sesion.php`                  | público  | Estado de la sesión + token CSRF              |
| POST   | `sesion.php`                  | público  | Iniciar sesión `{nombre, clave}`              |
| DELETE | `sesion.php`                  | sesión   | Cerrar sesión                                 |
| GET    | `votos.php`                   | sesión   | Ids de los disfraces votados por el usuario   |
| POST   | `votos.php`                   | sesión   | Votar `{id_disfraz}` (idempotente: `201` nuevo / `200` ya votado) |
| DELETE | `votos.php?id_disfraz={id}`   | sesión   | Retirar el voto (idempotente)                 |
| GET    | `imagen.php?id={id}`          | público  | Foto del disfraz                              |

Códigos usados: `200`, `201`, `400`, `401` (sin sesión), `403` (sin permiso / CSRF), `404`, `409` (duplicado en registro), `413`/`415` (imagen), `422` (validación), `429` (demasiados intentos).

## Seguridad: cumplimiento OWASP Top 10

Mapeo con la bibliografía (*Arquitectura Web Segura: el estándar OWASP Top 10*):

| Cat. | Medida aplicada | Dónde |
|------|-----------------|-------|
| **A01** Broken Access Control | Autorización siempre en el servidor (`401` sin sesión, `403` sin rol); el usuario sale de `$_SESSION`, nunca de un parámetro; el rol se fija al autenticar y `requerir_admin()` revalida la cuenta en BD; nombre `admin` reservado; cabecera `Origin` verificada. | `bootstrap.php`, `votos.php`, `disfraces.php` |
| **A02** Cryptographic Failures | `password_hash(PASSWORD_BCRYPT, cost 12)` + `password_verify`, rehash transparente al iniciar sesión; sin contraseñas por defecto en el repo; HTTPS forzado fuera de localhost, HSTS y cookie `Secure`. | `bootstrap.php`, `sesion.php`, `.htaccess`, `tools/crear_admin.php` |
| **A03** Injection | PDO con consultas preparadas nativas (`EMULATE_PREPARES=false`) en **todo** el código (se eliminó la última concatenación SQL); frontend solo con `textContent`/`createElement`; **CSP** `script-src 'self'` como segunda barrera contra XSS. | `db.php`, `votos.php`, `js/api.js`, `.htaccess` |
| **A04** Insecure Design | Votos **idempotentes** (reintentos/doble clic no duplican; `UNIQUE` + `INSERT IGNORE` en transacción); límite de altas por IP; validación de lógica en el servidor; cuerpo JSON limitado a 64 KB. | `votos.php`, `usuarios.php`, `bootstrap.php` |
| **A05** Security Misconfiguration | Secretos en `.env`/variables de entorno (fuera del código y del repo); `display_errors=0` y errores genéricos con id de referencia; usuario MySQL de mínimo privilegio; `.htaccess` bloquea `SQL/`, `tools/`, `logs/`, `.env`, `*.md`, ficheros internos de `api/` y archivos ocultos; `Options -Indexes`; cabeceras de seguridad. | `config.php`, `.htaccess`, `SQL/usuario_minimo_privilegio.sql` |
| **A06** Vulnerable Components | Sin dependencias de Composer/NPM (solo PHP + extensiones PDO/GD). Inventario: PHP ≥ 8.1, MySQL/MariaDB, Apache (`mod_headers`, `mod_rewrite`), Google Fonts (CSS). Mantén PHP/Apache/MySQL al día; si agregas librerías, ejecuta `composer audit` / `npm audit --production` en el despliegue. | — |
| **A07** Authentication Failures | Fricción progresiva con límites **persistentes en BD** (par IP+usuario, IP y usuario), por lo que no se evaden borrando la cookie; cookie `HttpOnly` + `Secure` + `SameSite=Strict`; `use_strict_mode`, `use_only_cookies`; `session_regenerate_id` al iniciar sesión; caducidad por inactividad (30 min) y absoluta (8 h); logout destruye la sesión en el servidor; mensaje de error genérico y hash de relleno del mismo coste (sin enumeración de usuarios). | `sesion.php`, `seguridad.php`, `bootstrap.php` |
| **A08** Integrity Failures | Sin deserialización de datos de usuario (`json_decode` a arreglos); CSRF por token + `Origin`; subida de imágenes validada por contenido real, nombre aleatorio y servida con `nosniff` + `CSP: sandbox`; el DOM solo recibe texto. Nota: el CSS de Google Fonts no admite SRI; si necesitas integridad total, aloja las fuentes localmente. | `imagenes.php`, `imagen.php` |
| **A09** Logging & Monitoring | Logs **JSON estructurados** (`logs/seguridad.log`) con `timestamp`, `event`, `userId`, `ip` y `requestId` de correlación (también en la cabecera `X-Request-Id`); eventos: `login_ok/failed`, `logout`, `registro_ok`, `acceso_denegado` (401/403/429), `sesion_expirada`, `voto_*`, `disfraz_*`, `subida_rechazada`, `error_interno`. Nunca se registran contraseñas, tokens ni ids de sesión. | `seguridad.php` |
| **A10** SSRF | La aplicación **no realiza peticiones HTTP salientes** ni acepta URLs del cliente: las imágenes se suben como archivo y se leen solo desde disco local. En el servidor, mantén `allow_url_fopen = Off` en `php.ini`. | — |

Matriz de defensa en profundidad: **Cliente** (`textContent`, CSP) → **Transporte** (HTTPS, HSTS, cookies `HttpOnly`/`Secure`/`SameSite`, CORS cerrado) → **Servidor** (`401/403`, bcrypt, validaciones de lógica) → **BD** (PDO preparado, usuario de mínimo privilegio).

Recomendaciones de despliegue (no se pueden fijar desde el código): `display_errors = Off`, `expose_php = Off`, `allow_url_fopen = Off` en `php.ini`; `ServerTokens Prod` en Apache; mover `.env` y `logs/` fuera de `htdocs` (`HALLOWEEN_ENV_FILE`, `LOG_FILE`); rotar los logs.

## Decisiones de diseño

- **Esquema**: se respetaron las tres tablas del enunciado y se añadieron `PRIMARY KEY` / `AUTO_INCREMENT`, claves únicas y claves foráneas (el enunciado no las incluía).
- **Administrador**: la tabla `usuarios` no tiene columna de rol, por lo que el admin es el usuario cuyo nombre coincide con `ADMIN_USER` (`api/config.php`), creado con `tools/crear_admin.php`.
- **Foto**: el archivo se guarda en `uploads/` (columna `foto`, nombre de ≤ 20 caracteres) y `foto_blob` guarda una miniatura JPEG, porque el tipo `BLOB` admite como máximo 64 KB. Si el archivo faltara, la API sirve la miniatura.
- **Votos**: cada usuario puede votar **una vez por disfraz** (puede apoyar a varios) y puede retirar su voto. Si prefieres un solo voto por usuario en total, bastaría cambiar la clave única a `(id_usuario)`.
- **Borrado**: lógico (`eliminado = 1`), usando la columna prevista en el enunciado.
- Las fuentes de Google (Creepster/Nunito) se cargan por internet; sin conexión se usan fuentes del sistema.

## Estructura

```
halloween/
├── index.html · login.html · registro.html · admin.html
├── css/estilo.css
├── js/            api.js (cliente REST) · index.js · login.js · registro.js · admin.js
├── api/           config.php · db.php · bootstrap.php · seguridad.php · imagenes.php
│                  disfraces.php · votos.php · sesion.php · usuarios.php · imagen.php
├── SQL/           halloween.sql · usuario_minimo_privilegio.sql
├── tools/         crear_admin.php (solo consola)
├── logs/          seguridad.log (JSON; acceso web bloqueado)
├── .env.example · .gitignore · .htaccess
└── uploads/       (fotos subidas; acceso directo bloqueado)
```
