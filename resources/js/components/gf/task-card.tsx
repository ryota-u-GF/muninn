import { Check } from 'lucide-react';
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
                'gf-rise w-full rounded-md border bg-card p-2 text-left text-sm shadow-xs transition duration-200 hover:-translate-y-0.5 hover:border-foreground/30 hover:shadow-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none motion-reduce:transition-none motion-reduce:hover:translate-y-0',
                draggable && 'cursor-grab active:cursor-grabbing',
                dragging && 'scale-[0.97] opacity-40',
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
                {task.status === 'done' && (
                    <Check
                        aria-hidden="true"
                        className="gf-pop mt-0.5 size-4 shrink-0 text-primary"
                    />
                )}
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
