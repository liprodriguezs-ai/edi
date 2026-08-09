# copy-emails

Cuando ejecutes este comando (`/copy-emails`), haz lo siguiente:

**Ejecutar desde el directorio del frontend:**
```bash
pnpm run copy-emails
```

Ese script:
1. Ejecuta `export:email:blade` (genera los HTML con sintaxis Blade en `frontend/out/`).
2. Crea la carpeta `backend/Modules/Email/resources/views` si no existe.
3. Copia todo el contenido de `frontend/out/` a `backend/Modules/Email/resources/views/`.

Resultado: las vistas Blade/HTML generadas por react-email quedan en el módulo Email del backend para que Laravel las use al enviar correos.
