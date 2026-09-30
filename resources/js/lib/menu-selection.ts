import { reducedMotion } from '@/lib/appearance';

const selecting = new WeakSet<HTMLElement>();
const replaying = new WeakSet<HTMLElement>();

export async function blinkMenuSelection(event: MouseEvent): Promise<void> {
    const item = event.currentTarget as HTMLElement;
    const menu = item.closest<HTMLElement>('[role="menu"]');
    if (replaying.has(item) || reducedMotion.value || event.defaultPrevented || event.button !== 0
        || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey
        || item.hasAttribute('data-disabled') || menu?.dataset.state !== 'open') return;

    event.preventDefault();
    event.stopImmediatePropagation();
    if (selecting.has(menu)) return;
    selecting.add(menu);

    try {
        await item.animate(
            { backgroundColor: ['transparent', 'var(--menu-selection-highlight, var(--accent))'] },
            { duration: 180, easing: 'steps(2, jump-none)' },
        ).finished;
    } catch {
        return;
    } finally {
        selecting.delete(menu);
    }

    if (!item.isConnected || menu.dataset.state !== 'open' || item.hasAttribute('data-disabled')) return;
    replaying.add(item);
    try {
        item.click();
    } finally {
        replaying.delete(item);
    }
}
