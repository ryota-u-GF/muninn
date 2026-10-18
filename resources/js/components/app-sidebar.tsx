import { Link, usePage } from '@inertiajs/react';
import {
    CalendarRange,
    ClipboardList,
    FolderKanban,
    History,
    KanbanSquare,
    NotebookPen,
    ShieldCheck,
    Sparkles,
    Sun,
    Tv,
    Users,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import type { NavItem } from '@/types';

export function AppSidebar() {
    const { auth } = usePage().props;
    const role = auth.user?.role;
    const internal = role === 'admin' || role === 'employee';

    const tasks: NavItem[] = [
        { title: 'タスクボード', href: '/', icon: KanbanSquare },
        { title: '今日', href: '/today', icon: Sun },
        { title: '自分のタスク', href: '/my-tasks', icon: ClipboardList },
        { title: '担当者一覧', href: '/people', icon: Users },
        { title: 'スケジュール', href: '/schedule', icon: CalendarRange },
    ];
    const docs: NavItem[] = [
        { title: '議事録', href: '/minutes', icon: NotebookPen },
        { title: '変更履歴', href: '/history', icon: History },
    ];
    const manage: NavItem[] = [];
    if (internal) {
        manage.push({ title: '案件', href: '/projects', icon: FolderKanban });
        manage.push({
            title: 'メール',
            href: '/imports',
            icon: Sparkles,
        });
        manage.push({ title: 'TV表示', href: '/tv', icon: Tv });
    }
    if (role === 'admin') {
        manage.push({
            title: '権限・利用者',
            href: '/admin/access',
            icon: ShieldCheck,
        });
    }

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain label="タスク" items={tasks} />
                <NavMain label="記録" items={docs} />
                {manage.length > 0 && <NavMain label="管理" items={manage} />}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
