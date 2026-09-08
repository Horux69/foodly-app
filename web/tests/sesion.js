// Una sesión de mentira para las pruebas: lo que devuelve /auth/me.
//
// Se arma con `sesion({...})` para que cada prueba diga solo lo que le
// importa —los permisos que tiene, si el restaurante reparte— y no repita
// las quince claves que la aplicación espera encontrar.

const BASE = {
  user_id: '11111111-1111-4111-8111-111111111111',
  name: 'Ana Cajera',
  email: 'ana@demo.local',
  role: 'admin',
  permissions: [],
  branch_id: '22222222-2222-4222-8222-222222222222',
  branch_name: 'Centro',
  tenant_name: 'Restaurante Demo',
  currency: 'COP',
  channels: ['counter'],
  uses_tables: false,
  asks_tip: false,
  branches: [{ id: '22222222-2222-4222-8222-222222222222', name: 'Centro', code: 'CEN' }],
};

export const sesion = (cambios = {}) => ({ ...BASE, ...cambios });
