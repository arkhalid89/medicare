; Fonts used by the MediCare UI and receipts (docs/02-design/design-system.md, "Fonts in Windows Forms").
; Shared by MediCareHost.iss and MediCareClient.iss via #include.
;
; All three families are SIL Open Font License 1.1. The TTF files are NOT in the repository: copy them into
; installer\fonts\ as listed in installer\fonts\README.md before compiling. A missing file is skipped
; (skipifsourcedoesntexist) and the app falls back to Segoe UI / Consolas, so a build without fonts still works.
;
; Fonts are installed per machine (GDI TextRenderer, used by DataGridView, cannot see private fonts),
; never overwrite a font that is already there, and are left in place on uninstall because other
; programs may use them. FontInstall must be the font's full name as Windows lists it.

[Files]
Source: "fonts\AtkinsonHyperlegible-Regular.ttf"; DestDir: "{autofonts}"; FontInstall: "Atkinson Hyperlegible Regular"; Flags: onlyifdoesntexist uninsneveruninstall skipifsourcedoesntexist
Source: "fonts\AtkinsonHyperlegible-Bold.ttf"; DestDir: "{autofonts}"; FontInstall: "Atkinson Hyperlegible Bold"; Flags: onlyifdoesntexist uninsneveruninstall skipifsourcedoesntexist
Source: "fonts\AtkinsonHyperlegible-Italic.ttf"; DestDir: "{autofonts}"; FontInstall: "Atkinson Hyperlegible Italic"; Flags: onlyifdoesntexist uninsneveruninstall skipifsourcedoesntexist
Source: "fonts\AtkinsonHyperlegible-BoldItalic.ttf"; DestDir: "{autofonts}"; FontInstall: "Atkinson Hyperlegible Bold Italic"; Flags: onlyifdoesntexist uninsneveruninstall skipifsourcedoesntexist
Source: "fonts\IBMPlexMono-Regular.ttf"; DestDir: "{autofonts}"; FontInstall: "IBM Plex Mono Regular"; Flags: onlyifdoesntexist uninsneveruninstall skipifsourcedoesntexist
Source: "fonts\IBMPlexMono-Bold.ttf"; DestDir: "{autofonts}"; FontInstall: "IBM Plex Mono Bold"; Flags: onlyifdoesntexist uninsneveruninstall skipifsourcedoesntexist
Source: "fonts\NotoNaskhArabic-Regular.ttf"; DestDir: "{autofonts}"; FontInstall: "Noto Naskh Arabic Regular"; Flags: onlyifdoesntexist uninsneveruninstall skipifsourcedoesntexist
Source: "fonts\NotoNaskhArabic-Bold.ttf"; DestDir: "{autofonts}"; FontInstall: "Noto Naskh Arabic Bold"; Flags: onlyifdoesntexist uninsneveruninstall skipifsourcedoesntexist
; The OFL requires the licence to travel with the fonts.
Source: "fonts\OFL-*.txt"; DestDir: "{app}\Licenses\Fonts"; Flags: ignoreversion skipifsourcedoesntexist
