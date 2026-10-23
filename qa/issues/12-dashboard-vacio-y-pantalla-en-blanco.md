# Dos pantallas que no muestran nada: Inicio y un tamizaje ya aplicado

## A. Inicio no muestra ninguna métrica

Una vez desbloqueado, Inicio renderiza sólo su título — sin indicadores, sin
actividad, sin nada. **Ni siquiera consulta al servidor**, aunque el resumen existe y
responde. Es la primera pantalla tras iniciar sesión y está vacía.

**Severidad: grave** (es la portada del producto).

## B. Abrir "aplicar tamizaje" sobre una visita ya tamizada deja la pantalla en blanco

### Qué pasó

Al entrar a aplicar un tamizaje sobre una visita que ya tiene uno, el servidor responde
correctamente que esa visita ya fue tamizada. La aplicación intenta entonces recuperar
el tamizaje existente, esa búsqueda falla (ver el problema del listado vacío), y **la
página queda completamente en blanco**: sin mensaje, sin explicación y sin enlace al
tamizaje que sí existe.

### Qué esperaba

Que redirija al tamizaje existente, o al menos lo diga.

### Pasos para reproducir

1. Aplicar y completar un tamizaje sobre una visita.
2. Volver a entrar a aplicar tamizaje sobre esa misma visita.
3. Pantalla en blanco.

### Contexto adicional

Depende del mismo defecto que vacía los listados de tamizajes y seguimientos: la
búsqueda no acepta el método que la aplicación usa. Al corregir aquello, este caso
necesita además su propio manejo — hoy no tiene ninguno.

**Severidad: grave.**
