import { Link } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { useState } from 'react';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn } from '@/lib/utils';
import type { NavItem } from '@/types';

const STORAGE_PREFIX = 'gf.nav.closed.';

function readClosed(label: string): boolean {
    try {
        return window.localStorage.getItem(STORAGE_PREFIX + label) === '1';
    } catch {
        return false;
    }
}

function writeClosed(label: string, closed: boolean) {
    try {
        if (closed) {
            window.localStorage.setItem(STORAGE_PREFIX + label, '1');
        } else {
            window.localStorage.removeItem(STORAGE_PREFIX + label);
        }
    } catch {
        // 保存できない環境でも開閉は動く
    }
}

/** 項目の一覧（グループ見出しなしで、そのまま使える） */
function NavItems({ items }: { items: NavItem[] }) {
    const { isCurrentUrl } = useCurrentUrl();

    return (
        <SidebarMenu>
            {items.map((item) => (
                <SidebarMenuItem key={item.title}>
                    <SidebarMenuButton
                        asChild
                        isActive={isCurrentUrl(item.href)}
                        tooltip={{ children: item.title }}
                    >
                        <Link href={item.href}>
                            {item.icon && <item.icon />}
                            <span>{item.title}</span>
                        </Link>
                    </SidebarMenuButton>
                    {item.badge ? (
                        <SidebarMenuBadge
                            aria-label={`${item.title}の未確認 ${item.badge}件`}
                            data-testid="nav-badge"
                        >
                            {item.badge > 99 ? '99+' : item.badge}
                        </SidebarMenuBadge>
                    ) : null}
                </SidebarMenuItem>
            ))}
        </SidebarMenu>
    );
}

/** グループに入れず、単体で置く項目（見出し・開閉なし） */
export function NavSingle({ items }: { items: NavItem[] }) {
    return (
        <SidebarGroup className="px-2 py-0">
            <NavItems items={items} />
        </SidebarGroup>
    );
}

export function NavMain({ items, label }: { items: NavItem[]; label: string }) {
    const { state } = useSidebar();
    const [open, setOpen] = useState(() => !readClosed(label));

    // アイコンだけの細い表示のときは、項目が消えないよう常に開く
    const iconOnly = state === 'collapsed';

    return (
        <SidebarGroup className="px-2 py-0">
            <Collapsible
                open={iconOnly || open}
                onOpenChange={(next) => {
                    setOpen(next);
                    writeClosed(label, !next);
                }}
            >
                <SidebarGroupLabel asChild>
                    <CollapsibleTrigger
                        className="w-full cursor-pointer justify-between hover:bg-sidebar-accent"
                        aria-label={`${label}を開閉`}
                    >
                        {label}
                        <ChevronDown
                            className={cn(
                                'size-4 transition-transform',
                                !open && '-rotate-90',
                            )}
                        />
                    </CollapsibleTrigger>
                </SidebarGroupLabel>
                <CollapsibleContent>
                    <NavItems items={items} />
                </CollapsibleContent>
            </Collapsible>
        </SidebarGroup>
    );
}
