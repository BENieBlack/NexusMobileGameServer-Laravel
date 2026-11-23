// マスター配信画面の日時表示。保存・送信は UTC の 'Y-m-d H:i:s'、表示は JST を併記する（api.md 2 節）

const UTC_PATTERN = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/;
const JST_OFFSET_MS = 9 * 60 * 60 * 1000;

const pad = (n) => String(n).padStart(2, '0');

/** 'Y-m-d H:i:s'（UTC）を Date にする。形式・日付が不正なら null */
export const parseUtc = (value) => {
    if (typeof value !== 'string' || !UTC_PATTERN.test(value)) return null;
    const date = new Date(value.replace(' ', 'T') + 'Z');
    if (Number.isNaN(date.getTime())) return null;
    // 2026-02-31 のような繰り上がりを弾く
    return toUtcString(date) === value ? date : null;
};

export const toUtcString = (date) =>
    `${date.getUTCFullYear()}-${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())} `
    + `${pad(date.getUTCHours())}:${pad(date.getUTCMinutes())}:${pad(date.getUTCSeconds())}`;

/** UTC 文字列 → JST 文字列。不正なら null */
export const toJst = (value) => {
    const date = parseUtc(value);
    return date === null ? null : toUtcString(new Date(date.getTime() + JST_OFFSET_MS));
};

/** 「2026-12-01 15:00:00 UTC（2026-12-02 00:00:00 JST）」。null は '—' */
export const both = (value) => {
    if (value === null || value === undefined || value === '') return '—';
    const jst = toJst(value);
    return jst === null ? String(value) : `${value} UTC（${jst} JST）`;
};

/** 2 つの UTC 文字列の差（秒。a - b）。不正なら null */
export const diffSeconds = (a, b) => {
    const da = parseUtc(a);
    const db = parseUtc(b);
    return da === null || db === null ? null : Math.round((da.getTime() - db.getTime()) / 1000);
};

export const shortHash = (hash) => (typeof hash === 'string' ? hash.slice(0, 16) : '—');
