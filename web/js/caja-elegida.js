// Qué caja usa este dispositivo, cuando la sucursal tiene más de una (F7.4).
//
// Va aparte de las pantallas porque lo comparten dos: la caja, que abre y
// cierra turnos, y el detalle del pedido, que cobra desde Pedidos, Cocina,
// Domicilios y el Salón. Elegir "Barra" en una y que la otra pregunte de
// nuevo sería absurdo en la misma tableta.
//
// Con una sola caja —o ninguna configurada, el caso de casi todos— cada
// pantalla resuelve sola cuál usar y no hay nada que recordar.

import { api } from './api.js';
import { activeBranchId, branchQuery } from './session.js';

const CLAVE = 'resto_caja_elegida';

function leer() {
  try {
    return localStorage.getItem(`${CLAVE}:${activeBranchId()}`) || null;
  } catch {
    return null;
  }
}

function guardar(registerId) {
  try {
    const clave = `${CLAVE}:${activeBranchId()}`;
    if (registerId === null) localStorage.removeItem(clave);
    else localStorage.setItem(clave, registerId);
  } catch {
    // Sin almacenamiento la elección vale para esta sesión y ya.
  }
}

/** Las cajas con turno abierto ahora, tal como las ve quien va a cobrar. */
export async function cajasAbiertas() {
  return api.get(`/cash/registers/open${branchQuery()}`);
}

export { guardar as recordarCaja, leer as cajaGuardada };
