# Installer fonts

The MediCare UI and receipts use three font families (see `docs/02-design/design-system.md`, "Fonts in Windows Forms"). All three are licensed under the **SIL Open Font License 1.1**, which allows bundling them with an application and installing them, as long as the licence text goes with them and the fonts are not sold on their own.

The font files are **not committed** to this repository. Before building release installers, copy the files below into this folder (`installer/fonts/`). `installer/fonts.iss` lists them one by one. A missing file is skipped (`skipifsourcedoesntexist`), and the app falls back to Segoe UI or Consolas, so a build without fonts still works but does not look as designed.

## Files expected

Use the **static** TTF files. Do not use variable fonts (file names with `[wght]`): Windows 7 cannot use them, and GDI support for them is limited on Windows 10.

| File (exact name) | Family | Registered as | Used for |
|---|---|---|---|
| `AtkinsonHyperlegible-Regular.ttf` | Atkinson Hyperlegible | Atkinson Hyperlegible | UI text |
| `AtkinsonHyperlegible-Bold.ttf` | Atkinson Hyperlegible | Atkinson Hyperlegible Bold | UI headings, buttons |
| `AtkinsonHyperlegible-Italic.ttf` | Atkinson Hyperlegible | Atkinson Hyperlegible Italic | hints |
| `AtkinsonHyperlegible-BoldItalic.ttf` | Atkinson Hyperlegible | Atkinson Hyperlegible Bold Italic | completeness of the family |
| `IBMPlexMono-Regular.ttf` | IBM Plex Mono | IBM Plex Mono | amounts, batch numbers, document numbers |
| `IBMPlexMono-Bold.ttf` | IBM Plex Mono | IBM Plex Mono Bold | totals |
| `NotoNaskhArabic-Regular.ttf` | Noto Naskh Arabic | Noto Naskh Arabic | Urdu lines on receipts |
| `NotoNaskhArabic-Bold.ttf` | Noto Naskh Arabic | Noto Naskh Arabic Bold | Urdu headings on receipts |
| `OFL-AtkinsonHyperlegible.txt` | licence | - | installed to `Licenses\Fonts` |
| `OFL-IBMPlexMono.txt` | licence | - | installed to `Licenses\Fonts` |
| `OFL-NotoNaskhArabic.txt` | licence | - | installed to `Licenses\Fonts` |

Each download includes an `OFL.txt`. Rename it to the `OFL-<Family>.txt` name above, so that the three licences do not overwrite each other.

## Where to download

**Atkinson Hyperlegible** (Braille Institute)
- Google Fonts: https://fonts.google.com/specimen/Atkinson+Hyperlegible (select "Get font", then "Download all"; the zip contains the four `AtkinsonHyperlegible-*.ttf` files and `OFL.txt`)
- GitHub: https://github.com/google/fonts/tree/main/ofl/atkinsonhyperlegible
- Publisher: https://www.brailleinstitute.org/freefont/

Use the original "Atkinson Hyperlegible" family, not "Atkinson Hyperlegible Next" or "Atkinson Hyperlegible Mono". Those are different families with different names.

**IBM Plex Mono** (IBM)
- Google Fonts: https://fonts.google.com/specimen/IBM+Plex+Mono (the zip contains `IBMPlexMono-Regular.ttf`, `IBMPlexMono-Bold.ttf` and `OFL.txt`)
- GitHub releases: https://github.com/IBM/plex/releases (asset `ibm-plex-mono.zip`, folder `fonts/complete/ttf/`)
- GitHub (Google Fonts copy): https://github.com/google/fonts/tree/main/ofl/ibmplexmono

**Noto Naskh Arabic** (Google Noto project)
- Google Fonts: https://fonts.google.com/noto/specimen/Noto+Naskh+Arabic (the zip contains a variable font plus a `static/` folder; take `static/NotoNaskhArabic-Regular.ttf` and `static/NotoNaskhArabic-Bold.ttf`)
- GitHub releases: https://github.com/notofonts/arabic/releases (asset `NotoNaskhArabic-v*.zip`, folder `NotoNaskhArabic/hinted/ttf/`; hinted files render best in GDI)

## Licence note

Atkinson Hyperlegible (Copyright 2020 Braille Institute of America, Inc.), IBM Plex Mono (Copyright 2017 IBM Corp.) and Noto Naskh Arabic (Copyright 2022 The Noto Project Authors) are each licensed under the SIL Open Font License, Version 1.1 (https://openfontlicense.org). The installers copy the `OFL-*.txt` files from this folder to `<install folder>\Licenses\Fonts\` to meet the licence's requirement that the licence accompanies the fonts. Do not rename the font families or sell the fonts on their own. Check the copyright line in each downloaded `OFL.txt`; the one in the file is authoritative.

## Checking the registered names

`FontInstall:` in `fonts.iss` must match the font's full name. After installing on a test PC, check `HKLM\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Fonts`. If a newer release of a font uses a different full name, update `fonts.iss`.
