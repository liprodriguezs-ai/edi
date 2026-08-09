module.exports = {
  extends: ['@commitlint/config-conventional'],
  rules: {
    // Warning (1), no error: los generadores de commits (Cursor/IA) escriben el body
    // como párrafo corrido y un límite duro solo bloquea el commit sin aportar valor.
    // El formato conventional del header sigue siendo error vía config-conventional.
    'body-max-line-length': [1, 'always', 150], // nivel, aplicabilidad, largo
  },
}
