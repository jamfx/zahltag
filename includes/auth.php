<?php
declare(strict_types=1);

function session_start_secure(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function require_member(string $shareToken): array
{
    if (empty($_SESSION['member_id']) || empty($_SESSION['group_id'])) {
        redirect('/group/' . $shareToken);
    }

    try {
        $stmt = db()->prepare(
            'SELECT m.*, g.share_token FROM members m
             JOIN groups g ON g.id = m.group_id
             WHERE m.id = ? AND m.group_id = ? AND g.share_token = ? AND m.active = 1
             LIMIT 1'
        );
        $stmt->execute([$_SESSION['member_id'], $_SESSION['group_id'], $shareToken]);
        $member = $stmt->fetch();
    } catch (Throwable) {
        redirect('/group/' . $shareToken);
    }

    if (!$member) {
        unset($_SESSION['member_id'], $_SESSION['group_id']);
        redirect('/group/' . $shareToken);
    }

    return $member;
}

function require_admin(): void
{
    if (empty($_SESSION['admin_id'])) {
        redirect('/admin');
    }
}

function require_group_admin(string $adminToken): array
{
    try {
        $stmt = db()->prepare(
            'SELECT * FROM groups WHERE admin_token = ? LIMIT 1'
        );
        $stmt->execute([$adminToken]);
        $group = $stmt->fetch();
    } catch (Throwable) {
        http_response_code(404);
        exit;
    }

    if (!$group) {
        http_response_code(404);
        exit;
    }

    return $group;
}

function is_group_admin(string $adminToken): bool
{
    if (empty($adminToken)) {
        return false;
    }
    try {
        $stmt = db()->prepare('SELECT id FROM groups WHERE admin_token = ? LIMIT 1');
        $stmt->execute([$adminToken]);
        return (bool)$stmt->fetch();
    } catch (Throwable) {
        return false;
    }
}

function get_group_by_share_token(string $token): ?array
{
    try {
        $stmt = db()->prepare('SELECT * FROM groups WHERE share_token = ? LIMIT 1');
        $stmt->execute([$token]);
        $group = $stmt->fetch();
        return $group ?: null;
    } catch (Throwable) {
        return null;
    }
}
