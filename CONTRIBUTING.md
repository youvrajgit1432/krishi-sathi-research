# Contributing

## Ground rules
- Never commit real research/participant data. All demo data must be fictional.
- Never commit `.env`, `secret.php`, uploads, logs or media.
- Run PHP lint on changed files: `php -l path/to/file.php`.
- Rebuild the demo database from `database/schema.sql` + `database/demo_seed.sql`
  and confirm login and the main workflows still work.

## Pull requests
Describe the change, list files changed, and note the checks you ran.
