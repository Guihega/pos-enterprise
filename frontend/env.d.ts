/// <reference types="vite/client" />

interface ImportMetaEnv {
  /** URL base del API REST del backend (ej. http://localhost:8080/api/v1) */
  readonly VITE_API_URL: string

  /**
   * Slug o UUID del tenant a usar en desarrollo, inyectado como `X-Tenant`.
   * En produccion el tenant se resuelve por otro mecanismo (subdominio o
   * token), asi que esta variable solo se usa en dev/staging.
   */
  readonly VITE_DEV_TENANT?: string

  /** Pusher/Reverb app key (websockets) */
  readonly VITE_REVERB_APP_KEY: string
  /** Pusher/Reverb host */
  readonly VITE_REVERB_HOST: string
  /** Pusher/Reverb port */
  readonly VITE_REVERB_PORT: string
  /** Pusher/Reverb scheme (ws o wss) */
  readonly VITE_REVERB_SCHEME: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
