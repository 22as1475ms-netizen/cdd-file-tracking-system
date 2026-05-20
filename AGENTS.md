# CDD-File-Tracking-System Agent Guidance

## Project Shape

- This is a custom procedural PHP app. The main web entry point is [public/index.php](public/index.php); [index.php](index.php) and [api/index.php](api/index.php) are thin wrappers.
- Controllers are plain functions in [app/controllers](app/controllers) and are loaded from the route map in [public/index.php](public/index.php).
- Models are static PDO helpers in [app/models](app/models). Shared helpers live in [app/helpers](app/helpers), middleware in [app/middleware](app/middleware), and services in [app/services](app/services).
- Views are rendered through the helper in [app/helpers/view.php](app/helpers/view.php); keep the existing PHP-rendered structure intact.

## Working Rules

- Preserve the procedural style and existing helper functions instead of introducing a framework pattern.
- Keep changes compatible with both local filesystem storage and Vercel/database-backed storage. The environment and runtime rules are defined in [app/config/app.php](app/config/app.php) and [DEPLOY_VERCEL.md](DEPLOY_VERCEL.md).
- When changing uploads, sessions, routing, or document persistence, verify both the local defaults and the Vercel assumptions described in [DEPLOY_VERCEL.md](DEPLOY_VERCEL.md).
- Prefer small, targeted edits that fit the surrounding controller/model/service pattern.

## Tests

- The project uses a custom PHP test runner: [tests/run.php](tests/run.php).
- Test setup resets the `cdd_file_tracking_system_test` database and test storage paths in [tests/bootstrap.php](tests/bootstrap.php), so behavior changes should usually be covered by a test in [tests](tests).
- Add or update a focused test whenever behavior changes in controllers, models, services, or storage/session handling.

## Useful Docs

- [README.md](README.md)
- [DEPLOY_VERCEL.md](DEPLOY_VERCEL.md)