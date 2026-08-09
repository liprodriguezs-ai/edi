# Guía de estilo — documentación de EDI (`/docs`)

Este archivo es el contrato de escritura del manual de producto que se publica en
`edi.example.com/docs`. Lo leen las personas del equipo y el subagente `docs-writer`
antes de tocar cualquier página.

El contenido vive en `src/content/docs/docs/**`. El subdirectorio extra es a
propósito: el slug de cada entrada es su ruta relativa a `src/content/docs/`, así
que `docs/cobranza/pagos.md` se publica en `/docs/cobranza/pagos`.

> **Nunca** crees `src/content/docs/index.md`. Su slug sería `''` y chocaría con
> `src/pages/index.astro`, la home de la landing.

---

## 1. Para quién escribimos

Una persona que administra un organización en México. Sabe de cuotas, asambleas y
morosos; no sabe de bases de datos, endpoints ni estados finitos. Puede ser la
administradora contratada, el tesorero voluntario que da su tiempo los sábados, o
un vecino que solo quiere ver cuánto debe.

Consecuencia práctica: **el vocabulario es el del organización, no el del código.**

| Escribe | Nunca escribas |
|---|---|
| cuota, cargo | `charge`, `charge_batch` |
| organización, organización | `tenant`, organización |
| casa, unidad | `unit_id` |
| folio de recibo | `receipt_number` |
| indiviso | `ownership_share` |
| arrendatario | inquilino, `renter` |
| saldo a favor | `credit_balance` |
| $1,500.00 MXN | 150000, centavos, *minor units* |

---

## 2. Idioma

- **Español de México, de tú.** "Selecciona", "captura", "revisa".
- **Nada de voseo.** Ni "seleccioná", ni "buscás", ni "volvé".
- Acentos y eñes completos, siempre. `Configuración`, no `Configuracion`.
- Montos con formato mexicano: `$1,500.00 MXN`. Fechas: `15 de marzo de 2026`.

---

## 3. Voz

- **Imperativo en los pasos.** "Selecciona el concepto", no "el concepto deberá
  ser seleccionado" ni "podemos seleccionar el concepto".
- **Voz activa.** "EDI genera el folio", no "el folio es generado".
- **Sin minimizar el esfuerzo del lector.** Prohibidas: *simplemente*, *solo
  tienes que*, *es muy fácil*, *basta con*. Si algo fuera fácil, no estaría
  documentado.
- **Sin relleno.** Nada de "en esta sección aprenderás a…". Entra directo.
- Los nombres de botón, pestaña, campo o menú van en **negrita** y **literales**
  a la interfaz. Si la pantalla dice **Cobros programados**, la documentación
  dice **Cobros programados** — no "lotes de cobro".

---

## 4. Estructura de una página de módulo

Ocho bloques, en este orden. Los que no apliquen se omiten; no se reordenan.

1. **Qué es** — una o dos frases. Qué resuelve, en lenguaje de administrador.
2. **Quién puede usarlo** — los roles, como dato. Es la única repetición
   permitida con la sección de Roles, y es intencional.
3. **Antes de empezar** — prerequisitos reales, solo si existen.
4. **Cómo se hace** — `<Steps>` con pasos numerados.
5. **Qué significa cada campo** — tabla, solo si el formulario lo amerita.
6. **Qué pasa después** — efectos: qué se notifica, qué estado cambia, qué se
   desbloquea o se bloquea. Imprescindible en cobranza y accesos.
7. **Cuidado con** — `<Aside type="caution">` para lo irreversible.
8. **Relacionado** — `<LinkCard>` a las páginas contiguas.

---

## 5. La regla de oro: una sola fuente

**Las páginas de módulo son la única fuente de verdad.** Roles, Preguntas
frecuentes y Solución de problemas **no explican**: enrutan.

- Cada página de rol es una tabla *qué ve · qué puede hacer · dónde se hace*,
  donde la última columna es un enlace a la ancla del módulo.
- Cada pregunta frecuente es: pregunta → respuesta de una o dos frases → enlace
  al procedimiento completo. Solo se permite una respuesta autocontenida cuando
  es un dato puntual que no vive en ningún módulo.

> **Si una explicación de más de tres líneas aparece en dos páginas, es un bug de
> documentación.** Se deja en el módulo y se enlaza.

Las anclas son parte del contrato: `/docs/cobranza/pagos#registrar-un-pago` es
una URL pública. **Renombrar un encabezado rompe todos los enlaces que apuntan a
él.** Si cambias un encabezado, busca quién lo enlazaba y arréglalo en el mismo
cambio.

---

## 6. El organización de ejemplo

Todos los ejemplos usan el mismo organización ficticio. Así el lector sigue un hilo
coherente y nunca se filtra información de un cliente real — ni en el texto ni en
las capturas de pantalla.

- **Residencial Las Palmas** — organización horizontal, 48 casas, Guadalajara.
- Secciones: **Sección Norte** y **Sección Sur**. Casas: **Casa 1** a **Casa 48**.
- Conceptos de cobro: **Cuota de mantenimiento** $1,500.00 MXN mensual,
  **Cuota de vigilancia** $400.00 MXN mensual, **Derrama para portón** (eventual).
- Personas: **Laura Méndez** (administradora), **Roberto Cruz** (tesorero),
  **Ana Salgado** (residente, Casa 12), **Miguel Torres** (vigilante).

No inventes otros nombres ni otros montos. Si necesitas un caso nuevo, extiende
este organización y anótalo aquí.

---

## 7. Componentes disponibles

Para usar componentes, la página debe ser `.mdx` y hay que importarlos:

```mdx
import { Steps, Aside, LinkCard, CardGrid, Card, Tabs, TabItem } from '@astrojs/starlight/components';
```

| Componente | Cuándo |
|---|---|
| `<Steps>` | Todo procedimiento de más de dos pasos. Envuelve una lista numerada. |
| `<Aside type="caution">` | Acciones irreversibles o con consecuencia de dinero. |
| `<Aside type="tip">` | Atajos y buenas prácticas. Con moderación. |
| `<Aside type="note">` | Aclaraciones que interrumpirían el párrafo. |
| `<LinkCard>` | El bloque "Relacionado" del final. |
| `<CardGrid>` + `<Card>` | Portadas de sección. No dentro de un procedimiento. |
| `<Tabs>` + `<TabItem>` | Cuando el mismo objetivo se logra distinto según el rol. |

No uses directivas `:::` sueltas en los posts del blog: el plugin de directivas
está registrado globalmente y podría interpretarlas.

---

## 8. Frontmatter

```yaml
---
title: "Registrar un pago"
description: "Cómo se captura un pago de un vecino y qué pasa con sus cargos."
sidebar:
  order: 9
---
```

- `title` y `description` **siempre entre comillas dobles**: muchos llevan dos
  puntos o comillas internas y romperían el YAML.
- `description` es lo que ve Google y lo que muestra el buscador interno. Escribe
  una frase completa y útil, no un rótulo.
- `sidebar.order` define la posición dentro de su grupo. El nombre del grupo se
  define en `astro.config.mjs`, no aquí.

---

## 9. Capturas de pantalla

- Van en `src/assets/docs/*.webp`, **nunca** en `public/`. Así reciben nombre con
  hash y caen bajo la regla de caché `immutable` que ya existe.
- WebP, ~1440 px de ancho, menos de 180 KB. **Hay que optimizarlas fuera de
  Astro**: el proyecto usa `passthroughImageService()` y no tiene Sharp, así que
  Astro no redimensiona ni transcodifica nada.
- **Alt text descriptivo obligatorio.** El sitio tiene compromiso de cero
  violaciones de accesibilidad y las capturas son la regresión más frecuente.
- Solo con datos del organización de ejemplo. Nunca con datos de un cliente real.

---

## 10. Antes de dar por terminada una página

- [ ] ¿Los nombres de botones y campos coinciden **literalmente** con la interfaz?
- [ ] ¿Los pasos se pueden seguir sin conocer el código?
- [ ] ¿Está el bloque "Qué pasa después" cuando la acción tiene efectos?
- [ ] ¿Lo irreversible está marcado con `<Aside type="caution">`?
- [ ] ¿Ninguna explicación se repite de otra página?
- [ ] ¿Los enlaces internos apuntan a anclas que existen de verdad?
- [ ] ¿`pnpm build` pasa?
