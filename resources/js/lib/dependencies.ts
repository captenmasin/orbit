import type { PackageRoot } from '@/types';

export function dependencyRows(snapshot: PackageRoot['snapshot']) {
    return ['composer', 'npm'].flatMap(ecosystem => {
        const manifest = snapshot?.files[ecosystem === 'composer' ? 'composer.json' : 'package.json'];
        const lock = snapshot?.files[ecosystem === 'composer' ? 'composer.lock' : 'package-lock.json'];
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
        return [...rows, ...locked.flatMap((entry, index) => used.has(index) ? [] : [{ ...entry, ecosystem, required: undefined, identity: manifest?.state === 'Current' ? 'Transitive' : 'Lock only', stale: lock?.state !== 'Current' }])];
    });
}
