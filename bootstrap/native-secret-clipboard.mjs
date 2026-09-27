import { app, clipboard } from 'electron';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';

let timer;
let pending;

function changeCount() {
    if (process.platform !== 'darwin') throw new Error('Clipboard change tracking is unavailable.');
    const output = execFileSync('/usr/bin/osascript', ['-l', 'JavaScript', '-e', 'ObjC.import("AppKit"); $.NSPasteboard.generalPasteboard.changeCount'], { encoding: 'utf8', timeout: 2000 }).trim();
    if (!/^\d+$/.test(output)) throw new Error('Clipboard change tracking is unavailable.');
    const count = Number(output);
    if (!Number.isSafeInteger(count) || count < 0) throw new Error('Clipboard change tracking is unavailable.');
    return count;
}

function digest(text = clipboard.readText()) {
    return createHash('sha256').update(text).digest('hex');
}

function clearSecretCopy() {
    if (timer) clearTimeout(timer);
    timer = undefined;
    if (!pending) return;
    try {
        if (changeCount() === pending.count && digest() === pending.digest) clipboard.clear();
        pending = undefined;
    } catch {
        // Retry on Quit if the native pasteboard is temporarily unavailable.
    }
}

export function copySecret(req, res) {
    const { text, clearAfterSeconds } = req.body;
    if (typeof text !== 'string' || ![0, 30, 60].includes(clearAfterSeconds)) {
        res.sendStatus(422);
        return;
    }
    try {
        if (clearAfterSeconds) changeCount();
        clipboard.writeText(text);
        if (timer) clearTimeout(timer);
        timer = undefined;
        pending = undefined;
        if (clearAfterSeconds) {
            pending = { count: changeCount(), digest: digest(text) };
            timer = setTimeout(clearSecretCopy, clearAfterSeconds * 1000);
        }
        res.json({ copied: true });
    } catch {
        res.sendStatus(500);
    }
}

app.on('before-quit', clearSecretCopy);
