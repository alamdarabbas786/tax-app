export async function apiGet(baseUrl, path, token) {
  return request({ baseUrl, path, method: 'GET', token });
}

export async function apiPost(baseUrl, path, token, body) {
  return request({ baseUrl, path, method: 'POST', token, body });
}

export async function apiPostForm(baseUrl, path, token, formData) {
  return request({ baseUrl, path, method: 'POST', token, body: formData, isForm: true });
}

async function request({ baseUrl, path, method, token, body, isForm }) {
  const headers = {};
  if (!isForm) headers['Content-Type'] = 'application/json';
  if (token) {
    headers.Authorization = `Bearer ${token}`;
    headers['X-Auth-Token'] = String(token);
  }

  const res = await fetch(`${baseUrl}${path}`, {
    method,
    headers,
    body: body ? (isForm ? body : JSON.stringify(body)) : undefined
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

  // Some endpoints can respond with empty body on success (e.g. 204).
  // Return an object so callers that read `res.status` do not crash.
  return data ?? {};
}
