/* src/pages/reclamos/tiendas.json.ts */
// Copia de los locales para que enviar.php valide la marca/ubicación/dirección recibidas.
import { marcas } from '../../data/tiendas';

export const GET = () => new Response(JSON.stringify(marcas));
