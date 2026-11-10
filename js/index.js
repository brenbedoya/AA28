'use strict';

(async function () {
  const { pedir, sesion, el, mensaje, renderNav } = Halloween;
  const lista = document.getElementById('lista');
  const aviso = document.getElementById('mensaje');
  let estado = { autenticado: false };

  try {
    estado = await sesion();
  } catch (_) { /* si falla se muestra como visitante */ }
  renderNav(estado);
  await cargar();

  async function cargar() {
    try {
      const disfraces = await pedir('disfraces.php');
      pintar(disfraces);
    } catch (e) {
      lista.replaceChildren();
      mensaje(aviso, e.message, 'error');
    }
  }

  function pintar(disfraces) {
    lista.replaceChildren();
    if (disfraces.length === 0) {
      lista.append(el('p', { class: 'vacio', text: '🦇 Todavía no hay disfraces. ¡Vuelve pronto!' }));
      return;
    }
    disfraces.forEach((d, i) => lista.append(tarjeta(d, i)));
  }

  function tarjeta(d, posicion) {
    const foto = d.imagen
      ? el('img', { src: d.imagen, alt: 'Foto del disfraz ' + d.nombre, loading: 'lazy' })
      : el('div', { class: 'sin-foto', 'aria-hidden': 'true', text: ['🎃', '🧛', '🧙', '👻', '🧟', '🦇'][d.id % 6] });

    const medalla = posicion < 3 && d.votos > 0
      ? el('span', { class: 'medalla', text: ['🥇', '🥈', '🥉'][posicion], title: 'Puesto ' + (posicion + 1) })
      : null;

    const boton = el('button', {
      type: 'button',
      class: 'btn ' + (d.votado ? 'btn-votado' : 'btn-votar'),
      text: !estado.autenticado ? '🔒 Inicia sesión para votar' : d.votado ? '💀 Quitar mi voto' : '🎃 Votar',
      onclick: () => alternarVoto(d, boton),
    });

    return el('article', { class: 'tarjeta' },
      el('div', { class: 'tarjeta-foto' }, foto, medalla),
      el('div', { class: 'tarjeta-cuerpo' },
        el('h2', { text: d.nombre }),
        el('p', { class: 'descripcion', text: d.descripcion }),
        el('div', { class: 'tarjeta-pie' },
          el('span', { class: 'votos', 'aria-label': d.votos + ' votos' },
            el('strong', { text: String(d.votos) }), ' ', d.votos === 1 ? 'voto' : 'votos'),
          boton
        )
      )
    );
  }

  async function alternarVoto(d, boton) {
    if (!estado.autenticado) {
      window.location.href = 'login.html?volver=index.html';
      return;
    }
    boton.disabled = true;
    try {
      if (d.votado) {
        await pedir('votos.php?id_disfraz=' + d.id, { metodo: 'DELETE' });
        mensaje(aviso, 'Voto retirado de «' + d.nombre + '».', 'info');
      } else {
        await pedir('votos.php', { metodo: 'POST', json: { id_disfraz: d.id } });
        mensaje(aviso, '¡Voto registrado para «' + d.nombre + '»! 🎃', 'ok');
      }
      await cargar();
    } catch (e) {
      if (e.status === 401) { window.location.href = 'login.html?volver=index.html'; return; }
      mensaje(aviso, e.message, 'error');
      boton.disabled = false;
    }
  }
})();
