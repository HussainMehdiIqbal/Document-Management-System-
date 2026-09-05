import os
import re

exclude_dirs = {'.git', 'uploads', 'scans', 'DHA_Scans', 'scratch', 'node_modules', '.agents'}
extensions = {'.php', '.js', '.css', '.html', '.sql'}

def analyze_file(filepath):
    with open(filepath, 'r', encoding='utf-8', errors='ignore') as f:
        lines = f.readlines()
    
    tabs_count = 0
    spaces_count = 0
    mixed_count = 0
    bad_indent_lines = []
    
    for idx, line in enumerate(lines, 1):
        # leading whitespace
        match = re.match(r'^(\s+)', line)
        if match:
            ws = match.group(1)
            if '\t' in ws and ' ' in ws:
                mixed_count += 1
                if len(bad_indent_lines) < 5:
                    bad_indent_lines.append((idx, repr(ws)))
            elif '\t' in ws:
                tabs_count += 1
            elif ' ' in ws:
                spaces_count += 1

    return {
        'total_lines': len(lines),
        'tabs_lines': tabs_count,
        'spaces_lines': spaces_count,
        'mixed_lines': mixed_count,
        'bad_samples': bad_indent_lines
    }

file_list = []
for root, dirs, files in os.walk('.'):
    dirs[:] = [d for d in dirs if d not in exclude_dirs]
    for file in files:
        ext = os.path.splitext(file)[1].lower()
        if ext in extensions:
            file_list.append(os.path.normpath(os.path.join(root, file)))

print(f"Found {len(file_list)} files.")
stats = {}
for fpath in sorted(file_list):
    res = analyze_file(fpath)
    stats[fpath] = res
    print(f"{fpath}: Total={res['total_lines']}, Tabs={res['tabs_lines']}, Spaces={res['spaces_lines']}, Mixed={res['mixed_lines']}")

