import { usePage } from '@inertiajs/react';
import {
    CalendarDays,
    FileUp,
    History,
    List,
    Plus,
    Search,
    Trash2,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import { PrivateBadge } from '@/components/gf/badges';
import { ConfirmDialog } from '@/components/gf/confirm-dialog';
import { HistoryDialog } from '@/components/gf/history-dialog';
import { Empty, ErrorBanner, Loading, Page } from '@/components/gf/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect, Textarea } from '@/components/ui/textarea';
import { useReferenceData } from '@/hooks/use-reference-data';
import { confirmDiscard, useUnsavedGuard } from '@/hooks/use-unsaved-guard';
import { api, ApiError } from '@/lib/api';
import {
    addDays,
    formatDateTime,
    formatJa,
    parseYmd,
    startOfWeek,
    tokyoYmd,
} from '@/lib/dates';
import type { DocumentItem, DocumentKind, Visibility } from '@/lib/types';
import { cn } from '@/lib/utils';

const BODY_LIMIT = 100000;
const FILE_LIMIT = 400 * 1024;
export const MINUTES_TEMPLATE =
    '## 議題\n- \n\n## 決定事項\n- \n\n## 議論\n- \n\n## アクション\n- 担当：\n  期限：\n';
const DOCUMENT_TEMPLATE = '## 目的\n\n## 手順\n1. \n\n## 注意事項\n- \n';

type Form = {
    title: string;
    project_id: string;
    visibility: Visibility;
    meeting_date: string;
    participants: string;
    body: string;
};

const emptyForm = (
    kind: DocumentKind,
    today: string,
    projectId = '',
): Form => ({
    title: '',
    project_id: projectId,
    visibility: 'shared',
    meeting_date: kind === 'minutes' ? today : '',
    participants: '',
    body: '',
});

const toForm = (d: DocumentItem): Form => ({
    title: d.title,
    project_id: d.project_id === null ? '' : String(d.project_id),
    visibility: d.visibility,
    meeting_date: d.meeting_date ?? '',
    participants: d.participants ?? '',
    body: d.body ?? '',
});

const toPayload = (f: Form) => ({
    title: f.title,
    project_id: f.project_id === '' ? null : Number(f.project_id),
    visibility: f.visibility,
    meeting_date: f.meeting_date || null,
    participants: f.participants || null,
    body: f.body,
});

export default function Documents({ kind }: { kind: DocumentKind }) {
    const { auth } = usePage().props;
    const me = auth.user!;
    const internal = me.role === 'admin' || me.role === 'employee';
    const ref = useReferenceData();
    const today = tokyoYmd();
    const label = kind === 'minutes' ? '議事録' : '文書';

    // 一覧・検索
    const [view, setView] = useState<'list' | 'calendar'>('list');
    const [search, setSearch] = useState('');
    const [q, setQ] = useState('');
    const [projectFilter, setProjectFilter] = useState('');
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');
    const [date, setDate] = useState('');
    const [month, setMonth] = useState(today.slice(0, 7));
    const [items, setItems] = useState<DocumentItem[]>([]);
    const [listLoading, setListLoading] = useState(true);
    const [listError, setListError] = useState<string | null>(null);

    // 編集
    const [selected, setSelected] = useState<DocumentItem | null>(null);
    const [isNew, setIsNew] = useState(false);
    const [initial, setInitial] = useState<Form>(emptyForm(kind, today));
    const [form, setForm] = useState<Form>(initial);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [message, setMessage] = useState<string | null>(null);
    const [conflict, setConflict] = useState<DocumentItem | null>(null);
    const [saving, setSaving] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [historyOpen, setHistoryOpen] = useState(false);
    const fileInput = useRef<HTMLInputElement>(null);

    const editorOpen = isNew || selected !== null;
    const dirty =
        editorOpen && JSON.stringify(form) !== JSON.stringify(initial);
    useUnsavedGuard(dirty);

    useEffect(() => {
        const t = window.setTimeout(() => setQ(search.trim()), 300);

        return () => window.clearTimeout(t);
    }, [search]);

    const query = useMemo(() => {
        const p = new URLSearchParams({ kind });
        if (q) p.set('q', q);
        if (projectFilter) p.set('project_id', projectFilter);
        if (view === 'calendar') {
            const first = `${month}-01`;
            const next = parseYmd(first);
            next.setUTCMonth(next.getUTCMonth() + 1);
            p.set('from', first);
            p.set('to', addDays(next.toISOString().slice(0, 10), -1));
        } else {
            if (date) p.set('date', date);
            if (from) p.set('from', from);
            if (to) p.set('to', to);
        }

        return p.toString();
    }, [kind, q, projectFilter, view, month, date, from, to]);

    const loadList = useCallback(async () => {
        setListLoading(true);
        try {
            const res = await api<{ data: DocumentItem[] }>(
                'GET',
                `/api/documents?${query}`,
            );
            setItems(res.data);
            setListError(null);
        } catch (e) {
            setListError(
                e instanceof ApiError ? e.message : '読み込みに失敗しました。',
            );
        } finally {
            setListLoading(false);
        }
    }, [query]);

    useEffect(() => {
        void loadList();
    }, [loadList]);

    const openDocument = async (id: number) => {
        if (!confirmDiscard(dirty)) return;
        try {
            const res = await api<{ data: DocumentItem }>(
                'GET',
                `/api/documents/${id}`,
            );
            const f = toForm(res.data);
            setSelected(res.data);
            setIsNew(false);
            setInitial(f);
            setForm(f);
            setErrors({});
            setMessage(null);
            setConflict(null);
        } catch (e) {
            toast.error(
                e instanceof ApiError ? e.message : '開けませんでした。',
            );
        }
    };

    const startNew = () => {
        if (!confirmDiscard(dirty)) return;
        const defaultProject = !internal
            ? String(ref.projects.find((p) => p.can_edit_content)?.id ?? '')
            : '';
        const f = {
            ...emptyForm(kind, date || today, defaultProject),
            body: kind === 'minutes' ? MINUTES_TEMPLATE : '',
        };
        setSelected(null);
        setIsNew(true);
        setInitial(f);
        setForm(f);
        setErrors({});
        setMessage(null);
        setConflict(null);
    };

    const closeEditor = () => {
        if (!confirmDiscard(dirty)) return;
        setSelected(null);
        setIsNew(false);
    };

    const set = <K extends keyof Form>(key: K, value: Form[K]) =>
        setForm((f) => ({ ...f, [key]: value }));

    const save = async (overrideRevision?: number) => {
        setSaving(true);
        setErrors({});
        setMessage(null);
        try {
            let saved: DocumentItem;
            if (isNew) {
                saved = (
                    await api<{ data: DocumentItem }>(
                        'POST',
                        '/api/documents',
                        { kind, ...toPayload(form) },
                    )
                ).data;
            } else {
                const current = toPayload(form) as Record<string, unknown>;
                const before = toPayload(initial) as Record<string, unknown>;
                const changes: Record<string, unknown> = {};
                for (const key of Object.keys(current)) {
                    if (current[key] !== before[key])
                        changes[key] = current[key];
                }
                saved = (
                    await api<{ data: DocumentItem }>(
                        'PATCH',
                        `/api/documents/${selected!.id}`,
                        {
                            ...changes,
                            expected_revision:
                                overrideRevision ?? selected!.revision,
                        },
                    )
                ).data;
            }
            const f = toForm(saved);
            setSelected(saved);
            setIsNew(false);
            setInitial(f);
            setForm(f);
            setConflict(null);
            toast.success('保存しました');
            void loadList();
        } catch (e) {
            if (e instanceof ApiError && e.isConflict) {
                setMessage(
                    '他の人が先に更新しました。入力中の内容はこの画面に残っています。',
                );
                try {
                    setConflict(
                        (
                            await api<{ data: DocumentItem }>(
                                'GET',
                                `/api/documents/${selected!.id}`,
                            )
                        ).data,
                    );
                } catch {
                    // 取得できなくても下書きは残す
                }
            } else if (e instanceof ApiError) {
                setErrors(e.errors);
                setMessage(e.status === 422 ? e.firstError() : e.message);
            } else {
                setMessage('保存に失敗しました。');
            }
        } finally {
            setSaving(false);
        }
    };

    const remove = async () => {
        setSaving(true);
        try {
            await api('DELETE', `/api/documents/${selected!.id}`, {
                expected_revision: selected!.revision,
            });
            toast.success('削除しました（変更履歴から復元できます）');
            setSelected(null);
            setInitial(form);
            void loadList();
        } catch (e) {
            toast.error(
                e instanceof ApiError ? e.message : '削除に失敗しました。',
            );
        } finally {
            setSaving(false);
            setConfirmDelete(false);
        }
    };

    const importFile = async (file: File) => {
        if (!/\.(txt|md)$/i.test(file.name)) {
            toast.error('.txt または .md のファイルを選択してください。');

            return;
        }
        if (file.size > FILE_LIMIT) {
            toast.error('ファイルは 400KB 以下にしてください。');

            return;
        }
        const data = new FormData();
        data.append('file', file);
        try {
            const res = await api<{ text: string; characters: number }>(
                'POST',
                '/api/documents/parse-file',
                data,
            );
            if (
                form.body.trim() !== '' &&
                !window.confirm(
                    '現在の本文の末尾に追加しますか？（キャンセルで取り込み中止）',
                )
            ) {
                return;
            }
            const body =
                form.body.trim() === ''
                    ? res.text
                    : `${form.body}\n\n${res.text}`;
            if (body.length > BODY_LIMIT) {
                toast.error('取り込むと本文の上限（100,000文字）を超えます。');

                return;
            }
            set('body', body);
            if (!form.title)
                set('title', file.name.replace(/\.(txt|md)$/i, ''));
            toast.success(
                `${res.characters.toLocaleString()}文字を取り込みました（未保存）`,
            );
        } catch (e) {
            toast.error(
                e instanceof ApiError
                    ? e.firstError()
                    : '取り込めませんでした。',
            );
        }
    };

    const canEdit = isNew || (selected?.can.update ?? false);
    const projectOptions = ref.projects.filter(
        (p) =>
            !p.archived &&
            (internal ||
                p.can_edit_content ||
                String(p.id) === form.project_id),
    );
    const fieldError = (key: string) =>
        errors[key]?.[0] ? (
            <p className="text-xs text-red-600">{errors[key][0]}</p>
        ) : null;

    return (
        <Page
            title={label}
            actions={
                <>
                    {kind === 'minutes' && (
                        <div
                            className="flex rounded-md border p-0.5"
                            role="tablist"
                            aria-label="表示切替"
                        >
                            <button
                                role="tab"
                                aria-selected={view === 'list'}
                                className={cn(
                                    'flex items-center gap-1 rounded px-3 py-1 text-sm',
                                    view === 'list' &&
                                        'bg-primary text-primary-foreground',
                                )}
                                onClick={() => setView('list')}
                            >
                                <List className="size-4" />
                                一覧
                            </button>
                            <button
                                role="tab"
                                aria-selected={view === 'calendar'}
                                className={cn(
                                    'flex items-center gap-1 rounded px-3 py-1 text-sm',
                                    view === 'calendar' &&
                                        'bg-primary text-primary-foreground',
                                )}
                                onClick={() => setView('calendar')}
                            >
                                <CalendarDays className="size-4" />
                                月カレンダー
                            </button>
                        </div>
                    )}
                    {(internal ||
                        ref.projects.some((p) => p.can_edit_content)) && (
                        <Button size="sm" onClick={startNew}>
                            <Plus className="mr-1 size-4" />
                            {label}を作成
                        </Button>
                    )}
                </>
            }
        >
            <div className="flex flex-wrap items-end gap-2 text-sm">
                <div className="relative">
                    <Search className="absolute top-2.5 left-2 size-4 text-muted-foreground" />
                    <Input
                        aria-label="本文検索"
                        placeholder={
                            kind === 'minutes'
                                ? 'タイトル・本文・参加者'
                                : 'タイトル・本文'
                        }
                        className="w-60 pl-8"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                </div>
                <NativeSelect
                    aria-label="案件で絞り込み"
                    className="w-44"
                    value={projectFilter}
                    onChange={(e) => setProjectFilter(e.target.value)}
                >
                    <option value="">案件：すべて</option>
                    {ref.projects.map((p) => (
                        <option key={p.id} value={p.id}>
                            {p.name}
                        </option>
                    ))}
                    {internal && <option value="none">会社共通</option>}
                </NativeSelect>
                {kind === 'minutes' && view === 'list' && (
                    <>
                        <Input
                            type="date"
                            aria-label="会議日（から）"
                            className="w-40"
                            value={from}
                            onChange={(e) => {
                                setFrom(e.target.value);
                                setDate('');
                            }}
                        />
                        <span>〜</span>
                        <Input
                            type="date"
                            aria-label="会議日（まで）"
                            className="w-40"
                            value={to}
                            min={from || undefined}
                            onChange={(e) => {
                                setTo(e.target.value);
                                setDate('');
                            }}
                        />
                        {date && (
                            <span className="flex items-center gap-1 rounded bg-muted px-2 py-1">
                                {formatJa(date)}のみ
                                <button
                                    className="underline"
                                    onClick={() => setDate('')}
                                >
                                    解除
                                </button>
                            </span>
                        )}
                    </>
                )}
            </div>
            <ErrorBanner
                message={listError ?? ref.error}
                onRetry={() => void loadList()}
            />

            <div className="grid min-h-0 gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
                <div className="min-w-0">
                    {view === 'calendar' ? (
                        <MonthCalendar
                            month={month}
                            today={today}
                            items={items}
                            onMonthChange={setMonth}
                            onPickDate={(d) => {
                                setDate(d);
                                setFrom('');
                                setTo('');
                                setView('list');
                            }}
                            onOpen={(id) => void openDocument(id)}
                        />
                    ) : listLoading && items.length === 0 ? (
                        <Loading />
                    ) : items.length === 0 ? (
                        <Empty>該当する{label}はありません。</Empty>
                    ) : (
                        <ul
                            className="divide-y rounded-md border"
                            aria-label={`${label}一覧`}
                        >
                            {items.map((d) => (
                                <li key={d.id}>
                                    <button
                                        type="button"
                                        onClick={() => void openDocument(d.id)}
                                        className={cn(
                                            'w-full px-3 py-2 text-left hover:bg-muted',
                                            selected?.id === d.id && 'bg-muted',
                                        )}
                                    >
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="font-medium">
                                                {d.title}
                                            </span>
                                            {d.visibility === 'private' && (
                                                <PrivateBadge />
                                            )}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {kind === 'minutes' && (
                                                <>
                                                    {formatJa(d.meeting_date)}・
                                                </>
                                            )}
                                            {ref.projectName(d.project_id)}
                                            {d.participants && (
                                                <>・{d.participants}</>
                                            )}
                                        </div>
                                        <div className="truncate text-xs text-muted-foreground">
                                            {d.excerpt}
                                        </div>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <div className="min-w-0 rounded-md border p-3">
                    {!editorOpen ? (
                        <p className="text-sm text-muted-foreground">
                            左の一覧から{label}を選ぶか、「{label}
                            を作成」を押してください。
                        </p>
                    ) : (
                        <div className="space-y-3">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h2 className="font-semibold">
                                    {isNew
                                        ? `新しい${label}`
                                        : canEdit
                                          ? `${label}を編集（版 ${selected?.revision}）`
                                          : `${label}（閲覧のみ）`}
                                    {dirty && (
                                        <span className="ml-2 text-xs text-orange-600">
                                            未保存の変更があります
                                        </span>
                                    )}
                                </h2>
                                <div className="flex gap-2">
                                    {selected && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => setHistoryOpen(true)}
                                        >
                                            <History className="mr-1 size-4" />
                                            変更履歴
                                        </Button>
                                    )}
                                    {selected?.can.delete && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            className="text-red-600"
                                            onClick={() =>
                                                setConfirmDelete(true)
                                            }
                                        >
                                            <Trash2 className="mr-1 size-4" />
                                            削除
                                        </Button>
                                    )}
                                </div>
                            </div>
                            {message && (
                                <div
                                    role="alert"
                                    className="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:bg-red-950 dark:text-red-200"
                                >
                                    <p>{message}</p>
                                    {conflict && (
                                        <div className="mt-2 space-y-2">
                                            <p className="text-xs">
                                                最新（版 {conflict.revision}
                                                ）のタイトル：{conflict.title}
                                            </p>
                                            <div className="flex flex-wrap gap-2">
                                                {conflict.can.update && (
                                                    <Button
                                                        size="sm"
                                                        disabled={saving}
                                                        onClick={() => {
                                                            setSelected(
                                                                conflict,
                                                            );
                                                            void save(
                                                                conflict.revision,
                                                            );
                                                        }}
                                                    >
                                                        自分の入力で上書き保存
                                                    </Button>
                                                )}
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() => {
                                                        const f =
                                                            toForm(conflict);
                                                        setSelected(conflict);
                                                        setInitial(f);
                                                        setForm(f);
                                                        setConflict(null);
                                                        setMessage(null);
                                                    }}
                                                >
                                                    最新の内容に切り替える（入力を破棄）
                                                </Button>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            )}
                            <fieldset
                                disabled={!canEdit || saving}
                                className="grid gap-3"
                            >
                                <div className="grid gap-1">
                                    <Label htmlFor="doc-title">
                                        タイトル（必須）
                                    </Label>
                                    <Input
                                        id="doc-title"
                                        value={form.title}
                                        maxLength={300}
                                        onChange={(e) =>
                                            set('title', e.target.value)
                                        }
                                    />
                                    {fieldError('title')}
                                </div>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    {kind === 'minutes' && (
                                        <div className="grid gap-1">
                                            <Label htmlFor="doc-date">
                                                会議日（必須）
                                            </Label>
                                            <Input
                                                id="doc-date"
                                                type="date"
                                                value={form.meeting_date}
                                                onChange={(e) =>
                                                    set(
                                                        'meeting_date',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                            {fieldError('meeting_date')}
                                        </div>
                                    )}
                                    <div className="grid gap-1">
                                        <Label htmlFor="doc-project">
                                            案件
                                        </Label>
                                        <NativeSelect
                                            id="doc-project"
                                            value={form.project_id}
                                            disabled={
                                                !isNew &&
                                                !(
                                                    selected?.can
                                                        .change_project ?? false
                                                )
                                            }
                                            onChange={(e) =>
                                                set(
                                                    'project_id',
                                                    e.target.value,
                                                )
                                            }
                                        >
                                            {internal && (
                                                <option value="">
                                                    会社共通（案件なし）
                                                </option>
                                            )}
                                            {!internal &&
                                                form.project_id === '' && (
                                                    <option value="">
                                                        案件を選択
                                                    </option>
                                                )}
                                            {projectOptions.map((p) => (
                                                <option key={p.id} value={p.id}>
                                                    {p.name}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                        {fieldError('project_id')}
                                    </div>
                                    {internal && (
                                        <div className="grid gap-1">
                                            <Label htmlFor="doc-visibility">
                                                公開区分
                                            </Label>
                                            <NativeSelect
                                                id="doc-visibility"
                                                value={form.visibility}
                                                disabled={
                                                    !isNew &&
                                                    !(
                                                        selected?.can
                                                            .change_visibility ??
                                                        false
                                                    )
                                                }
                                                onChange={(e) =>
                                                    set(
                                                        'visibility',
                                                        e.target
                                                            .value as Visibility,
                                                    )
                                                }
                                            >
                                                <option value="shared">
                                                    共有
                                                </option>
                                                <option value="private">
                                                    非公開（自分だけ）
                                                </option>
                                            </NativeSelect>
                                        </div>
                                    )}
                                </div>
                                {kind === 'minutes' && (
                                    <div className="grid gap-1">
                                        <Label htmlFor="doc-participants">
                                            参加者
                                        </Label>
                                        <Input
                                            id="doc-participants"
                                            value={form.participants}
                                            maxLength={2000}
                                            placeholder="例：山田、佐藤、外注1"
                                            onChange={(e) =>
                                                set(
                                                    'participants',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        {fieldError('participants')}
                                    </div>
                                )}
                                <div className="grid gap-1">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <Label htmlFor="doc-body">
                                            本文（プレーンテキスト／貼り付け可）
                                        </Label>
                                        <div className="flex gap-2">
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    set(
                                                        'body',
                                                        form.body
                                                            ? `${form.body}\n\n${kind === 'minutes' ? MINUTES_TEMPLATE : DOCUMENT_TEMPLATE}`
                                                            : kind === 'minutes'
                                                              ? MINUTES_TEMPLATE
                                                              : DOCUMENT_TEMPLATE,
                                                    )
                                                }
                                            >
                                                テンプレート挿入
                                            </Button>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    fileInput.current?.click()
                                                }
                                            >
                                                <FileUp className="mr-1 size-4" />
                                                .txt/.md 取り込み
                                            </Button>
                                            <input
                                                ref={fileInput}
                                                type="file"
                                                accept=".txt,.md,text/plain,text/markdown"
                                                className="hidden"
                                                data-testid="doc-file-input"
                                                onChange={(e) => {
                                                    const file =
                                                        e.target.files?.[0];
                                                    e.target.value = '';
                                                    if (file)
                                                        void importFile(file);
                                                }}
                                            />
                                        </div>
                                    </div>
                                    <Textarea
                                        id="doc-body"
                                        rows={16}
                                        className="font-mono text-sm"
                                        value={form.body}
                                        onChange={(e) =>
                                            set('body', e.target.value)
                                        }
                                    />
                                    <p
                                        className={cn(
                                            'text-right text-xs',
                                            form.body.length > BODY_LIMIT
                                                ? 'text-red-600'
                                                : 'text-muted-foreground',
                                        )}
                                    >
                                        {form.body.length.toLocaleString()} /{' '}
                                        {BODY_LIMIT.toLocaleString()} 文字
                                    </p>
                                    {fieldError('body')}
                                </div>
                            </fieldset>
                            <div className="flex justify-between gap-2">
                                <span className="text-xs text-muted-foreground">
                                    {selected?.updated_at &&
                                        `最終更新 ${formatDateTime(selected.updated_at)}`}
                                </span>
                                <div className="flex gap-2">
                                    <Button
                                        variant="outline"
                                        onClick={closeEditor}
                                        disabled={saving}
                                    >
                                        閉じる
                                    </Button>
                                    {canEdit && (
                                        <Button
                                            onClick={() => void save()}
                                            disabled={
                                                saving ||
                                                (!dirty && !isNew) ||
                                                form.title.trim() === '' ||
                                                form.body.length > BODY_LIMIT
                                            }
                                        >
                                            {saving ? '保存中…' : '保存'}
                                        </Button>
                                    )}
                                </div>
                            </div>
                        </div>
                    )}
                </div>
            </div>

            <ConfirmDialog
                open={confirmDelete}
                title={`${label}を削除しますか？`}
                destructive
                confirmLabel="削除する"
                busy={saving}
                onConfirm={() => void remove()}
                onCancel={() => setConfirmDelete(false)}
            >
                「{selected?.title}」を削除します。変更履歴から復元できます。
            </ConfirmDialog>
            {selected && historyOpen && (
                <HistoryDialog
                    open
                    entity="documents"
                    id={selected.id}
                    title={selected.title}
                    currentRevision={selected.revision}
                    canRestore={selected.can.restore}
                    projectName={ref.projectName}
                    memberName={ref.memberName}
                    onClose={() => setHistoryOpen(false)}
                    onRestored={async () => {
                        const res = await api<{ data: DocumentItem }>(
                            'GET',
                            `/api/documents/${selected.id}`,
                        );
                        const f = toForm(res.data);
                        setSelected(res.data);
                        setInitial(f);
                        setForm(f);
                        void loadList();
                    }}
                />
            )}
        </Page>
    );
}

function MonthCalendar({
    month,
    today,
    items,
    onMonthChange,
    onPickDate,
    onOpen,
}: {
    month: string;
    today: string;
    items: DocumentItem[];
    onMonthChange: (m: string) => void;
    onPickDate: (d: string) => void;
    onOpen: (id: number) => void;
}) {
    const first = `${month}-01`;
    const gridStart = startOfWeek(first);
    const cells = Array.from({ length: 42 }, (_, i) => addDays(gridStart, i));
    const shift = (delta: number) => {
        const d = parseYmd(first);
        d.setUTCMonth(d.getUTCMonth() + delta);
        onMonthChange(d.toISOString().slice(0, 7));
    };
    const byDate = new Map<string, DocumentItem[]>();
    for (const item of items) {
        if (item.meeting_date) {
            byDate.set(item.meeting_date, [
                ...(byDate.get(item.meeting_date) ?? []),
                item,
            ]);
        }
    }
    const [y, m] = month.split('-').map(Number);

    return (
        <div className="rounded-md border p-2" data-testid="minutes-calendar">
            <div className="mb-2 flex items-center justify-between">
                <Button size="sm" variant="outline" onClick={() => shift(-1)}>
                    前月
                </Button>
                <span className="font-semibold">
                    {y}年{m}月
                </span>
                <Button size="sm" variant="outline" onClick={() => shift(1)}>
                    翌月
                </Button>
            </div>
            <div className="grid grid-cols-7 text-center text-xs text-muted-foreground">
                {['月', '火', '水', '木', '金', '土', '日'].map((w) => (
                    <div key={w}>{w}</div>
                ))}
            </div>
            <div className="grid grid-cols-7 gap-px bg-border">
                {cells.map((d) => {
                    const list = byDate.get(d) ?? [];
                    const inMonth = d.startsWith(month);

                    return (
                        <div
                            key={d}
                            className={cn(
                                'min-h-20 bg-background p-1 text-xs',
                                !inMonth && 'bg-muted/40 text-muted-foreground',
                            )}
                        >
                            <button
                                type="button"
                                aria-label={`${formatJa(d)}の議事録で絞り込む`}
                                onClick={() => onPickDate(d)}
                                className={cn(
                                    'mb-1 rounded px-1 hover:bg-muted',
                                    d === today &&
                                        'bg-orange-100 font-bold dark:bg-orange-900',
                                )}
                            >
                                {parseYmd(d).getUTCDate()}
                            </button>
                            {list.slice(0, 3).map((doc) => (
                                <button
                                    key={doc.id}
                                    type="button"
                                    onClick={() => onOpen(doc.id)}
                                    className="block w-full truncate rounded bg-blue-100 px-1 text-left text-[11px] text-blue-900 hover:bg-blue-200 dark:bg-blue-900 dark:text-blue-100"
                                >
                                    {doc.title}
                                </button>
                            ))}
                            {list.length > 3 && (
                                <button
                                    type="button"
                                    className="text-[11px] underline"
                                    onClick={() => onPickDate(d)}
                                >
                                    他{list.length - 3}件
                                </button>
                            )}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
