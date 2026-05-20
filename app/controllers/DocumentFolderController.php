<?php

function empty_trash(): void {
  global $pdo;
  csrf_verify();

  $uid = (int)($_SESSION['user']['id'] ?? 0);
  if (!require_reauth($pdo, $uid, req_str('confirm_password', ''))) {
    redirect('/admin/dashboard');
  }
  $ownerId = selected_owner_id($pdo, $uid);
  $trashedRoots = Folder::trashedRootFolders(Folder::listTrashedForUser($pdo, $ownerId));
  $eligibleRootPaths = [];
  foreach ($trashedRoots as $folder) {
    $deletedAt = strtotime((string)($folder['deleted_at'] ?? ''));
    if (TRASH_RETENTION_DAYS <= 0 || ($deletedAt !== false && $deletedAt <= strtotime('-' . TRASH_RETENTION_DAYS . ' days'))) {
      $eligibleRootPaths[] = (string)$folder['name'];
    }
  }
  $folderIds = Folder::idsInTreeForPaths($pdo, $ownerId, $eligibleRootPaths);
  $docIds = Document::trashedIdsEligibleForPurge($pdo, $ownerId, TRASH_RETENTION_DAYS);
  if (empty($docIds) && empty($folderIds)) {
    redirect('/admin/dashboard');
  }

  try {
    purge_trash_items($pdo, $ownerId, $folderIds, $docIds);
  } catch (Throwable $e) {
    redirect('/admin/dashboard');
  }

  AuditLog::add($pdo, $uid, "Emptied trash", null, "owner_id=".$ownerId);
  redirect('/admin/dashboard?msg=trash_emptied');
}

function create_folder(): void {
  global $pdo;
  csrf_verify();
  $uid = (int)$_SESSION['user']['id'];
  $ownerId = selected_owner_id($pdo, $uid);
  $name = req_str('name', '');
  $parentFolderId = req_int('folder_id', 0);
  $parentFolder = $parentFolderId ? Folder::getForUser($pdo, $parentFolderId, $ownerId, 'OFFICIAL') : null;
  $fullName = folder_path_join($parentFolder ? (string)$parentFolder['name'] : '', $name);
  if ($name !== '') {
    Folder::firstOrCreateForUser($pdo, $ownerId, $fullName, 'OFFICIAL');
    AuditLog::add($pdo, $uid, "Created folder", null, $fullName . ";owner_id=".$ownerId);
  }
  redirect('/admin/dashboard');
}

function delete_folder(): void {
  global $pdo;
  csrf_verify();
  $uid = (int)$_SESSION['user']['id'];
  $ownerId = selected_owner_id($pdo, $uid);
  $storageArea = Document::normalizeStorageArea(req_str('storage_area', 'PRIVATE'));
  $folderId = request_folder_id();
  $folder = Folder::getForUser($pdo, $folderId, $ownerId, $storageArea);
  if (!$folder) {
    redirect('/admin/dashboard');
  }
  if (folder_tree_locked_document_exists($pdo, $ownerId, (string)$folder['name'], $storageArea) && !is_admin_user()) {
    redirect('/admin/dashboard');
  }

  $tree = Folder::listTreeForUser($pdo, $ownerId, (string)$folder['name'], $storageArea);
  $deletedDocs = 0;
  foreach ($tree as $treeFolder) {
    $deletedDocs += Document::softDeleteByFolder($pdo, $ownerId, (int)$treeFolder['id'], $uid, 'folder_deleted', $storageArea);
  }
  $deletedFolders = Folder::softDeleteTreeForUser($pdo, $ownerId, (string)$folder['name'], $uid, $storageArea);
  AuditLog::add($pdo, $uid, "Deleted folder", null, "folder_id=".$folderId.", docs_trashed=".$deletedDocs.", folders_trashed=".$deletedFolders);
  redirect('/admin/dashboard?msg=folder_deleted');
}

function move_folder_to_official(): void {
  redirect('/admin/dashboard?msg=feature_retired');
}

function move_folder_to_private(): void {
  redirect('/admin/dashboard?msg=feature_retired');
}

function rename_folder(): void {
  global $pdo;
  csrf_verify();
  $uid = (int)$_SESSION['user']['id'];
  $ownerId = selected_owner_id($pdo, $uid);
  $folderId = req_int('id', 0);
  $newName = trim(req_str('new_name', ''));
  $folder = Folder::getForUser($pdo, $folderId, $ownerId, 'OFFICIAL');
  if (!$folder || $newName === '') {
    redirect('/admin/dashboard');
  }
  $parentPath = Folder::parentPath((string)$folder['name']);
  $targetPath = folder_path_join($parentPath, mb_substr($newName, 0, 120));
  Folder::renameTreeForUser($pdo, $ownerId, (string)$folder['name'], $targetPath, 'OFFICIAL');
  AuditLog::add($pdo, $uid, "Renamed folder", null, "folder_id=".$folderId.", name=".$targetPath);
  redirect('/admin/dashboard?msg=folder_renamed');
}
