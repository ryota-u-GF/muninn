import {
    Archive,
    ArchiveRestore,
    ArrowDown,
    ArrowUp,
    Pencil,
    Plus,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/gf/confirm-dialog';
import { ErrorBanner, Loading, Page } from '@/components/gf/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useReferenceData } from '@/hooks/use-reference-data';
import { useUnsavedGuard } from '@/hooks/use-unsaved-guard';
import { api, ApiError } from '@/lib/api';
import type { Project } from '@/lib/types';

export default function Projects() {
    const ref = useReferenceData({ includeArchived: true });
    const [name, setName] = useState('');
    const [editing, setEditing] = useState<{ id: number; name: string } | null>(
        null,
    );
    const [archiveTarget, setArchiveTarget] = useState<Project | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useUnsavedGuard(name.trim() !== '' || editing !== null);

    const replace = (p: Project) =>
        ref.setProjects((list) => list.map((x) => (x.id === p.id ? p : x)));

    const handle = async (fn: () => Promise<void>) => {
        setBusy(true);
        setError(null);
        try {
            await fn();
        } catch (e) {
            if (e instanceof ApiError && e.isConflict) {
                setError(
                    '他の人が先に変更しました。最新の一覧を読み込みました。',
                );
                await ref.reload();
            } else {
                setError(
                    e instanceof ApiError
                        ? e.firstError()
                        : '保存に失敗しました。',
                );
            }
        } finally {
            setBusy(false);
        }
    };

    const create = () =>
        handle(async () => {
            const res = await api<{ data: Project }>('POST', '/api/projects', {
                name,
            });
            ref.setProjects((list) => [...list, res.data]);
            setName('');
            toast.success('案件を作成しました');
        });

    const rename = () =>
        handle(async () => {
            const project = ref.projects.find((p) => p.id === editing!.id)!;
            const res = await api<{ data: Project }>(
                'PATCH',
                `/api/projects/${project.id}`,
                {
                    name: editing!.name,
                    expected_revision: project.revision,
                },
            );
            replace(res.data);
            setEditing(null);
            toast.success('名称を変更しました');
        });

    const toggleArchive = () =>
        handle(async () => {
            const project = archiveTarget!;
            const res = await api<{ data: Project }>(
                'PATCH',
                `/api/projects/${project.id}`,
                {
                    archived: !project.archived,
                    expected_revision: project.revision,
                },
            );
            replace(res.data);
            setArchiveTarget(null);
            toast.success(
                res.data.archived
                    ? 'アーカイブしました'
                    : 'アーカイブを解除しました',
            );
        });

    const move = (project: Project, direction: -1 | 1) =>
        handle(async () => {
            const active = ref.projects.filter(
                (p) => p.archived === project.archived,
            );
            const index = active.findIndex((p) => p.id === project.id);
            const other = active[index + direction];
            if (!other) return;
            const a = await api<{ data: Project }>(
                'PATCH',
                `/api/projects/${project.id}`,
                {
                    sort_order: other.sort_order,
                    expected_revision: project.revision,
                },
            );
            const b = await api<{ data: Project }>(
                'PATCH',
                `/api/projects/${other.id}`,
                {
                    sort_order: project.sort_order,
                    expected_revision: other.revision,
                },
            );
            ref.setProjects((list) =>
                list
                    .map((x) =>
                        x.id === a.data.id
                            ? a.data
                            : x.id === b.data.id
                              ? b.data
                              : x,
                    )
                    .sort((x, y) => x.sort_order - y.sort_order || x.id - y.id),
            );
        });

    const active = ref.projects.filter((p) => !p.archived);
    const archived = ref.projects.filter((p) => p.archived);

    const row = (p: Project, list: Project[], i: number) => (
        <li key={p.id} className="flex flex-wrap items-center gap-2 px-3 py-2">
            {editing?.id === p.id ? (
                <>
                    <Input
                        aria-label="案件名"
                        value={editing.name}
                        maxLength={100}
                        onChange={(e) =>
                            setEditing({ id: p.id, name: e.target.value })
                        }
                        className="max-w-sm"
                        autoFocus
                    />
                    <Button
                        size="sm"
                        onClick={() => void rename()}
                        disabled={busy || editing.name.trim() === ''}
                    >
                        保存
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setEditing(null)}
                        disabled={busy}
                    >
                        キャンセル
                    </Button>
                </>
            ) : (
                <>
                    <span className="flex-1 font-medium">{p.name}</span>
                    {!p.archived && (
                        <>
                            <Button
                                size="icon"
                                variant="ghost"
                                aria-label="上へ"
                                disabled={busy || i === 0}
                                onClick={() => void move(p, -1)}
                            >
                                <ArrowUp className="size-4" />
                            </Button>
                            <Button
                                size="icon"
                                variant="ghost"
                                aria-label="下へ"
                                disabled={busy || i === list.length - 1}
                                onClick={() => void move(p, 1)}
                            >
                                <ArrowDown className="size-4" />
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    setEditing({ id: p.id, name: p.name })
                                }
                            >
                                <Pencil className="mr-1 size-4" />
                                名前変更
                            </Button>
                        </>
                    )}
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setArchiveTarget(p)}
                    >
                        {p.archived ? (
                            <>
                                <ArchiveRestore className="mr-1 size-4" />
                                解除
                            </>
                        ) : (
                            <>
                                <Archive className="mr-1 size-4" />
                                アーカイブ
                            </>
                        )}
                    </Button>
                </>
            )}
        </li>
    );

    return (
        <Page title="案件">
            <form
                className="flex flex-wrap gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    if (name.trim()) void create();
                }}
            >
                <Input
                    aria-label="新しい案件名"
                    placeholder="新しい案件名"
                    value={name}
                    maxLength={100}
                    onChange={(e) => setName(e.target.value)}
                    className="max-w-sm"
                />
                <Button type="submit" disabled={busy || name.trim() === ''}>
                    <Plus className="mr-1 size-4" />
                    案件を作成
                </Button>
            </form>
            <ErrorBanner
                message={error ?? ref.error}
                onRetry={() => void ref.reload()}
            />
            {!ref.loaded ? (
                <Loading />
            ) : (
                <>
                    <section>
                        <h2 className="mb-2 font-semibold">
                            有効な案件（{active.length}）
                        </h2>
                        <ul className="divide-y rounded-md border">
                            {active.map((p, i) => row(p, active, i))}
                        </ul>
                    </section>
                    <section>
                        <h2 className="mb-2 font-semibold text-muted-foreground">
                            アーカイブ（{archived.length}）
                        </h2>
                        <p className="mb-2 text-xs text-muted-foreground">
                            アーカイブした案件は通常の画面に表示されません。タスク・文書・履歴は削除されず残ります。外注はアーカイブ案件を閲覧できません。
                        </p>
                        <ul className="divide-y rounded-md border">
                            {archived.map((p, i) => row(p, archived, i))}
                        </ul>
                    </section>
                </>
            )}
            <ConfirmDialog
                open={archiveTarget !== null}
                title={
                    archiveTarget?.archived
                        ? 'アーカイブを解除しますか？'
                        : '案件をアーカイブしますか？'
                }
                confirmLabel={
                    archiveTarget?.archived ? '解除する' : 'アーカイブする'
                }
                busy={busy}
                onConfirm={() => void toggleArchive()}
                onCancel={() => setArchiveTarget(null)}
            >
                「{archiveTarget?.name}」
                {archiveTarget?.archived
                    ? 'を通常表示に戻します。'
                    : 'を通常表示から外します。データと履歴は残ります。'}
            </ConfirmDialog>
        </Page>
    );
}
