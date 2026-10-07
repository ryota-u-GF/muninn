export type Role = 'admin' | 'employee' | 'contractor' | 'tv';

export type User = {
    id: number;
    name: string;
    email: string;
    role: Role;
    role_label: string;
    avatar?: string;
};

export type Auth = {
    user: User | null;
};
