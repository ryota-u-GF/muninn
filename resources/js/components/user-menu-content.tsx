import { Link, router } from '@inertiajs/react';
import { LogOut } from 'lucide-react';
import {
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { UserInfo } from '@/components/user-info';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import type { User } from '@/types';

export function UserMenuContent({ user }: { user: User }) {
    const cleanup = useMobileNavigation();

    return (
        <>
            <DropdownMenuLabel className="p-0 font-normal">
                <div className="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                    <UserInfo user={user} showEmail={true} />
                </div>
                <div className="px-1 pb-1 text-xs text-muted-foreground">
                    役割：{user.role_label}
                </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuItem asChild>
                <Link
                    className="block w-full cursor-pointer"
                    href="/logout"
                    method="post"
                    as="button"
                    onClick={() => {
                        cleanup();
                        router.flushAll();
                    }}
                    data-test="logout-button"
                >
                    <LogOut className="mr-2" />
                    ログアウト
                </Link>
            </DropdownMenuItem>
        </>
    );
}
