'use strict';

(async function () {
  const { pedir, sesion, mensaje, renderNav, destinoSeguro } = Halloween;
  const form = document.getElementById('form-login');
  const aviso = document.getElementById('mensaje');
  const destino = destinoSeguro(new URLSearchParams(location.search).get('volver'));

  const estado = await sesion();
  renderNav(estado);
  if (estado.autenticado) { window.location.replace(destino); return; }

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const boton = form.querySelector('button[type="submit"]');
    boton.disabled = true;
    mensaje(aviso, '');
    try {
      await pedir('sesion.php', {
        metodo: 'POST',
        json: { nombre: form.nombre.value.trim(), clave: form.clave.value },
      });
      window.location.href = destino;
    } catch (e) {
      mensaje(aviso, e.message, 'error');
      form.clave.value = '';
      form.clave.focus();
      boton.disabled = false;
    }
  });
})();
