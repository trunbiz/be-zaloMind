import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import opentype from 'opentype.js';
import sharp from 'sharp';

const root = resolve(import.meta.dirname, '..');
const output = resolve(root, 'public/brand');
const fonts = await Promise.all(['latin', 'vietnamese'].map(async subset => {
    const bytes = await readFile(resolve(root, `node_modules/@fontsource/be-vietnam-pro/files/be-vietnam-pro-${subset}-700-normal.woff`));
    return opentype.parse(bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength));
}));
for (const directory of ['logo', 'favicon', 'social', 'illustrations']) await mkdir(resolve(output, directory), { recursive: true });
const blue = '#1A56DB';
const navy = '#172B4D';
const mint = '#10B981';
const svg = (width, height, content) => `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}" viewBox="0 0 ${width} ${height}" fill="none">${content}</svg>`;
const text = (value, x, y, size, color = navy) => {
    let cursor = x;
    return [...value].map(character => {
        const font = fonts.find(candidate => candidate.charToGlyphIndex(character)) || fonts[0];
        const glyph = font.charToGlyph(character);
        const path = glyph.getPath(cursor, y, size).toPathData(3);
        cursor += glyph.advanceWidth * size / font.unitsPerEm;
        return `<path d="${path}" fill="${color}"/>`;
    }).join('');
};
// An original conversation tile, a connected M and a mint signal dot.
const mark = (background = blue, foreground = '#fff', signal = mint) => `<path d="M18 4H46C54 4 60 10 60 18V42C60 50 54 56 46 56H23L9 63V51C6 48 4 45 4 40V18C4 10 10 4 18 4Z" fill="${background}"/><path d="M17 40V25C17 18 29 18 29 26V39M29 29C29 19 43 19 43 29V40" stroke="${foreground}" stroke-width="5" stroke-linecap="round"/><circle cx="54" cy="10" r="9" fill="${signal}" stroke="${foreground}" stroke-width="3"/>`;
const label = (color = navy) => text('zaloMind', 61, 39, 32, color);
const variants = {
    'zalomind-logo': svg(222, 54, `<g transform="translate(0 -1) scale(.78)">${mark()}</g>${label()}`),
    'zalomind-logo-dark': svg(222, 54, `<g transform="translate(0 -1) scale(.78)">${mark()}</g>${label('#F1F5FF')}`),
    'zalomind-logo-white': svg(222, 54, `<g transform="translate(0 -1) scale(.78)">${mark('#fff', blue, '#fff')}</g>${label('#fff')}`),
    'zalomind-logo-stacked': svg(222, 134, `<g transform="translate(78 0)">${mark()}</g>${text('zaloMind', 22, 116, 36)}`),
    'zalomind-logo-stacked-dark': svg(222, 134, `<g transform="translate(78 0)">${mark()}</g>${text('zaloMind', 22, 116, 36, '#F1F5FF')}`),
    'zalomind-mark': svg(64, 64, mark()),
    'zalomind-mark-white': svg(64, 64, mark('#fff', blue, '#fff')),
    'zalomind-mark-mono': svg(64, 64, mark(navy, '#fff', navy)),
};
for (const [name, source] of Object.entries(variants)) {
    await writeFile(resolve(output, `logo/${name}.svg`), source);
    await sharp(Buffer.from(source)).resize({ width: name.includes('mark') ? 256 : 666 }).png().toFile(resolve(output, `logo/${name}.png`));
}
const favicon = svg(64, 64, mark());
await writeFile(resolve(output, 'favicon/favicon.svg'), favicon);
for (const [name, size] of [['apple-touch-icon', 180], ['icon-192', 192], ['icon-512', 512]]) {
    await sharp(Buffer.from(svg(80, 80, `<rect width="80" height="80" rx="18" fill="#fff"/><g transform="translate(8 6)">${mark()}</g>`))).resize(size, size).png().toFile(resolve(output, `favicon/${name}.png`));
}
// ICO containing PNG entries, supported by current browsers and Windows.
const iconSizes = [16, 32, 48];
const iconImages = await Promise.all(iconSizes.map(size => sharp(Buffer.from(favicon)).resize(size, size).png().toBuffer()));
const header = Buffer.alloc(6 + iconImages.length * 16);
header.writeUInt16LE(1, 2);
header.writeUInt16LE(iconImages.length, 4);
let offset = header.length;
iconImages.forEach((image, index) => {
    const entry = 6 + index * 16;
    header[entry] = iconSizes[index]; header[entry + 1] = iconSizes[index];
    header.writeUInt16LE(1, entry + 4); header.writeUInt16LE(32, entry + 6);
    header.writeUInt32LE(image.length, entry + 8); header.writeUInt32LE(offset, entry + 12);
    offset += image.length;
});
await writeFile(resolve(output, 'favicon/favicon.ico'), Buffer.concat([header, ...iconImages]));
await writeFile(resolve(output, 'favicon/site.webmanifest'), JSON.stringify({
    id: '/', name: 'zaloMind', short_name: 'zaloMind', lang: 'vi', start_url: '/', scope: '/',
    display: 'standalone', background_color: '#F5F7FB', theme_color: blue,
    icons: [192, 512].map(size => ({ src: `/brand/favicon/icon-${size}.png`, sizes: `${size}x${size}`, type: 'image/png', purpose: 'any' })),
}, null, 2));
const hero = svg(520, 340, `
<circle cx="265" cy="170" r="150" fill="#E1EAFF"/>
<path d="M52 250C150 295 385 285 470 235" stroke="#C3D2F5" stroke-width="2" stroke-dasharray="7 9"/>
<rect x="86" y="40" width="348" height="238" rx="20" fill="white" stroke="#CDD9F1" stroke-width="2"/>
<path d="M86 91H434" stroke="#E8EDF8" stroke-width="2"/>
<circle cx="111" cy="66" r="5" fill="#D2DDF4"/><circle cx="129" cy="66" r="5" fill="#D2DDF4"/><circle cx="147" cy="66" r="5" fill="#D2DDF4"/>
<rect x="105" y="111" width="71" height="148" rx="10" fill="#F0F4FC"/>
<rect x="116" y="126" width="47" height="8" rx="4" fill="#A9C1F5"/><rect x="116" y="148" width="33" height="6" rx="3" fill="#D2DDF4"/><rect x="116" y="168" width="40" height="6" rx="3" fill="#D2DDF4"/>
<rect x="198" y="112" width="213" height="44" rx="10" fill="#EFF5FF"/>
<circle cx="220" cy="134" r="11" fill="#C5D8FF"/><rect x="242" y="124" width="104" height="7" rx="3.5" fill="#84A7EF"/><rect x="242" y="139" width="145" height="6" rx="3" fill="#CDDDFB"/>
<rect x="198" y="168" width="170" height="39" rx="11" fill="#1A56DB"/><path d="M214 182H327M214 193H294" stroke="white" stroke-width="5" stroke-linecap="round" opacity=".85"/>
<rect x="253" y="219" width="158" height="29" rx="10" fill="#E3F8EF"/><path d="M266 233H387" stroke="#64BD9E" stroke-width="5" stroke-linecap="round"/>
<g transform="translate(36 157)"><rect width="80" height="80" rx="22" fill="#1A56DB"/><g transform="translate(9 7)">${mark()}</g></g>
<circle cx="432" cy="264" r="38" fill="#10B981" stroke="#F0FBF7" stroke-width="9"/><path d="M417 264L428 275L449 253" stroke="white" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/>
<path d="M432 45V27M423 36H441" stroke="#10B981" stroke-width="4" stroke-linecap="round"/>
`);
await writeFile(resolve(output, 'illustrations/login-hero.svg'), hero);
const emptyBase = content => svg(280, 200, `<ellipse cx="140" cy="176" rx="101" ry="12" fill="#E9EEF8"/><circle cx="140" cy="94" r="77" fill="#EFF4FF"/>${content}<circle cx="232" cy="49" r="5" fill="#A7DCCB"/><path d="M42 71V83M36 77H48" stroke="#A4BDF2" stroke-width="3" stroke-linecap="round"/>`);
const illustrations = {
    'empty-profiles': emptyBase('<rect x="88" y="34" width="104" height="137" rx="16" fill="white" stroke="#BCCFF7" stroke-width="2"/><circle cx="140" cy="80" r="22" fill="#D9E5FF"/><path d="M109 132C112 101 168 101 171 132" fill="#A5BEF2"/><circle cx="184" cy="143" r="24" fill="#1A56DB"/><path d="M174 143H194M184 133V153" stroke="white" stroke-width="4" stroke-linecap="round"/>'),
    'empty-batches': emptyBase('<rect x="69" y="53" width="146" height="111" rx="13" fill="white" stroke="#BCCFF7" stroke-width="2"/><path d="M69 82H215" stroke="#BCCFF7" stroke-width="2"/><path d="M98 43V64M184 43V64" stroke="#1A56DB" stroke-width="7" stroke-linecap="round"/><rect x="91" y="102" width="28" height="25" rx="6" fill="#D8E5FF"/><rect x="130" y="102" width="28" height="25" rx="6" fill="#D8E5FF"/><circle cx="198" cy="148" r="25" fill="#10B981"/><path d="M198 133V148L208 155" stroke="white" stroke-width="4" stroke-linecap="round"/>'),
    'empty-inbox': emptyBase('<path d="M68 80L140 34L212 80V155C212 162 206 168 199 168H81C74 168 68 162 68 155V80Z" fill="white" stroke="#BCCFF7" stroke-width="2"/><rect x="92" y="53" width="96" height="89" rx="9" fill="#DCE8FF"/><path d="M107 76H171M107 93H156" stroke="#86A8EC" stroke-width="6" stroke-linecap="round"/><path d="M68 80L140 130L212 80M68 161L121 119M212 161L159 119" stroke="#BCCFF7" stroke-width="2"/>'),
    'empty-search': emptyBase('<circle cx="130" cy="89" r="49" fill="white" stroke="#1A56DB" stroke-width="9"/><path d="M167 125L201 160" stroke="#1A56DB" stroke-width="15" stroke-linecap="round"/><path d="M111 89H149" stroke="#BCCFF7" stroke-width="7" stroke-linecap="round"/>'),
    'error-404': emptyBase('<path d="M96 33H159L192 67V160H96V33Z" fill="white" stroke="#BCCFF7" stroke-width="2"/><path d="M159 33V67H192" fill="#DCE8FF"/><path d="M124 93L161 130M161 93L124 130" stroke="#1A56DB" stroke-width="7" stroke-linecap="round"/><circle cx="82" cy="139" r="25" fill="#10B981"/><path d="M82 126V141M82 149V150" stroke="white" stroke-width="4" stroke-linecap="round"/>'),
};
for (const [name, source] of Object.entries(illustrations)) await writeFile(resolve(output, `illustrations/${name}.svg`), source);
const ogSource = svg(1200, 630, `<rect width="1200" height="630" fill="#F5F7FB"/><circle cx="1065" cy="65" r="270" fill="#E2EBFF"/><circle cx="1050" cy="630" r="210" fill="#DEF4EC"/><g transform="translate(76 69) scale(1.25)">${variants['zalomind-logo'].replace(/^<svg[^>]*>|<\/svg>$/g, '')}</g>${text('Chăm sóc khách hàng', 80, 280, 54)}${text('Zalo thông minh', 80, 355, 54, blue)}${text('Kết nối bền lâu. Kinh doanh bứt phá.', 82, 436, 23, '#53657F')}<g transform="translate(735 205) scale(2.8)">${mark()}</g><rect x="80" y="504" width="160" height="47" rx="23.5" fill="#E0EAFD"/>${text('zaloMind', 105, 535, 20, blue)}`);
await writeFile(resolve(output, 'social/og-image.svg'), ogSource);
await sharp(Buffer.from(ogSource)).png().toFile(resolve(output, 'social/og-image.png'));
const preview = svg(1000, 620, `<rect width="1000" height="620" fill="#F5F7FB"/><rect x="36" y="36" width="444" height="210" rx="16" fill="white"/><rect x="520" y="36" width="444" height="210" rx="16" fill="#182235"/><g transform="translate(92 113) scale(1.5)">${variants['zalomind-logo'].replace(/^<svg[^>]*>|<\/svg>$/g, '')}</g><g transform="translate(572 113) scale(1.5)">${variants['zalomind-logo-dark'].replace(/^<svg[^>]*>|<\/svg>$/g, '')}</g><rect x="36" y="282" width="285" height="298" rx="16" fill="white"/><g transform="translate(68 358)">${variants['zalomind-logo-stacked'].replace(/^<svg[^>]*>|<\/svg>$/g, '')}</g><g transform="translate(351 320) scale(.95)">${hero.replace(/^<svg[^>]*>|<\/svg>$/g, '')}</g>`);
await sharp(Buffer.from(preview)).png().toFile(resolve(output, 'preview.png'));
console.log('Generated zaloMind logos, favicon/manifest, social image and 6 illustrations.');
