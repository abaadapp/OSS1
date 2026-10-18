import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * تعليقُ الكود لا يُطبع على الشاشة.
 *
 * بين وسوم JSX يُقرأ `/* … *\/` نصًّا لا تعليقًا — والتعليقُ هناك يُكتب
 * `{/* … *\/}`. كان رأسُ «الحركة المالية» يطبع تعليقًا عن ملفّ التصدير بجانب
 * زرّ «تصدير»، فيتّسع الرأسُ حتى تُمرَّر الصفحةُ أفقيًّا على شاشةٍ بعرض ٧٦٨.
 */

const ROOT = join(__dirname, '..', '..', 'resources', 'js');

function screens(dir: string): string[] {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);
        if (statSync(path).isDirectory()) return screens(path);
        return path.endsWith('.tsx') ? [path] : [];
    });
}

describe('JSX comments', () => {
    it('never sit bare between tags, where React prints them as text', () => {
        const bare: string[] = [];

        for (const file of screens(ROOT)) {
            const lines = readFileSync(file, 'utf8').split('\n');
            lines.forEach((line, i) => {
                if (!line.trim().startsWith('/*')) return;
                let j = i - 1;
                while (j >= 0 && lines[j].trim() === '') j--;
                const previous = j >= 0 ? lines[j].trimEnd() : '';
                // سطرٌ ينتهي بوسمٍ مفتوح (`>` لا `=>`) يعني أنّ ما بعده أبناءُ JSX
                if (previous.endsWith('>') && !previous.endsWith('=>') && !previous.endsWith('->')) {
                    bare.push(`${file.replace(ROOT, 'resources/js')}:${i + 1}`);
                }
            });
        }

        expect(bare).toEqual([]);
    });
});
