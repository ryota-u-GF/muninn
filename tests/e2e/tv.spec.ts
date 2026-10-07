import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { apiCall, login } from './helpers';

/** ページ内の時計だけを注入する（OS・サーバーの時刻は変更しない） */
const jst = (s: string) => new Date(`${s}+09:00`);
const screen = (page: Page) => page.getByTestId('tv-root');

async function openTv(page: Page, time: string) {
    await page.clock.install({ time: jst(time) });
    await login(page, 'tv');
    await expect(page).toHaveURL(/\/tv$/);
    await page.clock.runFor(1500);
}

test('TV専用アカウントは TV 以外を使えない', async ({ page }) => {
    await login(page, 'tv');
    await expect(page).toHaveURL(/\/tv$/);
    await page.goto('/');
    await expect(page).toHaveURL(/\/tv$/);
    await page.goto('/documents');
    await expect(page).toHaveURL(/\/tv$/);
    expect((await apiCall(page, 'GET', '/api/tasks')).status).toBe(403);
    expect((await apiCall(page, 'GET', '/api/documents')).status).toBe(403);
    const tv = await apiCall(page, 'GET', '/api/tv');
    expect(tv.status).toBe(200);
    expect(
        Object.keys(
            tv.json.tasks[0] ?? {
                id: 0,
                title: '',
                status: '',
                project_id: 0,
                due_date: '',
                assignee_ids: [],
            },
        ).sort(),
    ).toEqual([
        'assignee_ids',
        'due_date',
        'id',
        'project_id',
        'status',
        'title',
    ]);
    expect(JSON.stringify(tv.json)).not.toMatch(
        /@example\.test|非公開|description|source_url/,
    );
});

test('平日 10:00 は通常表示、画面サイズに応じたカード数で巡回', async ({
    page,
}) => {
    await openTv(page, '2026-10-07T10:00:00');
    await expect(screen(page)).toHaveAttribute('data-screen', 'normal');
    await expect(page.getByTestId('tv-cards')).toHaveAttribute(
        'data-page-size',
        '6',
    );
    await expect(page.getByTestId('tv-clock')).toHaveText(/^10:00:0\d$/);

    await page.setViewportSize({ width: 1280, height: 800 });
    await expect(page.getByTestId('tv-cards')).toHaveAttribute(
        'data-page-size',
        '4',
    );
    await page.setViewportSize({ width: 700, height: 900 });
    await expect(page.getByTestId('tv-cards')).toHaveAttribute(
        'data-page-size',
        '1',
    );
    const first = await page.getByTestId('tv-card').first().textContent();
    await page.clock.runFor(15_500);
    await expect(page.getByTestId('tv-card').first()).not.toHaveText(
        first ?? '',
    );
});

test('時刻画面：始業・昼休憩・再開・終業・休日（休憩より優先）', async ({
    page,
}) => {
    await openTv(page, '2026-10-07T08:29:50');
    await expect(screen(page)).toHaveAttribute('data-screen', 'normal');
    await page.clock.runFor(15_000);
    await expect(screen(page)).toHaveAttribute('data-screen', 'start');
    await page.clock.runFor(3 * 60_000);
    await expect(screen(page)).toHaveAttribute('data-screen', 'normal');

    await page.clock.setSystemTime(jst('2026-10-07T12:00:05'));
    await page.clock.runFor(2000);
    await expect(screen(page)).toHaveAttribute('data-screen', 'lunch');
    await page.clock.setSystemTime(jst('2026-10-07T13:00:05'));
    await page.clock.runFor(2000);
    await expect(screen(page)).toHaveAttribute('data-screen', 'resume');
    await page.clock.setSystemTime(jst('2026-10-07T17:30:05'));
    await page.clock.runFor(2000);
    await expect(screen(page)).toHaveAttribute('data-screen', 'end');
    await page.clock.setSystemTime(jst('2026-10-10T10:00:00')); // 土曜
    await page.clock.runFor(2000);
    await expect(screen(page)).toHaveAttribute('data-screen', 'holiday');
});

test('通常300秒→画面休憩30秒→通常、手動休憩と戻る', async ({ page }) => {
    await openTv(page, '2026-10-07T10:00:00');
    await page.clock.runFor(300_000);
    await expect(screen(page)).toHaveAttribute('data-screen', 'rest');
    await page.clock.runFor(30_000);
    await expect(screen(page)).toHaveAttribute('data-screen', 'normal');

    await page.getByRole('button', { name: '設定とテスト' }).click();
    await page.getByRole('button', { name: '手動で画面休憩' }).click();
    await page.clock.runFor(1000);
    await expect(screen(page)).toHaveAttribute('data-screen', 'rest');
    await page.getByRole('button', { name: '休憩から戻る' }).click();
    await page.clock.runFor(1000);
    await expect(screen(page)).toHaveAttribute('data-screen', 'normal');
});

test('6種類の画面テスト：切替・設定パネルと同時操作・通常に戻る・5分で自動終了、発火済み状態を変えない', async ({
    page,
}) => {
    await openTv(page, '2026-10-07T10:00:00');
    const firedBefore = await page.evaluate(() =>
        localStorage.getItem('gf.tv.fired.v1'),
    );
    await page.getByRole('button', { name: '設定とテスト' }).click();
    for (const s of ['start', 'lunch', 'resume', 'end', 'holiday', 'rest']) {
        await page.getByTestId(`tv-test-${s}`).click();
        await expect(screen(page)).toHaveAttribute('data-screen', s);
        await expect(screen(page)).toHaveAttribute('data-testing', '1');
        // 設定パネルを開いたままでもテストバーが操作可能
        await expect(page.getByTestId('tv-test-bar')).toBeVisible();
    }
    await page
        .getByTestId('tv-test-bar')
        .getByRole('button', { name: '昼休憩' })
        .click();
    await expect(screen(page)).toHaveAttribute('data-screen', 'lunch');
    await page
        .getByTestId('tv-test-bar')
        .getByRole('button', { name: '通常表示に戻る' })
        .click();
    await expect(screen(page)).toHaveAttribute('data-screen', 'normal');

    await page.getByTestId('tv-test-end').click();
    await page.clock.runFor(5 * 60_000 + 1000);
    // 5 分で自動終了（その間に通常→画面休憩の周期が進むため、テスト状態の解除を確認する）
    await expect(screen(page)).toHaveAttribute('data-testing', '0');
    await expect(screen(page)).not.toHaveAttribute('data-screen', 'end');

    expect(
        await page.evaluate(() => localStorage.getItem('gf.tv.fired.v1')),
    ).toBe(firedBefore);
    expect(
        await page.evaluate(() => localStorage.getItem('gf.tv.settings.v1')),
    ).toBeNull();
});

test('設定：HH:mm と時刻順を検証、端末ごとに保存', async ({ page }) => {
    await openTv(page, '2026-10-10T10:00:00'); // 土曜
    await expect(screen(page)).toHaveAttribute('data-screen', 'holiday');
    await page.getByRole('button', { name: '設定とテスト' }).click();
    await page.getByLabel('始業').fill('8:30');
    await expect(
        page.getByText('始業は HH:mm 形式（例 08:30）で入力してください。'),
    ).toBeVisible();
    await expect(page.getByRole('button', { name: '保存' })).toBeDisabled();
    await page.getByLabel('始業').fill('13:30');
    await expect(page.getByText(/始業 < 昼休憩開始/)).toBeVisible();
    await page.getByLabel('始業').fill('08:30');
    await page.getByRole('combobox').selectOption('everyday');
    await page.getByRole('button', { name: '保存' }).click();
    await page.clock.runFor(1500);
    await expect(screen(page)).toHaveAttribute('data-screen', 'normal');
    expect(
        JSON.parse(
            (await page.evaluate(() =>
                localStorage.getItem('gf.tv.settings.v1'),
            )) ?? '{}',
        ).days,
    ).toBe('everyday');
});

test('通知音：有効化後だけ、境界通過時に一度だけ鳴る（巻き戻し・スリープ復帰で重複しない）', async ({
    page,
}) => {
    await page.addInitScript(() => {
        (window as unknown as { __osc: number }).__osc = 0;
        // eslint-disable-next-line typescript/unbound-method -- call() で this を渡す
        const original = AudioContext.prototype.createOscillator;
        AudioContext.prototype.createOscillator = function (
            this: AudioContext,
        ) {
            (window as unknown as { __osc: number }).__osc++;

            return original.call(this);
        };
    });
    const osc = () =>
        page.evaluate(() => (window as unknown as { __osc: number }).__osc);

    await openTv(page, '2026-10-07T08:29:30');
    await page.getByRole('button', { name: '設定とテスト' }).click();
    await page.getByTestId('tv-enable-sound').click();
    await expect(page.getByText('通知音は有効です')).toBeVisible();

    await page.clock.runFor(40_000); // 08:30 を通過
    expect(await osc()).toBe(4);

    // 時計の巻き戻し → 再度 08:30 を通過しても鳴らない
    await page.clock.setSystemTime(jst('2026-10-07T08:29:50'));
    await page.clock.runFor(20_000);
    expect(await osc()).toBe(4);

    // スリープ復帰（12:00 をまたいで長時間ジャンプ）では古い通知を鳴らさない
    await page.clock.setSystemTime(jst('2026-10-07T12:00:40'));
    await page.clock.runFor(3000);
    expect(await osc()).toBe(4);

    // 有効化済みの音はテスト再生できる
    await page.getByTestId('tv-test-start').click();
    expect(await osc()).toBe(8);
});

test('通信失敗では古いタスクを出し続けない／401は再ログイン案内', async ({
    page,
}) => {
    await openTv(page, '2026-10-07T10:00:00');
    await expect(page.getByTestId('tv-cards')).toBeVisible();

    await page.route('**/api/tv', (route) => route.abort());
    await page.clock.runFor(30_500);
    await expect(page.getByTestId('tv-error')).toBeVisible();
    await expect(page.getByTestId('tv-card')).toHaveCount(0);

    await page.unroute('**/api/tv');
    await page.route('**/api/tv', (route) =>
        route.fulfill({
            status: 401,
            contentType: 'application/json',
            body: '{"message":"Unauthenticated."}',
        }),
    );
    await page.clock.runFor(30_500);
    await expect(page.getByText('ログインが必要です')).toBeVisible();
    await expect(
        page.getByRole('link', { name: 'ログイン画面へ' }),
    ).toBeVisible();

    await page.unroute('**/api/tv');
    await page.clock.runFor(30_500);
    await expect(page.getByTestId('tv-cards')).toBeVisible();
});
