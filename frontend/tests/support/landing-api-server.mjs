// Isolated HTTP fixture, including server-side fetch. Never connects to Laravel or MySQL.
import { createServer } from 'node:http';
import { landingFixtureContent } from './landing-content.ts';

if (process.env.LANDING_FIXTURE_SERVER !== 'true') throw new Error('Explicit landing fixture environment required.');
const meta = { request_id: 'b3618469-5e0b-4f70-a61b-78680e559b1c' };
let state;
function reset() { state = { version: 0, content: landingFixtureContent(), media: [], publication: null, role: 'admin', failPublic: false, publicCookie: false, conflict: false, rotatePublic: false, publicReads: 0 }; }
reset();
// These navigation regressions model an existing member with an additional admin role.
// Operational accounts without membership have separate Stage11 fixture/live coverage.
const session = () => ({ user: { id: 42, full_name: 'Admin Sintetis', email: 'landing@example.test', email_verified: true }, roles: state.role === 'member' ? ['member'] : [state.role, 'member'], profile_complete: true, membership: { status: 'active', member_number: 'SRC-TEST', joined_at: '2026-11-21T00:00:00Z' } });
const server = createServer(async (request, response) => {
  const url = new URL(request.url, 'http://localhost:18100');
  response.setHeader('Access-Control-Allow-Origin', 'http://localhost:3110');
  response.setHeader('Access-Control-Allow-Credentials', 'true');
  response.setHeader('Access-Control-Allow-Headers', 'Content-Type, Accept, X-XSRF-TOKEN');
  response.setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
  response.setHeader('Cache-Control', 'no-store');
  const send = (status, data) => { response.writeHead(status, { 'Content-Type': 'application/json' }); response.end(JSON.stringify({ data, meta })); };
  const fail = (status, code) => { response.writeHead(status, { 'Content-Type': 'application/json' }); response.end(JSON.stringify({ error: { code, message: code, fields: {} }, meta })); };
  try {
    if (request.method === 'OPTIONS') { response.writeHead(204); response.end(); return; }
    if (url.pathname === '/health') return send(200, { fixture: true });
    if (url.pathname === '/__fixture/reset') { reset(); return send(200, {}); }
    if (url.pathname === '/__fixture/state' && request.method === 'GET') return send(200, state);
    const chunks = []; let size = 0;
    for await (const chunk of request) { size += chunk.length; if (size > 131072) return fail(413, 'PAYLOAD_TOO_LARGE'); chunks.push(chunk); }
    const body = chunks.length ? JSON.parse(Buffer.concat(chunks).toString()) : {};
    if (url.pathname === '/__fixture/state' && request.method === 'POST') { Object.assign(state, body); return send(200, {}); }
    if (url.pathname === '/sanctum/csrf-cookie') { response.setHeader('Set-Cookie', 'XSRF-TOKEN=fixture-csrf; Path=/; SameSite=Lax'); response.writeHead(204); response.end(); return; }
    if (url.pathname === '/api/v1/public/landing-page') {
      if (request.headers.cookie) state.publicCookie = true;
      if (state.failPublic) return fail(503, 'SERVICE_UNAVAILABLE');
      if (state.rotatePublic && state.publication) {
        const publication = structuredClone(state.publication);
        const label = `render-${++state.publicReads}`;
        publication.content.hero.title = label; publication.content.seo.title = label;
        return send(200, { publication });
      }
      return send(200, { publication: state.publication });
    }
    if (url.pathname === '/api/v1/auth/login') { response.setHeader('Set-Cookie', 'sorerunapp_session=fixture; Path=/; HttpOnly; SameSite=Lax'); return send(200, session()); }
    if (!request.headers.cookie?.includes('sorerunapp_session=fixture')) return fail(401, 'UNAUTHENTICATED');
    if (url.pathname === '/api/v1/auth/me') return send(200, session());
    if (url.pathname.startsWith('/api/v1/admin/') && !['admin', 'super_admin'].includes(state.role)) return fail(403, 'FORBIDDEN');
    if (request.method !== 'GET' && request.headers['x-xsrf-token'] !== 'fixture-csrf') return fail(419, 'CSRF_MISMATCH');
    const draft = () => ({ version: state.version, content: state.content, published_version: state.publication?.version ?? null, published_at: state.publication?.published_at ?? null, media: state.media });
    if (url.pathname === '/api/v1/admin/landing-page' || url.pathname === '/api/v1/admin/landing-page/preview') return send(200, draft());
    if (url.pathname === '/api/v1/admin/landing-page/media') return send(200, { items: [], page: 1, last_page: 1 });
    if (url.pathname.endsWith('/draft')) {
      if (body.version !== state.version || state.conflict) return fail(409, 'CONTENT_VERSION_CONFLICT');
      state.content = body.content; state.version++; return send(200, { version: state.version });
    }
    if (url.pathname.endsWith('/publish')) {
      if (body.version !== state.version) return fail(409, 'CONTENT_VERSION_CONFLICT');
      state.publication = { version: state.version, content: structuredClone(state.content), published_at: '2026-11-14T12:00:00Z', media: state.media.map(item => ({ ...item, url: item.url.replace('/admin/', '/public/') })) };
      return send(200, { version: state.version, published_at: state.publication.published_at });
    }
    return fail(404, 'NOT_FOUND');
  } catch { return fail(400, 'INVALID_JSON'); }
});
server.listen(18100, 'localhost');
for (const signal of ['SIGTERM', 'SIGINT']) process.on(signal, () => server.close(() => process.exit(0)));
