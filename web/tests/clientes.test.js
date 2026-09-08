// La pantalla de clientes.
//
// Lo que se protege aquí no es el maquetado: es que el teléfono no se pueda
// editar. Es la identidad del cliente y la llave única por empresa, y el día
// que aparezca un campo para tocarlo, cambiarlo convertirá a alguien en otra
// persona.

import { describe, expect, it } from 'vitest';
import { montarApp, reposar } from './montar-app.js';
import { sesion } from './sesion.js';

const CLIENTES = [
  { id: 'c1', phone: '3001112223', name: 'Ana Pérez', email: null, created_at: '2026-09-01T10:00:00Z' },
  { id: 'c2', phone: '3004445566', name: null, email: null, created_at: '2026-09-02T10:00:00Z' },
];

const DETALLE = {
  customer: CLIENTES[0],
  orders_completed: 7,
  total_spent: '154000.00',
  last_address: 'Calle 10 #5-20',
  recent_orders: [
    { id: 'o1', order_number: 'CEN-00012', channel: 'delivery', total: '41000.00', created_at: '2026-09-05T18:00:00Z' },
  ],
};

const montar = () =>
  montarApp({
    token: 'un-token',
    hash: '#/clientes',
    respuestas: {
      '/auth/me': sesion({ permissions: ['customers.view', 'customers.manage'] }),
      '/customers': CLIENTES,
      '/customers/*': DETALLE,
    },
  });

describe('pantalla de clientes', () => {
  it('lista lo que devuelve la búsqueda', async () => {
    await montar();
    const vista = document.getElementById('vista').textContent;
    expect(vista).toContain('Ana Pérez');
    expect(vista).toContain('3001112223');
    // Sin nombre no deja el hueco en blanco.
    expect(vista).toContain('Sin nombre');
  });

  it('abre la ficha con el historial que ya devolvía la API', async () => {
    await montar();
    document.querySelector('#vista button.fila').click();
    await reposar();

    const vista = document.getElementById('vista').textContent;
    expect(vista).toContain('7');
    expect(vista).toContain('CEN-00012');
    expect(vista).toContain('Domicilio');
  });

  it('no ofrece editar el teléfono', async () => {
    await montar();
    document.querySelector('#vista button.fila').click();
    await reposar();

    const valores = [...document.querySelectorAll('#vista input')].map((i) => i.value);
    expect(valores).toContain('Ana Pérez');
    expect(valores).not.toContain('3001112223');
  });

  it('no aparece en la navegación sin permiso de clientes', async () => {
    await montarApp({
      token: 'un-token',
      hash: '#/pedidos',
      respuestas: {
        '/auth/me': sesion({ permissions: ['orders.create'] }),
        '/menu': [],
      },
    });
    expect(document.getElementById('rail').textContent).not.toContain('Clientes');
  });
});
