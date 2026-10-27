import type { KUConfig } from './types';

const DEFAULTS: KUConfig = {
  graphUrl: '',
  graphRestUrl: '',
  graphVersion: '',
  newsUrl: '',
  newsCount: 12,
  newsRefresh: 600,
  searchUrl: '/',
  siteOrigin: window.location.origin,
  locale: document.documentElement.lang || 'en-US',
};

/** Reads the inert JSON config printed by the PHP template. */
export function readConfig(root: ParentNode = document): KUConfig {
  const el = root.querySelector<HTMLScriptElement>('#imedix-ku-config');
  if (!el?.textContent) return DEFAULTS;
  try {
    const parsed = JSON.parse(el.textContent) as Partial<KUConfig>;
    return { ...DEFAULTS, ...parsed };
  } catch {
    return DEFAULTS;
  }
}
