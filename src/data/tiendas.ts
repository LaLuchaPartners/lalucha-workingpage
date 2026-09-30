/* src/data/tiendas.ts */

/**
 * Locales disponibles en el Libro de Reclamaciones.
 *
 * Estructura: Marca → Ubicación → Direcciones (locales).
 * Para agregar un local, añade su nombre al arreglo `direcciones`
 * de la ubicación correspondiente. Ejemplo:
 *
 *   { nombre: 'Lima', direcciones: ['Lucha Puruchuco', 'Lucha La Marina'] }
 */

export interface Ubicacion {
  nombre: string;
  direcciones: string[];
}

export interface Marca {
  nombre: string;
  ubicaciones: Ubicacion[];
}

export const marcas: Marca[] = [
  {
    nombre: 'La Lucha Sanguchería Criolla',
    ubicaciones: [
      {
        nombre: 'Lima',
        direcciones: [
          'Lucha Canepa',
          'Lucha Óvalo',
          'Lucha Diagonal',
          'Lucha Polo',
          'Lucha Champagnat',
          'Lucha Larco 999',
          'Lucha Diez Canseco',
          'Lucha San Miguel',
          'Lucha Mega Plaza',
          'Lucha Plaza Norte',
          'Lucha Plaza Norte Express',
          'Lucha Villa Chorrillos',
          'Lucha Plaza Lima Sur',
          'Lucha Bellavista',
          'Lucha Centro Cívico',
          'Lucha Puruchuco',
          'Lucha La Marina',
          'Lucha Surquillo',
          'Lucha Santa Anita',
        ],
      },
      { nombre: 'Trujillo', direcciones: ['Lucha Mall Plaza'] },
      {
        nombre: 'Arequipa',
        direcciones: ['Lucha Porongoche', 'Lucha Cayma', 'Lucha Mercaderes'],
      },
    ],
  },
  {
    nombre: 'Siete Sopas',
    ubicaciones: [
      {
        nombre: 'Lima',
        direcciones: [
          'Lince',
          'Lince Terrazas',
          'Angamos',
          'Miraflores',
          'Parque Kennedy',
          'Surco',
          'Santa Anita',
          'San Juan de Lurigancho',
          'Canepa Express',
          'Plaza Norte',
          'Megaplaza Express',
        ],
      },
    ],
  },
  {
    nombre: 'Paco Yonque',
    ubicaciones: [{ nombre: 'Lima', direcciones: ['Miraflores'] }],
  },
  {
    nombre: 'Fuente de Soda',
    ubicaciones: [{ nombre: 'Lima', direcciones: ['Miraflores'] }],
  },
  {
    nombre: 'Carbón de La Lucha',
    ubicaciones: [{ nombre: 'Lima', direcciones: ['San Miguel'] }],
  },
];
