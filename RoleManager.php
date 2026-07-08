<?php

declare(strict_types=1);

namespace Wellz;

/**
 * صلاحيات متعددة: super_admin · admin · moderator
 */
final class RoleManager
{
    public const ROLE_SUPER = 'super_admin';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_MODERATOR = 'moderator';

    private string $rolesFile;

    /** @var array<string, string> */
    private array $roles = [];

    public function __construct(
        private array $config,
        string $dataDir,
    ) {
        $this->rolesFile = $dataDir.'/admins/roles.json';
        $this->load();
    }

    public function roleOf(int $userId): ?string
    {
        $id = (string) $userId;
        if (isset($this->roles[$id])) {
            return $this->roles[$id];
        }
        if (in_array($id, $this->config['admin_ids'] ?? [], true)) {
            return $this->isSuperId($userId) ? self::ROLE_SUPER : self::ROLE_ADMIN;
        }

        return null;
    }

    public function isStaff(int $userId): bool
    {
        return $this->roleOf($userId) !== null;
    }

    public function can(int $userId, string $permission): bool
    {
        $role = $this->roleOf($userId);
        if ($role === null) {
            return false;
        }
        $matrix = [
            self::ROLE_SUPER => ['generate', 'delete', 'disable', 'stats', 'deliver', 'manage_admins', 'report', 'panel'],
            self::ROLE_ADMIN => ['generate', 'stats', 'deliver', 'report', 'panel'],
            self::ROLE_MODERATOR => ['deliver', 'panel'],
        ];

        return in_array($permission, $matrix[$role] ?? [], true);
    }

    public function roleLabel(string $role): string
    {
        return match ($role) {
            self::ROLE_SUPER => 'سوبر أدمن',
            self::ROLE_ADMIN => 'مدير',
            self::ROLE_MODERATOR => 'مشرف',
            default => $role,
        };
    }

    public function addRole(int $userId, string $role): void
    {
        if (! in_array($role, [self::ROLE_SUPER, self::ROLE_ADMIN, self::ROLE_MODERATOR], true)) {
            throw new \InvalidArgumentException('دور غير صالح');
        }
        $this->roles[(string) $userId] = $role;
        $this->save();
    }

    public function removeRole(int $userId): bool
    {
        $id = (string) $userId;
        if (! isset($this->roles[$id])) {
            return false;
        }
        unset($this->roles[$id]);
        $this->save();

        return true;
    }

    /** @return array<string, string> */
    public function allRoles(): array
    {
        return $this->roles;
    }

    public function verifySuperPin(string $pin): bool
    {
        $expected = (string) ($this->config['super_admin_pin'] ?? $this->config['admin_pin'] ?? '');

        return $expected !== '' && hash_equals($expected, $pin);
    }

    private function isSuperId(int $userId): bool
    {
        $supers = array_map('trim', explode(',', (string) ($this->config['super_admin_ids'] ?? '')));

        return in_array((string) $userId, $supers, true);
    }

    private function load(): void
    {
        if (! is_file($this->rolesFile)) {
            return;
        }
        $data = json_decode((string) file_get_contents($this->rolesFile), true);
        if (is_array($data)) {
            $this->roles = $data;
        }
    }

    private function save(): void
    {
        $dir = dirname($this->rolesFile);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->rolesFile, json_encode($this->roles, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}
