# Third-party code in Fast Pages

Fast Pages itself is MIT licensed (see the repository's LICENSE). It ships
the following third-party files unchanged, each under its own license. The
license texts are next to the files.

| Files | Project | Version | License |
|---|---|---|---|
| `assets/vendor/minify/src/`, `assets/vendor/minify/data/` | [matthiasmullie/minify](https://github.com/matthiasmullie/minify) | 1.3.75 | MIT (`assets/vendor/minify/LICENSE`) |
| `assets/vendor/path-converter/src/` | [matthiasmullie/path-converter](https://github.com/matthiasmullie/path-converter) | 1.1.3 | MIT (`assets/vendor/path-converter/LICENSE`) |

Changes: none to the contents. Left out: Composer files, tests and the
`bin/` command line tools. The files are loaded without Composer by
`includes/class-vendor.php`, only when Fast Pages minifies a file and only
the classes that no other plugin has already loaded.

The vendor PHP files have no `ABSPATH` guard (they are kept unchanged).
They only declare namespaced classes, so opening one directly in a browser
runs nothing.

Use in Fast Pages: `@import` inlining is off (`setImportExtensions( array() )`,
`setMaxImportSize( 0 )`), so the minifier never reads other files from
disk; relative `url()` paths are rewritten by Fast Pages itself.

To update: take `src/` (and for minify `data/`) and `LICENSE` from the
release tag of each project, update `VERSION` and this file, and test the
minify options on Storefront and Twenty Twenty-Five.
