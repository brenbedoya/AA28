'use strict';

(async function () {
  const { pedir, sesion, el, mensaje, renderNav } = Halloween;
  const panel = document.getElementById('panel');
  const bloqueado = document.getElementById('bloqueado');
  const aviso = document.getElementById('mensaje');
  const form = document.getElementById('form-disfraz');
  const tabla = document.getElementById('tabla-cuerpo');
  const titulo = document.getElementById('titulo-form');
  const btnGuardar = document.getElementById('btn-guardar');
  const btnCancelar = document.getElementById('btn-cancelar');
  const inputFoto = document.getElementById('foto');
  const vistaPrevia = document.getElementById('vista-previa');
  const filaQuitar = document.getElementById('fila-quitar');

  const estado = await sesion();
  renderNav(estado);

  // La interfaz solo oculta/muestra; la protección real está en el servidor (requerir_admin).
  if (!estado.autenticado) { window.location.replace('login.html?volver=admin.html'); return; }
  if (!estado.usuario.admin) { bloqueado.hidden = false; return; }
  panel.hidden = false;

  let editandoId = null;
  let urlPrevia = null;

  inputFoto.addEventListener('change', () => {
    if (urlPrevia) URL.revokeObjectURL(urlPrevia);
    const archivo = inputFoto.files[0];
    if (!archivo) { vistaPrevia.hidden = true; return; }
    if (archivo.size > 2 * 1024 * 1024) {
      mensaje(aviso, 'La imagen supera los 2 MB.', 'error');
      inputFoto.value = '';
      vistaPrevia.hidden = true;
      return;
    }
    urlPrevia = URL.createObjectURL(archivo);
    vistaPrevia.src = urlPrevia;
    vistaPrevia.hidden = false;
  });

  btnCancelar.addEventListener('click', reiniciarForm);

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    mensaje(aviso, '');
    btnGuardar.disabled = true;

    const datos = new FormData(form);
    try {
      if (editandoId === null) {
        await pedir('disfraces.php', { metodo: 'POST', form: datos });
        mensaje(aviso, 'Disfraz creado. 🎃', 'ok');
      } else {
        datos.append('_method', 'PUT'); // multipart + PUT: se envía como POST con override
        await pedir('disfraces.php?id=' + editandoId, { metodo: 'POST', form: datos });
        mensaje(aviso, 'Disfraz actualizado. 🧙', 'ok');
      }
      reiniciarForm();
      await cargar();
    } catch (e) {
      mensaje(aviso, e.message, 'error');
    } finally {
      btnGuardar.disabled = false;
    }
  });

  async function cargar() {
    try {
      const disfraces = await pedir('disfraces.php');
      tabla.replaceChildren();
      if (disfraces.length === 0) {
        tabla.append(el('tr', {}, el('td', { colspan: '5', class: 'vacio', text: 'No hay disfraces registrados.' })));
        return;
      }
      for (const d of disfraces) tabla.append(fila(d));
    } catch (e) {
      mensaje(aviso, e.message, 'error');
    }
  }

  function fila(d) {
    const mini = d.imagen
      ? el('img', { src: d.imagen, alt: 'Foto de ' + d.nombre, class: 'miniatura' })
      : el('span', { class: 'miniatura sin-foto', 'aria-hidden': 'true', text: '🎃' });

    return el('tr', {},
      el('td', {}, mini),
      el('td', { 'data-label': 'Nombre', text: d.nombre }),
      el('td', { 'data-label': 'Descripción', class: 'col-desc', text: d.descripcion }),
      el('td', { 'data-label': 'Votos', text: String(d.votos) }),
      el('td', { class: 'acciones' },
        el('button', { type: 'button', class: 'btn btn-chico', text: 'Editar', onclick: () => editar(d) }),
        el('button', { type: 'button', class: 'btn btn-chico btn-peligro', text: 'Eliminar', onclick: () => eliminar(d) })
      )
    );
  }

  function editar(d) {
    editandoId = d.id;
    form.nombre.value = d.nombre;
    form.descripcion.value = d.descripcion;
    inputFoto.value = '';
    form.quitar_foto.checked = false;
    filaQuitar.hidden = !d.imagen;
    if (d.imagen) { vistaPrevia.src = d.imagen; vistaPrevia.hidden = false; } else { vistaPrevia.hidden = true; }
    titulo.textContent = 'Editar disfraz #' + d.id;
    btnGuardar.textContent = 'Guardar cambios';
    btnCancelar.hidden = false;
    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    form.nombre.focus();
  }

  async function eliminar(d) {
    if (!window.confirm('¿Eliminar el disfraz «' + d.nombre + '»? Dejará de mostrarse en la lista.')) return;
    try {
      await pedir('disfraces.php?id=' + d.id, { metodo: 'DELETE' });
      mensaje(aviso, 'Disfraz eliminado. 💀', 'ok');
      if (editandoId === d.id) reiniciarForm();
      await cargar();
    } catch (e) {
      mensaje(aviso, e.message, 'error');
    }
  }

  function reiniciarForm() {
    editandoId = null;
    form.reset();
    if (urlPrevia) { URL.revokeObjectURL(urlPrevia); urlPrevia = null; }
    vistaPrevia.hidden = true;
    vistaPrevia.removeAttribute('src');
    filaQuitar.hidden = true;
    titulo.textContent = 'Nuevo disfraz';
    btnGuardar.textContent = 'Crear disfraz';
    btnCancelar.hidden = true;
  }

  reiniciarForm();
  await cargar();
})();
