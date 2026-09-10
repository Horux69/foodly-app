// Base de clientes.
//
// No se da de alta a nadie desde aquí: la base se llena sola con el teléfono
// de cada pedido (ver `OrderService::createOrder`). Esta pantalla la vuelve
// consultable, que es el paso previo a que el agente de WhatsApp reconozca a
// quien escribe.
//
// El teléfono no se edita. Es la identidad del cliente y la llave única por
// empresa: cambiarlo convertiría a alguien en otra persona en vez de
// corregir un dato. El backend tampoco lo acepta.

import { api, query } from '../api.js';
import { date, money } from '../format.js';
import { icon } from '../icons.js';
import { can } from '../session.js';
import {
  badge, button, empty, errorBox, field, h, input, loading, pageHeader, render, section, skeleton, toast,
} from '../ui.js';
import { canal } from './cocina.js';

const ESPERA_BUSQUEDA_MS = 300;

export async function clientes(outlet) {
  const lista = h('div');
  const ficha = h('div');
  let seleccionado = null;
  let temporizador = null;

  const buscador = input({
    type: 'search',
    placeholder: 'Teléfono o nombre',
    class: 'campo pl-10',
    oninput: () => {
      clearTimeout(temporizador);
      temporizador = setTimeout(buscar, ESPERA_BUSQUEDA_MS);
    },
  });

  render(
    outlet,
    pageHeader('Clientes', {
      hint: 'Se registran solos con el teléfono de cada pedido. El teléfono es su identidad y no se edita.',
    }),
    h(
      'div',
      { class: 'grid grid-cols-1 lg:grid-cols-2 gap-4 items-start' },
      h(
        'div',
        { class: 'space-y-3' },
        h(
          'div',
          { class: 'relative' },
          h(
            'span',
            { class: 'absolute left-3 top-1/2 -translate-y-1/2 text-stone-400 pointer-events-none' },
            icon('buscar', { size: 18 })
          ),
          buscador
        ),
        lista
      ),
      h('div', { class: 'lg:sticky lg:top-[4.5rem]' }, ficha)
    )
  );

  async function buscar() {
    render(lista, skeleton({ rows: 4 }));
    let encontrados;
    try {
      encontrados = await api.get(`/customers${query({ q: buscador.value.trim() })}`);
    } catch (error) {
      return render(lista, errorBox(error.message, buscar));
    }

    if (!encontrados.length) {
      return render(
        lista,
        h(
          'div',
          { class: 'seccion' },
          buscador.value.trim()
            ? empty('Nadie coincide', `No encontramos “${buscador.value.trim()}”.`, null, 'buscar')
            : empty(
                'Todavía no hay clientes',
                'Cada pedido con teléfono registra uno automáticamente.',
                null,
                'clientes'
              )
        )
      );
    }

    render(
      lista,
      section(`${encontrados.length} cliente${encontrados.length === 1 ? '' : 's'}`, {
        list: encontrados.map((c) => filaCliente(c)),
      })
    );
  }

  function filaCliente(cliente) {
    const activo = cliente.id === seleccionado;
    return h(
      'button',
      {
        class: `fila w-full text-left ${activo ? 'bg-amber-50/60' : 'hover:bg-stone-50'}`,
        onClick: () => abrir(cliente.id),
      },
      h(
        'div',
        { class: 'flex-1 min-w-0' },
        h('div', { class: 'text-[13.5px] font-medium text-stone-900 truncate' }, cliente.name || 'Sin nombre'),
        h('div', { class: 'text-[12.5px] text-stone-500 tabular-nums' }, cliente.phone)
      ),
      icon('pedidos', { size: 15, class: 'text-stone-300' })
    );
  }

  async function abrir(customerId) {
    seleccionado = customerId;
    await buscar();
    render(ficha, h('div', { class: 'seccion' }, loading('Cargando cliente…')));

    let detalle;
    try {
      detalle = await api.get(`/customers/${customerId}`);
    } catch (error) {
      return render(ficha, errorBox(error.message, () => abrir(customerId)));
    }

    render(ficha, fichaCliente(detalle, () => abrir(customerId)));
  }

  await buscar();

  return {
    destroy() {
      clearTimeout(temporizador);
    },
  };
}

function fichaCliente(detalle, refrescar) {
  const cliente = detalle.customer;
  const editable = can('customers.manage');

  const nombre = input({ value: cliente.name ?? '', placeholder: 'Sin nombre', disabled: !editable });
  const correo = input({ value: cliente.email ?? '', placeholder: 'Sin correo', type: 'email', disabled: !editable });

  const guardar = button('Guardar', {
    onClick: async () => {
      guardar.disabled = true;
      try {
        await api.patch(`/customers/${cliente.id}`, {
          name: nombre.value.trim() || null,
          email: correo.value.trim() || null,
        });
        toast('Cliente actualizado', 'ok');
        await refrescar();
      } catch (error) {
        toast(error.message);
        guardar.disabled = false;
      }
    },
  });

  return [
    section(cliente.name || cliente.phone, {
      body: [
        h(
          'div',
          { class: 'grid grid-cols-2 gap-3 mb-4' },
          dato('Teléfono', cliente.phone),
          dato('Cliente desde', date(cliente.created_at)),
          dato('Pedidos completados', String(detalle.orders_completed)),
          dato('Total gastado', money(detalle.total_spent))
        ),
        h(
          'div',
          { class: 'space-y-3' },
          field('Nombre', nombre),
          field('Correo', correo, 'El teléfono no se edita: es la identidad del cliente.'),
          editable ? guardar : null
        ),
      ],
    }),

    section('Últimos pedidos', {
      list: detalle.recent_orders.length
        ? detalle.recent_orders.map((o) =>
            h(
              'div',
              { class: 'fila' },
              h(
                'div',
                { class: 'flex-1 min-w-0' },
                h(
                  'div',
                  { class: 'text-[13.5px] font-medium text-stone-900 flex items-center gap-2' },
                  h('span', { class: 'tabular-nums' }, o.order_number),
                  badge(canal(o.channel))
                ),
                h('div', { class: 'text-[12.5px] text-stone-500' }, date(o.created_at))
              ),
              h('span', { class: 'text-[13.5px] font-medium tabular-nums' }, money(o.total))
            )
          )
        : [h('div', { class: 'p-4' }, empty('Sin pedidos todavía', null, null, 'pedidos'))],
    }),
  ];
}

const dato = (etiqueta, valor) =>
  h(
    'div',
    {},
    h('div', { class: 'text-[11.5px] uppercase tracking-wide text-stone-400' }, etiqueta),
    h('div', { class: 'text-[14px] font-medium text-stone-900 tabular-nums' }, valor)
  );
