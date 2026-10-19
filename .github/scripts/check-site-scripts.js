// Parses every inline <script> of index.html: one stray quote in a
// dictionary line breaks the whole page's script (language switch, filters).
const fs = require('fs');
const html = fs.readFileSync(process.argv[2] || 'index.html', 'utf8');
const re = /<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/gi;
let m, n = 0, bad = 0;
while ((m = re.exec(html))) {
  n++;
  if (/type=["'](?!text\/javascript|module)/i.test(m[0].slice(0, m[0].indexOf('>')))) continue; // JSON-LD etc.
  try {
    new Function(m[1]);
  } catch (e) {
    bad++;
    const line = html.slice(0, m.index).split('\n').length;
    console.error(`index.html: script starting at line ${line}: ${e.message}`);
  }
}
console.log(`${n} inline scripts, ${bad} with syntax errors`);
process.exit(bad ? 1 : 0);
