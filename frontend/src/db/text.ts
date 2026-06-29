/**
 * Utilidades de normalizacion de texto para busqueda local (Dexie).
 *
 * Espejo en el cliente de lo que el backend hace con `ilike` + indices
 * trigram (Product::scopeSearch). Se usa al construir `searchBlob`
 * (indice multiEntry en Dexie, doc maestro 36.4).
 */

/** Normaliza una cadena: minusculas, sin acentos/diacriticos. */
export function normalizeText(text: string): string {
  return text
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
}

/**
 * Tokeniza un texto normalizado en palabras unicas, para usar como
 * indice multiEntry (`*searchBlob`) en Dexie.
 * "Café Soluble 200g" -> ["cafe", "soluble", "200g"]
 */
export function tokenize(text: string): string[] {
  const normalized = normalizeText(text)
  const tokens = normalized.split(/\s+/).filter((t) => t.length > 0)
  return Array.from(new Set(tokens))
}

/** Construye el searchBlob de un producto a partir de nombre y SKU. */
export function buildProductSearchBlob(name: string, sku: string): string[] {
  return tokenize(`${name} ${sku}`)
}

/**
 * True si cada token de `query` es prefijo de al menos un token del blob.
 * Permite busqueda incremental ("caf" encuentra "cafe").
 */
export function matchesSearch(searchBlob: string[], query: string): boolean {
  const queryTokens = tokenize(query)
  if (queryTokens.length === 0) return true
  return queryTokens.every((qt) => searchBlob.some((bt) => bt.startsWith(qt)))
}
