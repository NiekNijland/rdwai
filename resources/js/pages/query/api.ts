function csrfHeader(): Record<string, string> {
    if (typeof document === 'undefined') {
        return {};
    }

    const token = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;

    return token ? { 'X-CSRF-TOKEN': token } : {};
}

export async function postJson(
    path: string,
    body: unknown,
    signal?: AbortSignal,
): Promise<Response> {
    return fetch(path, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            ...csrfHeader(),
        },
        body: JSON.stringify(body),
        signal,
    });
}

export async function parseJson(response: Response): Promise<unknown> {
    const contentType = response.headers.get('content-type') ?? '';

    if (!contentType.includes('application/json')) {
        return null;
    }

    try {
        return await response.json();
    } catch {
        return null;
    }
}
