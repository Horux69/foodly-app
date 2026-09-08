// Sesión: quién es el usuario, cómo opera su restaurante y en qué sucursal
// está parado.
//
// Se carga una vez desde /auth/me y queda disponible para todas las
// pantallas, en vez de que cada una vuelva a preguntar.

import { api, clearToken, getToken, query } from './api.js';
import { setCurrency } from './format.js';

// La sucursal elegida sobrevive a recargar la página, pero por usuario: en
// una tableta compartida, que el siguiente turno herede la sucursal del
// anterior es justo el error que se paga con pedidos en la sede equivocada.
const SUCURSAL_KEY = 'resto_sucursal';

let actual = null;
let sucursalActiva = null;

export function me() {
  return actual;
}

export async function load() {
  if (!getToken()) return null;
  actual = await api.get('/auth/me');
  setCurrency(actual.currency);
  sucursalActiva = sucursalInicial();
  return actual;
}

export function forget() {
  actual = null;
  sucursalActiva = null;
  clearToken();
}

// ---------- sucursal activa ----------

/** Las sucursales entre las que puede moverse: llegan en /auth/me. */
export function branches() {
  return actual?.branches ?? [];
}

export function activeBranchId() {
  return sucursalActiva;
}

export function activeBranch() {
  return branches().find((b) => b.id === sucursalActiva) ?? null;
}

export function setActiveBranch(branchId) {
  if (!branches().some((b) => b.id === branchId)) return;
  sucursalActiva = branchId;
  try {
    localStorage.setItem(`${SUCURSAL_KEY}:${actual.user_id}`, branchId);
  } catch {
    // Modo privado o almacenamiento lleno: la elección vale para esta sesión.
  }
}

/**
 * Query string con la sucursal activa, más lo que se le sume.
 *
 * El backend valida ese branch_id contra el tenant del token
 * (`Api\Deps::activeBranchId`); aquí solo se dice cuál se está mirando.
 */
export function branchQuery(extra = {}) {
  return query({ branch_id: activeBranchId(), ...extra });
}

/** Guardada si sigue siendo válida; si no, la del token; si no, la primera. */
function sucursalInicial() {
  const disponibles = branches();
  let guardada = null;
  try {
    guardada = localStorage.getItem(`${SUCURSAL_KEY}:${actual.user_id}`);
  } catch {
    // sin localStorage se arranca con la del token
  }
  if (disponibles.some((b) => b.id === guardada)) return guardada;
  if (disponibles.some((b) => b.id === actual.branch_id)) return actual.branch_id;
  return disponibles[0]?.id ?? actual.branch_id ?? null;
}

// ---------- permisos ----------

/** Si el usuario tiene un permiso del catálogo fijo de la plataforma. */
export function can(permission) {
  return Boolean(actual?.permissions.includes(permission));
}

/** Comodidad para condicionar por varios permisos a la vez. */
export function canAny(...permissions) {
  return permissions.some(can);
}
