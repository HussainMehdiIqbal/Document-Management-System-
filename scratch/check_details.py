import os
import re

exclude_dirs = {'.git', 'uploads', 'scans', 'DHA_Scans', 'scratch', 'node_modules', '.agents'}
extensions = {'.php', '.js', '.css', '.html', '.sql'}

def check_file_indent_details(filepath):
    with open(filepath, 'r', encoding='utf-8', errors='ignore') as f:
        lines = f.readlines()
    
    odd_indents = []
    space_counts = {}
    mixed_lines = []
    tab_lines = []
    
    for idx, line in enumerate(lines, 1):
        if not line.strip():
            continue
        match = re.match(r'^(\s+)', line)
        if match:
            ws = match.group(1)
            if '\t' in ws and ' ' in ws:
                mixed_lines.append(idx)
            elif '\t' in ws:
                tab_lines.append(idx)
            num_spaces = len(ws.replace('\t', '    '))
            space_counts[num_spaces % 4] = space_counts.get(num_spaces % 4, 0) + 1
            if num_spaces % 2 != 0:
                odd_indents.append((idx, num_spaces, line.strip()[:60]))
                
    return len(lines), space_counts, odd_indents, tab_lines, mixed_lines

file_list = []
for root, dirs, files in os.walk('.'):
    dirs[:] = [d for d in dirs if d not in exclude_dirs]
    for file in files:
        ext = os.path.splitext(file)[1].lower()
        if ext in extensions:
            file_list.append(os.path.normpath(os.path.join(root, file)))

out_lines = []
for fpath in sorted(file_list):
    total, counts, odds, tabs, mixed = check_file_indent_details(fpath)
    out_lines.append(f"File: {fpath} (Lines: {total})")
    out_lines.append(f"  Space mod 4: {counts}")
    out_lines.append(f"  Tab lines count: {len(tabs)}, Mixed lines count: {len(mixed)}, Odd space lines count: {len(odds)}")
    if odds:
        out_lines.append("  First few odd lines:")
        for line_no, spaces, text in odds[:5]:
            out_lines.append(f"    Line {line_no} ({spaces} sp): {text}")
    out_lines.append("-" * 60)

with open('scratch/indent_report.txt', 'w', encoding='utf-8') as f:
    f.write("\n".join(out_lines))

print("Wrote scratch/indent_report.txt successfully.")
