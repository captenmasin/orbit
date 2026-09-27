import { ref } from 'vue';

export type Appearance = 'light' | 'dark' | 'system';
export type MotionPreference = 'system' | 'on' | 'off';
export const appearance = ref<Appearance>((document.documentElement.dataset.orbitTheme as Appearance) ?? 'system');
export const motionPreference = ref<MotionPreference>((document.documentElement.dataset.orbitMotion as MotionPreference) ?? 'system');
export const reducedMotion = ref(false);
const system = window.matchMedia('(prefers-color-scheme: dark)');
const systemMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
export function applyAppearance(mode: Appearance) {
    appearance.value = mode;
    const dark = mode === 'dark' || (mode === 'system' && system.matches);
    document.documentElement.classList.toggle('dark', dark);
    document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
}
export function applyMotionPreference(mode: MotionPreference) {
    motionPreference.value = mode;
    reducedMotion.value = mode === 'on' || (mode === 'system' && systemMotion.matches);
    document.documentElement.dataset.orbitMotion = mode;
    document.documentElement.classList.toggle('reduce-motion', reducedMotion.value);
}
system.addEventListener('change', () => { if (appearance.value === 'system') applyAppearance('system'); });
systemMotion.addEventListener('change', () => { if (motionPreference.value === 'system') applyMotionPreference('system'); });
applyAppearance(appearance.value);
applyMotionPreference(motionPreference.value);
