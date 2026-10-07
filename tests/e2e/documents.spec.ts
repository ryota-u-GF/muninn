import { expect, test } from '@playwright/test';
import { login } from './helpers';

test('議事録：月カレンダーの日付で絞り込み・テンプレート・本文検索・未保存確認・履歴復元', async ({
    page,
}) => {
    await login(page, 'admin');
    await page.goto('/minutes');
    await page.getByRole('tab', { name: '月カレンダー' }).click();
    await expect(page.getByTestId('minutes-calendar')).toBeVisible();
    // 今日の日付（同日に2件）をクリック → 一覧が絞り込まれる
    const today = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Tokyo',
    }).format(new Date());
    const [, m, d] = today.split('-').map(Number);
    await page
        .getByRole('button', {
            name: new RegExp(`^${m}/${d}（.）の議事録で絞り込む`),
        })
        .click();
    const list = page.getByRole('list', { name: '議事録一覧' });
    await expect(list.getByRole('listitem')).toHaveCount(2);
    await expect(list.getByText('全体朝会', { exact: true })).toBeVisible();

    // 新規作成（テンプレート入り）
    await page.getByRole('button', { name: '議事録を作成' }).click();
    await expect(
        page.getByLabel('本文（プレーンテキスト／貼り付け可）'),
    ).toHaveValue(/## 決定事項/);
    await page.getByLabel('タイトル（必須）').fill('E2E定例');
    await page.getByLabel('参加者').fill('山田、外注1');
    await page
        .getByLabel('本文（プレーンテキスト／貼り付け可）')
        .fill('## 決定事項\n- E2E固有キーワード');
    await page.getByRole('button', { name: '保存', exact: true }).click();
    await expect(page.getByText('保存しました')).toBeVisible();

    // 本文検索
    await page.getByRole('button', { name: '解除' }).click();
    await page.getByLabel('本文検索').fill('E2E固有キーワード');
    await expect(list.getByRole('listitem')).toHaveCount(1);

    // 会議日・参加者を変更して保存 → 履歴から版1に復元
    await page.getByLabel('会議日（必須）').fill('2026-11-03');
    await page.getByLabel('参加者').fill('山田');
    await page.getByRole('button', { name: '保存', exact: true }).click();
    await expect(page.getByText('編集（版 2）')).toBeVisible();

    // 未保存のまま別画面へ移動しようとすると確認（キャンセルで残る）
    await page.getByLabel('タイトル（必須）').fill('E2E定例（未保存）');
    page.once('dialog', (dlg) => dlg.dismiss());
    await page.getByRole('link', { name: '文書', exact: true }).click();
    await expect(page).toHaveURL(/\/minutes/);
    await page.getByLabel('タイトル（必須）').fill('E2E定例');

    await page.getByRole('button', { name: '変更履歴' }).click();
    await page.getByRole('button', { name: /版 1・作成/ }).click();
    await expect(page.getByText('山田、外注1')).toBeVisible();
    await page.getByRole('button', { name: 'この版に復元…' }).click();
    await page
        .getByRole('button', { name: '復元する（新しい版を作成）' })
        .click();
    await expect(
        page.getByText('版 1 の内容で新しい版を作成しました'),
    ).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByLabel('会議日（必須）')).toHaveValue(today);
    await expect(page.getByLabel('参加者')).toHaveValue('山田、外注1');
});

test('文書：.md ファイル取り込み（UTF-8・上限確認）', async ({ page }) => {
    await login(page, 'engineer');
    await page.goto('/documents');
    await page.getByRole('button', { name: '文書を作成' }).click();
    await page.getByTestId('doc-file-input').setInputFiles({
        name: 'manual.md',
        mimeType: 'text/markdown',
        buffer: Buffer.from('# 手順書\n1. 起動する\n', 'utf8'),
    });
    await expect(
        page.getByLabel('本文（プレーンテキスト／貼り付け可）'),
    ).toHaveValue('# 手順書\n1. 起動する\n');
    await expect(page.getByLabel('タイトル（必須）')).toHaveValue('manual');

    await page.getByTestId('doc-file-input').setInputFiles({
        name: 'big.txt',
        mimeType: 'text/plain',
        buffer: Buffer.alloc(401 * 1024, 'a'),
    });
    await expect(
        page.getByText('ファイルは 400KB 以下にしてください。'),
    ).toBeVisible();
});
