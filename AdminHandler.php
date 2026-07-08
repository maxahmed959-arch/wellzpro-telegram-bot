<?php

declare(strict_types=1);

namespace Wellz;

/**
 * أوامر ولوحة تحكم الأدمن — إحصائيات، توليد، صلاحيات، تقارير.
 */
final class AdminHandler
{
    public function __construct(
        private RoleManager $roles,
        private LicenseManager $licenses,
        private StatsService $stats,
        private AuditLogger $audit,
        private KeyboardBuilder $keyboards,
        private array $config,
        private string $dataDir,
    ) {}

    public function handleCommand(string $cmd, string $arg, int $chatId, int $fromId, array $from): ?array
    {
        $username = (string) ($from['username'] ?? '');

        return match ($cmd) {
            '/panel', '/dashboard' => $this->cmdPanel($chatId, $fromId),
            '/stats' => $this->cmdStats($chatId, $fromId),
            '/report', '/csv' => $this->cmdReport($chatId, $fromId),
            '/audit' => $this->cmdAudit($chatId, $fromId),
            '/off', '/disable' => $this->cmdDisable($chatId, $fromId, $username, strtoupper(trim($arg))),
            '/del', '/delete' => $this->cmdDelete($chatId, $fromId, $username, strtoupper(trim($arg))),
            '/add_admin' => $this->cmdAddAdmin($chatId, $fromId, $arg),
            '/remove_admin' => $this->cmdRemoveAdmin($chatId, $fromId, $arg),
            '/roles' => $this->cmdRoles($chatId, $fromId),
            '/codes', '/stock' => $this->cmdCodesStock($chatId, $fromId),
            default => null,
        };
    }

    public function handleCallback(string $data, int $chatId, int $fromId): ?array
    {
        if (! str_starts_with($data, 'adm:')) {
            return null;
        }
        $action = substr($data, 4);

        return match ($action) {
            'panel' => $this->cmdPanel($chatId, $fromId),
            'stats' => $this->cmdStats($chatId, $fromId),
            'gen' => $this->responseGenMenu($chatId, $fromId),
            'codes' => $this->cmdCodesStock($chatId, $fromId),
            'csv' => $this->cmdReport($chatId, $fromId),
            'audit' => $this->cmdAudit($chatId, $fromId),
            'back' => ['text' => 'اضغط ▶️ بدء أو استخدم القائمة أدناه.', 'markup' => null, 'keyboard' => true],
            default => null,
        };
    }

    public function adminHelpText(int $fromId): string
    {
        $plans = implode(' · ', array_keys($this->config['license_plans'] ?? []));
        $role = $this->roles->roleOf($fromId);
        $roleLine = $role ? "\n👤 دورك: <b>".$this->roles->roleLabel($role).'</b>' : '';

        return '🛠 <b>مركز إدارة WellzPro</b>'.$roleLine."\n\n"
            ."/panel — لوحة التحكم (أزرار)\n"
            ."/stats — إحصائيات فورية\n"
            ."/gen — توليد رمز (Firebase)\n"
            ."/codes — مخزون محلي\n"
            ."/report — تقرير CSV\n"
            ."/orders — طلبات معلقة\n"
            ."/off &lt;code&gt; — إيقاف رمز\n"
            ."/del &lt;code&gt; — حذف رمز غير مُفعّل\n"
            ."/add_admin — إضافة مسؤول (سوبر فقط)\n"
            ."/remove_admin — إزالة مسؤول\n\n"
            ."الخطط: {$plans}";
    }

    /** @return array{text: string, markup: ?array, keyboard?: bool, document?: array}|null */
    private function cmdPanel(int $chatId, int $fromId): ?array
    {
        if (! $this->roles->can($fromId, 'panel')) {
            return $this->deny();
        }

        return [
            'text' => "🎛 <b>لوحة تحكم الأدمن</b>\n\nاختر من الأزرار:",
            'markup' => $this->keyboards->adminMainMenu(),
            'keyboard' => false,
        ];
    }

    private function cmdStats(int $chatId, int $fromId): ?array
    {
        if (! $this->roles->can($fromId, 'stats')) {
            return $this->deny();
        }
        if (! $this->licenses->isReady()) {
            return ['text' => '❌ Firebase غير مضبوط.', 'markup' => null, 'keyboard' => false];
        }
        $snap = $this->stats->snapshot();

        return [
            'text' => $this->stats->formatDashboardText($snap),
            'markup' => $this->keyboards->backToPanel(),
            'keyboard' => false,
        ];
    }

    private function cmdReport(int $chatId, int $fromId): ?array
    {
        if (! $this->roles->can($fromId, 'report')) {
            return $this->deny();
        }
        if (! $this->licenses->isReady()) {
            return ['text' => '❌ Firebase غير مضبوط.', 'markup' => null, 'keyboard' => false];
        }
        $snap = $this->stats->snapshot();
        $csv = $this->stats->buildCsv($snap);
        $path = $this->dataDir.'/reports/licenses_'.date('Y-m-d_His').'.csv';
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $csv);
        $this->audit->log('report.csv', $fromId, null, ['rows' => count($snap['licenses'])]);

        return [
            'text' => '📥 <b>تقرير التراخيص</b> — '.count($snap['licenses']).' سجل',
            'markup' => $this->keyboards->backToPanel(),
            'keyboard' => false,
            'document' => ['path' => $path, 'filename' => 'wellzpro_licenses_'.date('Y-m-d').'.csv'],
        ];
    }

    private function cmdAudit(int $chatId, int $fromId): ?array
    {
        if (! $this->roles->can($fromId, 'stats')) {
            return $this->deny();
        }
        $rows = $this->audit->recent(15);
        if ($rows === []) {
            return ['text' => '📜 لا يوجد سجل تدقيق اليوم.', 'markup' => $this->keyboards->backToPanel(), 'keyboard' => false];
        }
        $lines = ["📜 <b>سجل التدقيق (اليوم)</b>\n"];
        foreach ($rows as $r) {
            $lines[] = '• '.substr((string) ($r['ts'] ?? ''), 11, 8)
                .' — '.($r['action'] ?? '')
                .' — uid:'.($r['user_id'] ?? '');
        }

        return ['text' => implode("\n", $lines), 'markup' => $this->keyboards->backToPanel(), 'keyboard' => false];
    }

    private function cmdCodesStock(int $chatId, int $fromId): ?array
    {
        if (! $this->roles->can($fromId, 'generate')) {
            return $this->deny();
        }
        $codes = $this->licenses->availableLocalCodes();
        if ($codes === []) {
            return [
                'text' => "📦 <b>مخزون الأكواد</b>\nلا توجد أكواد متاحة.\nاستخدم /gen أو زر توليد رمز.",
                'markup' => $this->keyboards->backToPanel(),
                'keyboard' => false,
            ];
        }
        $lines = ['📦 <b>مخزون الأكواد</b>', 'العدد: <b>'.count($codes)."</b>\n"];
        foreach (array_slice($codes, 0, 20) as $code) {
            $lines[] = '• <code>'.$code.'</code>';
        }

        return ['text' => implode("\n", $lines), 'markup' => $this->keyboards->backToPanel(), 'keyboard' => false];
    }

    private function responseGenMenu(int $chatId, int $fromId): ?array
    {
        if (! $this->roles->can($fromId, 'generate')) {
            return $this->deny();
        }

        return [
            'text' => "🧾 <b>توليد رمز اشتراك</b>\n\nيُكتب مباشرة في Firebase:",
            'markup' => $this->keyboards->genPlanButtons(),
            'keyboard' => false,
        ];
    }

    private function cmdDisable(int $chatId, int $fromId, ?string $username, string $code): ?array
    {
        if (! $this->roles->can($fromId, 'disable')) {
            return $this->deny();
        }
        if ($code === '') {
            return ['text' => 'الصيغة: <code>/off WELLZ-XXXX-XXXX</code>', 'markup' => null, 'keyboard' => false];
        }
        try {
            $ok = $this->licenses->disable($code, $fromId, $username);

            return ['text' => $ok ? "🚫 تم إيقاف:\n<code>{$code}</code>" : "❌ الرمز غير موجود: <code>{$code}</code>", 'markup' => null, 'keyboard' => false];
        } catch (\Throwable $e) {
            return ['text' => '❌ '.$e->getMessage(), 'markup' => null, 'keyboard' => false];
        }
    }

    private function cmdDelete(int $chatId, int $fromId, ?string $username, string $code): ?array
    {
        if (! $this->roles->can($fromId, 'delete')) {
            return $this->deny();
        }
        if ($code === '') {
            return ['text' => 'الصيغة: <code>/del WELLZ-XXXX-XXXX</code>', 'markup' => null, 'keyboard' => false];
        }
        try {
            $ok = $this->licenses->delete($code, $fromId, $username);

            return ['text' => $ok ? "🗑 تم حذف:\n<code>{$code}</code>" : "❌ الرمز غير موجود", 'markup' => null, 'keyboard' => false];
        } catch (\Throwable $e) {
            return ['text' => '❌ '.$e->getMessage(), 'markup' => null, 'keyboard' => false];
        }
    }

    private function cmdAddAdmin(int $chatId, int $fromId, string $arg): ?array
    {
        if (! $this->roles->can($fromId, 'manage_admins')) {
            return $this->deny();
        }
        $parts = preg_split('/\s+/', trim($arg)) ?: [];
        $pin = $parts[0] ?? '';
        $targetId = (int) ($parts[1] ?? 0);
        $role = strtolower($parts[2] ?? RoleManager::ROLE_ADMIN);
        if (! $this->roles->verifySuperPin($pin)) {
            return ['text' => '❌ رمز السوبر أدمن خاطئ.', 'markup' => null, 'keyboard' => false];
        }
        if ($targetId <= 0) {
            return ['text' => "الصيغة:\n<code>/add_admin الرمز user_id admin|moderator|super_admin</code>", 'markup' => null, 'keyboard' => false];
        }
        $this->roles->addRole($targetId, $role);
        $this->audit->log('admin.add', $fromId, null, ['target' => $targetId, 'role' => $role]);

        return ['text' => "✅ تمت إضافة <code>{$targetId}</code> كـ <b>".$this->roles->roleLabel($role).'</b>', 'markup' => null, 'keyboard' => false];
    }

    private function cmdRemoveAdmin(int $chatId, int $fromId, string $arg): ?array
    {
        if (! $this->roles->can($fromId, 'manage_admins')) {
            return $this->deny();
        }
        $parts = preg_split('/\s+/', trim($arg)) ?: [];
        $pin = $parts[0] ?? '';
        $targetId = (int) ($parts[1] ?? 0);
        if (! $this->roles->verifySuperPin($pin)) {
            return ['text' => '❌ رمز السوبر أدمن خاطئ.', 'markup' => null, 'keyboard' => false];
        }
        if ($targetId <= 0) {
            return ['text' => 'الصيغة: <code>/remove_admin الرمز user_id</code>', 'markup' => null, 'keyboard' => false];
        }
        $ok = $this->roles->removeRole($targetId);
        if ($ok) {
            $this->audit->log('admin.remove', $fromId, null, ['target' => $targetId]);
        }

        return ['text' => $ok ? "✅ تمت إزالة <code>{$targetId}</code>" : '❌ غير موجود في قائمة الأدوار', 'markup' => null, 'keyboard' => false];
    }

    private function cmdRoles(int $chatId, int $fromId): ?array
    {
        if (! $this->roles->can($fromId, 'manage_admins')) {
            return $this->deny();
        }
        $all = $this->roles->allRoles();
        if ($all === []) {
            return ['text' => 'لا توجد أدوار مخصصة (يُستخدم TELEGRAM_ADMIN_IDS فقط).', 'markup' => null, 'keyboard' => false];
        }
        $lines = ["👥 <b>المسؤولون</b>\n"];
        foreach ($all as $uid => $role) {
            $lines[] = '• <code>'.$uid.'</code> — '.$this->roles->roleLabel($role);
        }

        return ['text' => implode("\n", $lines), 'markup' => null, 'keyboard' => false];
    }

    /** @return array{text: string, markup: null, keyboard: false} */
    private function deny(): array
    {
        return ['text' => '⛔ ليس لديك صلاحية لهذا الإجراء.', 'markup' => null, 'keyboard' => false];
    }
}
