import type { APIRequestContext, Page } from '@playwright/test';
import { expect } from '@playwright/test';

export const USERS = {
    admin: '山田 太郎（社長）',
    sales: '佐藤 花子（営業）',
    engineer: '鈴木 一郎（エンジニア）',
    c1: '外注 高橋',
} as const;

export async function login(page: Page, who: keyof typeof USERS) {
    await page.goto('/login');
    await page
        .getByRole('button', {
            name: new RegExp(USERS[who].replace(/[()（）]/g, '.')),
        })
        .click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
}

/** ブラウザのセッションを使って API を呼ぶ（XSRF トークン付き） */
export async function apiCall(
    page: Page,
    method: string,
    url: string,
    body?: unknown,
) {
    return page.evaluate(
        async ({ method, url, body }) => {
            const token = decodeURIComponent(
                document.cookie
                    .split('; ')
                    .find((c) => c.startsWith('XSRF-TOKEN='))
                    ?.split('=')[1] ?? '',
            );
            const res = await fetch(url, {
                method,
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': token,
                },
                ...(body === undefined || method === 'GET'
                    ? {}
                    : { body: JSON.stringify(body) }),
            });

            return {
                status: res.status,
                json: await res.json().catch(() => null),
            };
        },
        { method, url, body },
    );
}

export async function findTask(page: Page, title: string) {
    const res = await apiCall(
        page,
        'GET',
        `/api/tasks?include_old_done=1&q=${encodeURIComponent(title)}`,
    );
    expect(res.status).toBe(200);
    const task = res.json.data.find(
        (t: { title: string }) => t.title === title,
    );
    expect(task, `task ${title}`).toBeTruthy();

    return task as {
        id: number;
        title: string;
        status: string;
        revision: number;
        project_id: number | null;
    };
}

export type { APIRequestContext };
