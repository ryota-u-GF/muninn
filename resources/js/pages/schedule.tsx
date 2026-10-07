import { CalendarCheck } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { ErrorBanner, Loading, Page } from '@/components/gf/page';
import { useTaskEditor } from '@/components/gf/use-task-editor';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/textarea';
import { useReferenceData } from '@/hooks/use-reference-data';
import { useTasks } from '@/hooks/use-tasks';
import {
    addDays,
    diffDays,
    formatJa,
    parseYmd,
    tokyoYmd,
    weekday,
} from '@/lib/dates';
import type { ScheduleMode } from '@/lib/schedule';
import { autoRange, barPosition, buildGroups, taskSpan } from '@/lib/schedule';
import { cn } from '@/lib/utils';

const LABEL_WIDTH = 240;
const ROW_HEIGHT = 32;
const BAR_CLASS = {
    todo: 'bg-slate-400 dark:bg-slate-500',
    doing: 'bg-blue-500',
    done: 'bg-emerald-500/70',
};

export default function Schedule() {
    const ref = useReferenceData();
    const today = tokyoYmd();
    const [mode, setMode] = useState<ScheduleMode>('project');
    const [zoom, setZoom] = useState<'day' | 'week'>('day');
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');
    const [assignee, setAssignee] = useState('');
    const [showDone, setShowDone] = useState(false);
    const scroller = useRef<HTMLDivElement>(null);

    const query = useMemo(() => {
        const p = new URLSearchParams();
        if (assignee) p.set('assignee_id', assignee);
        if (showDone) p.set('include_old_done', '1');

        return p.toString();
    }, [assignee, showDone]);
    const { tasks: all, loading, error, reload, upsert } = useTasks(query);
    const editor = useTaskEditor({ ...ref, onChanged: upsert });

    const tasks = useMemo(
        () => all.filter((t) => showDone || t.status !== 'done'),
        [all, showDone],
    );
    const auto = useMemo(() => autoRange(tasks, today), [tasks, today]);
    const rangeStart = from || auto.from;
    const rangeEnd = to && to >= rangeStart ? to : auto.to;
    const days = diffDays(rangeStart, rangeEnd) + 1;
    const dayWidth = zoom === 'day' ? 36 : 12;
    const groups = useMemo(
        () => buildGroups(tasks, mode, ref.projects, ref.members),
        [tasks, mode, ref.projects, ref.members],
    );
    const outside = tasks.filter((t) => {
        const span = taskSpan(t);

        return (
            span !== null && barPosition(span, rangeStart, rangeEnd) === null
        );
    }).length;

    const scrollToToday = () => {
        const el = scroller.current;
        if (!el) return;
        const offset = diffDays(rangeStart, today) * dayWidth;
        el.scrollTo({
            left: Math.max(0, offset - (el.clientWidth - LABEL_WIDTH) / 3),
            behavior: 'smooth',
        });
    };

    useEffect(() => {
        if (!loading) {
            scrollToToday();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [loading, zoom, mode]);

    const dateCells = Array.from({ length: days }, (_, i) =>
        addDays(rangeStart, i),
    );
    const todayOffset =
        today >= rangeStart && today <= rangeEnd
            ? diffDays(rangeStart, today)
            : null;

    return (
        <Page
            title="スケジュール"
            actions={
                <>
                    <div
                        role="tablist"
                        aria-label="表示切替"
                        className="flex rounded-md border p-0.5"
                    >
                        {(['project', 'person'] as const).map((m) => (
                            <button
                                key={m}
                                role="tab"
                                aria-selected={mode === m}
                                className={cn(
                                    'rounded px-3 py-1 text-sm',
                                    mode === m &&
                                        'bg-primary text-primary-foreground',
                                )}
                                onClick={() => setMode(m)}
                            >
                                {m === 'project' ? '案件別' : '人別'}
                            </button>
                        ))}
                    </div>
                    <div
                        className="flex rounded-md border p-0.5"
                        aria-label="ズーム"
                    >
                        {(['day', 'week'] as const).map((z) => (
                            <button
                                key={z}
                                aria-pressed={zoom === z}
                                className={cn(
                                    'rounded px-3 py-1 text-sm',
                                    zoom === z && 'bg-muted font-semibold',
                                )}
                                onClick={() => setZoom(z)}
                            >
                                {z === 'day' ? '日' : '週'}
                            </button>
                        ))}
                    </div>
                    <Button size="sm" variant="outline" onClick={scrollToToday}>
                        <CalendarCheck className="mr-1 size-4" />
                        今日へ
                    </Button>
                </>
            }
        >
            <div className="flex flex-wrap items-end gap-3 text-sm">
                <label className="grid gap-1">
                    <span className="text-xs text-muted-foreground">開始</span>
                    <Input
                        type="date"
                        value={from}
                        onChange={(e) => setFrom(e.target.value)}
                        className="w-40"
                        aria-label="表示期間の開始"
                    />
                </label>
                <label className="grid gap-1">
                    <span className="text-xs text-muted-foreground">終了</span>
                    <Input
                        type="date"
                        value={to}
                        min={from || undefined}
                        onChange={(e) => setTo(e.target.value)}
                        className="w-40"
                        aria-label="表示期間の終了"
                    />
                </label>
                {(from || to) && (
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => {
                            setFrom('');
                            setTo('');
                        }}
                    >
                        期間を自動に戻す
                    </Button>
                )}
                <NativeSelect
                    aria-label="担当者で絞り込み"
                    className="w-44"
                    value={assignee}
                    onChange={(e) => setAssignee(e.target.value)}
                >
                    <option value="">担当者：すべて</option>
                    <option value="me">自分</option>
                    <option value="none">未割当</option>
                    {ref.members
                        .filter((m) => m.role !== 'tv')
                        .map((m) => (
                            <option key={m.id} value={m.id}>
                                {m.name}
                            </option>
                        ))}
                </NativeSelect>
                <label className="flex items-center gap-1">
                    <input
                        type="checkbox"
                        checked={showDone}
                        onChange={(e) => setShowDone(e.target.checked)}
                    />
                    完了を表示
                </label>
                <span className="text-xs text-muted-foreground">
                    {formatJa(rangeStart)}〜{formatJa(rangeEnd)}
                    {outside > 0 && `／期間外 ${outside}件`}
                </span>
            </div>
            <ErrorBanner
                message={error ?? ref.error}
                onRetry={() => void reload()}
            />
            {loading && all.length === 0 ? (
                <Loading />
            ) : (
                <div
                    ref={scroller}
                    data-testid="schedule-scroller"
                    className="relative overflow-auto rounded-md border"
                    style={{ maxHeight: 'calc(100vh - 230px)', minHeight: 240 }}
                >
                    <div
                        style={{ width: LABEL_WIDTH + days * dayWidth }}
                        className="relative"
                    >
                        {/* 日付ヘッダー（上に固定） */}
                        <div
                            className="sticky top-0 z-20 flex border-b bg-background"
                            style={{ height: 40 }}
                        >
                            <div
                                className="sticky left-0 z-30 shrink-0 border-r bg-background px-2 py-2 text-xs font-semibold"
                                style={{ width: LABEL_WIDTH }}
                            >
                                {mode === 'project'
                                    ? '案件 / タスク'
                                    : '担当者 / タスク'}
                            </div>
                            {dateCells.map((d) => {
                                const wd = weekday(d);
                                const showLabel = zoom === 'day' || wd === 1;

                                return (
                                    <div
                                        key={d}
                                        className={cn(
                                            'shrink-0 border-r text-center text-[10px] leading-tight',
                                            (wd === 0 || wd === 6) &&
                                                'bg-muted/60',
                                            d === today &&
                                                'bg-orange-100 font-bold dark:bg-orange-900',
                                        )}
                                        style={{ width: dayWidth }}
                                        title={formatJa(d)}
                                    >
                                        {showLabel && (
                                            <span className="block pt-1 whitespace-nowrap">
                                                {zoom === 'day'
                                                    ? `${parseYmd(d).getUTCDate()}`
                                                    : `${parseYmd(d).getUTCMonth() + 1}/${parseYmd(d).getUTCDate()}`}
                                                {zoom === 'day' && (
                                                    <span className="block">
                                                        {'日月火水木金土'[wd]}
                                                    </span>
                                                )}
                                            </span>
                                        )}
                                    </div>
                                );
                            })}
                        </div>

                        {todayOffset !== null && (
                            <div
                                className="pointer-events-none absolute top-0 bottom-0 z-10 w-px bg-orange-500"
                                style={{
                                    left:
                                        LABEL_WIDTH +
                                        todayOffset * dayWidth +
                                        dayWidth / 2,
                                }}
                            />
                        )}

                        {groups.length === 0 && (
                            <p className="p-6 text-sm text-muted-foreground">
                                表示するタスクがありません。
                            </p>
                        )}
                        {groups.map((g) => (
                            <div
                                key={g.key}
                                data-testid={`schedule-group-${g.key}`}
                            >
                                <div
                                    className="flex border-b bg-muted/40"
                                    style={{ height: ROW_HEIGHT }}
                                >
                                    <div
                                        className="sticky left-0 z-10 flex shrink-0 items-center justify-between gap-2 border-r bg-muted px-2 text-sm font-semibold"
                                        style={{ width: LABEL_WIDTH }}
                                    >
                                        <span className="truncate">
                                            {g.label}
                                        </span>
                                        {g.undated > 0 && (
                                            <span
                                                className="shrink-0 text-[11px] font-normal text-muted-foreground"
                                                title="開始日・期限が未設定のタスクは棒を表示しません"
                                            >
                                                日付未設定 {g.undated}件
                                            </span>
                                        )}
                                    </div>
                                </div>
                                {g.tasks.map((t) => {
                                    const pos = barPosition(
                                        taskSpan(t)!,
                                        rangeStart,
                                        rangeEnd,
                                    );

                                    return (
                                        <div
                                            key={`${g.key}-${t.id}`}
                                            className="relative flex border-b"
                                            style={{ height: ROW_HEIGHT }}
                                        >
                                            <button
                                                type="button"
                                                onClick={() => editor.open(t)}
                                                className="sticky left-0 z-10 shrink-0 truncate border-r bg-background px-2 text-left text-xs hover:underline"
                                                style={{ width: LABEL_WIDTH }}
                                                title={t.title}
                                            >
                                                {t.title}
                                            </button>
                                            {pos && (
                                                <button
                                                    type="button"
                                                    data-testid={`bar-${t.id}`}
                                                    onClick={() =>
                                                        editor.open(t)
                                                    }
                                                    title={`${t.title}（${formatJa(taskSpan(t)!.start)}〜${formatJa(taskSpan(t)!.end)}）`}
                                                    className={cn(
                                                        'absolute top-1.5 h-5 truncate rounded px-1 text-left text-[11px] text-white',
                                                        BAR_CLASS[t.status],
                                                        t.status !== 'done' &&
                                                            t.due_date !==
                                                                null &&
                                                            t.due_date <
                                                                today &&
                                                            'ring-2 ring-red-500',
                                                    )}
                                                    style={{
                                                        left:
                                                            LABEL_WIDTH +
                                                            pos.offset *
                                                                dayWidth +
                                                            1,
                                                        width: Math.max(
                                                            pos.length *
                                                                dayWidth -
                                                                2,
                                                            8,
                                                        ),
                                                    }}
                                                >
                                                    {zoom === 'day'
                                                        ? t.title
                                                        : ''}
                                                </button>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        ))}
                    </div>
                </div>
            )}
            <p className="text-xs text-muted-foreground">
                棒をクリックすると詳細を編集できます。赤枠は期限超過。開始日か期限の一方だけのタスクは1日分の棒で表示し、両方未設定のタスクは件数のみ表示します。
            </p>
            {editor.element}
        </Page>
    );
}
