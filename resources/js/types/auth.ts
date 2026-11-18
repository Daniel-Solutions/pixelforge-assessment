import type { Money } from '@/types/generation';

export type User = {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
    balance: Money;
    available_balance: Money;
    avatar?: string;
};

export type Auth = {
    user: User;
};
