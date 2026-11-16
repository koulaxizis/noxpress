# Third-party code in Ebook Delivery

Ebook Delivery itself is MIT licensed (see the repository's LICENSE). It ships
the following third-party file, under its own license. The license text is
next to the file.

| Files | Project | Version | License |
|---|---|---|---|
| `assets/vendor/noto-sans/NotoSans-Watermark.ttf` | [Noto Sans](https://github.com/notofonts/latin-greek-cyrillic) Regular | 2.015 | SIL Open Font License 1.1 (`assets/vendor/noto-sans/OFL.txt`) |

Changes: the font is a subset (the glyphs of the Latin, Greek, polytonic
Greek and Cyrillic letters, punctuation, € № ™), without hinting and layout
features, so the PDF copies stay small (≈ 30 KB added). The subset keeps the
font's names and copyright notice. Under the OFL a modified font may not use
the Reserved Font Name; Noto Sans has none.

`includes/class-font.php` holds the subset's metrics and its
code point → glyph → width map, generated from the TTF.

`includes/class-pdf.php` (the PDF reader) is Noxpress code, copied from
Book Preview (MIT) without its writer.

To update: take the unhinted `NotoSans-Regular.ttf` from
`fonts/NotoSans/unhinted/ttf/` of notofonts.github.io and run

```
pyftsubset NotoSans-Regular.ttf \
  --unicodes="U+0020-007E,U+00A0-017F,U+0370-03FF,U+1F00-1FFF,U+0400-045F,U+2010-2027,U+2030-203A,U+20AC,U+2116,U+2122" \
  --layout-features='' --no-hinting --desubroutinize --name-IDs='*' \
  --notdef-outline --output-file=NotoSans-Watermark.ttf
```

then regenerate `class-font.php` from it (units per em, ascent, descent, cap
height, bounding box and the `cmap` with `hmtx` advances, scaled to 1000),
update `VERSION` and this file, and stamp the test PDFs again.
