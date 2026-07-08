<?php

declare(strict_types=1);

namespace Wellz;

/**
 * بناء لوحات Inline Keyboard للأدمن والعملاء.
 */
final class KeyboardBuilder
{
    public function __construct(private array $config) {}

    /** @return array<string, mixed> */
    public function adminMainMenu(): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '📊 إحصائيات', 'callback_data' => 'adm:stats'],
                    ['text' => '🧾 توليد رمز', 'callback_data' => 'adm:gen'],
                ],
                [
                    ['text' => '🔑 المخزون', 'callback_data' => 'adm:codes'],
                    ['text' => '📋 الطلبات', 'callback_data' => 'adm:orders'],
                ],
                [
                    ['text' => '📥 تقرير CSV', 'callback_data' => 'adm:csv'],
                    ['text' => '📜 سجل التدقيق', 'callback_data' => 'adm:audit'],
                ],
                [['text' => '↩️ رجوع للقائمة', 'callback_data' => 'adm:back']],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function genPlanButtons(): array
    {
        $plans = $this->config['license_plans'] ?? [];
        $rows = [];
        $row = [];
        foreach ($plans as $key => $cfg) {
            $row[] = ['text' => (string) ($cfg['label'] ?? $key), 'callback_data' => 'genfb:'.$key];
            if (count($row) === 2) {
                $rows[] = $row;
                $row = [];
            }
        }
        if ($row !== []) {
            $rows[] = $row;
        }
        $rows[] = [['text' => '↩️ رجوع', 'callback_data' => 'adm:panel']];

        return ['inline_keyboard' => $rows];
    }

    /** @return array<string, mixed> */
    public function backToPanel(): array
    {
        return ['inline_keyboard' => [[['text' => '↩️ لوحة الأدمن', 'callback_data' => 'adm:panel']]]];
    }
}
