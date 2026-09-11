// Tema claro u oscuro, por dispositivo.
//
// Se recuerda con la misma llave de localStorage que la estación de cocina o
// la caja elegida: es del aparato, no de la persona. Lo que se protege aquí
// es que el interruptor cambie el atributo que app.css lee (`data-theme`) y
// lo deje guardado, no el color exacto de cada variable — eso lo comprueba
// mejor un vistazo al navegador que una prueba de jsdom.

import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { montarApp, reposar } from './montar-app.js';
import { sesion } from './sesion.js';

const boton = (texto) => [...document.querySelectorAll('button')].find((b) => b.textContent.trim() === texto);

beforeEach(() => {
  document.documentElement.removeAttribute('data-theme');
});

afterEach(() => {
  document.documentElement.removeAttribute('data-theme');
});

describe('tema claro/oscuro', () => {
  const montar = () =>
    montarApp({
      token: 't',
      hash: '#/pedidos',
      respuestas: {
        '/auth/me': sesion({ permissions: ['orders.create'] }),
        '/menu': [],
      },
    });

  it('arranca en claro y el botón ofrece pasar a oscuro', async () => {
    // El atributo lo pone el script anti-parpadeo de index.html, que aquí no
    // corre (el arnés monta solo el <body>): sin él, no estar en "dark" ya
    // es "claro" para `temaActual()`, así que alcanza con que no sea 'dark'.
    await montar();
    await reposar();

    expect(document.documentElement.getAttribute('data-theme')).not.toBe('dark');
    expect(boton('Tema oscuro')).toBeDefined();
  });

  it('alternar pone data-theme en oscuro y lo guarda por dispositivo', async () => {
    await montar();
    await reposar();

    boton('Tema oscuro').click();
    await reposar();

    expect(document.documentElement.getAttribute('data-theme')).toBe('dark');
    expect(localStorage.getItem('resto_tema')).toBe('dark');
    // El botón ahora ofrece volver: si siguiera diciendo "Tema oscuro" nadie
    // podría deshacer el cambio sin adivinar que el mismo botón alterna.
    expect(boton('Tema claro')).toBeDefined();
  });

  it('alternar dos veces vuelve a claro', async () => {
    await montar();
    await reposar();

    boton('Tema oscuro').click();
    await reposar();
    boton('Tema claro').click();
    await reposar();

    expect(document.documentElement.getAttribute('data-theme')).toBe('light');
    expect(localStorage.getItem('resto_tema')).toBe('light');
  });
});
