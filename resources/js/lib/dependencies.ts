import type { DependencyAdvisory, PackageRoot, ProjectFolder } from '@/types';

function dependencySources(snapshot: PackageRoot['snapshot']): Record<string, { manifest: string | null; lockfile: string | null; lockfiles?: string[] }> {
    return {
        composer: { manifest: 'composer.json', lockfile: 'composer.lock' },
        npm: { manifest: 'package.json', lockfile: snapshot?.npm_lockfile === undefined ? 'package-lock.json' : snapshot.npm_lockfile },
        ...snapshot?.additional_ecosystems,
    };
}

function dependencyName(ecosystem: string, name: string): string {
    return ecosystem === 'python' ? name.toLowerCase().replace(/[-_.]+/g, '-') : ecosystem === 'nuget' ? name.toLowerCase() : name;
}

function hasPackageFiles(root: PackageRoot): boolean {
    return !root.snapshot || Object.values(root.snapshot.files).some(file => file.state !== 'Missing file');
}

export function dependencyRows(snapshot: PackageRoot['snapshot']) {
    return Object.entries(dependencySources(snapshot)).flatMap(([ecosystem, source]) => {
        const manifest = source.manifest ? snapshot?.files[source.manifest] : undefined;
        const declared = (manifest?.entries ?? []).filter(entry => !entry.root);
        const lockNames = [...new Set(source.lockfiles ?? (source.lockfile ? [source.lockfile] : []))];
        const lockStale = lockNames.some(name => snapshot?.files[name]?.state !== 'Current');
        const locked = lockNames.flatMap(name => {
            const lock = snapshot?.files[name];
            return (lock?.entries ?? []).filter(entry => !entry.root).map(entry => ({ ...entry, stale: lockStale }));
        });
        const used = new Set<number>();
        const standalone = lockNames.length === 1 && lockNames[0] === source.manifest;
        const rows = declared.map((entry, entryIndex) => {
            const index = standalone ? entryIndex : locked.findIndex(item => ecosystem === 'npm' ? item.location === `node_modules/${entry.name}` : dependencyName(ecosystem, item.name) === dependencyName(ecosystem, entry.name));
            const resolved = locked[index];
            if (resolved) used.add(index);
            return { ...entry, ecosystem, identity: entry.identity ?? resolved?.identity ?? 'Direct', version: resolved?.version ?? entry.version, location: resolved?.location, link: entry.link || resolved?.link,
                stale: manifest?.state !== 'Current' || !!resolved?.stale };
        });
        const directVersions = new Set([...used].map(index => `${locked[index]!.name}:${locked[index]!.version}`));
        return [...rows, ...locked.flatMap((entry, index) => used.has(index) || (entry.location?.startsWith('lock:') && !!entry.version && directVersions.has(`${entry.name}:${entry.version}`)) ? [] : [{ ...entry, ecosystem, required: ecosystem === 'composer' || ecosystem === 'npm' ? undefined : entry.required, identity: entry.identity ?? (manifest?.state === 'Current' ? 'Transitive' : 'Lock only') }])];
    });
}

export const dependencySeverities = ['Critical', 'High', 'Moderate', 'Low', 'Unknown'] as const;

export function folderName(folder: ProjectFolder): string {
    return folder.path.split(/[\\/]/).filter(Boolean).at(-1) ?? folder.path;
}

export function currentDependencyCheck(root: PackageRoot, check: PackageRoot['outdated'] | PackageRoot['security'], folder?: ProjectFolder): boolean {
    return !!check && !!root.snapshot?.fingerprint && check.fingerprint === root.snapshot.fingerprint
        && ['Current', 'Partial', 'Queued', 'Scanning'].includes(root.scan_state) && !root.scan_error
        && (!folder?.availability || folder.availability === 'Available');
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
        for (const root of (folder.package_roots ?? []).filter(hasPackageFiles)) {
            const dependencies = dependencyRows(root.snapshot);
            const npmLockName = root.snapshot?.npm_lockfile === undefined ? 'package-lock.json' : root.snapshot.npm_lockfile;
            const npmLocked = npmLockName ? root.snapshot?.files[npmLockName]?.entries ?? [] : [];
            const managerNames: Record<string, string> = { 'pnpm-lock.yaml': 'pnpm', 'yarn.lock': 'Yarn', 'bun.lock': 'Bun', 'bun.lockb': 'Bun', pnpm: 'pnpm', yarn: 'Yarn', bun: 'Bun' };
            const javaSource = root.snapshot?.additional_ecosystems?.maven;
            const gradle = (javaSource?.lockfiles ?? (javaSource?.lockfile ? [javaSource.lockfile] : [])).some(name => name.endsWith('.lockfile'));
            const ecosystemNames: Record<string, string> = { composer: 'Composer', python: 'Python', rust: 'Cargo', go: 'Go', ruby: 'RubyGems', nuget: 'NuGet', dart: 'Dart', maven: gradle ? 'Gradle' : 'Maven' };
            const declaredManager = root.snapshot?.files['package.json']?.requirements?.packageManager?.split('@')[0] ?? '';
            const add = (item: { name: string; ecosystem: string; current: string; latest?: string; advisories?: DependencyAdvisory[] }) => {
                const key = `${root.id}:${item.ecosystem}:${dependencyName(item.ecosystem, item.name)}:${item.current}`;
                const resolved = npmLocked.filter(entry => entry.name === item.name && entry.version === item.current);
                const matches = dependencies.filter(row => row.ecosystem === item.ecosystem && row.version === item.current && (dependencyName(item.ecosystem, row.name) === dependencyName(item.ecosystem, item.name) || (item.ecosystem === 'npm' && resolved.some(entry => !!entry.location && row.location === entry.location))));
                const advisories = item.advisories ?? issues.get(key)?.advisories ?? [];
                issues.set(key, {
                    ...issues.get(key), ...item, key, root, folder, advisories,
                    manager: item.ecosystem === 'npm' ? managerNames[npmLockName ?? ''] ?? managerNames[declaredManager] ?? 'npm' : ecosystemNames[item.ecosystem] ?? item.ecosystem,
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
    const locations = folders.flatMap(folder => (folder.package_roots ?? []).filter(hasPackageFiles).map(root => {
        const securityCurrent = currentDependencyCheck(root, root.security, folder);
        const outdatedCurrent = currentDependencyCheck(root, root.outdated, folder);
        let reason = '';
        if (folder.availability && folder.availability !== 'Available') reason = `Folder ${folder.availability.toLowerCase()}.`;
        else if (!['Current', 'Partial'].includes(root.scan_state)) reason = root.scan_error ?? `Inspection ${root.scan_state.toLowerCase()}.`;
        else if (root.snapshot?.unsupported_lockfiles.length) reason = `Unsupported lockfiles: ${root.snapshot.unsupported_lockfiles.join(', ')}.`;
        else {
            let sources = 0;
            for (const [ecosystem, source] of Object.entries(dependencySources(root.snapshot))) {
                const manifest = source.manifest ? root.snapshot?.files[source.manifest] : undefined;
                const lockNames = source.lockfiles ?? (source.lockfile ? [source.lockfile] : []);
                if ((!manifest || manifest.state === 'Missing file') && !lockNames.some(name => root.snapshot?.files[name]?.state !== undefined && root.snapshot.files[name]!.state !== 'Missing file')) continue;
                sources++;
                if (manifest && manifest.state !== 'Current') reason ||= `${source.manifest}: ${manifest.state}.`;
                for (const lockName of lockNames) {
                    const lock = root.snapshot?.files[lockName];
                    if (lock?.state !== 'Current' && (!manifest || manifest.entries.some(item => ecosystem !== 'composer' || item.name.includes('/')))) reason ||= `${lockName}: ${lock?.state ?? 'Missing file'}.`;
                }
                if (!lockNames.length && manifest?.entries.some(item => ecosystem !== 'composer' || item.name.includes('/'))) reason ||= 'The package manager lockfile could not be selected.';
                if (ecosystem !== 'composer' && ecosystem !== 'npm') {
                    const unresolved = dependencyRows(root.snapshot).find(row => row.ecosystem === ecosystem && (!row.version || row.link));
                    if (unresolved) reason ||= unresolved.link ? `Non-registry dependency: ${unresolved.name}.` : `Unresolved version: ${unresolved.name}.`;
                }
                if (ecosystem === 'maven' && (source.manifest === 'pom.xml' || lockNames.includes('pom.xml'))) reason ||= root.snapshot?.files['pom.xml']?.requirements?.securityCoverage ?? '';
            }
            if (!sources) reason = root.snapshot ? 'The package manager lockfile could not be selected.' : 'Package files have not been inspected yet.';
        }
        const stale = !!(root.security && !securityCurrent) || !!(root.outdated && !outdatedCurrent);
        const unavailable = (securityCurrent ? root.security!.unavailable + (root.security!.incomplete ?? 0) : 0) + (outdatedCurrent ? root.outdated!.unavailable + (root.outdated!.incomplete ?? 0) : 0);
        const complete = securityCurrent && outdatedCurrent && !unavailable && !reason;
        const state = stale ? 'Stale' : complete ? 'Checked' : securityCurrent || outdatedCurrent || reason ? 'Incomplete' : 'Not checked';
        if (!reason && unavailable) reason = [
            { label: 'Security', check: securityCurrent ? root.security : null },
            { label: 'Updates', check: outdatedCurrent ? root.outdated : null },
        ].filter(item => item.check && (item.check.unavailable || item.check.incomplete)).map(({ label, check }) => `${label}: ${check!.checked} packages checked${check!.incomplete ? `, ${check!.incomplete} packages skipped (incomplete coverage)` : ''}${check!.unavailable ? `, ${check!.unavailable} unavailable (request failed or response incomplete)` : ''}.`).join(' ');
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
        complete: !!locations.length && locations.every(location => location.complete),
    };
}

export function dependencyReleaseUrl(issue: Pick<DependencyIssue, 'name' | 'ecosystem' | 'latest'>): string | undefined {
    if (issue.ecosystem === 'composer' && /^[a-z0-9_.-]+\/[a-z0-9_.-]+$/i.test(issue.name)) return `https://packagist.org/packages/${issue.name.split('/').map(encodeURIComponent).join('/')}`;
    if (issue.ecosystem === 'npm' && /^(?:@[a-z0-9_.-]+\/)?[a-z0-9_.-]+$/i.test(issue.name)) return `https://www.npmjs.com/package/${encodeURIComponent(issue.name)}${issue.latest ? `/v/${encodeURIComponent(issue.latest)}` : ''}`;
    const name = encodeURIComponent(issue.name);
    const version = issue.latest ? encodeURIComponent(issue.latest) : '';
    if (/^[a-z0-9][a-z0-9_.-]*$/i.test(issue.name)) {
        if (issue.ecosystem === 'python') return `https://pypi.org/project/${name}/${version ? `${version}/` : ''}`;
        if (issue.ecosystem === 'rust') return `https://crates.io/crates/${name}${version ? `/${version}` : ''}`;
        if (issue.ecosystem === 'ruby') return `https://rubygems.org/gems/${name}${version ? `/versions/${version}` : ''}`;
        if (issue.ecosystem === 'nuget') return `https://www.nuget.org/packages/${name}${version ? `/${version}` : ''}`;
        if (issue.ecosystem === 'dart') return `https://pub.dev/packages/${name}${version ? `/versions/${version}` : ''}`;
    }
    if (issue.ecosystem === 'go' && /^[a-z0-9][a-z0-9_.-]*(?:\/[a-z0-9][a-z0-9_.-]*)*$/i.test(issue.name)) return `https://pkg.go.dev/${issue.name.split('/').map(encodeURIComponent).join('/')}${version ? `@${version}` : ''}`;
    if (issue.ecosystem === 'maven' && /^[a-z0-9][a-z0-9_.-]*:[a-z0-9][a-z0-9_.-]*$/i.test(issue.name)) return `https://central.sonatype.com/artifact/${issue.name.split(':').map(encodeURIComponent).join('/')}${version ? `/${version}` : ''}`;
}
