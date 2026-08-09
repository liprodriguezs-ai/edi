# Kivo7 / Residia — Frontend

SPA de **Kivo7** (Residia): plataforma SaaS multi-tenant para administrar
clústeres residenciales en México. Consume la API Laravel del directorio
`../backend`.

## Stack

- **React 19** · **TypeScript** (strict) · **Vite** · **Tailwind v4** · **shadcn/ui**
- **Zustand** (`createEntityStore`) para estado de entidades, **TanStack Query** para server-state
- **React Hook Form + Zod** para formularios, **Axios** con interceptores (auth, tenant, refresh)
- **Firebase** (push/FCM web), **pnpm** como package manager

## Requisitos

- Node 20+ y **pnpm**

## Setup

```bash
pnpm install
cp .env.example .env      # configurar VITE_API_BASE_URL apuntando al backend
pnpm dev                  # servidor de desarrollo (modo staging por defecto)
```

Modos de dev disponibles: `pnpm dev:localhost`, `pnpm dev:development`,
`pnpm dev:staging`, `pnpm dev:production`.

### Variables de entorno (`.env`)

| Variable | Default / Para qué |
|---|---|
| `VITE_API_BASE_URL` | URL base de la API (ej. `http://localhost:8000/api`) |
| `VITE_PAGE_SIZE_DEFAULT` | Tamaño de página por defecto en listados (ej. `10`) |
| `VITE_RECAPTCHA_SITE_KEY` | Site key de reCAPTCHA (registro/login) |

> La configuración de Firebase web vive en `src/shared/firebase/firebaseConfig.ts`
> (las claves de cliente web de Firebase son públicas por diseño; la seguridad
> está en las reglas de Firebase, no en la apiKey).

## Build y deploy

```bash
pnpm build                # build de producción (tsc -b && vite build)
pnpm preview              # build + wrangler dev (Cloudflare Pages)
pnpm deploy               # build + wrangler deploy
```

## Calidad

```bash
pnpm typecheck            # tsc --noEmit
pnpm lint                 # ESLint
pnpm format               # Prettier --write
pnpm test                 # Vitest
```

El hook de pre-commit (Husky) corre typecheck + pretty-quick + lint-staged.

## Estructura

- `src/modules/<modulo>/` — cada módulo: `schema.ts` (Zod + interfaces), `*ListPage.tsx`, `*Form.tsx`, `services/` (resources Axios), `store/` (Zustand).
- `src/shared/` — hooks base (`useBaseList`, `useBaseCrud`), API client, auth, componentes y utilidades transversales.
- `src/App.tsx` — router con guards (`ProtectedRoute`, `PermissionRoute`, `SubscriptionGuard`).

El menú lateral es dinámico: lo sirve `GET /api/menu` según los permisos del rol.
