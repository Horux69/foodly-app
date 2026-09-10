// La sesión: cambiar la propia contraseña y renovar el token.
//
// Lo que se protege es que la sesión no se caiga en mitad de un pedido —el
// token dura ocho horas y un turno puede ser más largo— y que cambiar la
// contraseña no sea algo que haya que pedirle a un administrador.

import { describe, expect, it, vi } from 'vitest';
import { montarApp, reposar, Respuesta } from './montar-app.js';
import { sesion } from './sesion.js';

/** Un JWT de mentira: solo importa su carga útil, que es lo que api.js lee. */
function token({ segundos }) {
  const carga = { sub: 'u1', exp: Math.floor(Date.now() / 1000) + segundos };
  const b64 = btoa(JSON.stringify(carga)).replace(/\+/g, '-').replace(/\//g, '_');
  return `cabecera.${b64}.firma`;
}

const RESPUESTAS = {
  '/auth/me': sesion({ permissions: ['orders.create', 'orders.view'] }),
  '/menu': [],
  '/orders': { items: [], next_cursor: null },
  '/kitchen/orders': { columns: [], orders: [], dispatched: [] },
};

const boton = (texto, raiz = document) =>
  [...raiz.querySelectorAll('button')].find((b) => b.textContent.trim().startsWith(texto));

const campo = (etiqueta) =>
  [...document.querySelectorAll('label')]
    .find((l) => l.textContent.trim().startsWith(etiqueta))
    ?.querySelector('input');

const llamadas = (fetch, ruta) =>
  fetch.mock.calls.filter(([url]) => String(url).includes(ruta));

describe('renovación del token', () => {
  it('no renueva mientras falta mucho', async () => {
    const { fetch } = await montarApp({
      token: token({ segundos: 3600 }),
      hash: '#/pedidos',
      respuestas: RESPUESTAS,
    });

    expect(llamadas(fetch, '/auth/refresh')).toHaveLength(0);
  });

  it('renueva cuando está por vencer, antes de la petición', async () => {
    const nuevo = token({ segundos: 28800 });
    const { fetch } = await montarApp({
      token: token({ segundos: 120 }),
      hash: '#/pedidos',
      respuestas: { ...RESPUESTAS, '/auth/refresh': { access_token: nuevo, token_type: 'bearer' } },
    });

    expect(llamadas(fetch, '/auth/refresh')).toHaveLength(1);
    // Y el token guardado es el nuevo: la siguiente petición ya lo usa.
    expect(localStorage.getItem('resto_token')).toBe(nuevo);
  });

  it('renueva una sola vez aunque salgan varias peticiones juntas', async () => {
    const { fetch } = await montarApp({
      token: token({ segundos: 3600 }),
      hash: '#/pedidos',
      respuestas: {
        ...RESPUESTAS,
        '/auth/refresh': { access_token: token({ segundos: 28800 }), token_type: 'bearer' },
      },
    });

    // Se vuelve a poner un token por vencer y se disparan dos peticiones a la
    // vez, que es lo que hace cualquier pantalla que carga varias cosas con
    // un Promise.all. Sin coalescer, cada una pediría su renovación.
    const { api } = await import('../js/api.js');
    localStorage.setItem('resto_token', token({ segundos: 120 }));
    await Promise.all([api.get('/menu'), api.get('/orders')]);

    expect(llamadas(fetch, '/auth/refresh')).toHaveLength(1);
  });

  it('si la renovación falla, la petición sigue su curso y el 401 manda al ingreso', async () => {
    const { fetch } = await montarApp({
      token: token({ segundos: 120 }),
      hash: '#/pedidos',
      respuestas: {
        ...RESPUESTAS,
        '/auth/refresh': new Respuesta(401, { detail: 'La sesion lleva demasiado tiempo abierta' }),
        '/auth/me': new Respuesta(401, { detail: 'Token invalido' }),
      },
    });
    await reposar();

    expect(llamadas(fetch, '/auth/refresh')).toHaveLength(1);
    expect(window.location.hash).toBe('#/ingresar');
    expect(localStorage.getItem('resto_token')).toBeNull();
  });

  it('un token ilegible no dispara renovaciones', async () => {
    const { fetch } = await montarApp({
      token: 'esto-no-es-un-jwt',
      hash: '#/pedidos',
      respuestas: RESPUESTAS,
    });

    expect(llamadas(fetch, '/auth/refresh')).toHaveLength(0);
  });
});

describe('cambiar la propia contraseña', () => {
  async function abrirCuenta(extra = {}) {
    const montado = await montarApp({
      token: token({ segundos: 3600 }),
      hash: '#/pedidos',
      respuestas: { ...RESPUESTAS, ...extra },
    });
    boton('Mi cuenta').click();
    await reposar();
    return montado;
  }

  it('está a mano para cualquiera, sin permisos de administración', async () => {
    await abrirCuenta();

    const dialogo = document.querySelector('[role="dialog"]');
    expect(dialogo).not.toBeNull();
    expect(dialogo.getAttribute('aria-label')).toBe('Mi cuenta');
    // Y el foco entra en el primer campo, como en todos los diálogos.
    expect(document.activeElement).toBe(campo('Contraseña actual'));
  });

  it('no manda nada si las dos copias no coinciden', async () => {
    const { fetch } = await abrirCuenta();

    campo('Contraseña actual').value = 'la de siempre';
    campo('Contraseña nueva').value = 'la del turno de la noche';
    campo('Repite la nueva').value = 'la del turno de la nohce';
    boton('Cambiar contraseña').click();
    await reposar();

    expect(llamadas(fetch, '/auth/password')).toHaveLength(0);
    expect(document.body.textContent).toContain('no coinciden');
  });

  it('manda la actual y la nueva', async () => {
    const { fetch } = await abrirCuenta({ '/auth/password': new Respuesta(204, null) });

    campo('Contraseña actual').value = 'la de siempre';
    campo('Contraseña nueva').value = 'la del turno de la noche';
    campo('Repite la nueva').value = 'la del turno de la noche';
    boton('Cambiar contraseña').click();
    await reposar();

    const [, opciones] = llamadas(fetch, '/auth/password')[0];
    expect(JSON.parse(opciones.body)).toEqual({
      current_password: 'la de siempre',
      new_password: 'la del turno de la noche',
    });
    // Cerró y avisó.
    expect(document.querySelector('[role="dialog"]')).toBeNull();
    expect(document.body.textContent).toContain('Contraseña cambiada');
  });

  /** El largo lo decide el dominio, y su mensaje es el que se lee. */
  it('muestra el motivo del backend sin cerrar el diálogo', async () => {
    await abrirCuenta({
      '/auth/password': new Respuesta(422, { detail: 'La contrasena nueva necesita al menos 8 caracteres' }),
    });

    campo('Contraseña actual').value = 'la de siempre';
    campo('Contraseña nueva').value = 'corta12';
    campo('Repite la nueva').value = 'corta12';
    boton('Cambiar contraseña').click();
    await reposar();

    expect(document.body.textContent).toContain('al menos 8 caracteres');
    expect(document.querySelector('[role="dialog"]')).not.toBeNull();
    expect(boton('Cambiar contraseña').disabled).toBe(false);
  });
});
