import type { Quality, Size, TokenCounts } from '@/types/pricing';

export type GenerationStatus = 'queued' | 'processing' | 'succeeded' | 'failed';

/** A money amount from the backend: rounded for display, exact (9 places) for tooltips. */
export type Money = { display: string; exact: string };

export type Generation = {
    id: string;
    prompt: string;
    size: Size;
    size_label: string;
    quality: Quality;
    quality_label: string;
    reference_images: string[];
    status: GenerationStatus;
    is_final: boolean;
    status_detail: string;
    image_url: string | null;
    image_expired: boolean;
    estimate: { min: Money; max: Money };
    charged: Money | null;
    was_charged: boolean;
    failure_message: string | null;
    can_retry: boolean;
    tokens: {
        estimated_min: TokenCounts;
        estimated_max: TokenCounts;
        actual: TokenCounts | null;
    };
    created_at: string;
    completed_at: string | null;
    image_url_expires_at: string | null;
};

export type Option<T extends string = string> = { value: T; label: string };

export type Paginated<T> = {
    data: T[];
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
    meta: {
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
};
