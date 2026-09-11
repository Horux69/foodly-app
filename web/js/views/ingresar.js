import { api, setToken } from '../api.js';
import { icon } from '../icons.js';
import { go, firstAllowed } from '../router.js';
import * as session from '../session.js';
import { button, errorBox, field, h, input, render } from '../ui.js';

export async function ingresar(outlet) {
  const correo = input({ type: 'email', autocomplete: 'username', required: true, placeholder: 'tu@restaurante.com' });
  const clave = input({ type: 'password', autocomplete: 'current-password', required: true, placeholder: '••••••••' });
  const aviso = h('div');
  const entrar = button('Entrar', { type: 'submit', full: true, iconName: 'salir' });

  // El nombre corto del restaurante. Escondido hasta que haga falta: solo
  // hace falta cuando el mismo correo y la misma contraseña sirven en más de
  // uno, que es raro. Pedirlo siempre sería fricción para todo el mundo por
  // el caso de unos pocos.
  const empresa = input({ autocomplete: 'organization', placeholder: 'burger-demo' });
  const campoEmpresa = h(
    'div',
    { hidden: true },
    field('Restaurante', empresa, 'El nombre corto de tu restaurante. Si no lo sabes, pregúntale a quien administra.')
  );

  const formulario = h(
    'form',
    {
      class: 'seccion p-6 space-y-5',
      onSubmit: async (event) => {
        event.preventDefault();
        render(aviso);
        entrar.disabled = true;
        try {
          const { access_token } = await api.post('/auth/login', {
            email: correo.value.trim(),
            password: clave.value,
            slug: empresa.value.trim() || null,
          });
          setToken(access_token);
          await session.load();
          go(firstAllowed() ?? 'pedidos', { replace: true });
        } catch (error) {
          render(aviso, errorBox(error.message));

          // 409: las credenciales están bien, falta decir en qué restaurante.
          // Solo entonces aparece el campo, y la contraseña se conserva —
          // volver a escribirla para responder "¿cuál?" sería absurdo.
          if (error.status === 409) {
            campoEmpresa.hidden = false;
            empresa.focus();
          } else {
            clave.value = '';
            clave.focus();
          }
        } finally {
          entrar.disabled = false;
        }
      },
    },
    h(
      'div',
      { class: 'text-center' },
      h(
        'div',
        { class: 'inline-flex items-center justify-center w-12 h-12 rounded-2xl bg-amber-700 text-[--tinta-inversa] mb-3' },
        icon('cocina', { size: 26 })
      ),
      h('h1', { class: 'text-xl font-semibold tracking-tight text-stone-900' }, 'Bienvenido'),
      h('p', { class: 'text-sm text-stone-500 mt-1' }, 'Ingresa para operar tu restaurante')
    ),
    field('Correo', correo),
    field('Contraseña', clave),
    campoEmpresa,
    aviso,
    entrar
  );

  render(
    outlet,
    h(
      'div',
      { class: 'min-h-[75vh] flex items-center justify-center p-4' },
      h('div', { class: 'w-full max-w-sm aparece' }, formulario)
    )
  );

  correo.focus();
}
