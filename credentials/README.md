# Credentials

This folder stores connection credentials for generated ETL scripts.

- `creds_<processkey>.example.ini` — template (committed to git)
- `creds_<processkey>.ini` — actual credentials (gitignored)

## Format

```ini
[source]
host     = your-source-host
port     = 3306
database = your_database
username = your_user
password = YourPassword

[destination]
host     = your-dest-host
port     = 5432
database = your_dest_database
username = your_dest_user
password = YourDestPassword
```

Never commit `.ini` files with real passwords.
