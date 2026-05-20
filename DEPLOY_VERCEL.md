# Vercel Deployment

This project can now run on Vercel with the PHP community runtime.

## TiDB Cloud Starter Checklist

Use this checklist if you want a free-tier deployment with Vercel plus TiDB Cloud Starter.

1. Create a `TiDB Cloud Starter` cluster in TiDB Cloud.
2. Create a database for this app, such as `cdd_file_tracking_system`.
3. Copy the cluster connection details from TiDB Cloud.
4. Import this repository into Vercel and create a project.
5. In Vercel, open `Project Settings -> Environment Variables`.
6. Add the variables from the example block below.
7. Deploy the project.
8. Open the Vercel URL and verify the login page loads.

### Recommended Vercel Environment Variables for TiDB

Replace the placeholder values below with your TiDB Cloud values and final Vercel domain.

```env
APP_SECRET=replace-with-a-long-random-secret
APP_URL=https://your-project-name.vercel.app
APP_BASE_PATH=

DB_HOST=your-tidb-host
DB_PORT=4000
DB_NAME=cdd_file_tracking_system
DB_USER=your-tidb-user
DB_PASS=your-tidb-password
DB_CHARSET=utf8mb4

DB_AUTO_BOOTSTRAP_SCHEMA=1
DB_AUTO_UNIFY_ROUTED_STORAGE=0
DB_ENABLE_FULLTEXT=0

SESSION_DRIVER=database
STORAGE_DRIVER=database

MAX_UPLOAD_BYTES_USER=4194304
MAX_UPLOAD_BYTES_ADMIN=4194304
SESSION_NAME=CDDFILETRACKINGSYSTEMSESSID
SESSION_SAMESITE=Lax
SESSION_TIMEOUT_MINUTES=45
```

### Notes for TiDB

- `DB_PORT=4000` is the usual TiDB SQL port.
- Keep `APP_BASE_PATH` empty on Vercel unless you intentionally deploy behind a subpath.
- Set `DB_ENABLE_FULLTEXT=0` for TiDB compatibility. The app will keep document search working through its `LIKE` fallback.
- This project stores uploaded files in the database when `STORAGE_DRIVER=database`, so TiDB storage usage will grow with document uploads.
- TiDB Cloud Starter is generous for free storage, but uploads still count against your database usage.

## Required Environment Variables

- `APP_SECRET`
- `APP_URL`
- `APP_BASE_PATH`
- `DB_HOST`
- `DB_PORT`
- `DB_NAME`
- `DB_USER`
- `DB_PASS`

## Recommended Environment Variables

- `SESSION_DRIVER=database`
- `STORAGE_DRIVER=database`
- `DB_AUTO_BOOTSTRAP_SCHEMA=1`
- `DB_AUTO_UNIFY_ROUTED_STORAGE=0`
- `DB_ENABLE_FULLTEXT=0` when deploying to TiDB Cloud regions that do not support the app's document search `FULLTEXT` index
- `MAX_UPLOAD_BYTES_USER=4194304`
- `MAX_UPLOAD_BYTES_ADMIN=4194304`

## Notes

- On Vercel, the app automatically defaults to database-backed sessions and database-backed file storage if `SESSION_DRIVER` and `STORAGE_DRIVER` are not set.
- If your hosted MySQL-compatible provider does not support the `documents` search `FULLTEXT` index, set `DB_ENABLE_FULLTEXT=0`; the app will continue using its `LIKE`-based search fallback.
- `APP_SECRET` must be provided on Vercel. The app will not try to persist a local secret there.
- Static assets from `public/` are served by Vercel before the catch-all rewrite runs.
- Document files, avatars, and chat attachments can now persist in MySQL, which avoids dependence on Vercel's read-only filesystem.
- Function-based uploads on Vercel should stay under roughly 4 MB unless you later move to direct-to-blob client uploads.
