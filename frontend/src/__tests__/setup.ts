/**
 * Setup global de Vitest.
 *
 * `fake-indexeddb/auto` parchea `globalThis.indexedDB` y
 * `globalThis.IDBKeyRange` con una implementacion en memoria, necesaria
 * porque jsdom (entorno de test configurado en vitest.config.ts) no
 * implementa IndexedDB. Sin esto, Dexie (src/db) lanzaria
 * "indexedDB is not defined" en cualquier test que toque la base local.
 *
 * No afecta tests que no usan Dexie: solo agrega un global, no cambia
 * comportamiento de nada mas.
 */
import 'fake-indexeddb/auto'
