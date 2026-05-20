# CDD-File-Tracking-System

CDD-File-Tracking-System is a custom procedural PHP document-routing system for the CDD workflow. It handles account management, PDF uploads, document routing, section handoff, route completion, notifications, and audit logging.

**App Shape**

- Entry point: [public/index.php](/c:/xampp/htdocs/cddfts/public/index.php)
- Thin wrappers: [index.php](/c:/xampp/htdocs/cddfts/index.php), [api/index.php](/c:/xampp/htdocs/cddfts/api/index.php)
- Controllers: [app/controllers](/c:/xampp/htdocs/cddfts/app/controllers)
- Models: [app/models](/c:/xampp/htdocs/cddfts/app/models)
- Services: [app/services](/c:/xampp/htdocs/cddfts/app/services)
- Views: [app/views](/c:/xampp/htdocs/cddfts/app/views)
- Config/bootstrap: [app/config/app.php](/c:/xampp/htdocs/cddfts/app/config/app.php), [app/config/database.php](/c:/xampp/htdocs/cddfts/app/config/database.php)
- Tests: [tests](/c:/xampp/htdocs/cddfts/tests)

**Architecture Map**

The app is procedural, but the responsibilities are fairly consistent:

- `public/index.php`
  Defines routes and loads the controller function for the current request.
- `app/controllers/*`
  Handle request flow, authorization, redirects, and view composition.
- `app/models/*`
  Hold static PDO queries and low-level database access.
- `app/services/*`
  Hold cross-cutting workflow logic such as document storage, sharing, review, and access checks.
- `app/views/*`
  Render the HTML UI with PHP templates.

**Main Runtime Flows**

1. Authentication

- Route entry: [public/index.php](/c:/xampp/htdocs/cddfts/public/index.php)
- Controller: [app/controllers/AuthController.php](/c:/xampp/htdocs/cddfts/app/controllers/AuthController.php)
- Services: [app/services/AuthService.php](/c:/xampp/htdocs/cddfts/app/services/AuthService.php)
- Middleware: [app/middleware/require_login.php](/c:/xampp/htdocs/cddfts/app/middleware/require_login.php), [app/middleware/require_role.php](/c:/xampp/htdocs/cddfts/app/middleware/require_role.php)

2. Document Upload

- Controller: [app/controllers/DocumentController.php](/c:/xampp/htdocs/cddfts/app/controllers/DocumentController.php)
- Model: [app/models/Document.php](/c:/xampp/htdocs/cddfts/app/models/Document.php)
- Service: [app/services/DocumentService.php](/c:/xampp/htdocs/cddfts/app/services/DocumentService.php)
- Storage adapter: [app/services/StorageService.php](/c:/xampp/htdocs/cddfts/app/services/StorageService.php)

3. Document Routing / Sharing

- Controller entry: [app/controllers/DocumentShareController.php](/c:/xampp/htdocs/cddfts/app/controllers/DocumentShareController.php)
- Workflow service: [app/services/DocumentShareService.php](/c:/xampp/htdocs/cddfts/app/services/DocumentShareService.php)
- Route history: [app/models/DocumentRoute.php](/c:/xampp/htdocs/cddfts/app/models/DocumentRoute.php)
- Permissions: [app/models/Permission.php](/c:/xampp/htdocs/cddfts/app/models/Permission.php)

4. Review Workflow

- Controller: [app/controllers/DocumentReviewController.php](/c:/xampp/htdocs/cddfts/app/controllers/DocumentReviewController.php)
- Service: [app/services/DocumentReviewService.php](/c:/xampp/htdocs/cddfts/app/services/DocumentReviewService.php)
- Model: [app/models/DocumentReview.php](/c:/xampp/htdocs/cddfts/app/models/DocumentReview.php)

5. Dashboards

- Staff dashboard: [app/controllers/DashboardController.php](/c:/xampp/htdocs/cddfts/app/controllers/DashboardController.php), [app/views/dashboard/user.php](/c:/xampp/htdocs/cddfts/app/views/dashboard/user.php)
- Admin dashboard: [app/controllers/AdminController.php](/c:/xampp/htdocs/cddfts/app/controllers/AdminController.php), [app/views/admin/dashboard.php](/c:/xampp/htdocs/cddfts/app/views/admin/dashboard.php)
- Shared routed-query source: [app/models/Document.php](/c:/xampp/htdocs/cddfts/app/models/Document.php)

**Current Routing Lifecycle**

The active routed-file behavior is documented in [WORKFLOW.md](/c:/xampp/htdocs/cddfts/WORKFLOW.md). Read that first before changing any route-completion, section-admin, or section-staff logic.

**Important Business Rules**

- Section Admin can route files to Section Staff inside the same section.
- Section Staff cannot forward routed files onward.
- Section Staff can only open the file and mark the route complete.
- When a route is completed, the file stays with the final routed holder.
- Admin and Super Admin see that state as `route completed`; the file is not returned to admin automatically.

**Storage Notes**

- Local/default runtime prefers filesystem document storage.
- Vercel defaults to database-backed sessions and database-backed file storage unless overridden.
- Storage handling is centralized in [app/services/StorageService.php](/c:/xampp/htdocs/cddfts/app/services/StorageService.php).
- Deployment details are in [DEPLOY_VERCEL.md](/c:/xampp/htdocs/cddfts/DEPLOY_VERCEL.md).

**Search and Performance Notes**

- Routed dashboard queries are intentionally paged and centralized in the `Document` model.
- Document/folder search should prefer SQL-backed filtering over PHP array scanning.
- If routed dashboard filters or sorts change, revisit related indexes in [app/config/database.php](/c:/xampp/htdocs/cddfts/app/config/database.php).

**Testing**

- Test runner: [tests/run.php](/c:/xampp/htdocs/cddfts/tests/run.php)
- Bootstrap/reset logic: [tests/bootstrap.php](/c:/xampp/htdocs/cddfts/tests/bootstrap.php)
- Add focused tests for workflow changes, especially routing, review, storage, and visibility rules.

**Recommended Maintainer Reading Order**

1. [README.md](/c:/xampp/htdocs/cddfts/README.md)
2. [WORKFLOW.md](/c:/xampp/htdocs/cddfts/WORKFLOW.md)
3. [DEPLOY_VERCEL.md](/c:/xampp/htdocs/cddfts/DEPLOY_VERCEL.md)
4. [public/index.php](/c:/xampp/htdocs/cddfts/public/index.php)
5. [app/controllers/DocumentController.php](/c:/xampp/htdocs/cddfts/app/controllers/DocumentController.php)
6. [app/controllers/DocumentShareController.php](/c:/xampp/htdocs/cddfts/app/controllers/DocumentShareController.php)
7. [app/services/DocumentShareService.php](/c:/xampp/htdocs/cddfts/app/services/DocumentShareService.php)
