# Runbook: ambiente local no levanta

> Aplica a problemas de Docker Compose en máquina del developer.

## Síntomas

- `make up` falla.
- Algún contenedor en estado `Restarting` o `Exited`.
- `curl http://localhost:8080/health/live` no responde.

## Diagnóstico rápido

```bash
make status
make logs
```

Observa en qué contenedor está el error.

## Casos comunes y solución

### 1. Puerto ya en uso

Síntoma: error tipo `bind: address already in use` para 5432, 6379, 8080, 5173, 8025 o 8081.

```bash
# Ver qué proceso usa el puerto (ej. 8080)
sudo lsof -i :8080
# o
sudo ss -tulpn | grep 8080
```

Solución:
- Detener el proceso en conflicto, o
- Crear `docker-compose.override.yml` con puertos alternativos:
  ```yaml
  services:
    nginx:
      ports: ["18080:80"]
  ```

### 2. Permisos en `backend/` o `frontend/`

Síntoma: `Permission denied` al escribir archivos desde el contenedor.

Esto ocurre porque el contenedor escribe con UID 1000 y tu usuario en Linux puede tener otro UID.

```bash
# Verifica tu UID
id -u

# Si NO es 1000, reconstruye con tu UID
WWW_USER_ID=$(id -u) WWW_GROUP_ID=$(id -g) \
docker compose build --no-cache app
make up
```

### 3. Volúmenes corruptos / estado inconsistente

Síntomas variados, suele resolverse con un reset.

```bash
make clean       # detiene servicios y elimina volúmenes (DESTRUCTIVO)
make init        # vuelve a setupear todo
```

### 4. Postgres no inicia (datos corruptos)

```bash
docker compose logs postgres
```

Si ves errores de "PANIC" o "could not read":

```bash
docker volume rm pos-enterprise_pos-postgres-data
make init
```

### 5. Redis pide password

```bash
docker compose logs redis
```

Si ves `NOAUTH Authentication required`, verifica que `.env` tenga `REDIS_PASSWORD=redis_dev_secret_change_me`.

### 6. APP_KEY vacío

```bash
make artisan cmd="key:generate"
```

### 7. Frontend tarda muchísimo en arrancar

Primer arranque puede tomar 1–3 minutos (npm install). Posteriores deben ser segundos.

Si tarda más de 5 minutos:

```bash
docker compose logs frontend
```

Revisa si hay errores de red al instalar paquetes.

### 8. `make` no funciona

En Linux nativo `make` viene casi siempre instalado. Si no:

```bash
# Ubuntu / Debian
sudo apt install make

# Fedora
sudo dnf install make

# Arch
sudo pacman -S make
```

### 9. Docker Compose v1 en lugar de v2

Si `docker compose` (con espacio) no funciona:

```bash
# Ubuntu
sudo apt install docker-compose-plugin

# Fedora
sudo dnf install docker-compose-plugin
```

## Si nada de lo anterior funciona

1. `docker system prune -a --volumes` (DESTRUCTIVO: limpia todo Docker, no solo este proyecto).
2. Reinstalar Docker Engine.
3. Pedir ayuda en el canal interno de soporte.
