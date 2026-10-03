import { BrowserWindow } from 'electron';
import { execFile } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { promisify } from 'node:util';
import windowsHelloScriptPath from './windows-hello.ps1?asset';

const execute = promisify(execFile);
let verifying = false;

async function windowsHello(verify) {
    if (process.platform !== 'win32') return false;
    try {
        let handle = 0n;
        if (verify) {
            const window = BrowserWindow.getFocusedWindow() ?? BrowserWindow.getAllWindows().find(window => !window.isDestroyed() && window.isVisible());
            if (!window || window.isDestroyed()) return false;
            const nativeHandle = window.getNativeWindowHandle();
            handle = nativeHandle.length === 8 ? nativeHandle.readBigUInt64LE() : BigInt(nativeHandle.readUInt32LE());
            if (handle === 0n) return false;
        }
        const script = readFileSync(windowsHelloScriptPath, 'utf8');
        const command = `${script}\n[OrbitWindowsHello]::Run($${verify ? 'true' : 'false'}, [Int64]${handle})`;
        const { stdout } = await execute(join(process.env.SystemRoot, 'System32', 'WindowsPowerShell', 'v1.0', 'powershell.exe'),
            ['-NoLogo', '-NoProfile', '-NonInteractive', '-MTA', '-EncodedCommand', Buffer.from(command, 'utf16le').toString('base64')],
            { encoding: 'utf8', windowsHide: true, timeout: verify ? 95000 : 10000, maxBuffer: 65536 });
        return stdout.trim() === 'True';
    } catch {
        return false;
    }
}

export async function windowsHelloStatus(req, res) {
    res.json({ available: await windowsHello(false) });
}

export async function verifyWindowsHello(req, res) {
    if (verifying) {
        res.json({ verified: false });
        return;
    }
    verifying = true;
    try {
        res.json({ verified: await windowsHello(true) });
    } finally {
        verifying = false;
    }
}
