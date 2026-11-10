'use strict';

(async function () {
  const { pedir, sesion, mensaje, renderNav } = Halloween;
  const form = document.getElementById('form-registro');
  const aviso = document.getElementById('mensaje');

  const estado = await sesion();
  renderNav(estado);
  if (estado.autenticado) { window.location.replace('index.html'); return; }

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    mensaje(aviso, '');

    if (form.clave.value !== form.clave2.value) {
      mensaje(aviso, 'Las contraseñas no coinciden.', 'error');
      form.clave2.focus();
      return;
    }

    const boton = form.querySelector('button[type="submit"]');
    boton.disabled = true;
    try {
      await pedir('usuarios.php', {
        metodo: 'POST',
        json: { nombre: form.nombre.value.trim(), clave: form.clave.value },
      });
      window.location.href = 'index.html'; // el registro ya inicia la sesión
    } catch (e) {
      mensaje(aviso, e.message, 'error');
      boton.disabled = false;
    }
  });
})();
