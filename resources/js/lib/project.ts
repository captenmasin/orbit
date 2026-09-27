export function repositoryName(remote: string): string {
    try {
        const url = new URL(remote.trim().replace(/^[\w.-]+@([^:]+):/, 'https://$1/').replace(/^ssh:\/\/(?:[^@/]+@)?/, 'https://'));
        return decodeURIComponent(url.pathname.replace(/\/+$/, '').replace(/\.git$/, '').split('/').pop() ?? '');
    } catch { return ''; }
}

export const projectStatusDotClasses: Record<string, string> = {
    Idea: 'bg-violet-500 dark:bg-violet-400',
    'In Progress': 'bg-blue-500 dark:bg-blue-400',
    Live: 'bg-emerald-500 dark:bg-emerald-400',
    Paused: 'bg-amber-500 dark:bg-amber-400',
    Maintenance: 'bg-orange-600 dark:bg-orange-400',
    Archived: 'bg-neutral-500 dark:bg-neutral-400',
};

export const boardColumnColors: Record<string, { label: string; dotClass: string }> = {
    gray: { label: 'Grey', dotClass: 'bg-neutral-500' },
    blue: { label: 'Blue', dotClass: 'bg-blue-500/70' },
    amber: { label: 'Amber', dotClass: 'bg-amber-500/80' },
    green: { label: 'Green', dotClass: 'bg-emerald-600/80' },
    purple: { label: 'Purple', dotClass: 'bg-purple-500/80' },
    pink: { label: 'Pink', dotClass: 'bg-pink-500/80' },
    red: { label: 'Red', dotClass: 'bg-red-500/80' },
};

const defaultColumnColors: Record<string, string> = { 'to do': 'blue', 'in progress': 'amber', done: 'green' };
export const boardColumnColor = (column: { name: string; color?: string | null }): string => column.color ?? defaultColumnColors[column.name.trim().toLowerCase()] ?? 'gray';
