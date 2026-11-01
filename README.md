# ServiceNow mock for PAC Sync

A local stand-in for the ServiceNow side described in *PAC Sync – ServiceNow Implementation Requirements*:
the four PAC tables, the **Request PAC exception** catalog item, the approval workflow, validation rules V1–V12,
and a Table API compatible with `pac-sync` (`source: api`).

PHP 8.4 built-in server + SQLite. No web server, no database server, no internet access needed.
For local development only: no CSRF protection, demo passwords, and it must never be exposed on a network
(non-local clients get 403, see "Network exposure").

## Run

```powershell
cd snow-mock
powershell -ExecutionPolicy Bypass -File .\start.ps1             # background, http://127.0.0.1:8090
powershell -ExecutionPolicy Bypass -File .\start.ps1 -WaitReady  # background, returns once it answers (15 s max)
powershell -ExecutionPolicy Bypass -File .\start.ps1 -Foreground # attached, Ctrl+C to stop
powershell -ExecutionPolicy Bypass -File .\stop.ps1
```

PHP was installed with `winget install PHP.PHP.8.4`. `start.ps1` finds it and loads `pdo_sqlite` and `mbstring`
with `-d` flags, so the global PHP install needs no `php.ini`. The database is created and seeded on first request
in `data/snow.sqlite`. Delete that file (or use **Reset demo data** as admin) to start over.

## Demo users

Switch users with the **Acting as** menu (top right), no password needed in the UI.

| User | Name | Role in the flow |
| --- | --- | --- |
| `jdoe` | Jane Doe | Requester, member of App Team A |
| `mmanager` | Mark Manager | Manager of App Team A and Workplace Team: approval step 1 |
| `nsec` | Nora Security | PAC Approvers group: approval step 2 |
| `cab` | Carl Board | CAB group: approval step 3 for global rules |
| `nadmin` | Nina Network | Network Team, role `u_pac_admin`: edits rules, profiles and changes in the backend |
| `admin` | System Administrator | Everything, including users, groups and regions |
| `svc_pac_sync` | PAC Sync Integration | API only, role `u_pac_sync_integration` |

API passwords are in `config.php` (`admin/admin`, `svc_pac_sync/svc_pac_sync_pw`). OAuth client: `pac-sync` / `pac-sync-secret`.

## What is inside

| URL | Page |
| --- | --- |
| `/` | Portal home: catalog tile, my requests, my approvals, rules by state |
| `/catalog/pac` | Catalog item: new, modify, extend or retire a rule, with client-side show/hide and server-side validation |
| `/requests`, `/ritm/{sys_id}` | Requested items with approval timeline, rule summary and activity |
| `/approvals` | Approve or reject (a comment is required to reject) |
| `/list/{table}` | Backend list view with encoded-query filter, sorting and paging |
| `/form/{table}/{sys_id}` | Backend form with related lists and field-level audit history |
| `/pacsync` | What PAC Sync did per region: latest deployed and staged ZIA version, last run status and summary, rules marked failed, a "Not activated" banner |
| `/integration` | Credentials, ACLs and curl examples |
| `/oauth_token.do`, `/api/now/table/{table}` | OAuth and Table API |

Seed data covers every rule state: deployed, approved, future-dated, expired, retire requested, plus one open request
waiting for Mark Manager.

## Workflow

1. Jane submits the catalog item. The rule is created in `pending_approval`, so V1–V12 run immediately and errors show on the form.
2. Approvals run in sequence: owner group manager, then Network security, then CAB for global rules. A rejection closes the request as `closed_incomplete` and moves the rule to `rejected`.
3. On final approval the rule becomes `approved`. For modify or extend, the old rule becomes `retire_requested`. For retire, the target rule becomes `retire_requested`.
4. PAC Sync deploys and writes back: `approved → deployed`, `retire_requested → retired`, expired `→ retired`. The request then closes as `closed_complete`.

Business rules apply to the UI and the API alike. The integration account can change only `u_deploy_*` and the allowed
`u_state` transitions. Everyone except PAC admins changes rules only through the catalog item.

**Separation of duties**, admins included: nobody approves their own request or two steps of the same request, and
an approval only moves from `requested` to `approved` or `rejected`. Who approved is recorded once in `u_approved_by`.
The routing fields (`sysapproval`, `approver`, `u_approval_group`) are read-only after creation. PAC admins are trusted
operators: in the backend they can still create or move a rule straight to `approved`, outside the catalog flow.

## Use with PAC Sync

The quickest path is `..\pac-sync\start-poc.cmd` (see the PAC Sync README, "Quick start"): it starts this mock,
writes the credentials and runs PAC Sync. By hand:

```powershell
cd ..\pac-sync
.venv\Scripts\pac-sync setup --profile mock --non-interactive   # once: config\config.yaml + .env (demo OAuth client)
.venv\Scripts\pac-sync --config config\config.mock.yaml doctor
.venv\Scripts\pac-sync --config config\config.mock.yaml run
```

`config/config.demo.yaml` points the same mock at a real ZIA tenant (prefixed PAC files, see the PAC Sync README, "Demo on a real tenant").

`config/config.mock.yaml` is local only: it reads this mock over OAuth, deploys to a **simulated ZIA**
(`zscaler.target: simulated`, files in `..\pac-sync\sim-zia\`), keeps the history in `..\pac-sync\pac-history-mock\`
and never pushes. EMEA and APAC are `auto`, NA is `stage`; the first run creates the three PAC files in the simulator.
The credentials come from `..\pac-sync\.env` (`SNOW_CLIENT_ID=pac-sync`, `SNOW_CLIENT_SECRET=pac-sync-secret`), so no
`$env:` exports are needed.

`/pacsync` (menu "PAC Sync") reads only `u_pac_deployment`, `u_pac_region` and `u_pac_rule`: per region the newest deployed (or rolled back) and staged
version PAC Sync reported, its last run and the first line of `u_summary` (the ZIA commit message), plus the rules with `u_deploy_status=failed`
and their `u_deploy_message`. A red banner appears when the newest row says "not activated" (PAC Sync saved versions in ZIA but did not
activate them because other changes were pending). The versions are PAC Sync's report, not a live read of ZIA.

After a run, the request page (`/ritm/{sys_id}`) shows the **PAC Sync deployment** step from the rule's
`u_deploy_status`: Failed with `u_deploy_message`, Staged (waiting for `pac-sync promote`), or Deployed with the ZIA
version, plus the `u_pac_deployment` rows of the rule's regions since the request was approved.

## Network exposure

`public/index.php` answers `403` to every client that is not loopback (`127.0.0.0/8`, `::1`) before any routing.
Add clients only if you must, with `allowed_clients` in `config.php` or `SNOW_MOCK_ALLOWED_CLIENTS` (comma-separated
IPv4 CIDRs or addresses). `start.ps1` refuses a non-loopback `-Bind` unless `-AllowRemote` is given.
`php tests/client_guard_test.php` (with the flags from `tools/find-php.ps1`) checks the guard.

## Backups

```powershell
powershell -ExecutionPolicy Bypass -File .\backup.ps1            # snapshot + table exports, commit, push
powershell -ExecutionPolicy Bypass -File .\backup.ps1 -Keep 30 -NoPush
```

Backups go to the `pac-history` repository (private GitHub repo `cheikhnorris/pac-history`, local clone at
`..\pac-sync\pac-history`). PAC Sync shares that repository, so `backup.ps1` takes PAC Sync's lock file
`<repo>\.pac-sync.lock` while it runs: it waits up to 60 s (`-LockWaitSeconds`) for a running pac-sync, then fails,
and a pac-sync run that starts during a backup stops with "another run holds ...lock". The backup stages and commits
`backups\` only; PAC Sync commits `regions\` only, so neither can sweep up the other's files.

- `backups/snow-mock/db/snow-<UTC timestamp>.sqlite` is a consistent snapshot, safe while the server runs. OAuth tokens are removed. The newest 14 are kept.
- `backups/snow-mock/tables/<table>.json` holds readable exports with password hashes removed, so `git log -p` shows every data change.

To restore, stop the server, copy a snapshot over `data\snow.sqlite`, then start it again.

## Table API support

`GET` list and single record, `POST`, `PATCH`/`PUT`, `DELETE`. Parameters: `sysparm_query` (`=`, `!=`, `IN`, `NOT IN`,
`LIKE`, `NOT LIKE`, `STARTSWITH`, `ENDSWITH`, `<`, `<=`, `>`, `>=`, `ISEMPTY`, `ISNOTEMPTY`, `^OR`, `^NQ`, `ORDERBY`,
`ORDERBYDESC`), `sysparm_fields`, `sysparm_limit`, `sysparm_offset`, `sysparm_display_value` (`true`/`false`/`all`),
`sysparm_exclude_reference_link`. Responses carry `X-Total-Count`. Errors use the ServiceNow shape: `401` for no auth,
`403` for an ACL failure or a business rule abort, `404` for no record. Dot-walking is not supported.

## Layout

```
config.php            instance name, OAuth clients, seed passwords, allowed_clients
public/index.php      front controller / router for php -S (loopback guard first)
src/client_guard.php  loopback / allowed_clients check
tests/                client_guard_test.php
public/assets/        style.css, catalog.js (catalog client scripts)
src/schema.php        table dictionary (fields, types, choices, references)
src/db.php, repo.php  SQLite storage, CRUD with business rules and sys_audit
src/query.php         encoded query -> SQL
src/validation.php    V1-V12 (same checks as pac_sync/normalizer.py)
src/business_rules.php, workflow.php   state transitions, field security, catalog submit, approvals
src/api.php           Table API, OAuth, ACLs
src/ui/               portal pages and backend list/form views
```
