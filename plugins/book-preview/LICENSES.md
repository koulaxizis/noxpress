# Third-party code in Book Preview

Book Preview itself is MIT licensed (see the repository's LICENSE). It ships
the following third-party files unchanged, each under its own license. The
license texts are next to the files.

| Files | Project | Version | License |
|---|---|---|---|
| `assets/vendor/pdfjs/pdf.min.js`, `pdf.worker.min.js` | [PDF.js](https://github.com/mozilla/pdf.js) (`pdfjs-dist`, legacy build) | 6.3.289 | Apache-2.0 (`assets/vendor/pdfjs/LICENSE`) |
| `assets/vendor/pdfjs/standard_fonts/Foxit*.pfb` | PDFium standard fonts | from pdfjs-dist 6.3.289 | BSD-3-Clause (`LICENSE_FOXIT`) |
| `assets/vendor/pdfjs/standard_fonts/Liberation*.ttf` | Liberation Fonts | from pdfjs-dist 6.3.289 | GPL-2.0 with the font exception (`LICENSE_LIBERATION`) |
| `assets/vendor/pdfjs/wasm/openjpeg.wasm` | OpenJPEG, built by Mozilla | from pdfjs-dist 6.3.289 | BSD-2-Clause (`LICENSE_OPENJPEG`, `LICENSE_PDFJS_OPENJPEG`) |
| `assets/vendor/pdfjs/wasm/jbig2.wasm` | PDFium JBIG2 decoder, built by Mozilla | from pdfjs-dist 6.3.289 | BSD-3-Clause and Apache-2.0 (`LICENSE_JBIG2`, `LICENSE_PDFJS_JBIG2`) |
| `assets/vendor/pdfjs/wasm/qcms_bg.wasm` | qcms, built by Mozilla | from pdfjs-dist 6.3.289 | MIT (`LICENSE_QCMS`, `LICENSE_PDFJS_QCMS`) |
| `assets/vendor/pdfjs/iccs/*.icc` | ICC profile shipped with PDF.js | from pdfjs-dist 6.3.289 | CC0-1.0 (`iccs/LICENSE`) |

Changes: none to the contents. The two ES module files were renamed from
`.mjs` to `.js` so that every web server sends them with a JavaScript MIME
type (many servers send `.mjs` as `application/octet-stream`, which browsers
refuse to run as a module). Left out on purpose: the JavaScript fallbacks for
browsers without WebAssembly and the scripting sandbox (`quickjs-eval`), since
the viewer never runs PDF scripts.

To update: take `legacy/build/pdf.min.mjs` and
`legacy/build/pdf.worker.min.mjs` (the legacy build also runs on older
Safari), `standard_fonts/`, `iccs/` and the three `.wasm` files with their
`LICENSE_*` files from `wasm/` of the `pdfjs-dist` npm package, rename the two scripts to `.js`, update `VERSION` and this file,
and test the viewer.
