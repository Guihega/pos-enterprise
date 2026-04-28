-- ==============================================================
-- POS Enterprise - PostgreSQL 16 init script
-- Ejecutado automáticamente al primer arranque del contenedor.
-- ==============================================================

-- --- Extensiones necesarias ---
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";    -- generación de UUIDs
CREATE EXTENSION IF NOT EXISTS "pgcrypto";     -- gen_random_uuid, hashing
CREATE EXTENSION IF NOT EXISTS "citext";       -- columnas case-insensitive (emails)
CREATE EXTENSION IF NOT EXISTS "pg_trgm";      -- búsqueda fuzzy
CREATE EXTENSION IF NOT EXISTS "btree_gin";    -- índices GIN sobre tipos comunes
CREATE EXTENSION IF NOT EXISTS "unaccent";     -- normalización de acentos

-- --- Schemas auxiliares ---
CREATE SCHEMA IF NOT EXISTS system;
CREATE SCHEMA IF NOT EXISTS audit;

COMMENT ON SCHEMA system IS 'Tablas globales del SaaS (no tenant-scoped)';
COMMENT ON SCHEMA audit  IS 'Logs de auditoría (append-only)';

-- --- Roles operativos ---
-- En desarrollo el usuario "pos" tiene acceso pleno.
-- En producción se definirán roles separados (app_user, app_readonly, backup_user).

-- --- Función helper para current_tenant_id (RLS) ---
CREATE OR REPLACE FUNCTION current_tenant_id()
RETURNS BIGINT
LANGUAGE plpgsql
STABLE
AS $$
BEGIN
    RETURN COALESCE(
        NULLIF(current_setting('app.current_tenant_id', TRUE), '')::BIGINT,
        0
    );
EXCEPTION WHEN OTHERS THEN
    RETURN 0;
END;
$$;

COMMENT ON FUNCTION current_tenant_id() IS
    'Devuelve el tenant_id de la sesión actual o 0 si no hay contexto. Usado por políticas RLS.';

-- --- Función helper para audit / hash chain (preparación para Fase 9) ---
CREATE OR REPLACE FUNCTION compute_audit_hash(
    prev_hash TEXT,
    payload   JSONB
) RETURNS TEXT
LANGUAGE plpgsql
IMMUTABLE
AS $$
BEGIN
    RETURN encode(
        digest(COALESCE(prev_hash, '') || payload::TEXT, 'sha256'),
        'hex'
    );
END;
$$;

-- --- Mensaje de confirmación ---
DO $$
BEGIN
    RAISE NOTICE 'POS Enterprise: PostgreSQL initialized successfully';
END;
$$;
