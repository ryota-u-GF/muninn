import { useCallback, useEffect, useRef, useState } from 'react';
import { api, ApiError } from '@/lib/api';
import type { Task } from '@/lib/types';

/** タスク一覧の取得。params はクエリ文字列（サーバー側で認可済みの範囲に限定） */
export function useTasks(query: string) {
    const [tasks, setTasks] = useState<Task[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const seq = useRef(0);

    const reload = useCallback(async () => {
        const current = ++seq.current;
        setLoading(true);
        try {
            const res = await api<{ data: Task[] }>(
                'GET',
                `/api/tasks${query ? `?${query}` : ''}`,
            );
            if (current === seq.current) {
                setTasks(res.data);
                setError(null);
            }
        } catch (e) {
            if (current === seq.current) {
                setError(
                    e instanceof ApiError
                        ? e.message
                        : '読み込みに失敗しました。',
                );
            }
        } finally {
            if (current === seq.current) {
                setLoading(false);
            }
        }
    }, [query]);

    useEffect(() => {
        void reload();
    }, [reload]);

    const upsert = useCallback((task: Task) => {
        setTasks((list) => {
            if (task.deleted) {
                return list.filter((t) => t.id !== task.id);
            }
            const index = list.findIndex((t) => t.id === task.id);
            if (index === -1) {
                return [...list, task];
            }
            const next = [...list];
            next[index] = task;

            return next;
        });
    }, []);

    return { tasks, setTasks, loading, error, reload, upsert };
}
