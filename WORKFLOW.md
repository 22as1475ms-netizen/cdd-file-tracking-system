# Workflow Guide

This document explains the current routed-document workflow and the status meanings that future maintainers should preserve unless the business rules are intentionally changing.

**Roles**

- `SUPER_ADMIN`
  Global admin workflow access.
- `SECTION_ADMIN`
  Routes files to section staff inside the same section.
- `SECTION_STAFF`
  Receives routed files, performs the requested action, and marks the route complete.

**High-Level Lifecycle**

1. A document is uploaded into the routed workflow.
2. A section admin or admin shares/routes the file to the intended receiver.
3. The receiver opens the file and checks the route lifecycle panel.
4. If the receiver is section staff, they follow the `Actions to be taken` note.
5. Once the work is done, the receiver marks the route complete.
6. The route is marked `COMPLETED` for everyone in that routed chain.

**Important Current Rule**

Completed routes do not return the file to admin.

The file stays with the final routed holder, and the visible update for admin/super admin is that the route is completed.

**Role-by-Role Behavior**

`SUPER_ADMIN`

- Can upload and manage routed files.
- Can see broad workflow state across users.
- Can route files into the workflow.

`SECTION_ADMIN`

- Sees files routed to their section.
- Can route a file to section staff in the same section.
- Can add `Actions to be taken` when sending a file to section staff.

`SECTION_STAFF`

- Sees routed files assigned to them.
- Cannot forward routed files onward.
- Can view the file.
- Can mark the route complete.

**Actions to Be Taken**

Section Admin can attach an instruction note when routing a file to Section Staff.

That note is stored in the route note with the tagged prefix:

`Actions to be taken:`

The UI parses and displays that instruction separately in the route lifecycle panel and the staff dashboard summary.

**Route Completion Meaning**

When a route is completed:

- `routing_status` becomes `COMPLETED`
- `route_outcome` becomes `COMPLETED`
- the document stays with the last routed holder
- earlier routed participants keep route visibility
- dashboards should show the route as completed, not returned

**Status Meanings**

These values appear across controllers, models, services, and views, so changes must be coordinated carefully.

- `AVAILABLE`
  Not actively in a routed handoff.
- `PENDING_SHARE_ACCEPTANCE`
  Share/routing action has been issued and is waiting.
- `SHARE_ACCEPTED`
  The routed recipient accepted the share.
- `SHARE_DECLINED`
  The share was declined.
- `PENDING_REVIEW_ACCEPTANCE`
  Waiting for review acceptance.
- `IN_REVIEW`
  Actively under review.
- `REVIEW_ASSIGNMENT_DECLINED`
  Review assignment was declined.
- `APPROVED`
  Review/approval flow reached approved state.
- `REJECTED`
  Review/approval flow reached rejected state.
- `COMPLETED`
  Routed lifecycle is finished and remains with the final holder.

**Where to Change Workflow Logic**

- Request handlers and role checks:
  [app/controllers/DocumentController.php](/c:/xampp/htdocs/cddfts/app/controllers/DocumentController.php)
  [app/controllers/DocumentShareController.php](/c:/xampp/htdocs/cddfts/app/controllers/DocumentShareController.php)
- Main routing behavior:
  [app/services/DocumentShareService.php](/c:/xampp/htdocs/cddfts/app/services/DocumentShareService.php)
- Review behavior:
  [app/services/DocumentReviewService.php](/c:/xampp/htdocs/cddfts/app/services/DocumentReviewService.php)
- Routed dashboards and inbox queries:
  [app/controllers/DashboardController.php](/c:/xampp/htdocs/cddfts/app/controllers/DashboardController.php)
  [app/controllers/AdminController.php](/c:/xampp/htdocs/cddfts/app/controllers/AdminController.php)
  [app/models/Document.php](/c:/xampp/htdocs/cddfts/app/models/Document.php)
- Route timeline/history:
  [app/models/DocumentRoute.php](/c:/xampp/htdocs/cddfts/app/models/DocumentRoute.php)

**Change Safety Checklist**

Before changing workflow logic, verify:

1. Who is allowed to route the file?
2. Who is allowed to complete the route?
3. Whether the file should stay with the final holder or move again
4. Whether dashboards and lifecycle panels still show the same meaning
5. Whether existing tests covering routed visibility, completion, and recipient state still pass

**Recommended Tests to Review**

- [tests/DocumentShareWorkflowTest.php](/c:/xampp/htdocs/cddfts/tests/DocumentShareWorkflowTest.php)
- [tests/AdminUserRoutingViewDataTest.php](/c:/xampp/htdocs/cddfts/tests/AdminUserRoutingViewDataTest.php)
- [tests/DashboardRoutingPriorityTest.php](/c:/xampp/htdocs/cddfts/tests/DashboardRoutingPriorityTest.php)
