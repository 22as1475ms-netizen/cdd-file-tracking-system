<?php

class Organization {
    public static function all(PDO $pdo): array {
        $stmt = $pdo->query("SELECT * FROM organizations ORDER BY name");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function findById(PDO $pdo, int $organizationId): ?array {
        $stmt = $pdo->prepare("SELECT * FROM organizations WHERE id = ? LIMIT 1");
        $stmt->execute([$organizationId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

class Section {
    public static function findById(PDO $pdo, int $sectionId): ?array {
        $stmt = $pdo->prepare("SELECT * FROM sections WHERE id = ? LIMIT 1");
        $stmt->execute([$sectionId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function findByOrganization(PDO $pdo, int $organizationId, bool $includeChiefDetails = false): array {
        if ($includeChiefDetails) {
            $stmt = $pdo->prepare("\n                SELECT s.*, u.name AS chief_name, u.email AS chief_email\n                FROM sections s\n                LEFT JOIN users u ON u.id = s.chief_id\n                WHERE s.organization_id = ?\n                ORDER BY s.position_in_chart, s.name\n            ");
        } else {
            $stmt = $pdo->prepare("\n                SELECT s.*\n                FROM sections s\n                WHERE s.organization_id = ?\n                ORDER BY s.position_in_chart, s.name\n            ");
        }

        $stmt->execute([$organizationId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getMembersCount(PDO $pdo, int $sectionId): int {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM team_members WHERE section_id = ?");
        $stmt->execute([$sectionId]);
        return (int)$stmt->fetchColumn();
    }

    public static function getMembers(PDO $pdo, int $sectionId): array {
        $stmt = $pdo->prepare("SELECT u.id, u.name, u.email, u.availability_status, u.availability_note, tm.role, tm.delegate_user_id, tm.delegate_note, tm.joined_at, du.name AS delegate_name, du.email AS delegate_email\n        FROM team_members tm\n        JOIN users u ON tm.user_id = u.id\n        LEFT JOIN users du ON du.id = tm.delegate_user_id\n        WHERE tm.section_id = ?\n        ORDER BY tm.role DESC, u.name");
        $stmt->execute([$sectionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function create(PDO $pdo, int $orgId, string $name, string $description, int $createdBy, ?int $chiefId = null): ?int {
        $stmt = $pdo->prepare("INSERT INTO sections (organization_id, name, description, chief_id, created_by, created_at, updated_at)\n        VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
        $stmt->execute([$orgId, $name, $description, $chiefId, $createdBy]);
        return (int)$pdo->lastInsertId();
    }

    public static function update(PDO $pdo, int $sectionId, string $name, string $description, ?int $chiefId): bool {
        $stmt = $pdo->prepare("UPDATE sections SET name = ?, description = ?, chief_id = ?, updated_at = NOW()\n        WHERE id = ?");
        return $stmt->execute([$name, $description, $chiefId, $sectionId]);
    }

    public static function delete(PDO $pdo, int $sectionId): bool {
        return (bool)$pdo->prepare("DELETE FROM sections WHERE id = ?")->execute([$sectionId]);
    }

    public static function findManagedByChief(PDO $pdo, int $userId): array {
        $stmt = $pdo->prepare("\n            SELECT s.*\n            FROM sections s\n            JOIN team_members tm ON tm.section_id = s.id\n            WHERE tm.user_id = ? AND tm.role = 'SECTION_CHIEF'\n            ORDER BY s.position_in_chart, s.name\n        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// TeamMember Model
class TeamMember {
    public static function findBySection(PDO $pdo, int $sectionId): array {
        $stmt = $pdo->prepare("SELECT tm.*, u.name, u.email, u.availability_status, u.availability_note, du.name AS delegate_name, du.email AS delegate_email FROM team_members tm\n        JOIN users u ON tm.user_id = u.id\n        LEFT JOIN users du ON du.id = tm.delegate_user_id\n        WHERE tm.section_id = ?\n        ORDER BY tm.role DESC, u.name");
        $stmt->execute([$sectionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function findByUser(PDO $pdo, int $userId): array {
        $stmt = $pdo->prepare("SELECT tm.*, s.name as section_name, o.name as org_name, du.name AS delegate_name, du.email AS delegate_email FROM team_members tm\n        JOIN sections s ON tm.section_id = s.id\n        JOIN organizations o ON s.organization_id = o.id\n        LEFT JOIN users du ON du.id = tm.delegate_user_id\n        WHERE tm.user_id = ?\n        ORDER BY s.name");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function addMember(PDO $pdo, int $sectionId, int $userId, string $role, int $addedBy): ?int {
        $stmt = $pdo->prepare("INSERT IGNORE INTO team_members (section_id, user_id, role, delegate_user_id, delegate_note, added_by, joined_at)\n        VALUES (?, ?, ?, NULL, NULL, ?, NOW())");
        $stmt->execute([$sectionId, $userId, $role, $addedBy]);
        return (int)$pdo->lastInsertId();
    }

    public static function updateMember(PDO $pdo, int $sectionId, int $userId, string $role, ?int $delegateUserId, ?string $delegateNote = null): bool {
        $cleanRole = strtoupper(trim($role));
        if (!in_array($cleanRole, ['MEMBER', 'TEAM_LEAD'], true)) {
            $cleanRole = 'MEMBER';
        }
        $cleanDelegateNote = trim((string)$delegateNote);
        $delegateId = $delegateUserId !== null && $delegateUserId > 0 ? $delegateUserId : null;
        $stmt = $pdo->prepare("UPDATE team_members SET role = ?, delegate_user_id = ?, delegate_note = ? WHERE section_id = ? AND user_id = ?");
        return $stmt->execute([$cleanRole, $delegateId, $cleanDelegateNote !== '' ? mb_substr($cleanDelegateNote, 0, 255) : null, $sectionId, $userId]);
    }

    public static function removeMember(PDO $pdo, int $sectionId, int $userId): bool {
        return (bool)$pdo->prepare("DELETE FROM team_members WHERE section_id = ? AND user_id = ?")->execute([$sectionId, $userId]);
    }

    public static function updateRole(PDO $pdo, int $sectionId, int $userId, string $role): bool {
        $stmt = $pdo->prepare("UPDATE team_members SET role = ? WHERE section_id = ? AND user_id = ?");
        return $stmt->execute([$role, $sectionId, $userId]);
    }

    public static function exists(PDO $pdo, int $sectionId, int $userId): bool {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM team_members WHERE section_id = ? AND user_id = ?");
        $stmt->execute([$sectionId, $userId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public static function setSectionChief(PDO $pdo, int $sectionId, int $userId, int $actorId = 0): void {
        $pdo->prepare("UPDATE team_members SET role = 'MEMBER' WHERE section_id = ? AND role = 'SECTION_CHIEF'")
            ->execute([$sectionId]);

        if (!self::exists($pdo, $sectionId, $userId)) {
            self::addMember($pdo, $sectionId, $userId, 'SECTION_CHIEF', $actorId);
        } else {
            self::updateRole($pdo, $sectionId, $userId, 'SECTION_CHIEF');
        }
    }

    public static function setDelegate(PDO $pdo, int $sectionId, int $userId, ?int $delegateUserId, ?string $delegateNote = null): bool {
        $cleanDelegateNote = trim((string)$delegateNote);
        $stmt = $pdo->prepare("UPDATE team_members SET delegate_user_id = ?, delegate_note = ? WHERE section_id = ? AND user_id = ?");
        return $stmt->execute([$delegateUserId !== null && $delegateUserId > 0 ? $delegateUserId : null, $cleanDelegateNote !== '' ? mb_substr($cleanDelegateNote, 0, 255) : null, $sectionId, $userId]);
    }

    public static function isSectionChief(PDO $pdo, int $userId, int $sectionId): bool {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM team_members WHERE user_id = ? AND section_id = ? AND role = 'SECTION_CHIEF'");
        $stmt->execute([$userId, $sectionId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public static function chiefSectionIds(PDO $pdo, int $userId): array {
        $stmt = $pdo->prepare("SELECT section_id FROM team_members WHERE user_id = ? AND role = 'SECTION_CHIEF'");
        $stmt->execute([$userId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function sectionReviewAssignmentForUser(PDO $pdo, int $userId): ?array {
        $stmt = $pdo->prepare("\n            SELECT\n                tm.section_id,\n                s.name AS section_name,\n                COALESCE(NULLIF(s.chief_id, 0), chief.user_id) AS chief_user_id,\n                u.name AS chief_name,\n                u.email AS chief_email\n            FROM team_members tm\n            JOIN sections s ON s.id = tm.section_id\n            LEFT JOIN team_members chief\n                ON chief.section_id = tm.section_id\n                AND chief.role = 'SECTION_CHIEF'\n            LEFT JOIN users u ON u.id = COALESCE(NULLIF(s.chief_id, 0), chief.user_id)\n            WHERE tm.user_id = ?\n              AND u.id IS NOT NULL\n              AND u.status = 'ACTIVE'\n              AND UPPER(u.role) NOT IN ('SUPER_ADMIN', 'ADMIN')\n            ORDER BY\n              CASE WHEN tm.role = 'MEMBER' THEN 0 ELSE 1 END,\n              tm.section_id\n            LIMIT 1\n        ");
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public static function isAssignedSectionChiefForUser(PDO $pdo, int $chiefUserId, int $memberUserId): bool {
        $stmt = $pdo->prepare("\n            SELECT COUNT(*)\n            FROM team_members member\n            JOIN team_members chief\n              ON chief.section_id = member.section_id\n             AND chief.role = 'SECTION_CHIEF'\n             AND chief.user_id = ?\n            WHERE member.user_id = ?\n        ");
        $stmt->execute([$chiefUserId, $memberUserId]);
        return (int)$stmt->fetchColumn() > 0;
    }
}
