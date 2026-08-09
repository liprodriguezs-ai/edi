---
description: CRM multi-company project context, auth routes, state management, and environment variables
alwaysApply: false
---

> STATUS: React (React 19 + Vite + Tailwind v4 + shadcn) es el stack de frontend ACTIVO. El detalle React de abajo es guía actual y vigente. Stack canónico: stack.md.

# CRM Multi-Company — Project Overview

## Project Context

**CRM Multi-Company Management System** — SPA en React + TypeScript para gestionar empresas, usuarios, roles, permisos, colaboradores, documentos, tenants y suscripciones en un entorno multi-tenant. Incluye dominio de cumplimiento laboral (expedientes, plantillas de contrato, generación de contratos).

## Autenticación y rutas

- **Públicas (sin sesión)**: `/login`, `/register`, `/forgot-password`, `/auth/reset-password/:token/:email`, `/auth/accept-invitation/:token`.
- **Protegidas**: envueltas en `ProtectedRoute`; las que requieren suscripción activa usan `SubscriptionGuard`.
- **Por permiso**: `PermissionRoute permissionEntity="company"` (u otro) para listados/CRUD.
- **Redirección por rol**: `roleRoutes` en `Entity.ts` (SUPER_ADMIN → `/user`, COMPANY_ADMIN/RH → `/dashboard`, COLLABORATOR → `/collaborator/me`, etc.).

## Gestión de estado

| Capa | Herramienta | Uso |
|------|-------------|-----|
| Servidor | TanStack Query | Fetch, caché, invalidación |
| Entidades | Zustand (`createEntityStore`) | Lista, paginación, flags de CRUD |
| Sesión | Zustand vanilla (`sessionStore`) | Token, perfil, roles, permisos |
| Formularios | React Hook Form + Zod | Validación y submit |
| UI local | `useState` | Modales, selección |

## Variables de entorno

- `VITE_API_BASE_URL` — API del backend (default: `http://localhost:8000/api`)
- `VITE_PAGE_SIZE_DEFAULT` — Tamaño de página por defecto (default: `10`)
