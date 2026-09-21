export function repositoryName(remote: string): string {
    try {
        const url = new URL(remote.trim().replace(/^[\w.-]+@([^:]+):/, 'https://$1/').replace(/^ssh:\/\/(?:[^@/]+@)?/, 'https://'));
        return decodeURIComponent(url.pathname.replace(/\/+$/, '').replace(/\.git$/, '').split('/').pop() ?? '');
    } catch { return ''; }
}
