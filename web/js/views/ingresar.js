import { api, setToken } from '../api.js';
import * as session from '../session.js';
import { go, firstAllowed } from '../router.js';
import { button, errorBox, field, h, input, render } from '../ui.js';

export async function ingresar(outlet) {
  const correo = input({ type: 'email', autocomplete: 'username', required: true, placeholder: 'tu@restaurante.com' });
  const clave = input({ type: 'password', autocomplete: 'current-password', required: true });
  const aviso = h('div');
  const entrar = button('Entrar', { type: 'submit', class: 'w-full bg-slate-900 text-white rounded-lg py-2.5 font-medium hover:bg-slate-800 disabled:opacity-40' });

  const formulario = h(
    'form',
    {
      class: 'bg-white rounded-xl shadow-sm border border-slate-200 p-6 space-y-4',
      onSubmit: async (event) => {
        event.preventDefault();
        render(aviso);
        entrar.disabled = true;
        entrar.textContent = 'Entrando…';
        try {
          const { access_token } = await api.post('/auth/login', {
            email: correo.value.trim(),
            password: clave.value,
          });
          setToken(access_token);
          await session.load();
          go(firstAllowed() ?? 'pedidos', { replace: true });
        } catch (error) {
          render(aviso, errorBox(error.message));
          clave.value = '';
          clave.focus();
        } finally {
          entrar.disabled = false;
          entrar.textContent = 'Entrar';
        }
      },
    },
    h(
      'div',
      {},
      h('h1', { class: 'text-xl font-semibold text-slate-900' }, 'Ingresar'),
      h('p', { class: 'text-sm text-slate-500 mt-1' }, 'Plataforma de restaurantes')
    ),
    field('Correo', correo),
    field('Contraseña', clave),
    aviso,
    entrar
  );

  render(
    outlet,
    h('div', { class: 'min-h-[70vh] flex items-center justify-center p-4' }, h('div', { class: 'w-full max-w-sm' }, formulario))
  );

  correo.focus();
}
