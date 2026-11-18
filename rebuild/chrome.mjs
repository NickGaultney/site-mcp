// Finds a Chrome or Chromium to drive, on Linux, macOS or Windows: CHROME_PATH, else the newest
// Playwright Chromium (npx playwright install chromium), else an installed Google Chrome.
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { Launcher } from 'chrome-launcher';

const playwright = {
  linux: [path.join(os.homedir(), '.cache/ms-playwright'), 'chrome-linux/chrome'],
  darwin: [path.join(os.homedir(), 'Library/Caches/ms-playwright'), 'chrome-mac/Chromium.app/Contents/MacOS/Chromium'],
  win32: [path.join(process.env.LOCALAPPDATA || path.join(os.homedir(), 'AppData/Local'), 'ms-playwright'), 'chrome-win/chrome.exe'],
}[process.platform];

export function chromePath() {
  if (process.env.CHROME_PATH) return process.env.CHROME_PATH;
  const root = process.env.PLAYWRIGHT_BROWSERS_PATH || playwright?.[0];
  if (root && fs.existsSync(root)) {
    const dirs = fs.readdirSync(root).filter((d) => /^chromium-\d+$/.test(d)).sort((a, b) => a.split('-')[1] - b.split('-')[1]);
    for (const dir of dirs.reverse()) {
      const exe = path.join(root, dir, playwright[1]);
      if (fs.existsSync(exe)) return exe;
    }
  }
  let installed = [];
  try {
    installed = Launcher.getInstallations();
  } catch {} // On Linux it throws when there is none.
  if (installed.length) return installed[0];
  throw new Error('No Chrome found. Install Google Chrome, or run: npx playwright install chromium (or set CHROME_PATH).');
}
