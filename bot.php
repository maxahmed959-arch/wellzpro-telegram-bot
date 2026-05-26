<?php

/**
 * WellzPro Telegram Bot — مستقل.
 * العميل: خطط + إقامة + كلمة سر + تحويل urpay + إيصال.
 * الأدمن: يستلم الطلب في محادثة البوت ويرد بـ Reply على رسالة الطلب.
 */

declare(strict_types=1);

require __DIR__.'/bootstrap.php';

final class WellzTelegramBot
{
    private array $config;

    private string $dataDir;

    private string $token;

    private const BTN_START = '▶️ بدء';

    private const BTN_CANCEL = '❌ إلغاء';

    private const BOT_BUILD = '2026-05-26-guide';

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->token = (string) ($config['bot_token'] ?? '');
        $this->dataDir = __DIR__.'/data';
        foreach (['sessions', 'orders', 'notify_map', 'admins'] as $sub) {
            $dir = $this->dataDir.'/'.$sub;
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
        $this->mergeSavedAdmins();
    }

    /** معالجة تحديث واحد (ويب هوك على السحابة). */
    public function processUpdate(array $update): void
    {
        $this->handleUpdate($update);
    }

    public function setupOnce(): void
    {
        $this->setupBotMenu();
    }

    public function run(): void
    {
        if ($this->token === '') {
            fwrite(STDERR, "ضع TELEGRAM_BOT_TOKEN في telegram-bot/.env\n");
            exit(1);
        }

        $this->apiPost('deleteWebhook', ['drop_pending_updates' => 'false']);

        echo "WellzPro Bot — خطط + urpay + إيصال | build ".self::BOT_BUILD."\n";
        $admins = $this->config['admin_ids'] ?? [];
        if ($admins === []) {
            echo "⚠️  لا يوجد أدمن — أرسل للبوت: /admin ".($this->config['admin_pin'] ?? '')."\n";
        } else {
            echo 'أدمن: '.implode(', ', $admins)."\n";
        }
        echo "اضغط Ctrl+C للإيقاف\n\n";

        $this->setupBotMenu();

        $me = $this->apiGet('getMe', []);
        if (is_array($me) && isset($me['username'])) {
            echo 'متصل: @'.$me['username']."\n\n";
        }

        $offset = 0;
        while (true) {
            $updates = $this->apiGet('getUpdates', ['timeout' => 25, 'offset' => $offset]);
            if (! is_array($updates)) {
                sleep(3);
                continue;
            }
            foreach ($updates as $update) {
                $this->handleUpdate($update);
                $offset = ((int) ($update['update_id'] ?? 0)) + 1;
            }
        }
    }

    private function handleUpdate(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->onCallback($update['callback_query']);
            return;
        }
        if (isset($update['message'])) {
            $this->onMessage($update['message']);
        }
    }

    private function onMessage(array $message): void
    {
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $from = $message['from'] ?? [];
        $fromId = (int) ($from['id'] ?? 0);
        if ($chatId === 0) {
            return;
        }

        $text = trim((string) ($message['text'] ?? ''));

        if ($text !== '' && str_starts_with($text, '/')) {
            if ($this->onCommand($chatId, $fromId, $text)) {
                return;
            }
        }

        if ($this->isAdmin($fromId) && isset($message['reply_to_message'])) {
            $this->onAdminReply($chatId, $message);
            return;
        }

        if ($this->isAdmin($fromId) && ! $this->isCustomerInFlow($chatId)) {
            $this->send($chatId, 'أنت مسجّل كأدمن.\nللرد على عميل: <b>Reply</b> على رسالة «طلب جديد» وأرسل الكود والـ JSON.', null, false);
            return;
        }

        if ($text !== '' && str_starts_with($text, '/')) {
            return;
        }

        if ($this->hasPhotoOrDocument($message) && ! $this->isAdmin($fromId)) {
            $this->onTransferProof($chatId, $from, $message);
            return;
        }

        if ($text !== '') {
            $this->onText($chatId, $from, $text);
        }
    }

    private function onCommand(int $chatId, int $fromId, string $text): bool
    {
        $cmd = strtolower(strtok($text, " \n\r\t") ?: '');
        $arg = trim(strtok(" \n\r\t") ?: '');

        if ($cmd === '/admin') {
            $pin = $arg !== '' ? $arg : trim(substr($text, strlen('/admin')));
            if ($pin === ($this->config['admin_pin'] ?? '')) {
                $this->registerAdmin($fromId);
                $this->send($chatId, "✅ تم تسجيلك كأدمن.\nستصلك الطلبات هنا في محادثة <b>WellzProShopBot</b>.\n\nللرد على عميل: <b>Reply</b> على رسالة الطلب وأرسل رمز التفعيل + JSON.", null, false);
            } else {
                $this->send($chatId, '❌ رمز خاطئ. مثال: /admin الرمز_من_env', null, false);
            }
            return true;
        }

        if ($this->isAdmin($fromId) && $cmd === '/orders') {
            $this->sendPendingOrders($chatId);
            return true;
        }

        if ($cmd === '/cancel' || $cmd === '/الغاء') {
            $this->cancelPendingOrder($chatId);
            $this->clearSession($chatId);
            $this->send($chatId, 'تم الإلغاء. اضغط ▶️ بدء.');
            return true;
        }

        if (in_array($cmd, ['/start', '/buy', '/plans', '/refresh', '/تحديث'], true) || str_starts_with($cmd, '/start')) {
            $this->clearSession($chatId);
            $this->sendPlansMenu($chatId);
            return true;
        }

        if ($cmd === '/help') {
            if ($this->isAdmin($fromId)) {
                $this->send($chatId, "/orders — طلبات معلقة\n/admin — تسجيل أدمن\nReply على رسالة طلب → يصل للعميل", null, false);
            } else {
                $this->send($chatId, '/start — خطط الاشتراك\n/cancel — إلغاء');
            }
            return true;
        }

        if (! $this->isAdmin($fromId)) {
            $this->send($chatId, 'أرسل /start لعرض الخطط.');
            return true;
        }

        return false;
    }

    private function onText(int $chatId, array $from, string $text): void
    {
        if ($this->isStartButton($text) || in_array($text, ['شراء', 'اشتراك', 'خطط'], true)) {
            $this->clearSession($chatId);
            $this->sendPlansMenu($chatId);
            return;
        }

        if ($text === self::BTN_CANCEL) {
            $this->cancelPendingOrder($chatId);
            $this->clearSession($chatId);
            $this->send($chatId, 'تم الإلغاء. اضغط ▶️ بدء.');
            return;
        }

        if ($text === $this->howToRunButton()) {
            $this->sendHowToRunGuide($chatId);
            return;
        }

        $infoKey = $this->areaInfoKeyFromButton($text);
        if ($infoKey !== null) {
            $this->sendAreaCodesInfo($chatId, $infoKey);
            return;
        }

        $session = $this->loadSession($chatId);
        if ($session !== null) {
            $state = $session['state'] ?? '';

            if ($state === 'awaiting_transfer') {
                $this->send($chatId, '📸 أرسل <b>صورة إشعار التحويل</b> هنا.\n(أو اضغط ❌ إلغاء)');
                return;
            }
        }

        $planKey = $this->planKeyFromButtonText($text);
        if ($planKey !== null) {
            $this->clearSession($chatId);
            $this->beginPlan($chatId, $from, $planKey);
            return;
        }

        if ($session === null) {
            return;
        }

        $state = $session['state'] ?? '';

        if ($state === 'waiting_iqama') {
            if (! preg_match('/^\d{10}$/', $text)) {
                $this->send($chatId, '❌ رقم الإقامة: <b>10 أرقام</b> فقط.');
                return;
            }
            $session['iqama_id'] = $text;
            $session['state'] = 'waiting_password';
            $this->saveSession($chatId, $session);
            $this->send($chatId, '🔐 كلمة السر: أدخل <b>6 أحرف</b> بالضبط:');
            return;
        }

        if ($state === 'waiting_password') {
            if (! preg_match('/^.{6}$/u', $text)) {
                $this->send($chatId, '❌ كلمة السر: <b>6 أحرف</b> بالضبط.');
                return;
            }
            $session['password'] = $text;
            $this->submitOrderAndShowBank($chatId, $from, $session);
        }
    }

    private function submitOrderAndShowBank(int $chatId, array $from, array $session): void
    {
        $orderId = 'ord_'.bin2hex(random_bytes(4));
        $order = [
            'id' => $orderId,
            'chat_id' => $chatId,
            'username' => $from['username'] ?? null,
            'first_name' => trim(($from['first_name'] ?? '').' '.($from['last_name'] ?? '')),
            'plan' => $session['plan'],
            'plan_label' => $session['plan_label'],
            'price' => $session['price'],
            'iqama_id' => $session['iqama_id'],
            'password' => $session['password'],
            'status' => 'awaiting_transfer',
            'created_at' => date('c'),
        ];
        file_put_contents(
            $this->dataDir.'/orders/'.$orderId.'.json',
            json_encode($order, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        $this->saveSession($chatId, [
            'state' => 'awaiting_transfer',
            'order_id' => $orderId,
        ]);

        $bank = (string) ($this->config['bank_account'] ?? '');
        $bankName = (string) ($this->config['bank_name'] ?? 'urpay');
        $holder = trim((string) ($this->config['bank_holder'] ?? ''));
        $holderLine = $holder !== '' ? "\nاسم المستفيد: <b>{$holder}</b>" : '';

        $this->send(
            $chatId,
            "📋 <b>ملخص الطلب</b> — <code>{$orderId}</code>\n\n"
            .'📦 الخطة: <b>'.($session['plan_label'] ?? '')."</b>\n"
            .'💰 المبلغ: <b>'.($session['price'] ?? 0)." ريال</b>\n"
            .'🪪 الإقامة: <code>'.($session['iqama_id'] ?? '')."</code>\n"
            .'🔐 كلمة السر: <code>'.($session['password'] ?? '')."</code>\n\n"
            ."🏦 <b>التحويل البنكي</b>\n"
            ."<b>{$bankName}</b> = <code>{$bank}</code>{$holderLine}\n\n"
            ."📸 بعد التحويل، <b>أرسل صورة إشعار التحويل</b> هنا.\n"
            .'(أو ❌ إلغاء)'
        );
    }

    private function onTransferProof(int $chatId, array $from, array $message): void
    {
        $session = $this->loadSession($chatId);
        $orderId = is_array($session) ? (string) ($session['order_id'] ?? '') : '';

        if (($session['state'] ?? '') !== 'awaiting_transfer' || $orderId === '') {
            $this->send($chatId, 'أرسل /start لبدء طلب اشتراك أولاً.');
            return;
        }

        $fileId = $this->extractProofFileId($message);
        if ($fileId === null) {
            $this->send($chatId, '❌ لم نتمكن من قراءة الملف. أرسل <b>صورة</b> إشعار التحويل.');
            return;
        }

        $orderPath = $this->dataDir.'/orders/'.$orderId.'.json';
        if (! is_file($orderPath)) {
            $this->send($chatId, '❌ تعذّر حفظ الطلب. أرسل /start للبدء من جديد.');
            $this->clearSession($chatId);
            return;
        }

        $order = json_decode(file_get_contents($orderPath), true);
        if (! is_array($order)) {
            $this->send($chatId, '❌ تعذّر حفظ الطلب. أرسل /start للبدء من جديد.');
            $this->clearSession($chatId);
            return;
        }

        $order['status'] = 'awaiting_admin';
        $order['proof_file_id'] = $fileId;
        $order['proof_at'] = date('c');
        file_put_contents($orderPath, json_encode($order, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $this->clearSession($chatId);

        $this->send(
            $chatId,
            "✅ <b>تم استلام إشعار التحويل</b>\n\n"
            ."طلبك <code>{$orderId}</code> قيد المراجعة.\n"
            ."سيصلك <b>رمز التفعيل</b> وبيانات الجلسة بعد التأكيد.\n\n"
            .'⏳ <b>انتظر الرد من الإدارة في هذه المحادثة.</b>'
        );

        $this->notifyAdmins($order, $message);
    }

    private function notifyAdmins(array $order, array $customerMessage): void
    {
        $admins = $this->config['admin_ids'] ?? [];
        if ($admins === []) {
            echo '['.date('H:i:s')."] طلب {$order['id']} — لا أدمن! أرسل /admin للبوت\n";
            return;
        }

        $bank = (string) ($this->config['bank_account'] ?? '');
        $bankName = (string) ($this->config['bank_name'] ?? 'urpay');
        $name = trim((string) ($order['first_name'] ?? ''));
        $nameLine = $name !== '' ? $name : 'عميل';
        $fromChatId = (int) ($customerMessage['chat']['id'] ?? 0);
        $proofMsgId = (int) ($customerMessage['message_id'] ?? 0);

        $text = "🆕 <b>طلب جديد</b> <code>{$order['id']}</code>\n\n"
            ."👤 {$nameLine}\n"
            .'📦 '.($order['plan_label'] ?? '')."\n"
            .'💰 <b>'.($order['price'] ?? 0)." ر.س</b>\n"
            .'🪪 <code>'.($order['iqama_id'] ?? '')."</code>\n"
            .'🔐 <code>'.($order['password'] ?? '')."</code>\n"
            ."🏦 {$bankName}: <code>{$bank}</code>\n\n"
            ."📸 إشعار التحويل مرفق أعلاه.\n\n"
            ."⬇️ <b>للرد على العميل:</b>\n"
            ."اضغط <b>رد Reply</b> على هذه الرسالة\n"
            ."وأرسل: رمز التفعيل + JSON الجلسة";

        foreach ($admins as $adminId) {
            $adminChat = (int) $adminId;
            if ($adminChat === 0) {
                continue;
            }
            if ($fromChatId > 0 && $proofMsgId > 0) {
                $this->apiPost('forwardMessage', [
                    'chat_id' => $adminChat,
                    'from_chat_id' => $fromChatId,
                    'message_id' => $proofMsgId,
                ]);
            }
            $msgId = $this->sendAndGetMessageId($adminChat, $text, null, false);
            if ($msgId !== null) {
                $this->saveNotifyMap($adminChat, $msgId, (int) $order['chat_id'], (string) $order['id']);
            }
        }
    }

    private function hasPhotoOrDocument(array $message): bool
    {
        return isset($message['photo']) || isset($message['document']);
    }

    private function extractProofFileId(array $message): ?string
    {
        if (isset($message['photo']) && is_array($message['photo'])) {
            $largest = end($message['photo']);
            return is_array($largest) ? ($largest['file_id'] ?? null) : null;
        }
        if (isset($message['document']['file_id'])) {
            return $message['document']['file_id'];
        }
        return null;
    }

    private function onAdminReply(int $adminChatId, array $message): void
    {
        $reply = $message['reply_to_message'] ?? null;
        if (! is_array($reply)) {
            return;
        }

        $replyMsgId = (int) ($reply['message_id'] ?? 0);
        $map = $this->loadNotifyMap($adminChatId, $replyMsgId);
        if ($map === null) {
            $this->send($adminChatId, '❌ هذه ليست رسالة طلب. اضغط Reply على رسالة «طلب جديد».', null, false);
            return;
        }

        $customerChatId = (int) ($map['customer_chat_id'] ?? 0);
        $orderId = (string) ($map['order_id'] ?? '');
        $adminText = trim((string) ($message['text'] ?? ''));

        if ($adminText === '') {
            $this->send($adminChatId, '❌ أرسل نصاً (رمز + JSON) كرد على رسالة الطلب.', null, false);
            return;
        }

        $customerMsg = "🎉 <b>تم تفعيل طلبك</b>\n<code>{$orderId}</code>\n\n".$adminText;
        $this->send($customerChatId, $customerMsg, null, true);

        $orderPath = $this->dataDir.'/orders/'.$orderId.'.json';
        if (is_file($orderPath)) {
            $order = json_decode(file_get_contents($orderPath), true);
            if (is_array($order)) {
                $order['status'] = 'delivered';
                $order['delivered_at'] = date('c');
                file_put_contents($orderPath, json_encode($order, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
        }

        $this->send($adminChatId, "✅ تم إرسال ردك للعميل (طلب <code>{$orderId}</code>)", null, false);
    }

    private function registerAdmin(int $userId): void
    {
        $ids = $this->config['admin_ids'] ?? [];
        $id = (string) $userId;
        if (! in_array($id, $ids, true)) {
            $ids[] = $id;
            $this->config['admin_ids'] = $ids;
            file_put_contents(
                $this->dataDir.'/admins/ids.json',
                json_encode($ids, JSON_UNESCAPED_UNICODE)
            );
            echo '['.date('H:i:s')."] أدمن جديد: {$userId}\n";
        }
    }

    private function mergeSavedAdmins(): void
    {
        $path = $this->dataDir.'/admins/ids.json';
        if (! is_file($path)) {
            return;
        }
        $saved = json_decode(file_get_contents($path), true);
        if (! is_array($saved)) {
            return;
        }
        $this->config['admin_ids'] = array_values(array_unique(array_merge(
            $this->config['admin_ids'] ?? [],
            $saved
        )));
    }

    private function isAdmin(int $userId): bool
    {
        return in_array((string) $userId, $this->config['admin_ids'] ?? [], true);
    }

    private function isCustomerInFlow(int $chatId): bool
    {
        return $this->loadSession($chatId) !== null;
    }

    private function sendPendingOrders(int $adminChatId): void
    {
        $lines = ["📋 <b>طلبات بانتظار الرد</b>\n"];
        $count = 0;
        foreach (glob($this->dataDir.'/orders/*.json') ?: [] as $file) {
            $o = json_decode(file_get_contents($file), true);
            if (! is_array($o) || ($o['status'] ?? '') !== 'awaiting_admin') {
                continue;
            }
            $count++;
            $lines[] = '• <code>'.($o['id'] ?? '').'</code> — '.($o['plan_label'] ?? '').' — '.($o['first_name'] ?? '');
        }
        if ($count === 0) {
            $this->send($adminChatId, 'لا توجد طلبات معلقة.', null, false);
            return;
        }
        $lines[] = "\nReply على رسالة الطلب في الأعلى لإرسال الكود للعميل.";
        $this->send($adminChatId, implode("\n", $lines), null, false);
    }

    private function saveNotifyMap(int $adminChat, int $msgId, int $customerChat, string $orderId): void
    {
        file_put_contents(
            $this->dataDir.'/notify_map/'.$adminChat.'_'.$msgId.'.json',
            json_encode(['customer_chat_id' => $customerChat, 'order_id' => $orderId], JSON_UNESCAPED_UNICODE)
        );
    }

    private function loadNotifyMap(int $adminChat, int $msgId): ?array
    {
        $path = $this->dataDir.'/notify_map/'.$adminChat.'_'.$msgId.'.json';
        if (! is_file($path)) {
            return null;
        }
        $data = json_decode(file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    private function onCallback(array $cb): void
    {
        $id = (string) ($cb['id'] ?? '');
        $message = $cb['message'] ?? null;
        $chatId = is_array($message) ? (int) ($message['chat']['id'] ?? 0) : 0;
        $this->apiPost('answerCallbackQuery', ['callback_query_id' => $id, 'text' => 'استخدم الأزرار أسفل الشاشة']);
        if ($chatId) {
            $this->sendPlansMenu($chatId);
        }
    }

    private function beginPlan(int $chatId, array $from, string $planKey): void
    {
        $plans = $this->config['plans'] ?? [];
        if (! isset($plans[$planKey])) {
            $this->send($chatId, '❌ خطة غير متاحة.');
            return;
        }
        $plan = $plans[$planKey];
        $this->saveSession($chatId, [
            'state' => 'waiting_iqama',
            'plan' => $planKey,
            'plan_label' => $plan['label'],
            'price' => (int) $plan['price'],
        ]);
        $this->send(
            $chatId,
            "✅ <b>{$plan['label']}</b>\n💰 <b>{$plan['price']} ريال</b>\n\n🪪 رقم الإقامة (<b>10 أرقام</b>):"
        );
    }

    private function sendPlansMenu(int $chatId): void
    {
        $this->send($chatId, $this->welcomeText(), $this->persistentKeyboard());
    }

    private function setupBotMenu(): void
    {
        $this->apiPost('setMyCommands', [
            'commands' => json_encode([
                ['command' => 'start', 'description' => 'خطط الاشتراك'],
                ['command' => 'cancel', 'description' => 'إلغاء'],
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function welcomeText(): string
    {
        $lines = [
            '🥋 <b>WellzPro</b>',
            '',
            'اضغط <b>▶️ بدء</b> أو اختر مدة الاشتراك:',
            '💊 🛒 📖 — الأزرار أسفل: أكواد المناطق + طريقة التشغيل',
        ];
        foreach ($this->config['plans'] as $key => $plan) {
            $lines[] = $this->planWelcomeLine($key, (int) $plan['price']);
        }

        return implode("\n", $lines);
    }

    private function planWelcomeLine(string $key, int $price): string
    {
        return match ($key) {
            'month' => "📦 شهر — {$price} ر.س",
            'two_months' => "📦 شهرين — {$price} ر.س",
            'quarter' => "📦 3 أشهر — {$price} ر.س",
            'lifetime' => "♾ مدى الحياة — {$price} ر.س",
            default => "📦 {$key} — {$price} ر.س",
        };
    }

    private function persistentKeyboard(): array
    {
        $rows = [[['text' => self::BTN_START]]];
        $row = [];
        foreach ($this->config['plans'] as $key => $plan) {
            $row[] = ['text' => $this->planButton($key, (int) $plan['price'])];
            if (count($row) === 2) {
                $rows[] = $row;
                $row = [];
            }
        }
        if ($row !== []) {
            $rows[] = $row;
        }
        $pharmacyBtn = (string) ($this->config['area_codes']['pharmacy']['button'] ?? '💊 أكواد الصيدلية');
        $groceryBtn = (string) ($this->config['area_codes']['grocery']['button'] ?? '🛒 أكواد البقالة');
        $rows[] = [
            ['text' => $pharmacyBtn],
            ['text' => $groceryBtn],
        ];
        $rows[] = [['text' => $this->howToRunButton()]];
        $rows[] = [['text' => self::BTN_CANCEL]];
        return [
            'keyboard' => $rows,
            'resize_keyboard' => true,
            'is_persistent' => true,
        ];
    }

    private function isStartButton(string $text): bool
    {
        return in_array(mb_strtolower(trim($text)), [mb_strtolower(self::BTN_START), 'بدء', 'start', '/start'], true)
            || $text === self::BTN_START;
    }

    private function planKeyFromButtonText(string $text): ?string
    {
        foreach ($this->config['plans'] as $key => $plan) {
            if ($text === $this->planButton($key, (int) $plan['price'])) {
                return $key;
            }
        }
        return null;
    }

    private function planButton(string $key, int $price): string
    {
        return match ($key) {
            'month' => "📦 شهر — {$price} ر.س",
            'two_months' => "📦 شهرين — {$price} ر.س",
            'quarter' => "📦 3 أشهر — {$price} ر.س",
            'lifetime' => "♾ مدى الحياة — {$price} ر.س",
            default => "{$key} — {$price} ر.س",
        };
    }

    private function areaInfoKeyFromButton(string $text): ?string
    {
        foreach ($this->config['area_codes'] ?? [] as $key => $info) {
            if ($text === (string) ($info['button'] ?? '')) {
                return $key;
            }
        }
        return null;
    }

    private function sendAreaCodesInfo(int $chatId, string $key): void
    {
        $info = $this->config['area_codes'][$key] ?? null;
        if (! is_array($info)) {
            return;
        }

        $label = (string) ($info['label'] ?? $key);
        $areaId = (int) ($info['area_id'] ?? 0);
        $code = (string) ($info['code'] ?? '');
        $areaLine = $areaId > 0 ? "\n🆔 رقم المنطقة: <b>{$areaId}</b>" : '';

        $intro = $key === 'pharmacy'
            ? 'كود استهداف <b>الصيدلية</b> في التطبيق (بعد تفعيل الاشتراك):'
            : 'كود استهداف <b>البقالة</b> في التطبيق (بعد تفعيل الاشتراك):';

        $this->send(
            $chatId,
            "📍 <b>{$label}</b>\n\n"
            .$intro."\n\n"
            .'<code>'.htmlspecialchars($code, ENT_QUOTES, 'UTF-8')."</code>{$areaLine}\n\n"
            ."ℹ️ الصقه في بطاقة <b>TARGETING</b> بالشاشة الرئيسية ثم احفظ.\n"
            .'📖 للخطوات الكاملة: اضغط <b>طريقة التشغيل</b>.'
        );
    }

    private function howToRunButton(): string
    {
        return (string) ($this->config['how_to_run_button'] ?? '📖 طريقة التشغيل');
    }

    private function sendHowToRunGuide(int $chatId): void
    {
        $this->send(
            $chatId,
            "📖 <b>طريقة تشغيل البوت في WellzPro</b>\n\n"
            ."<b>1️⃣ لصق الجلسة</b>\n"
            ."• أيقونة <b>الحساب 👤</b> أعلى الشاشة الرئيسية\n"
            ."• سجّل دخول (إقامة + كلمة سر) أو «لصق JSON» من رسالة الإدارة\n\n"
            ."<b>2️⃣ لصق كود المنطقة</b>\n"
            ."• الشاشة الرئيسية → بطاقة <b>TARGETING</b>\n"
            ."• الصق الكود (مثل <code>YAN-KHU</code> أو <code>YAN-SIH-KHU</code>)\n"
            ."• اضغط زر <b>حفظ</b> بجانب الحقل\n\n"
            ."<b>3️⃣ تفعيل الزمن الاحتياطي</b>\n"
            ."• بطاقة <b>الدوام الثاني (احتياطي)</b>\n"
            ."• فعّل الدوام الثاني إن أردت وردية احتياطية\n\n"
            ."<b>4️⃣ ضبط Fast Mode و Dual Tap</b>\n"
            ."• بطاقة <b>PERFORMANCE</b> في الشاشة الرئيسية\n"
            ."• فعّل <b>Fast Mode</b> (استطلاع أسرع)\n"
            ."• فعّل <b>Dual Tap</b> (حجز مزدوج) إن رغبت\n\n"
            ."<b>5️⃣ تشغيل البوت ومراقبة السجلات</b>\n"
            ."• زر <b>حفظ 💾</b> أسفل الشاشة أو مفتاح <b>BOT</b> أعلى اليمين\n"
            ."• راقب <b>السجلات</b> في أسفل الشاشة الرئيسية\n\n"
            .'💊 <code>YAN-SIH-KHU</code> — صيدلية | 🛒 <code>YAN-KHU</code> — بقالة'
        );
    }

    private function send(int $chatId, string $text, ?array $replyMarkup = null, bool $withKeyboard = true): void
    {
        $this->sendAndGetMessageId($chatId, $text, $replyMarkup, $withKeyboard);
    }

    private function sendAndGetMessageId(int $chatId, string $text, ?array $replyMarkup, bool $withKeyboard): ?int
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];
        if ($replyMarkup !== null) {
            $payload['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
        } elseif ($withKeyboard) {
            $payload['reply_markup'] = json_encode($this->persistentKeyboard(), JSON_UNESCAPED_UNICODE);
        }
        $json = $this->apiRequest('sendMessage', $payload, true);
        return is_array($json) ? (int) ($json['result']['message_id'] ?? 0) : null;
    }

    private function loadSession(int $chatId): ?array
    {
        $path = $this->dataDir.'/sessions/'.$chatId.'.json';
        if (! is_file($path)) {
            return null;
        }
        $data = json_decode(file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    private function saveSession(int $chatId, array $data): void
    {
        file_put_contents($this->dataDir.'/sessions/'.$chatId.'.json', json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    private function clearSession(int $chatId): void
    {
        $path = $this->dataDir.'/sessions/'.$chatId.'.json';
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function cancelPendingOrder(int $chatId): void
    {
        $session = $this->loadSession($chatId);
        if (! is_array($session)) {
            return;
        }
        $orderId = (string) ($session['order_id'] ?? '');
        if ($orderId === '') {
            return;
        }
        $orderPath = $this->dataDir.'/orders/'.$orderId.'.json';
        if (! is_file($orderPath)) {
            return;
        }
        $order = json_decode(file_get_contents($orderPath), true);
        if (! is_array($order) || ! in_array($order['status'] ?? '', ['awaiting_transfer', 'awaiting_admin'], true)) {
            return;
        }
        $order['status'] = 'cancelled';
        $order['cancelled_at'] = date('c');
        file_put_contents($orderPath, json_encode($order, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function apiRequest(string $method, array $params, bool $isPost = false): ?array
    {
        $url = 'https://api.telegram.org/bot'.$this->token.'/'.$method;
        if (! $isPost) {
            $url .= '?'.http_build_query($params);
        }
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $isPost ? 20 : 35,
            CURLOPT_SSL_VERIFYPEER => true,
        ];
        if ($isPost) {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $params;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        curl_close($ch);
        if ($body === false) {
            return null;
        }
        $json = json_decode($body, true);
        return is_array($json) && ($json['ok'] ?? false) ? $json : null;
    }

    private function apiGet(string $method, array $params): ?array
    {
        $json = $this->apiRequest($method, $params, false);
        return $json !== null ? ($json['result'] ?? []) : null;
    }

    private function apiPost(string $method, array $params): bool
    {
        return $this->apiRequest($method, $params, true) !== null;
    }
}

if (PHP_SAPI === 'cli') {
    (new WellzTelegramBot($config))->run();
}
