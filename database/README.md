# Database

Public demo database for Krishi Sathi Research.

- **Database name:** `krishi_sathi_research_demo`
- **Engine:** MySQL / MariaDB (utf8mb4)
- **Tables:** 21 (schema only — no production rows)

## Files

| File | Purpose |
|---|---|
| `schema.sql` | Table structure (21 tables, constraints). **No data.** |
| `demo_seed.sql` | Fictional demo data (research users/roles, farmers, participants, farm profiles, interviews, observations, consent, problem rankings, responses). |

## Import

```bash
mysql -u root -p < database/schema.sql
mysql -u root -p krishi_sathi_research_demo < database/demo_seed.sql
```

`schema.sql` creates the database and drops/recreates tables, so it is safe to re-run.

## Demo credentials

| Role | Username | Password |
|---|---|---|
| Research Lead | `demo_research` | `DemoResearch@123` |
| Editor | `demo_editor` | `DemoEditor@123` |
| Contributor | `demo_contributor` | `DemoContributor@123` |
| Viewer | `demo_viewer` | `DemoViewer@123` |

Passwords are stored as `password_hash()` bcrypt hashes.

## Notes

All farmers, participants, interviews, observations and consent records are
**fictional**. No real research participant data is included.
