import { describe, expect, it } from 'vite-plus/test';
import { sortOrderFor } from '@/lib/board';
import { autoRange, barPosition, buildGroups, taskSpan } from '@/lib/schedule';
import type { Member, Project, Task } from '@/lib/types';

describe('sortOrderFor', () => {
    const col = [
        { id: 1, sort_order: 1 },
        { id: 2, sort_order: 2 },
        { id: 3, sort_order: 4 },
    ];
    it('末尾・先頭・中間', () => {
        expect(sortOrderFor([], null)).toBe(1);
        expect(sortOrderFor(col, null)).toBe(5);
        expect(sortOrderFor(col, 1)).toBe(0);
        expect(sortOrderFor(col, 3)).toBe(3);
    });
});

const task = (id: number, p: Partial<Task>): Task => ({
    id,
    project_id: null,
    title: `t${id}`,
    description: null,
    status: 'todo',
    visibility: 'shared',
    start_date: null,
    due_date: null,
    sort_order: id,
    assignee_ids: [],
    revision: 1,
    completed_at: null,
    updated_at: null,
    deleted: false,
    is_mine: false,
    can: {
        update: true,
        delete: true,
        restore: true,
        change_visibility: false,
        change_project: true,
        change_assignees: true,
    },
    ...p,
});

describe('スケジュール', () => {
    it('日付の扱い：片方だけなら1日、両方なしは棒なし（日付を捏造しない）', () => {
        expect(
            taskSpan({ start_date: '2026-10-01', due_date: '2026-10-05' }),
        ).toEqual({ start: '2026-10-01', end: '2026-10-05' });
        expect(taskSpan({ start_date: null, due_date: '2026-10-05' })).toEqual({
            start: '2026-10-05',
            end: '2026-10-05',
        });
        expect(taskSpan({ start_date: null, due_date: null })).toBeNull();
    });

    it('範囲外は描画しない・範囲で切り詰める', () => {
        expect(
            barPosition(
                { start: '2026-10-01', end: '2026-10-05' },
                '2026-10-03',
                '2026-10-31',
            ),
        ).toEqual({ offset: 0, length: 3 });
        expect(
            barPosition(
                { start: '2026-09-01', end: '2026-09-05' },
                '2026-10-03',
                '2026-10-31',
            ),
        ).toBeNull();
    });

    it('自動範囲は遠い期限まで含む', () => {
        const r = autoRange(
            [task(1, { due_date: '2027-03-01' })],
            '2026-10-07',
        );
        expect(r.from).toBe('2026-09-23');
        expect(r.to).toBe('2027-03-01');
    });

    it('人別では複数担当を各担当者の行に表示し、日付未設定は件数のみ', () => {
        const members: Member[] = [
            {
                id: 1,
                name: 'A',
                role: 'employee',
                role_label: '社員',
                is_active: true,
            },
            {
                id: 2,
                name: 'B',
                role: 'employee',
                role_label: '社員',
                is_active: true,
            },
        ];
        const tasks = [
            task(1, { assignee_ids: [1, 2], due_date: '2026-10-08' }),
            task(2, { assignee_ids: [2] }),
            task(3, { due_date: '2026-10-09' }),
        ];
        const groups = buildGroups(tasks, 'person', [] as Project[], members);
        expect(
            groups.map((g) => [g.label, g.tasks.map((t) => t.id), g.undated]),
        ).toEqual([
            ['A', [1], 0],
            ['B', [1], 1],
            ['未割当', [3], 0],
        ]);
    });
});
