import { expect, test } from '@playwright/test';
import { apiCall, findTask, login } from './helpers';

test('管理者：ボード表示・状態のドラッグ・他人の非公開は見えない', async ({
    page,
}) => {
    await login(page, 'admin');
    await expect(
        page.getByRole('heading', { name: 'タスクボード' }),
    ).toBeVisible();
    await expect(
        page.getByRole('region', { name: 'プロジェクト1' }),
    ).toBeVisible();
    await expect(
        page.getByRole('region', { name: '会社共通（案件なし）' }),
    ).toBeVisible();
    await expect(
        page.getByText('取締役会の資料下書き（非公開）'),
    ).toBeVisible(); // 自分の非公開
    await expect(page.getByText('個人の営業目標の整理（非公開）')).toHaveCount(
        0,
    ); // 他人の非公開

    const list = await apiCall(page, 'GET', '/api/tasks');
    const projects = await apiCall(page, 'GET', '/api/projects');
    const projectA = projects.json.data.find(
        (p: { name: string }) => p.name === 'プロジェクト1',
    );
    const todo = list.json.data.find(
        (t: { project_id: number; status: string }) =>
            t.project_id === projectA.id && t.status === 'todo',
    );

    await page
        .getByTestId(`task-card-${todo.id}`)
        .dragTo(page.getByTestId(`column-${projectA.id}:doing`), {
            targetPosition: { x: 40, y: 12 },
        });
    await expect(
        page
            .getByTestId(`column-${projectA.id}:doing`)
            .getByTestId(`task-card-${todo.id}`),
    ).toBeVisible();
    await expect
        .poll(async () => (await findTask(page, todo.title)).status)
        .toBe('doing');
});

test('外注（プロジェクト1編集）：プロジェクト1だけ表示、同一案件の状態ドラッグは成功、他案件はAPI直指定でも404', async ({
    page,
    browser,
}) => {
    // 先に管理者でB案件のタスクIDを調べる
    const adminPage = await (await browser.newContext()).newPage();
    await login(adminPage, 'admin');
    const bTask = await findTask(adminPage, 'テスト環境の準備');

    await login(page, 'c1');
    await expect(
        page.getByRole('region', { name: 'プロジェクト1' }),
    ).toBeVisible();
    await expect(
        page.getByRole('region', { name: 'プロジェクト2' }),
    ).toHaveCount(0);
    await expect(
        page.getByRole('region', { name: '会社共通（案件なし）' }),
    ).toHaveCount(0);
    await expect(page.getByRole('link', { name: '担当者・権限' })).toHaveCount(
        0,
    );

    const list = await apiCall(page, 'GET', '/api/tasks');
    const todo = list.json.data.find(
        (t: { status: string }) => t.status === 'todo',
    );
    await page
        .getByTestId(`task-card-${todo.id}`)
        .dragTo(page.getByTestId(`column-${todo.project_id}:done`), {
            targetPosition: { x: 40, y: 12 },
        });
    await expect
        .poll(
            async () =>
                (await apiCall(page, 'GET', `/api/tasks/${todo.id}`)).json.data
                    .status,
        )
        .toBe('done');

    expect((await apiCall(page, 'GET', `/api/tasks/${bTask.id}`)).status).toBe(
        404,
    );
    expect(
        (await apiCall(page, 'GET', `/api/tasks/${bTask.id}/revisions`)).status,
    ).toBe(404);
    expect(
        (
            await apiCall(page, 'PATCH', `/api/tasks/${todo.id}`, {
                project_id: bTask.project_id,
                expected_revision: 99,
            })
        ).status,
    ).toBeGreaterThanOrEqual(403);
    expect((await apiCall(page, 'GET', '/api/tv')).status).toBe(403);
    expect((await apiCall(page, 'GET', '/api/imports')).status).toBe(403);
    expect(
        JSON.stringify((await apiCall(page, 'GET', '/api/members')).json),
    ).not.toContain('sales@example.test');
});

test('2つのブラウザで同時編集：後から保存した側は409になり、入力が残る', async ({
    browser,
}) => {
    const a = await (await browser.newContext()).newPage();
    const b = await (await browser.newContext()).newPage();
    await login(a, 'sales');
    await login(b, 'engineer');
    const task = await findTask(a, 'ブース位置の確認');

    for (const p of [a, b]) {
        await p.getByTestId(`task-card-${task.id}`).click();
        await expect(p.getByLabel('タイトル（必須）')).toHaveValue(
            'ブース位置の確認',
        );
    }
    await a.getByLabel('タイトル（必須）').fill('ブース位置の確認（A）');
    await a.getByRole('button', { name: '保存' }).click();
    await expect(a.getByText('保存しました')).toBeVisible();

    await b
        .getByLabel('タイトル（必須）')
        .fill('ブース位置の確認（Bの下書き）');
    await b.getByRole('button', { name: '保存' }).click();
    await expect(
        b
            .getByRole('alert')
            .getByText('他の人が先に更新しました', { exact: false }),
    ).toBeVisible();
    await expect(
        b.getByText('入力中の内容はこの画面に残っています。'),
    ).toBeVisible();
    await expect(b.getByLabel('タイトル（必須）')).toHaveValue(
        'ブース位置の確認（Bの下書き）',
    );
    await expect(b.getByText('最新（版 2）')).toBeVisible();

    // 自分の入力で上書き保存を選ぶと、最新版を基準に保存される
    await b.getByRole('button', { name: '自分の入力で上書き保存' }).click();
    await expect(b.getByText('保存しました')).toBeVisible();
    await expect
        .poll(async () => {
            const res = await apiCall(a, 'GET', `/api/tasks/${task.id}`);

            return [res.json?.data?.title, res.json?.data?.revision];
        })
        .toEqual(['ブース位置の確認（Bの下書き）', 3]);
});

test('タスクの作成・未保存確認・削除と履歴からの復元', async ({ page }) => {
    await login(page, 'sales');
    await page
        .getByRole('region', { name: 'プロジェクト3' })
        .getByRole('button', { name: 'タスク' })
        .click();
    await page.getByLabel('タイトル（必須）').fill('E2Eで作成したタスク');
    await page.getByLabel('期限').fill('2026-12-24');
    await page.getByRole('button', { name: '保存' }).click();
    await expect(page.getByText('E2Eで作成したタスク')).toBeVisible();

    // 編集中に閉じようとすると確認が出る（キャンセルで編集継続）
    await page.getByText('E2Eで作成したタスク').click();
    await page.getByLabel('説明').fill('未保存の変更');
    page.once('dialog', (d) => d.dismiss());
    await page.getByRole('button', { name: 'キャンセル' }).click();
    await expect(page.getByLabel('説明')).toHaveValue('未保存の変更');
    await page.getByRole('button', { name: '保存' }).click();
    await expect(page.getByText('保存しました')).toBeVisible();

    await page.getByText('E2Eで作成したタスク').click();
    await page.getByRole('button', { name: '削除' }).click();
    await page.getByRole('button', { name: '削除する' }).click();
    await expect(page.getByText('E2Eで作成したタスク')).toHaveCount(0);

    await page.goto('/history');
    await page.getByRole('row', { name: /E2Eで作成したタスク.*削除/ }).click();
    await page.getByRole('button', { name: /版 2・更新/ }).click();
    await page.getByRole('button', { name: 'この版に復元…' }).click();
    await page
        .getByRole('button', { name: '復元する（新しい版を作成）' })
        .click();
    await expect(
        page.getByText('版 2 の内容で新しい版を作成しました'),
    ).toBeVisible();
    await expect(
        page.getByRole('button', { name: /版 4・復元/ }),
    ).toBeVisible();

    await page.goto('/');
    await expect(page.getByText('E2Eで作成したタスク')).toBeVisible();
});

test('今日・自分のタスク・ボードの担当者別', async ({ page }) => {
    await login(page, 'engineer');
    await page.goto('/today');
    await expect(page.getByRole('region', { name: '期限超過' })).toBeVisible();
    await expect(page.getByRole('region', { name: '本日期限' })).toBeVisible();
    await page.goto('/my-tasks');
    await expect(page.getByText('資格試験の勉強（非公開）')).toBeVisible();
    await page.goto('/people'); // 旧「担当者一覧」のアドレスは、ボードの担当者別へ移る
    await expect(page).toHaveURL(/view=people/);
    await expect(page.getByRole('region', { name: '未割当' })).toBeVisible();
    await expect(
        page.getByRole('region', { name: '鈴木 一郎（エンジニア）' }),
    ).toBeVisible();
});
