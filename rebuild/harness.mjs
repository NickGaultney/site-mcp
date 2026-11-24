// What capture.mjs and probe.mjs share: arguments, live and preview URLs, and loading a page into
// the state a visitor sees (deferred JS run, lazy images loaded, entrance animations finished).
import { chromePath } from './chrome.mjs';
import { chromium } from 'playwright-core';

/** Pulls `--name value` options out of argv; what is left are the positional arguments. */
export function parseArgs(argv, defaults) {
  const args = [...argv];
  const opts = { ...defaults };
  for (const name of Object.keys(defaults)) {
    const i = args.indexOf('--' + name);
    if (i === -1) continue;
    opts[name] = typeof defaults[name] === 'boolean' ? (args.splice(i, 1), true) : args.splice(i, 2)[1];
  }
  return { opts, rest: args };
}

/**
 * A page path as typed. Git Bash on Windows rewrites arguments that look like absolute paths
 * ("/about/" becomes "C:/Program Files/Git/about/"), so that rewrite is undone here.
 */
export function sitePath(arg) {
  const m = arg.match(/^[A-Za-z]:[\\/].*?[\\/]Git([\\/].*)?$/);
  const p = (m ? m[1] || '/' : arg).replace(/\\/g, '/');
  return p.startsWith('/') ? p : '/' + p;
}

/** The token from a preview link, or the token itself. */
export function previewToken(arg) {
  if (!arg) return null;
  return arg.includes('site_mcp_preview=') ? new URL(arg).searchParams.get('site_mcp_preview') : arg;
}

/** live: the active theme (site_mcp_preview=off also clears a preview cookie). preview: the new theme, unlabelled. */
export function pageUrl(site, p, side, token) {
  const u = new URL(site.replace(/\/$/, '') + p);
  u.searchParams.set('site_mcp_preview', side === 'preview' ? token : 'off');
  if (side === 'preview') u.searchParams.set('site_mcp_clean', '1');
  u.searchParams.set('nocache', Date.now());
  return u.href;
}

export const slug = (p) => p.replace(/^\/|\/$/g, '').replace(/[^a-z0-9]+/gi, '_') || 'home';

/** One viewport height for every width, so vh units measure the same on both sides. */
export const viewport = (width) => ({ width, height: width <= 480 ? 844 : 900 });

export function launch() {
  return chromium.launch({ executablePath: chromePath(), args: ['--hide-scrollbars', '--mute-audio', '--autoplay-policy=user-gesture-required'] });
}

const KILL_ANIMATIONS = `*,*::before,*::after{animation-duration:0s!important;animation-delay:0s!important;transition-duration:0s!important;transition-delay:0s!important}
.elementor-invisible{visibility:visible!important}`;

/** Plugins that hold back JavaScript until the visitor moves the mouse, scrolls or presses a key. */
const DELAY_JS = {
  'LiteSpeed Cache (Load JS Deferred / delay)': 'script[type="litespeed/javascript"]',
  'WP Rocket (delay JavaScript execution)': 'script[type="rocketlazyloadscript"]',
  'Perfmatters (delay JavaScript)': 'script[type="pmdelayedscript"]',
};

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/**
 * Opens a page in a fresh context (no preview cookie carries over between sides) and gets it into
 * the state a visitor sees. Returns { page, context, status, delayJs }.
 */
export async function openPage(browser, url, width) {
  const context = await browser.newContext({ viewport: viewport(width), deviceScaleFactor: 1 });
  const page = await context.newPage();
  let status = null;
  try {
    const response = await page.goto(url, { waitUntil: 'load', timeout: 90000 });
    status = response?.status() ?? null;
    await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
  } catch (e) {
    console.error(`could not load ${url}: ${e.message}`);
  }
  const delayJs = await page.evaluate((map) => Object.keys(map).filter((name) => document.querySelector(map[name])), DELAY_JS).catch(() => []);

  // Delay-JS plugins run nothing until a real interaction. Without this, carousels, galleries and
  // counters on the live site stay in their uninitialised layout and look different from what visitors see.
  await page.mouse.move(200, 200);
  await page.mouse.move(260, 240);
  await page.mouse.wheel(0, 120);
  await page.keyboard.press('Shift');
  await sleep(2500);
  await page.addStyleTag({ content: KILL_ANIMATIONS }).catch(() => {});

  // Scroll through in steps so IntersectionObservers fire: lazy images, galleries, counters, reveals.
  const step = Math.round(viewport(width).height * 0.7);
  let height = await page.evaluate(() => document.documentElement.scrollHeight);
  for (let y = 0; y < height; y += step) {
    await page.evaluate((yy) => window.scrollTo(0, yy), y);
    await sleep(160);
    height = await page.evaluate(() => document.documentElement.scrollHeight);
  }
  await sleep(2500);
  await page.evaluate(() => {
    document.querySelectorAll('.elementor-invisible').forEach((e) => e.classList.remove('elementor-invisible'));
    document.querySelectorAll('img[loading=lazy]').forEach((i) => { i.loading = 'eager'; });
  });
  await page.evaluate(async () => {
    const waiting = [...document.images].filter((i) => !i.complete);
    await Promise.race([Promise.all(waiting.map((i) => new Promise((r) => { i.onload = i.onerror = r; }))), new Promise((r) => setTimeout(r, 6000))]);
  });
  await page.evaluate(() => window.scrollTo(0, 0));
  await sleep(700);
  return { page, context, status, delayJs };
}
