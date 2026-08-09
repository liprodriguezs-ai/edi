---
name: scanqr-camera-lifecycle
description: ScanQrModal html5-qrcode camera leak — stopCamera checks isScanning but start() sets it true only after getUserMedia resolves, so a pending start leaks the stream
metadata:
  type: pattern
---

`modules/access/components/ScanQrModal.tsx` camera lifecycle (html5-qrcode 2.3.8, app runs in StrictMode per `src/main.tsx`).

**Defect (camera stream leak on pending start):** `stopCamera()` gates on `scanner.isScanning` before calling `scanner.stop()`. In html5-qrcode 2.3.8 `start()` returns a Promise and `isScanning` flips to `true` only inside `onRenderSurfaceReady` (AFTER `getUserMedia`/`camera.render()` resolves). If cleanup runs while `start()` is still pending (StrictMode mount→cleanup→mount, fast modal toggle, or any effect re-run), `stopCamera` reads `isScanning === false`, skips `stop()`, only calls `clear()`, and nulls `scannerRef`. The promise then resolves, opens the camera, starts `foreverScan` — but nobody holds the instance to stop it. Camera light stays on; stream leaked.

**Fix shape:** capture the `start()` promise; in cleanup, `.finally()`/await it then call `stop()` regardless of `isScanning` (wrapped in try/catch). Or in `stopCamera` call `stop()` unconditionally inside try/catch (it throws "Cannot stop, scanner is not running" when idle — already swallowed) rather than gating on `isScanning`. Note `getState()`/`Html5QrcodeScannerState.SCANNING` is the more reliable signal than the `isScanning` boolean.

**What IS sound (do not re-flag):** rAF cancelled in cleanup (`cancelAnimationFrame(rafId)`); `cancelled` flag guards setState-after-unmount in both `.catch` paths and `startWhenReady`; success/error decode callback reads `mutateRef`/`isPendingRef` (no stale closure, no setState race); reset-on-open uses render-phase `wasOpen` pattern (not effect); focus effect cleans its timeout. The `cancelled` flag does NOT cover the leaked-stream case because the leak is inside the library, not in component setState.
