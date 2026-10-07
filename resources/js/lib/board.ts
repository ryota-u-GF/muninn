import type { Task } from '@/lib/types';

/**
 * 列内のドロップ位置から sort_order を決める（前後の中間値）。
 * beforeId=null は列の末尾。column には移動するタスク自身を含めない。
 */
export function sortOrderFor(
    column: Pick<Task, 'id' | 'sort_order'>[],
    beforeId: number | null,
): number {
    if (column.length === 0) {
        return 1;
    }
    if (beforeId === null) {
        return column[column.length - 1].sort_order + 1;
    }
    const index = column.findIndex((t) => t.id === beforeId);
    if (index === -1) {
        return column[column.length - 1].sort_order + 1;
    }
    if (index === 0) {
        return column[0].sort_order - 1;
    }

    return (column[index - 1].sort_order + column[index].sort_order) / 2;
}
