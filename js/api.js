'use strict';

/**
 * Cliente de la API REST + utilidades compartidas por todas las páginas.
 * Todo el contenido dinámico se inserta con textContent / createElement (nunca innerHTML)
 * para evitar XSS con datos escritos por los usuarios.
 */
const Halloween = (() => {
  const BASE = 'api/';
  let csrf = null;
  let sesionCache = null;

  async function enviar(ruta, { metodo = 'GET', json = null, form = null } = {}) {
    const headers = { Accept: 'application/json' };
    if (metodo !== 'GET') headers['X-CSRF-Token'] = csrf;
    let cuerpo;
    if (form) {
      cuerpo = form;
    } else if (json !== null) {
      headers['Content-Type'] = 'application/json';
      cuerpo = JSON.stringify(json);
    }
    const resp = await fetch(BASE + ruta, { method: metodo, headers, body: cuerpo, credentials: 'same-origin' });
    let datos = null;
    try { datos = await resp.json(); } catch (_) { /* respuesta sin JSON */ }
    return { resp, datos };
  }

  /** Llama a la API. Lanza Error (con .status) si la respuesta no es 2xx. */
  async function pedir(ruta, opciones = {}) {
    const escribe = (opciones.metodo || 'GET') !== 'GET';
    if (escribe && !csrf) await sesion(true);

    let { resp, datos } = await enviar(ruta, opciones);
    if (resp.status === 403 && datos && datos.codigo === 'csrf') {
      await sesion(true);                        // token vencido: se renueva y se reintenta una vez
      ({ resp, datos } = await enviar(ruta, opciones));
    }
    if (!resp.ok) {
      const error = new Error((datos && datos.error) || `Error ${resp.status}`);
      error.status = resp.status;
      throw error;
    }
    if (datos && datos.csrf) { csrf = datos.csrf; sesionCache = null; }
    return datos;
  }

  /** Estado de la sesión actual: { autenticado, usuario, csrf } */
  async function sesion(forzar = false) {
    if (sesionCache && !forzar) return sesionCache;
    const resp = await fetch(BASE + 'sesion.php', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    const datos = await resp.json();
    csrf = datos.csrf;
    sesionCache = datos;
    return datos;
  }

  // ---------- Utilidades de interfaz ----------
  function el(etiqueta, atributos = {}, ...hijos) {
    const nodo = document.createElement(etiqueta);
    for (const [clave, valor] of Object.entries(atributos)) {
      if (valor === null || valor === undefined || valor === false) continue;
      if (clave === 'class') nodo.className = valor;
      else if (clave === 'text') nodo.textContent = valor;
      else if (clave.startsWith('on')) nodo.addEventListener(clave.slice(2), valor);
      else nodo.setAttribute(clave, valor === true ? '' : valor);
    }
    for (const hijo of hijos) nodo.append(hijo);
    return nodo;
  }

  function mensaje(contenedor, texto, tipo = 'info') {
    // El nodo permanece siempre en el DOM y en el Accessibility Tree.
    // Nunca usamos display:none ni hidden sobre la región live.
    contenedor.textContent = texto || '';
    contenedor.className = 'mensaje' + (texto ? ' mensaje-' + tipo : '');
  }

  async function cerrarSesion() {
    await pedir('sesion.php', { metodo: 'DELETE' });
    sesionCache = null;
    csrf = null;
    window.location.href = 'index.html';
  }

  function renderNav(estado) {
    const nav = document.getElementById('nav');
    if (!nav) return;
    nav.replaceChildren();
    const actual = location.pathname.split('/').pop() || 'index.html';
    const enlace = (href, texto) => el('a', { href, text: texto, 'aria-current': actual === href ? 'page' : null });

    nav.append(enlace('index.html', 'Disfraces'));
    if (estado.autenticado) {
      if (estado.usuario.admin) nav.append(enlace('admin.html', 'Administración'));
      nav.append(
        el('span', { class: 'saludo', text: '👻 ' + estado.usuario.nombre }),
        el('button', { type: 'button', class: 'btn-enlace', text: 'Salir', onclick: () => cerrarSesion().catch(() => {}) })
      );
    } else {
      nav.append(enlace('login.html', 'Iniciar sesión'), enlace('registro.html', 'Registrarse'));
    }
  }

  /** Solo se permite volver a páginas propias (evita redirecciones abiertas). */
  function destinoSeguro(valor) {
    return ['index.html', 'admin.html'].includes(valor) ? valor : 'index.html';
  }

  return { pedir, sesion, el, mensaje, renderNav, destinoSeguro };
})();
