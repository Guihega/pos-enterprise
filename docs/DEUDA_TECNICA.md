# Deuda tecnica

## Que es este documento

Inventario de la numeracion `[deuda-N]` que hasta el 2026-07-31 solo existia en los
mensajes de commit de la rama `feature/etapa3-frontend-cimientos` (diferida por
ADR-0013, tip 0f166e3). No habia archivo. La numeracion dependia de que nadie
borrara la rama; este volcado desactiva esa dependencia.

Fuente: `git log main..feature/etapa3-frontend-cimientos`. La descripcion de cada
entrada es el texto LITERAL del commit. Nada aqui esta inferido del codigo.

## Como leer la columna "En main"

Cada tag marca el commit que PAGO esa deuda DENTRO de la rama. Como la rama no
esta fusionada, el pago no esta en `main` salvo reintroduccion por otra via.

- `SI (verificado)`: confirmado con grep sobre `main`.
- `NO (...)`: confirmado ausente, con la referencia de donde consta.
- `no (frontend)`: `main` no contiene `frontend/`, asi que el pago no puede estar.
- `sin verificar`: significa exactamente eso. No es un "no".

## Inventario (rango real 3-18)

| N | Commit | Descripcion (literal del commit) | En main |
|---|--------|----------------------------------|---------|
| 3 | `0f166e3` | fix(openapi): corrige puerto del server local a 8080 para coincidir con docker | sin verificar |
| 5 | `18e18a7` | feat(frontend): PIN supervisor para vaciar carrito | no (frontend) |
| 6 | `4fc6d36` | feat(frontend): rediseno profesional del login UX | no (frontend) |
| 7 | `8a8411f` | docs(openapi): documenta create update y delete de products | sin verificar |
| 8 | `48adb40` | feat(seeders): nombres realistas de retail en productos demo | sin verificar |
| 9 | `e11fba5` | docs(tenancy): convierte mencion fantasma de TenantAwareJob en TODO rastreable | sin verificar |
| 10 | `d10e00e` | docs(tenancy): documenta listener Octane pendiente como TODO rastreable | sin verificar |
| 11 | `67ec408` | docs(adr): consolida ADRs en docs/adr unico y mueve 0007 desde backend | SI (verificado) |
| 12 | `2109b1f` | fix(docker): extrae credenciales hardcodeadas a variables del .env | SI (verificado) |
| 13 | `de69845` | feat(frontend): diseno responsive del POS con drawer de carrito | no (frontend) |
| 15 | `6066fc2` | feat(seeders): stocks iniciales por producto en DevDataSeeder | sin verificar |
| 16 | `f2c3261` | fix(frontend): limita cantidad en carrito al stock disponible | no (frontend) |
| 16 | `ae77559` | feat(frontend): validacion UX de stock en catalogo | no (frontend) |
| 17 | `185857d` | refactor(frontend): extrae formatPrice a lib/format.ts | no (frontend) |
| 18 | `b15adb6` | fix(docs): corrige enum status de sesion de caja a voided en OpenAPI | sin verificar |

## Anomalias de la numeracion

1. **`[deuda-4]` y `[deuda-14]` no existen** en ningun mensaje de la rama.
2. **`[deuda-16]` esta duplicada**: `f2c3261` y `ae77559`, mismo tema (limite de
   stock en carrito y en catalogo).
3. **El rango citado en documentos previos (`[deuda-3]...[deuda-16]`) es incorrecto**:
   omite dos huecos internos y el techo real es 18, no 16. El extremo inferior si
   es correcto.
4. `[deuda-17]` y `[deuda-18]` aparecen en el CUERPO del commit, no en el asunto.
   Un grep del asunto no los encuentra.

## Cruces con otros documentos

- **`[deuda-11]` (`67ec408`) YA esta en `main`** (verificado 2026-07-31).
  Esta entrada nacio como `NO` heredando una afirmacion de `TRASPASO_SESION.md`
  que nadie habia verificado, y era falsa. `backend/docs/adr/README.md` sobrevive
  a proposito como puntero, no como residuo.
- `[deuda-12]` (`2109b1f`) ya esta en `main` por otra via.
- `b3ec40d` (orden `EnsureTenantContext` -> `SubstituteBindings`) tambien esta en
  `main`, pero NO lleva tag de deuda: no toda correccion de la rama esta numerada.

## Mantenimiento

Si se reabre el ciclo de frontend y la rama se fusiona, las entradas marcadas
`no (frontend)` pasan a `SI` en bloque. Si la rama se borra alguna vez, este
archivo es el unico registro que queda.
