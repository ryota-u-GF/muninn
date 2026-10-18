import { expect, test } from '@playwright/test';
import { apiCall, login } from './helpers';

test('メール：候補（本人のみ）→選択→最終確認→タスク化（公開区分を選べる）', async ({
    page,
    browser,
}) => {
    await login(page, 'sales');
    const candidates = [
        { title: 'トップページ用の写真素材を用意する', visibility: 'shared' },
        { title: '会社概要ページの文言確認', visibility: 'shared' },
        { title: '自分だけの確認メモ', visibility: 'private' },
    ];
    await page.goto('/imports');
    const seeded = await apiCall(page, 'POST', '/api/imports', {
        json: JSON.stringify(candidates),
    });
    expect(seeded.status).toBe(201);
    await page.reload();
    await expect(page.getByRole('heading', { name: 'メール' })).toBeVisible();
    await expect(
        page.getByText('トップページ用の写真素材を用意する', { exact: true }),
    ).toBeVisible();
    await expect(
        page.getByText('会社概要ページの文言確認', { exact: true }),
    ).toBeVisible();

    // まだ共有されていない
    expect(
        (await apiCall(page, 'GET', '/api/tasks?q=トップページ用の写真素材'))
            .json.data,
    ).toHaveLength(0);

    // 他人には見えない
    const other = await (await browser.newContext()).newPage();
    await login(other, 'admin');
    expect(
        (await apiCall(other, 'GET', '/api/imports')).json.data,
    ).toHaveLength(0);

    await page
        .getByRole('checkbox', {
            name: '候補「トップページ用の写真素材を用意する」を選択',
        })
        .check();
    await page.getByRole('button', { name: /選択した 1 件を確認/ }).click();
    await expect(
        page
            .getByRole('dialog')
            .getByText('トップページ用の写真素材を用意する'),
    ).toBeVisible();
    await page.getByRole('button', { name: 'タスクを作成する' }).click();
    await expect(page.getByText('1件のタスクを作成しました')).toBeVisible();

    const created = (
        await apiCall(page, 'GET', '/api/tasks?q=トップページ用の写真素材')
    ).json.data;
    expect(created).toHaveLength(1);
    expect(created[0].visibility).toBe('shared');
    expect(created[0].created_via).toBe('ai_import');
    await expect(
        page.getByText('会社概要ページの文言確認', { exact: true }),
    ).toBeVisible(); // 未選択は残る

    // 却下 → 本文削除
    page.once('dialog', (d) => d.accept());
    await page
        .getByTestId(/candidate-/)
        .filter({ hasText: '会社概要ページの文言確認' })
        .getByRole('button', { name: '却下' })
        .click();
    await expect(page.getByText('却下（本文は削除済み）')).toBeVisible();

    // 「自分のみ」の候補は、非公開タスクとして作られ、他の人には見えない
    await page
        .getByRole('checkbox', {
            name: '候補「自分だけの確認メモ」を選択',
        })
        .check();
    await page.getByRole('button', { name: /選択した 1 件を確認/ }).click();
    await page.getByRole('button', { name: 'タスクを作成する' }).click();
    await expect(page.getByText('1件のタスクを作成しました')).toBeVisible();
    const mine = (await apiCall(page, 'GET', '/api/tasks?q=自分だけの確認メモ'))
        .json.data;
    expect(mine).toHaveLength(1);
    expect(mine[0].visibility).toBe('private');
    expect(
        (await apiCall(other, 'GET', '/api/tasks?q=自分だけの確認メモ')).json
            .data,
    ).toHaveLength(0);
});
