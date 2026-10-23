import { Link, usePage } from '@inertiajs/react';
import {
    CalendarRange,
    FolderKanban,
    History,
    KanbanSquare,
    Mail,
    NotebookPen,
    ShieldCheck,
    Sun,
    Tv,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain, NavSingle } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { usePendingMailCount } from '@/hooks/use-pending-mail-count';
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
    const pendingMail = usePendingMailCount(internal);

    const tasks: NavItem[] = [
        { title: 'タスクボード', href: '/', icon: KanbanSquare },
        { title: '今日', href: '/today', icon: Sun },
        { title: 'スケジュール', href: '/schedule', icon: CalendarRange },
    ];
    const docs: NavItem[] = [
        { title: '議事録', href: '/minutes', icon: NotebookPen },
        { title: '変更履歴', href: '/history', icon: History },
    ];
    // 候補（メール・議事録から見つけたタスク）は社内メンバーだけ（外注には出さない）。未確認の候補の件数を添える。
    if (internal) {
        docs.unshift({
            title: '候補',
            href: '/imports',
            icon: Mail,
            badge: pendingMail,
        });
    }
    const manage: NavItem[] = [];
    if (internal) {
        manage.push({ title: '案件', href: '/projects', icon: FolderKanban });
    }
    if (role === 'admin') {
        manage.push({
            title: '担当者・権限',
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
                {/* TV表示は、グループに入れず単体で置く */}
                {internal && (
                    <NavSingle
                        items={[{ title: 'TV表示', href: '/tv', icon: Tv }]}
                    />
                )}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
