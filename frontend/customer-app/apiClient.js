import { API_BASE } from './config';

// GET
export async function apiGet(path, token) {
  return request({ path, method: 'GET', token });
}

// POST
export async function apiPost(path, token, body) {
  return request({ path, method: 'POST', token, body });
}

async function request({ path, method, token, body }) {
  const headers = { 'Content-Type': 'application/json' };

  if (token) {
    headers.Authorization = `Bearer ${token}`;
    headers['X-Auth-Token'] = String(token);
  }

  //  SAFE URL BUILD
  const url = path.startsWith('http')
    ? path
    : `${API_BASE}${path}`;

  console.log("API CALL:", url);

  const res = await fetch(url, {
    method,
    headers,
    body: body ? JSON.stringify(body) : undefined
  });

  const text = await res.text();

  let data;
  try {
    data = text ? JSON.parse(text) : null;
  } catch (e) {
    data = null;
  }

  if (!res.ok) {
    const msg = data?.message || `Request failed (${res.status})`;
    throw new Error(msg);
  }

  return data;
}
