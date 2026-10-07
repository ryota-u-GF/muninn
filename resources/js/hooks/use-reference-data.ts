import { useCallback, useEffect, useMemo, useState } from 'react';
import { api } from '@/lib/api';
import type { Member, Project } from '@/lib/types';

/** 案件・メンバー（いずれもサーバーの認可済み範囲のみ返る） */
export function useReferenceData(options: { includeArchived?: boolean } = {}) {
    const [projects, setProjects] = useState<Project[]>([]);
    const [members, setMembers] = useState<Member[]>([]);
    const [error, setError] = useState<string | null>(null);
    const [loaded, setLoaded] = useState(false);

    const reload = useCallback(async () => {
        try {
            const [p, m] = await Promise.all([
                api<{ data: Project[] }>(
                    'GET',
                    `/api/projects${options.includeArchived ? '?include_archived=1' : ''}`,
                ),
                api<{ data: Member[] }>('GET', '/api/members'),
            ]);
            setProjects(p.data);
            setMembers(m.data);
            setError(null);
        } catch (e) {
            setError(
                e instanceof Error ? e.message : '読み込みに失敗しました。',
            );
        } finally {
            setLoaded(true);
        }
    }, [options.includeArchived]);

    useEffect(() => {
        void reload();
    }, [reload]);

    const projectName = useMemo(() => {
        const map = new Map(projects.map((p) => [p.id, p.name]));

        return (id: number | null) =>
            id === null ? '会社共通' : (map.get(id) ?? `案件#${id}`);
    }, [projects]);

    const memberName = useMemo(() => {
        const map = new Map(members.map((m) => [m.id, m.name]));

        return (id: number) => map.get(id) ?? '（表示できない利用者）';
    }, [members]);

    return {
        projects,
        members,
        error,
        loaded,
        reload,
        projectName,
        memberName,
        setProjects,
    };
}
