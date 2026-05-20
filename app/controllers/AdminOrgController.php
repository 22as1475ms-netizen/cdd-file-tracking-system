<?php
require_once __DIR__ . '/../helpers/view.php';
require_once __DIR__ . '/../helpers/redirect.php';
require_once __DIR__ . '/../helpers/http.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../models/Organization.php';
require_once __DIR__ . '/../models/User.php';

function admin_organizations(): void {
    global $pdo;
    $user = $_SESSION['user'] ?? null;
    if (!$user || !in_array(strtoupper((string)($user['role'] ?? '')), ['SUPER_ADMIN', 'ADMIN'], true)) {
        redirect('/login');
        return;
    }

    $organizations = Organization::all($pdo);
    view('admin/organizations', [
        'organizations' => $organizations,
        'user' => $user
    ]);
}

function admin_organization_chart(): void {
    // Organization chart view has been removed. Redirect to the users admin page.
    redirect('/admin/users');
}

function admin_update_organization_chart(): void {
    global $pdo;
    $user = $_SESSION['user'] ?? null;
    if (!$user || !in_array(strtoupper((string)($user['role'] ?? '')), ['SUPER_ADMIN', 'ADMIN'], true)) {
        http_response_code(403);
        die(json_encode(['error' => 'Unauthorized']));
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';

    header('Content-Type: application/json');

    if ($action === 'add_section') {
        $orgId = (int)($input['org_id'] ?? 0);
        $name = trim($input['name'] ?? '');
        $description = trim($input['description'] ?? '');
        
        if (!$name) {
            http_response_code(400);
            echo json_encode(['error' => 'Section name is required']);
            return;
        }

        $sectionId = Section::create($pdo, $orgId, $name, $description, $user['id']);
        echo json_encode(['success' => true, 'section_id' => $sectionId]);
        return;
    }

    if ($action === 'update_chief') {
        $sectionId = (int)($input['section_id'] ?? 0);
        $chiefId = (int)($input['chief_id'] ?? 0) ?: null;
        
        if (!$sectionId) {
            http_response_code(400);
            echo json_encode(['error' => 'Section ID is required']);
            return;
        }

        $section = Section::findById($pdo, $sectionId);
        if (!$section) {
            http_response_code(404);
            echo json_encode(['error' => 'Section not found']);
            return;
        }

        Section::update($pdo, $sectionId, $section['name'], $section['description'] ?? '', $chiefId);
        
        // Update user role if chief assigned
        if ($chiefId) {
            TeamMember::updateRole($pdo, $sectionId, $chiefId, 'SECTION_CHIEF');
        }
        
        echo json_encode(['success' => true]);
        return;
    }

    if ($action === 'remove_member') {
        $sectionId = (int)($input['section_id'] ?? 0);
        $userId = (int)($input['user_id'] ?? 0);
        
        if (!$sectionId || !$userId) {
            http_response_code(400);
            echo json_encode(['error' => 'Section and user IDs are required']);
            return;
        }

        TeamMember::removeMember($pdo, $sectionId, $userId);
        echo json_encode(['success' => true]);
        return;
    }

    if ($action === 'update_member') {
        $sectionId = (int)($input['section_id'] ?? 0);
        $userId = (int)($input['user_id'] ?? 0);
        $role = strtoupper(trim((string)($input['role'] ?? 'MEMBER')));
        $delegateUserId = (int)($input['delegate_user_id'] ?? 0);
        $delegateNote = trim((string)($input['delegate_note'] ?? ''));

        if ($sectionId <= 0 || $userId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Section and user IDs are required']);
            return;
        }

        $section = Section::findById($pdo, $sectionId);
        if (!$section) {
            http_response_code(404);
            echo json_encode(['error' => 'Section not found']);
            return;
        }

        if (!TeamMember::exists($pdo, $sectionId, $userId)) {
            http_response_code(404);
            echo json_encode(['error' => 'Member not found in this section']);
            return;
        }

        if (!in_array($role, ['MEMBER', 'TEAM_LEAD'], true)) {
            $role = 'MEMBER';
        }

        $delegateId = null;
        if ($delegateUserId > 0) {
            if ($delegateUserId === $userId) {
                http_response_code(422);
                echo json_encode(['error' => 'A member cannot delegate approval to themselves.']);
                return;
            }
            if (!TeamMember::exists($pdo, $sectionId, $delegateUserId)) {
                http_response_code(422);
                echo json_encode(['error' => 'Delegate must be an existing member of the same section.']);
                return;
            }

            $delegateUser = User::findById($pdo, $delegateUserId);
            if (!$delegateUser || strtoupper((string)($delegateUser['status'] ?? 'DISABLED')) !== 'ACTIVE') {
                http_response_code(422);
                echo json_encode(['error' => 'Delegate must be an active account.']);
                return;
            }

            $delegateId = $delegateUserId;
        }

        TeamMember::updateMember($pdo, $sectionId, $userId, $role, $delegateId, $delegateNote !== '' ? $delegateNote : null);
        echo json_encode(['success' => true]);
        return;
    }

    if ($action === 'delete_section') {
        $sectionId = (int)($input['section_id'] ?? 0);
        
        if (!$sectionId) {
            http_response_code(400);
            echo json_encode(['error' => 'Section ID is required']);
            return;
        }

        Section::delete($pdo, $sectionId);
        echo json_encode(['success' => true]);
        return;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Invalid action']);
}

?>
