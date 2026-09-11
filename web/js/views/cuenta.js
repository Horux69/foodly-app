// Mi cuenta: cambiar la propia contraseña.
//
// Antes había que pedírselo a un administrador, que en un restaurante chico
// es el dueño y en uno grande es alguien que no está. La actual se pide
// aunque la sesión ya esté abierta: una tableta desatendida en el mostrador
// es el caso común, y sin esa comprobación cualquiera que pase deja al dueño
// fuera de su propio sistema.

import { api } from '../api.js';
import { me } from '../session.js';
import { button, field, h, input, montarDialogo, render, toast } from '../ui.js';

export function abrirCuenta() {
  const usuario = me();

  const actual = input({ type: 'password', autocomplete: 'current-password' });
  const nueva = input({ type: 'password', autocomplete: 'new-password' });
  const repetida = input({ type: 'password', autocomplete: 'new-password' });
  const aviso = h('p', { class: 'text-[12.5px] text-red-600 min-h-[1.1em]' });

  let desmontar;
  const cerrar = () => desmontar();

  const guardar = button('Cambiar contraseña', {
    onClick: async () => {
      // Lo único que comprueba el navegador: que las dos copias coincidan.
      // El largo y lo demás lo decide `Domain\PasswordRules`, y su mensaje
      // es el que se muestra.
      if (nueva.value !== repetida.value) {
        return render(aviso, 'Las dos copias de la contraseña nueva no coinciden.');
      }

      guardar.disabled = true;
      render(aviso);
      try {
        await api.post('/auth/password', {
          current_password: actual.value,
          new_password: nueva.value,
        });
        cerrar();
        toast('Contraseña cambiada', 'ok');
      } catch (error) {
        render(aviso, error.message);
        guardar.disabled = false;
      }
    },
  });

  const overlay = h(
    'div',
    {
      class: 'fixed inset-0 z-50 bg-black/30 flex items-center justify-center p-4',
      onClick: (e) => e.target === overlay && cerrar(),
    },
    h(
      'div',
      {
        class: 'aparece bg-[--panel] rounded-[--r-g] max-w-sm w-full p-5 shadow-xl border border-[--linea]',
        role: 'dialog',
        'aria-modal': 'true',
        'aria-label': 'Mi cuenta',
      },
      h('h3', { class: 'text-[15px] font-semibold' }, 'Mi cuenta'),
      h('p', { class: 'text-[13px] text-stone-500 mt-0.5 mb-4' }, `${usuario?.name ?? ''} · ${usuario?.email ?? ''}`),
      h(
        'div',
        { class: 'space-y-3' },
        field('Contraseña actual', actual),
        field('Contraseña nueva', nueva, 'Al menos 8 caracteres. Larga vale más que complicada.'),
        field('Repite la nueva', repetida)
      ),
      aviso,
      h(
        'div',
        { class: 'flex justify-end gap-2 mt-4' },
        button('Cancelar', { variant: 'secondary', onClick: cerrar }),
        guardar
      )
    )
  );

  desmontar = montarDialogo(overlay, { alCerrar: cerrar, foco: actual });
}
