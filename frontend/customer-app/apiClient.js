export async function apiGet(baseUrl, path, token) {
  return request({ baseUrl, path, method: 'GET', token });
}

export async function apiPost(baseUrl, path, token, body) {
  return request({ baseUrl, path, method: 'POST', token, body });
}

async function request({ baseUrl, path, method, token, body }) {
  const normalizedPath = path.startsWith('/') ? path : `/${path}`;
  const baseCandidates = getBaseCandidates(baseUrl);
  const headers = { 'Content-Type': 'application/json' };

  if (token) {
    headers.Authorization = `Bearer ${token}`;
    headers['X-Auth-Token'] = String(token);
  }

  for (let i = 0; i < baseCandidates.length; i += 1) {
    const currentBase = baseCandidates[i];
    const res = await fetch(`${currentBase}${normalizedPath}`, {
      method,
      headers,
      body: body ? JSON.stringify(body) : undefined,
    });

    const text = await res.text();
    let data;
    try {
      data = text ? JSON.parse(text) : null;
    } catch (e) {
      data = null;
    }

    if (res.ok) {
      return data;
    }

    // Railway deployments may be reachable on either base URL or base/public.
    // Retry once on the alternate base only when route is missing.
    const canRetry = res.status === 404 && i < baseCandidates.length - 1;
    if (canRetry) {
      continue;
    }

    const msg = data?.message || `Request failed (${res.status})`;
    throw new Error(msg);
  }

  throw new Error('Request failed');
}

function getBaseCandidates(baseUrl) {
  const normalized = String(baseUrl || '').trim().replace(/\/+$/, '');
  if (!normalized) {
    return [''];
  }

  if (normalized.endsWith('/public')) {
    const withoutPublic = normalized.replace(/\/public$/, '');
    return [normalized, withoutPublic];
  }

  return [normalized, `${normalized}/public`];
}
