import '@testing-library/jest-dom/vitest'

// jsdom no implementa ResizeObserver; boneyard-js (Skeleton) lo usa al montar.
if (!('ResizeObserver' in globalThis)) {
  class ResizeObserverStub {
    observe(): void {}
    unobserve(): void {}
    disconnect(): void {}
  }
  globalThis.ResizeObserver = ResizeObserverStub as unknown as typeof ResizeObserver
}

// Node 24 expone un `localStorage` EXPERIMENTAL que sólo funciona con
// --localstorage-file, y su presencia deja a jsdom sin instalar el suyo: el
// global queda declarado pero `undefined`, y cualquier `localStorage.getItem`
// revienta con "Cannot read properties of undefined". Se instala un almacén en
// memoria cuando falta, que además es lo correcto para tests: estado limpio por
// proceso y sin tocar disco.
if (typeof globalThis.localStorage === 'undefined' || globalThis.localStorage === undefined) {
  const createMemoryStorage = (): Storage => {
    let store: Record<string, string> = {}

    return {
      get length(): number {
        return Object.keys(store).length
      },
      key: (index: number): string | null => Object.keys(store)[index] ?? null,
      getItem: (key: string): string | null => (key in store ? store[key] : null),
      setItem: (key: string, value: string): void => {
        store[key] = String(value)
      },
      removeItem: (key: string): void => {
        delete store[key]
      },
      clear: (): void => {
        store = {}
      },
    } as Storage
  }

  for (const target of [globalThis, window] as Array<typeof globalThis | Window>) {
    Object.defineProperty(target, 'localStorage', {
      configurable: true,
      writable: true,
      value: createMemoryStorage(),
    })
    Object.defineProperty(target, 'sessionStorage', {
      configurable: true,
      writable: true,
      value: createMemoryStorage(),
    })
  }
}

Object.defineProperty(window, 'matchMedia', {
  writable: true,
  value: (query: string) => ({
    matches: false, media: query, onchange: null,
    addEventListener: () => {}, removeEventListener: () => {},
    addListener: () => {}, removeListener: () => {}, dispatchEvent: () => false,
  }),
})
