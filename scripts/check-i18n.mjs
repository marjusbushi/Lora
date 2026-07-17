import fs from 'node:fs';
import path from 'node:path';
import { baseCompile as compileMessage } from '@intlify/message-compiler';
import { baseParse } from '@vue/compiler-dom';
import { parse as parseSfc } from '@vue/compiler-sfc';

const ROOT = process.cwd();
const readJson = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'));
const flatten = (value, prefix = '', result = new Map()) => {
    for (const [key, child] of Object.entries(value)) {
        const fullKey = prefix ? `${prefix}.${key}` : key;
        if (child && typeof child === 'object' && !Array.isArray(child)) flatten(child, fullKey, result);
        else result.set(fullKey, child);
    }
    return result;
};
const walk = (directory) => fs.readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const target = path.join(directory, entry.name);
    if (entry.isDirectory()) return walk(target);
    return /\.(vue|js|ts)$/.test(entry.name) ? [target] : [];
});

const enMessages = readJson('resources/js/locales/en.json');
const sqMessages = readJson('resources/js/locales/sq.json');
enMessages.marketing = readJson('resources/js/locales/marketing-en.json');
sqMessages.marketing = readJson('resources/js/locales/marketing-sq.json');

const en = flatten(enMessages);
const sq = flatten(sqMessages);
const errors = [];

for (const key of en.keys()) if (!sq.has(key)) errors.push(`Missing SQ translation: ${key}`);
for (const key of sq.keys()) if (!en.has(key)) errors.push(`Missing EN translation: ${key}`);

for (const [locale, messages] of [['EN', en], ['SQ', sq]]) {
    for (const [key, message] of messages) {
        if (typeof message !== 'string') continue;

        try {
            compileMessage(message, { onError: (error) => { throw error; } });
        } catch (error) {
            errors.push(`Invalid ${locale} translation "${key}": ${error.message}`);
        }
    }
}

const files = walk(path.join(ROOT, 'resources/js'));
for (const file of files) {
    const source = fs.readFileSync(file, 'utf8');
    const relative = path.relative(ROOT, file);

    for (const match of source.matchAll(/(?:\$t|\bt|translate)\(\s*['"]([^'"`]+)['"]/g)) {
        const key = match[1];
        if (key.endsWith('.') || key.includes('${')) continue;
        if (!en.has(key)) errors.push(`${relative}: missing locale key "${key}"`);
    }

    if (!file.endsWith('.vue')) continue;
    const template = parseSfc(source, { filename: relative }).descriptor.template?.content;
    if (!template) continue;

    let ast;
    try { ast = baseParse(template, { comments: false }); }
    catch (error) {
        continue;
    }
    visitTemplate(ast, relative, errors);
}

if (errors.length) {
    console.error(`i18n check failed with ${errors.length} issue(s):`);
    console.error(errors.join('\n'));
    process.exit(1);
}

console.log(`i18n check passed: ${en.size} SQ/EN keys and ${files.length} source files checked.`);

function isAllowedLiteral(text) {
    if (!/\p{L}/u.test(text)) return true;
    if (/^(?:Lora PMS|Lora Core|Control Panel|Super Admin|MRR|SLA|SKU|Barcode|Email|IP|Channex|POK|Channex \/ POK|Channex Channel Manager|POK Payments|Base URL|Merchant ID|Key ID|Key secret|Property ID|Webhook secret|API key|POS|OTA|HK|#MNT-|ALL · Lek|EUR · Euro|Housekeeping|Check-in|Check-out|Booking|Airbnb|Expedia|Agoda|Bar)$/i.test(text)) return true;
    if (/^(?:https?:\/\/|[\w.+-]+@[\w.-]+\.|AL__|riviera\.|p\.sh\.|e\.g\.)/i.test(text)) return true;
    if (/^(?:L|€[\d,.]+|\d+ × (?:Espresso|Club Sandwich)|© \d{4} Lora PMS · lorapms\.com|\*{4,}|Ana Berisha)$/i.test(text)) return true;
    if (/^[A-Z0-9 /&·._-]{2,24}$/.test(text)) return true;
    return false;
}

function visitTemplate(node, relative, issues) {
    if (node.type === 2) {
        const text = node.content.replace(/\s+/g, ' ').trim();
        if (text && !isAllowedLiteral(text)) {
            issues.push(`${relative}: hardcoded visible text "${text}"`);
        }
    }
    if (node.type === 1) {
        for (const prop of node.props || []) {
            if (prop.type !== 6 || !['placeholder', 'aria-label', 'title'].includes(prop.name) || !prop.value) continue;
            const text = prop.value.content.trim();
            if (text && !isAllowedLiteral(text)) issues.push(`${relative}: hardcoded attribute "${text}"`);
        }
    }
    for (const child of node.children || []) visitTemplate(child, relative, issues);
}
