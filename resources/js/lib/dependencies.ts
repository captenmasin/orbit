import type { DependencyAdvisory, PackageRoot, ProjectFolder } from '@/types';

export function dependencyRows(snapshot: PackageRoot['snapshot']) {
    return ['composer', 'npm'].flatMap(ecosystem => {
        const manifest = snapshot?.files[ecosystem === 'composer' ? 'composer.json' : 'package.json'];
        const lockName = ecosystem === 'composer' ? 'composer.lock' : snapshot?.npm_lockfile === undefined ? 'package-lock.json' : snapshot.npm_lockfile;
        const lock = lockName ? snapshot?.files[lockName] : undefined;
        const declared = manifest?.entries ?? [];
        const locked = lock?.entries ?? [];
        const used = new Set<number>();
        const rows = declared.map(entry => {
            const index = locked.findIndex(item => ecosystem === 'composer' ? item.name === entry.name : item.location === `node_modules/${entry.name}`);
            const resolved = locked[index];
            if (resolved) used.add(index);
            return { ...entry, ecosystem, identity: 'Direct', version: resolved?.version, location: resolved?.location, link: resolved?.link,
                stale: manifest?.state !== 'Current' || (!!resolved && lock?.state !== 'Current') };
        });
        const directVersions = new Set([...used].map(index => `${locked[index]!.name}:${locked[index]!.version}`));
        return [...rows, ...locked.flatMap((entry, index) => used.has(index) || (entry.location?.startsWith('lock:') && !!entry.version && directVersions.has(`${entry.name}:${entry.version}`)) ? [] : [{ ...entry, ecosystem, required: undefined, identity: manifest?.state === 'Current' ? 'Transitive' : 'Lock only', stale: lock?.state !== 'Current' }])];
    });
}

export const dependencySeverities = ['Critical', 'High', 'Moderate', 'Low', 'Unknown'] as const;

export function folderName(folder: ProjectFolder): string {
    return folder.path.split('/').filter(Boolean).at(-1) ?? folder.path;
}

export function currentDependencyCheck(root: PackageRoot, check: PackageRoot['outdated'] | PackageRoot['security'], folder?: ProjectFolder): boolean {
    return !!check && !!root.snapshot?.fingerprint && check.fingerprint === root.snapshot.fingerprint
        && ['Current', 'Partial'].includes(root.scan_state) && (!folder?.availability || folder.availability === 'Available');
}

export interface DependencyIssue {
    key: string;
    root: PackageRoot;
    folder: ProjectFolder;
    name: string;
    ecosystem: string;
    manager: string;
    current: string;
    latest?: string;
    identity: string;
    scope: string;
    advisories: DependencyAdvisory[];
    severity?: DependencyAdvisory['severity'];
}

export function dependencyIssues(folders: ProjectFolder[]): DependencyIssue[] {
    const issues = new Map<string, DependencyIssue>();
    for (const folder of folders) {
        for (const root of folder.package_roots ?? []) {
            const dependencies = dependencyRows(root.snapshot);
            const npmLockName = root.snapshot?.npm_lockfile === undefined ? 'package-lock.json' : root.snapshot.npm_lockfile;
            const npmLocked = npmLockName ? root.snapshot?.files[npmLockName]?.entries ?? [] : [];
            const managerNames: Record<string, string> = { 'pnpm-lock.yaml': 'pnpm', 'yarn.lock': 'Yarn', 'bun.lock': 'Bun', 'bun.lockb': 'Bun', pnpm: 'pnpm', yarn: 'Yarn', bun: 'Bun' };
            const declaredManager = root.snapshot?.files['package.json']?.requirements?.packageManager?.split('@')[0] ?? '';
            const add = (item: { name: string; ecosystem: string; current: string; latest?: string; advisories?: DependencyAdvisory[] }) => {
                const key = `${root.id}:${item.ecosystem}:${item.name}:${item.current}`;
                const resolved = npmLocked.filter(entry => entry.name === item.name && entry.version === item.current);
                const matches = dependencies.filter(row => row.ecosystem === item.ecosystem && row.version === item.current && (row.name === item.name || (item.ecosystem === 'npm' && resolved.some(entry => !!entry.location && row.location === entry.location))));
                const advisories = item.advisories ?? issues.get(key)?.advisories ?? [];
                issues.set(key, {
                    ...issues.get(key), ...item, key, root, folder, advisories,
                    manager: item.ecosystem === 'composer' ? 'Composer' : managerNames[npmLockName ?? ''] ?? managerNames[declaredManager] ?? 'npm',
                    identity: [...new Set(matches.map(row => row.identity))].join(' & ') || 'Locked',
                    scope: [...new Set(matches.map(row => row.scope))].join(', ') || 'Unknown scope',
                    severity: dependencySeverities.find(severity => advisories.some(advisory => advisory.severity === severity)),
                });
            };
            if (currentDependencyCheck(root, root.outdated, folder)) {
                for (const item of root.outdated!.packages) add(item);
            }
            if (currentDependencyCheck(root, root.security, folder)) {
                for (const item of root.security!.packages) {
                    if (item.advisories.length) add(item);
                }
            }
        }
    }
    return [...issues.values()].sort((a, b) => {
        const severity = (issue: DependencyIssue) => issue.severity ? dependencySeverities.indexOf(issue.severity) : dependencySeverities.length;
        return severity(a) - severity(b) || a.name.localeCompare(b.name) || folderName(a.folder).localeCompare(folderName(b.folder)) || a.root.relative_path.localeCompare(b.root.relative_path);
    });
}

export function dependencyHealth(folders: ProjectFolder[]) {
    const locations = folders.flatMap(folder => (folder.package_roots ?? []).map(root => {
        const securityCurrent = currentDependencyCheck(root, root.security, folder);
        const outdatedCurrent = currentDependencyCheck(root, root.outdated, folder);
        let reason = '';
        if (folder.availability && folder.availability !== 'Available') reason = `Folder ${folder.availability.toLowerCase()}.`;
        else if (!['Current', 'Partial'].includes(root.scan_state)) reason = root.scan_error ?? `Inspection ${root.scan_state.toLowerCase()}.`;
        else if (root.snapshot?.unsupported_lockfiles.length) reason = `Unsupported lockfiles: ${root.snapshot.unsupported_lockfiles.join(', ')}.`;
        else {
            let manifests = 0;
            for (const [manifestName, lockName] of [['composer.json', 'composer.lock'], ['package.json', root.snapshot?.npm_lockfile === undefined ? 'package-lock.json' : root.snapshot.npm_lockfile]] as const) {
                const manifest = root.snapshot?.files[manifestName];
                if (!manifest || manifest.state === 'Missing file') continue;
                manifests++;
                const lock = lockName ? root.snapshot?.files[lockName] : undefined;
                if (manifest.state !== 'Current') reason ||= `${manifestName}: ${manifest.state}.`;
                else if (manifest.entries.some(item => manifestName === 'package.json' || item.name.includes('/')) && lock?.state !== 'Current') reason ||= lockName ? `${lockName}: ${lock?.state ?? 'Missing file'}.` : 'The package manager lockfile could not be selected.';
            }
            if (!manifests) reason = 'No supported package manifest found.';
        }
        const stale = !!(root.security && !securityCurrent) || !!(root.outdated && !outdatedCurrent);
        const unavailable = (securityCurrent ? root.security!.unavailable + root.security!.skipped : 0) + (outdatedCurrent ? root.outdated!.unavailable + root.outdated!.skipped : 0);
        const complete = securityCurrent && outdatedCurrent && !unavailable && !reason;
        const state = stale ? 'Stale' : complete ? 'Checked' : securityCurrent || outdatedCurrent || reason ? 'Incomplete' : 'Not checked';
        if (!reason && unavailable) reason = 'Some packages could not be checked or were skipped.';
        if (!reason && stale) reason = 'Previous results do not match the current inspection. Check again.';
        if (!reason && !securityCurrent && !outdatedCurrent) reason = 'Security and updates have not been checked yet.';
        if (!reason && !securityCurrent) reason = 'Security has not been checked yet.';
        if (!reason && !outdatedCurrent) reason = 'Updates have not been checked yet.';
        return { root, folder, state, reason, complete, securityCurrent, outdatedCurrent };
    }));
    const issues = dependencyIssues(folders);
    return {
        locations, issues, security: issues.filter(issue => issue.advisories.length).length,
        outdated: issues.filter(issue => issue.latest).length, issueCount: issues.length,
        checked: locations.filter(location => location.complete).length, total: locations.length,
        unconfigured: folders.filter(folder => !folder.package_roots?.length),
        complete: !!locations.length && locations.every(location => location.complete) && folders.every(folder => !!folder.package_roots?.length),
    };
}

export function dependencyReleaseUrl(issue: Pick<DependencyIssue, 'name' | 'ecosystem' | 'latest'>): string | undefined {
    if (issue.ecosystem === 'composer' && /^[a-z0-9_.-]+\/[a-z0-9_.-]+$/i.test(issue.name)) return `https://packagist.org/packages/${issue.name.split('/').map(encodeURIComponent).join('/')}`;
    if (issue.ecosystem === 'npm' && /^(?:@[a-z0-9_.-]+\/)?[a-z0-9_.-]+$/i.test(issue.name)) return `https://www.npmjs.com/package/${encodeURIComponent(issue.name)}${issue.latest ? `/v/${encodeURIComponent(issue.latest)}` : ''}`;
}
