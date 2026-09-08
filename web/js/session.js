// Sesión: quién es el usuario y cómo opera su restaurante.
//
// Se carga una vez desde /auth/me y queda disponible para todas las
// pantallas, en vez de que cada una vuelva a preguntar.

import { api, clearToken, getToken } from './api.js';
import { setCurrency } from './format.js';

let actual = null;

export function me() {
  return actual;
}

export async function load() {
  if (!getToken()) return null;
  actual = await api.get('/auth/me');
  setCurrency(actual.currency);
  return actual;
}

export function forget() {
  actual = null;
  clearToken();
}

/** Si el usuario tiene un permiso del catálogo fijo de la plataforma. */
export function can(permission) {
  return Boolean(actual?.permissions.includes(permission));
}

/** Comodidad para condicionar por varios permisos a la vez. */
export function canAny(...permissions) {
  return permissions.some(can);
}
