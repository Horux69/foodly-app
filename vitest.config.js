import { defineConfig } from 'vitest/config';

// jsdom y no un navegador de verdad: lo que se quiere atrapar aquí es que la
// aplicación arranque y pinte, y eso no necesita un motor de render. Las
// pruebas cargan los mismos módulos ES que sirve php/public/index.php, sin
// empaquetar nada: si una prueba pasa, es sobre el archivo que se despliega.
export default defineConfig({
  test: {
    environment: 'jsdom',
    include: ['web/tests/**/*.test.js'],
    restoreMocks: true,
  },
});
