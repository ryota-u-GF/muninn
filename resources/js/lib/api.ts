/**
 * JSON API クライアント。セッション Cookie + XSRF トークン（Laravel 標準の CSRF 対策）。
 * エラーは ApiError として投げ、呼び出し側は入力内容を保持したまま表示する。
 */

export class ApiError extends Error {
    constructor(
        public status: number,
        message: string,
        public errors: Record<string, string[]> = {},
        public data: Record<string, unknown> = {},
    ) {
        super(message);
    }

    get isConflict(): boolean {
        return this.status === 409;
    }

    get isUnauthenticated(): boolean {
        return this.status === 401 || this.status === 419;
    }

    firstError(): string {
        const first = Object.values(this.errors)[0]?.[0];

        return first ?? this.message;
    }
}

function xsrfToken(): string | null {
    const match = document.cookie
        .split('; ')
        .find((row) => row.startsWith('XSRF-TOKEN='));

    return match ? decodeURIComponent(match.split('=')[1]) : null;
}

type Options = {
    timeoutMs?: number;
    signal?: AbortSignal;
};

const MESSAGES: Record<number, string> = {
    401: 'ログインの有効期限が切れました。再ログインしてください。',
    403: 'この操作を行う権限がありません。',
    404: '見つかりません（削除されたか、閲覧権限がありません）。',
    409: '他の人が先に更新しました。最新の内容を確認してください。',
    419: 'ページの有効期限が切れました。再読み込みしてください。',
    422: '入力内容を確認してください。',
    429: '操作が多すぎます。少し待ってからやり直してください。',
};

export async function api<T>(
    method: 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE',
    url: string,
    body?: unknown,
    options: Options = {},
): Promise<T> {
    const controller = new AbortController();
    const timer = window.setTimeout(
        () => controller.abort(new DOMException('timeout', 'TimeoutError')),
        options.timeoutMs ?? 20000,
    );
    options.signal?.addEventListener('abort', () => controller.abort());

    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };
    const token = xsrfToken();
    if (token) {
        headers['X-XSRF-TOKEN'] = token;
    }

    let payload: BodyInit | undefined;
    if (body instanceof FormData) {
        payload = body;
    } else if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
        payload = JSON.stringify(body);
    }

    let response: Response;
    try {
        response = await fetch(url, {
            method,
            headers,
            ...(method === 'GET' ? {} : { body: payload }),
            credentials: 'same-origin',
            signal: controller.signal,
        });
    } catch (error) {
        const timedOut =
            error instanceof DOMException &&
            (error.name === 'TimeoutError' ||
                controller.signal.reason?.name === 'TimeoutError');
        throw new ApiError(
            0,
            timedOut
                ? '通信がタイムアウトしました。'
                : '通信できませんでした。ネットワークを確認してください。',
        );
    } finally {
        window.clearTimeout(timer);
    }

    const text = await response.text();
    let json: Record<string, unknown> = {};
    try {
        json = text ? JSON.parse(text) : {};
    } catch {
        json = {};
    }

    if (!response.ok) {
        const message =
            (typeof json.message === 'string' && json.message) ||
            MESSAGES[response.status] ||
            `エラーが発生しました（${response.status}）。`;
        throw new ApiError(
            response.status,
            response.status === 422 || response.status === 409
                ? message
                : (MESSAGES[response.status] ?? message),
            (json.errors as Record<string, string[]>) ?? {},
            json,
        );
    }

    return json as T;
}

/** 書き込み操作の冪等キー */
export function newIdempotencyKey(): string {
    return (
        globalThis.crypto?.randomUUID?.() ??
        `${Date.now()}-${Math.random().toString(36).slice(2)}`
    ).replace(/[^A-Za-z0-9_-]/g, '');
}
