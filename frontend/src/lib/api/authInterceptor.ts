/**
 * Interceptor de auth para el cliente HTTP.
 *
 * Cuando cualquier llamada del SDK recibe 401 (token caducado, revocado
 * o sesion invalida en el backend), este interceptor:
 *  1) Limpia el state local de auth (token, tenant, user, localStorage).
 *  2) Redirige al usuario a /login.
 *
 * Excepcion deliberada: si el 401 viene de POST /auth/login (credenciales
 * malas), NO se dispara el logout automatico. El login falla con error
 * visible en el form; mantener al usuario en /login.
 *
 * Vive separado de client.ts porque depende del store y del router. El
 * cliente HTTP en si mismo no debe conocer ni el store ni el router; eso
 * mantiene client.ts reutilizable y testeable en aislamiento.
 *
 * Se instala una sola vez en main.ts, despues de inicializar Pinia y
 * el router.
 */
import { client } from './generated/client.gen'
import router from '@/router'
import { useAuthStore } from '@/stores/auth'

/** Path del endpoint de login (relativo al baseUrl del cliente). */
const LOGIN_PATH = '/auth/login'

/**
 * Determina si una request fallida es la del propio login. En ese caso
 * NO debemos disparar logout automatico, porque el usuario nunca tuvo
 * sesion para empezar.
 */
function isLoginRequest(request: Request | undefined): boolean {
  if (!request) {
    return false
  }
  try {
    const url = new URL(request.url)
    return url.pathname.endsWith(LOGIN_PATH)
  } catch {
    return false
  }
}

/**
 * Registra el interceptor de error en el cliente global.
 *
 * Devuelve el id del interceptor para que tests o codigo avanzado
 * puedan removerlo via `client.interceptors.error.eject(id)`.
 */
export function installAuthInterceptor(): number {
  return client.interceptors.error.use(async (error, response, request) => {
    if (response?.status !== 401) {
      return error
    }

    if (isLoginRequest(request)) {
      return error
    }

    // Sesion invalida: limpiar state y redirigir.
    const auth = useAuthStore()
    auth.forceLogout()

    // Solo navegamos si no estamos ya en /login para evitar warnings
    // del router por navegaciones duplicadas.
    if (router.currentRoute.value.name !== 'login') {
      await router.push({ name: 'login' })
    }

    return error
  })
}
