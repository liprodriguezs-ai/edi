# Fuentes legacy de los indicadores

El diseño se escribió sin estos archivos —no están en este repo— y tomó las reglas
de negocio del brief. **Estas son las consultas reales del sistema anterior** y
prevalecen sobre cualquier definición inferida.

| Archivo | Qué contiene |
|---|---|
| `legacy-IndicadorController.php` | El tablero del centro de salud |
| `legacy-TraitCenso.php` | El concentrado y el listado nominal |

Origen: `~/code/edi-backend/module/{Indicador,Censos}/`.

## Los seis indicadores del tablero

| Método | Indicador |
|---|---|
| `estadoNutricional()` | Estado nutricional, tomando por niño el ÚLTIMO registro de signos vitales (`max(id_signos_vitales)` agrupado por beneficiario) |
| `pruebasEdi()` | Pruebas aplicadas en el período |
| `beneficiariosPorGrupo()` | Niños por grupo de estimulación |
| `prubaPorArea()` | Áreas más afectadas — agrupa por `SUBSTRING(eje, 1, 2)`, y por `(eje, 1, 3)` para los ejes de tres letras (ALA, ALE) |
| `pruebasEdiResultado()` | Distribución por semáforo |

## Advertencias al transcribirlas

- **El corte por área usa el PREFIJO del código del reactivo**, no una tabla de áreas.
  En el modelo nuevo eso ya está normalizado en `development_area`: usá la relación,
  no vuelvas a cortar strings.
- Las consultas del legacy no tienen aislamiento por tenant: filtran por
  `centro_salud`. En el modelo nuevo el aislamiento lo da `TenantScope` y el centro
  es una dimensión más, no la frontera de seguridad.
- `estadoNutricional()` depende de tablas de referencia OMS de peso/talla que **no
  existen** en el módulo `Instrument`. Por eso el diseño difirió ese indicador a un
  change propio; la decisión es correcta y estas fuentes la confirman.
