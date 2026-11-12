#!/usr/bin/env python3
"""Pretvara migrations/*.sql (MySQL) u migrations/sqlite/*.sql. Pokrenuti nakon svake izmjene šeme."""
import re, glob, os
root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
for src in sorted(glob.glob(os.path.join(root, 'migrations', '*.sql'))):
    s = open(src, encoding='utf-8').read()
    s = re.sub(r'\b(BIG)?INT AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', s)
    s = re.sub(r"ENUM\([^)]*\)", 'TEXT', s)
    s = re.sub(r'\)\s*ENGINE=InnoDB', ')', s)
    s = re.sub(r'UNIQUE KEY \w+ \(', 'UNIQUE (', s)
    s = re.sub(r'(?<!UNIQUE )(?<!PRIMARY )(?<!FOREIGN )\bKEY (\w+ )?\([^)]*\),?', '', s)
    s = re.sub(r'UTC_TIMESTAMP\(\)', "datetime('now')", s)
    s = re.sub(r'--[^\n]*', '', s)            # komentari (zbog zareza u komentarima)
    s = re.sub(r',(\s*)\)', r'\1)', s)         # zarez ispred zatvorene zagrade nakon uklanjanja KEY
    s = re.sub(r'\n\s*\n+', '\n', s)
    out = os.path.join(root, 'migrations', 'sqlite', os.path.basename(src))
    open(out, 'w', encoding='utf-8').write('-- GENERISANO iz ../' + os.path.basename(src) + ' (tools/mysql2sqlite.py) — ne uređivati ručno\n' + s)
    print('->', out)
