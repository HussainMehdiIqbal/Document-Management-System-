# DHA DMS — Local Scan Agent

Installs on **every thin client that has a scanner attached**, not on the
DMS server. It makes "Start Scan" in the browser use the scanner physically
connected to that PC.

## Why this exists

A webpage can't launch a desktop program (NAPS2) or talk to a TWAIN/WIA
driver on your machine directly — browsers deliberately block that. This
agent is a tiny local script that *can*, and it exposes just enough of
itself over `http://127.0.0.1:<port>` for the DMS page to call.

It's a plain PowerShell script — **no Python, no Node, nothing to
install** beyond what's already on the PC and NAPS2 itself.

## 1. Prerequisites on the thin client

- **Windows PowerShell 5.1** (built into every Windows 10/11 PC — nothing
  to install) or newer PowerShell 7+.
- **NAPS2** installed (https://www.naps2.com/) with the scanner's TWAIN/WIA
  driver already set up and tested working inside the NAPS2 GUI at least
  once (open NAPS2 → Manage Devices → confirm the scanner shows up and
  scans a page).

## 2. Configure it

Run it once (see step 3) — that creates **`agent_config.json`** next to the
script with all the defaults below, ready to edit. You don't need to touch
the `.ps1` file at all; just edit the JSON and restart the agent.

| Setting | Meaning |
|---|---|
| `NAPS2_CONSOLE_PATH` | Path to `naps2.console.exe` on this PC |
| `SCANNER_DEVICE_NAME` | Exact device name from NAPS2 → Manage Devices — **this is the constant to change scanners** |
| `SCANNER_DRIVER` | Usually `twain` |
| `SCAN_PROFILE_NAME` | Used only if `SCANNER_DEVICE_NAME` is left blank (`""`) |
| `PORT` | Must match `SCAN_AGENT_PORT` in the server's `includes/config.php` |
| `ALLOWED_ORIGIN` | Set to your DMS site's exact URL, e.g. `https://dms.dha.example`, instead of `*`, once you've confirmed it works |
| `SCAN_SAVE_DIR` | Where scanned PDFs are permanently kept on **this PC**, in addition to being uploaded to the server. Defaults to `~/DHA_Scans` (that user's profile folder). Each scan batch gets its own sub-folder so nothing is ever overwritten. |
| `SCAN_RETENTION_DAYS` | How many days a scan's local copy is kept before the agent deletes it automatically. Default `30`. Set to `0` (or negative) to keep everything forever. |
| `SCAN_RETENTION_CHECK_HOURS` | Roughly how often the agent re-checks for expired scans while running. Default `6`. |

To point the agent at a different scanner later, just edit
`SCANNER_DEVICE_NAME` in `agent_config.json` and restart the agent —
nothing else needs to change.

## 3. Run it

PowerShell blocks running unsigned scripts by default on some machines.
Quick test, from a PowerShell window in this folder:

```powershell
powershell -ExecutionPolicy Bypass -File .\scan_agent.ps1
```

You should see:

```
[scan-agent] Config file: C:\path\to\scan-agent\agent_config.json
[scan-agent] Listening on http://127.0.0.1:51823
[scan-agent] NAPS2 console: C:\Program Files\NAPS2\naps2.console.exe (found)
[scan-agent] Device: Canon DR-3010C TWAIN
[scan-agent] Saving scans permanently to: C:\Users\<you>\DHA_Scans (kept 30 day(s))
```

Leave that window open and load the DMS site's Scan page in the browser —
"Start Scan" now scans on this PC. Every scan is uploaded to the DMS server
as normal **and** kept as a local copy under `SCAN_SAVE_DIR`
(`C:\Users\<you>\DHA_Scans` by default) for `SCAN_RETENTION_DAYS` days —
adjust or disable that in `agent_config.json` if 30 days isn't right for
your setup.

## 4. Make it start automatically (Windows)

So users don't have to open a terminal every morning:

**Option A — Task Scheduler (recommended)**
1. Task Scheduler → Create Task
2. General: "Run only when user is logged on" (needs the logged-in user's
   session for the scanner driver to work).
3. Trigger: "At log on"
4. Action: Start a program →
   Program: `powershell.exe`
   Arguments: `-WindowStyle Hidden -ExecutionPolicy Bypass -File "C:\path\to\scan_agent.ps1"`
5. Save.

**Option B — Startup folder**
Put a `.lnk` shortcut in `shell:startup` with:
- Target: `powershell.exe -WindowStyle Hidden -ExecutionPolicy Bypass -File "C:\path\to\scan_agent.ps1"`

Either way, package NAPS2 + this script + the scheduled task into your
standard thin-client image so new machines pick it up automatically.

If your organization enforces a stricter script execution policy, ask IT to
either sign this script or add a policy exception, rather than leaving
`-ExecutionPolicy Bypass` in place org-wide.

## 5. Firewall

The agent only binds to `127.0.0.1`, so it's not reachable from the network
at all — no firewall rule should be needed. If your organization's endpoint
security still flags a local process opening a local port, allow
`powershell.exe` (or `pwsh.exe`, if using PowerShell 7) to listen on `PORT`
for local connections only.

## Troubleshooting

- **Browser shows "Could not reach the local scan agent"** — the agent
  isn't running, is on the wrong port, or `ALLOWED_ORIGIN` doesn't match
  the page's URL exactly (scheme + host + port, no trailing slash).
- **"NAPS2 not found"** — fix `NAPS2_CONSOLE_PATH` in `agent_config.json`.
- **"Could not connect to the scanner"** — same troubleshooting as before:
  confirm the scanner is on, its driver is installed on *this* PC, and it
  scans successfully from the NAPS2 GUI directly.
- **Script won't run at all / "running scripts is disabled"** — that's
  Windows' execution policy. Use the `-ExecutionPolicy Bypass` flag shown
  above (it only affects this one launch, not the machine's global
  policy), or have IT sign the script.
- **"Access is denied" starting the listener** — rare for a loopback-only
  (`127.0.0.1`) binding, but if it happens, run once as Administrator:
  `netsh http add urlacl url=http://127.0.0.1:51823/ user=Everyone`
  (matching whatever `PORT` you set), then run the agent normally afterward.

## Note

This script hasn't been run end-to-end against a real NAPS2 install and
scanner yet — it was written and reviewed carefully, but PowerShell wasn't
available to execute in the environment this was built in. Please smoke-test
it on one real thin client (a plain scan, a Stop mid-scan, and a check that
`agent_config.json` is created correctly) before rolling it out further.
