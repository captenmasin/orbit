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
