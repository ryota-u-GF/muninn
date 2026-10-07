import { DueLabel, MemberChips, PrivateBadge } from '@/components/gf/badges';
import type { Task } from '@/lib/types';
import { cn } from '@/lib/utils';

export function TaskCard({
    task,
    today,
    memberName,
    projectLabel,
    onOpen,
    draggable = false,
    onDragStart,
    onDragEnd,
    dragging = false,
}: {
    task: Task;
    today: string;
    memberName: (id: number) => string;
    projectLabel?: string;
    onOpen: (task: Task) => void;
    draggable?: boolean;
    onDragStart?: (task: Task, e: React.DragEvent) => void;
    onDragEnd?: () => void;
    dragging?: boolean;
}) {
    return (
        <button
            type="button"
            data-task-id={task.id}
            data-testid={`task-card-${task.id}`}
            draggable={draggable}
            onDragStart={(e) => onDragStart?.(task, e)}
            onDragEnd={onDragEnd}
            onClick={() => onOpen(task)}
            className={cn(
                'w-full rounded-md border bg-card p-2 text-left text-sm shadow-xs transition hover:border-foreground/30 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                draggable && 'cursor-grab active:cursor-grabbing',
                dragging && 'opacity-40',
                task.status === 'done' && 'opacity-70',
            )}
        >
            <div className="flex items-start justify-between gap-1">
                <span
                    className={cn(
                        'font-medium break-words',
                        task.status === 'done' && 'line-through',
                    )}
                >
                    {task.title}
                </span>
                {task.visibility === 'private' && <PrivateBadge />}
            </div>
            {projectLabel && (
                <div className="mt-0.5 text-xs text-muted-foreground">
                    {projectLabel}
                </div>
            )}
            <div className="mt-1 flex flex-wrap items-center justify-between gap-1">
                <MemberChips ids={task.assignee_ids} name={memberName} />
                <DueLabel
                    due={task.due_date}
                    status={task.status}
                    today={today}
                />
            </div>
        </button>
    );
}
