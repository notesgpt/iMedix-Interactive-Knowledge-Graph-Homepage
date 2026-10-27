// Minimal static server rooted at the repository (preview HTML + built plugin assets).
import { createServer } from 'node:http';
import { readFile, stat } from 'node:fs/promises';
import { extname, join, normalize } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = fileURLToPath(new URL('../../', import.meta.url));
const TYPES = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript', '.css': 'text/css', '.json': 'application/json', '.woff2': 'font/woff2', '.svg': 'image/svg+xml' };

export function startServer(port = 0) {
  const server = createServer(async (req, res) => {
    const url = new URL(req.url, 'http://x');
    let path = normalize(decodeURIComponent(url.pathname)).replace(/^(\.\.[/\\])+/, '');
    if (path.endsWith('/')) path += 'index.html';
    const file = join(ROOT, path);
    if (!file.startsWith(ROOT)) return res.writeHead(403).end();
    try {
      if (!(await stat(file)).isFile()) throw new Error();
      res.writeHead(200, { 'Content-Type': TYPES[extname(file)] ?? 'application/octet-stream' });
      res.end(await readFile(file));
    } catch {
      res.writeHead(404).end('not found');
    }
  });
  return new Promise((resolve) => server.listen(port, '127.0.0.1', () => resolve(server)));
}
