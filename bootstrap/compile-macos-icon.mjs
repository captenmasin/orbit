import { execFileSync } from 'node:child_process';
import { cpSync, mkdirSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

if (process.platform !== 'darwin') process.exit(0);

const root = new URL('../', import.meta.url);
const temporary = mkdtempSync(join(tmpdir(), 'orbit-icon-'));
const icon = join(temporary, 'Icon.icon');
const output = join(temporary, 'out');

try {
    cpSync(new URL('public/icon.icon', root), icon, { recursive: true });
    mkdirSync(output);
    execFileSync('xcrun', [
        'actool', icon,
        '--compile', output,
        '--output-format', 'human-readable-text',
        '--notices', '--warnings',
        '--output-partial-info-plist', join(output, 'Info.plist'),
        '--app-icon', 'Icon', '--include-all-app-icons',
        '--enable-on-demand-resources', 'NO',
        '--development-region', 'en',
        '--target-device', 'mac',
        '--minimum-deployment-target', '26.0',
        '--platform', 'macosx',
    ], { stdio: 'inherit' });
    mkdirSync(new URL('resources/macos', root), { recursive: true });
    cpSync(join(output, 'Icon.icns'), new URL('public/icon.icns', root));
    cpSync(join(output, 'Assets.car'), new URL('resources/macos/Assets.car', root));
} finally {
    rmSync(temporary, { recursive: true, force: true });
}
