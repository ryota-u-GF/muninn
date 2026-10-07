import { expect, test } from '@playwright/test';
import { findTask, login } from './helpers';

test('スケジュール：縦横スクロールで全件に届く・人別で複数担当を各行に表示・棒から編集', async ({
    page,
}) => {
    await login(page, 'admin');
    await page.goto('/schedule');
    const scroller = page.getByTestId('schedule-scroller');
    await expect(page.getByTestId('schedule-group-p-none')).toBeAttached();

    // 縦：最下行まで
    await scroller.evaluate((el) => el.scrollTo({ top: el.scrollHeight }));
    await expect(page.getByTestId('schedule-group-p-none')).toBeInViewport();
    // 横：右端まで（遠い期限）
    const widths = await scroller.evaluate((el) => {
        el.scrollTo({ left: el.scrollWidth });

        return {
            scrollLeft: el.scrollLeft,
            max: el.scrollWidth - el.clientWidth,
        };
    });
    expect(widths.max).toBeGreaterThan(0);
    expect(Math.abs(widths.scrollLeft - widths.max)).toBeLessThan(2);

    // 日付未設定は件数のみ
    await expect(page.getByText(/日付未設定 \d+件/).first()).toBeVisible();

    // 人別：複数担当のタスクは各担当者の行に出る
    await page.getByRole('tab', { name: '人別' }).click();
    const multi = (
        await (
            await page.request.get('/api/tasks', {
                headers: { Accept: 'application/json' },
            })
        ).json()
    ).data.find(
        (t: {
            assignee_ids: number[];
            start_date: string | null;
            due_date: string | null;
            status: string;
        }) =>
            t.assignee_ids.length > 1 &&
            (t.start_date || t.due_date) &&
            t.status !== 'done',
    );
    expect(multi).toBeTruthy();
    for (const uid of multi.assignee_ids) {
        await expect(
            page
                .getByTestId(`schedule-group-u${uid}`)
                .getByTestId(`bar-${multi.id}`),
        ).toBeAttached();
    }

    // 棒をクリックして編集 → 再表示
    await page
        .getByTestId(`schedule-group-u${multi.assignee_ids[0]}`)
        .getByTestId(`bar-${multi.id}`)
        .click({ force: true });
    await page
        .getByLabel('タイトル（必須）')
        .fill(`${multi.title}（スケジュールから編集）`);
    await page.getByRole('button', { name: '保存' }).click();
    await expect(
        page
            .getByTestId(`schedule-group-u${multi.assignee_ids[0]}`)
            .getByRole('button', {
                name: `${multi.title}（スケジュールから編集）`,
            })
            .first(),
    ).toBeVisible();
    expect(
        (await findTask(page, `${multi.title}（スケジュールから編集）`)).id,
    ).toBe(multi.id);

    // 週ズーム・今日へ
    await page.getByRole('button', { name: '週' }).click();
    await page.getByRole('button', { name: '今日へ' }).click();
});
