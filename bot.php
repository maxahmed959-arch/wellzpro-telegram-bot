<?php

/**
 * WellzPro Telegram Bot — Samurai MiniBot edition.
 * العميل: خطط → حساب بنكي → إشعار تحويل → مفتاح التفعيل.
 * الأدمن: Reply على رسالة الطلب → يرسل License Key للعميل.
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

    private const BOT_BUILD = '2026-06-08-no-device-id';

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
        echo "دليل طريقة التشغيل: نص فقط (بدون فيديو)\n";
        $apkUrl = $this->appDownloadUrl();
        if ($apkUrl !== '') {
            echo "APK: {$apkUrl}\n";
        } else {
            echo "⚠️  APP_DOWNLOAD_URL غير مضبوط\n";
        }
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

        if ($text !== '' && $this->isStartLikeCommand($text)) {
            $this->clearSession($chatId);
            $this->sendPlansMenu($chatId);
            return;
        }

        if ($text !== '' && str_starts_with($text, '/')) {
            if ($this->commandName($text) === '/videoid' && $this->isAdmin($fromId)) {
                $this->onVideoIdCommand($chatId, $message);
                return;
            }
            if ($this->onCommand($chatId, $fromId, $text)) {
                return;
            }
        }

        if (isset($message['video']) && $this->isAdmin($fromId)) {
            $caption = trim((string) ($message['caption'] ?? ''));
            if ($caption !== '' && $this->commandName($caption) === '/videoid') {
                $this->sendVideoFileIdHelp($chatId, $message['video']);
                return;
            }
        }

        if ($text !== '' && $this->handleMenuButton($chatId, $from, $text)) {
            return;
        }

        if ($this->isAdmin($fromId) && isset($message['reply_to_message'])) {
            if ($text !== '' && $this->commandName($text) === '/videoid') {
                $this->onVideoIdCommand($chatId, $message);
                return;
            }
            $this->onAdminReply($chatId, $message);
            return;
        }

        if ($this->isAdmin($fromId) && ! $this->isCustomerInFlow($chatId) && $this->isAdminOnlyPlainText($text)) {
            $this->send($chatId, 'أنت مسجّل كأدمن.\nللرد على عميل: <b>Reply</b> على رسالة «طلب جديد».\n\nللعملاء: /start أو ▶️ بدء', null, false);
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

    /** أزرار القائمة — تعمل للجميع (عميل وأدمن) قبل أي حجز. */
    private function handleMenuButton(int $chatId, array $from, string $text): bool
    {
        if ($this->isStartButton($text) || in_array($text, ['شراء', 'اشتراك', 'خطط'], true)) {
            $this->clearSession($chatId);
            $this->sendPlansMenu($chatId);

            return true;
        }

        if ($text === self::BTN_CANCEL) {
            $this->cancelPendingOrder($chatId);
            $this->clearSession($chatId);
            $this->send($chatId, 'تم الإلغاء. اضغط ▶️ بدء.');

            return true;
        }

        if ($text === $this->areaCodesButton() || str_contains($text, 'أكواد المناطق')) {
            $this->sendAllAreaCodes($chatId);

            return true;
        }

        if ($text === $this->howToRunButton() || str_contains($text, 'طريقة التشغيل')) {
            $this->sendHowToRunGuide($chatId);

            return true;
        }

        if ($text === $this->appDownloadButton() || str_contains($text, 'تحميل التطبيق') || str_contains($text, 'تجميل التطبيق')) {
            $this->sendAppDownload($chatId);

            return true;
        }

        $planKey = $this->planKeyFromButtonText($text);
        if ($planKey !== null) {
            $this->clearSession($chatId);
            $this->beginPlan($chatId, $from, $planKey);

            return true;
        }

        return false;
    }

    private function commandName(string $text): string
    {
        $parts = preg_split('/\s+/u', trim($text), 2);
        $cmd = strtolower($parts[0] ?? '');
        if (str_contains($cmd, '@')) {
            $cmd = substr($cmd, 0, (int) strpos($cmd, '@'));
        }

        return $cmd;
    }

    private function commandArg(string $text): string
    {
        $parts = preg_split('/\s+/u', trim($text), 2);

        return trim($parts[1] ?? '');
    }

    private function isStartLikeCommand(string $text): bool
    {
        if (! str_starts_with($text, '/')) {
            return false;
        }
        $cmd = $this->commandName($text);

        return $cmd === '/start' || $cmd === '/buy' || $cmd === '/plans';
    }

    /** نص عادي من الأدمن — لا يعترض أزرار العميل (بدء / خطط). */
    private function isAdminOnlyPlainText(string $text): bool
    {
        if ($text === '') {
            return false;
        }
        if (str_starts_with($text, '/')) {
            return false;
        }
        if ($this->isStartButton($text) || in_array($text, ['شراء', 'اشتراك', 'خطط'], true)) {
            return false;
        }
        if ($this->planKeyFromButtonText($text) !== null) {
            return false;
        }
        if ($text === $this->areaCodesButton()
            || str_contains($text, 'أكواد المناطق')
            || $text === $this->howToRunButton()
            || $text === $this->appDownloadButton()) {
            return false;
        }
        if ($text === self::BTN_CANCEL) {
            return false;
        }

        return true;
    }

    private function onCommand(int $chatId, int $fromId, string $text): bool
    {
        $cmd = $this->commandName($text);
        $arg = $this->commandArg($text);

        if ($cmd === '/admin') {
            $pin = $arg !== '' ? $arg : trim(substr($text, strlen('/admin')));
            if ($pin === ($this->config['admin_pin'] ?? '')) {
                $this->registerAdmin($fromId);
                $this->send($chatId, "✅ تم تسجيلك كأدمن.\nستصلك الطلبات هنا في محادثة <b>WellzProShopBot</b>.\n\nللرد على عميل: <b>Reply</b> على رسالة «طلب جديد» وأرسل <b>مفتاح التفعيل License Key</b>.", null, false);
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

        if (in_array($cmd, ['/start', '/buy', '/plans', '/refresh', '/تحديث'], true)) {
            $this->clearSession($chatId);
            $this->sendPlansMenu($chatId);
            return true;
        }

        if ($cmd === '/help') {
            if ($this->isAdmin($fromId)) {
                $this->send($chatId, "/orders — طلبات معلقة\n/admin — تسجيل أدمن\n/videoid — معرّف فيديو طريقة التشغيل\nReply على «طلب جديد» → أرسل License Key للعميل", null, false);
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
        $session = $this->loadSession($chatId);
        if ($session !== null && ($session['state'] ?? '') === 'awaiting_transfer') {
            $this->send($chatId, "📸 أرسل <b>صورة إشعار التحويل</b> هنا.\n(أو اضغط ❌ إلغاء)");
        }
    }

    private function formatBankBlock(): string
    {
        $bank = (string) ($this->config['bank_account'] ?? '');
        $bankName = (string) ($this->config['bank_name'] ?? 'urpay');
        $holder = trim((string) ($this->config['bank_holder'] ?? ''));
        $holderLine = $holder !== '' ? "\n👤 اسم المستفيد: <b>{$holder}</b>" : '';

        return "🏦 <b>التحويل البنكي</b>\n"
            ."البنك: <b>{$bankName}</b>\n"
            ."رقم الحساب: <code>{$bank}</code>{$holderLine}";
    }

    private function formatOrderSummary(array $order): string
    {
        $orderId = (string) ($order['id'] ?? '');

        return "📋 <b>ملخص الطلب</b> — <code>{$orderId}</code>\n"
            .'📦 الخطة: <b>'.($order['plan_label'] ?? '')."</b>\n"
            .'💰 المبلغ: <b>'.($order['price'] ?? 0)." ريال</b>\n";
    }

    private function createPendingOrder(int $chatId, array $from, string $planKey, array $plan): string
    {
        $orderId = 'ord_'.bin2hex(random_bytes(4));
        $order = [
            'id' => $orderId,
            'chat_id' => $chatId,
            'username' => $from['username'] ?? null,
            'first_name' => trim(($from['first_name'] ?? '').' '.($from['last_name'] ?? '')),
            'plan' => $planKey,
            'plan_label' => $plan['label'],
            'price' => (int) $plan['price'],
            'status' => 'awaiting_transfer',
            'created_at' => date('c'),
        ];
        file_put_contents(
            $this->dataDir.'/orders/'.$orderId.'.json',
            json_encode($order, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        return $orderId;
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
        $order['proof_chat_id'] = $chatId;
        $order['proof_message_id'] = (int) ($message['message_id'] ?? 0);
        file_put_contents($orderPath, json_encode($order, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $this->clearSession($chatId);

        $this->send(
            $chatId,
            "✅ <b>تم استلام إشعار التحويل</b>\n\n"
            .$this->formatOrderSummary($order)."\n"
            ."طلبك قيد المراجعة.\n"
            ."سيصلك <b>مفتاح التفعيل License Key</b> بعد التأكيد.\n\n"
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

        $name = trim((string) ($order['first_name'] ?? ''));
        $nameLine = $name !== '' ? $name : 'عميل';
        $username = trim((string) ($order['username'] ?? ''));
        $userLine = $username !== '' ? "\n🔗 @{$username}" : '';
        $fromChatId = (int) ($customerMessage['chat']['id'] ?? 0);
        $proofMsgId = (int) ($customerMessage['message_id'] ?? 0);

        $text = "🆕 <b>طلب جديد</b>\n\n"
            ."👤 {$nameLine}{$userLine}\n"
            .$this->formatOrderSummary($order)."\n"
            ."📸 إشعار التحويل مرفق أعلاه.\n\n"
            ."⬇️ <b>للرد على العميل:</b>\n"
            ."اضغط <b>رد Reply</b> على هذه الرسالة\n"
            ."وأرسل <b>مفتاح التفعيل</b> (مثل <code>WELLZ-XXXX-XXXX</code>)";

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
                $this->saveNotifyMap($adminChat, $msgId, (int) ($order['chat_id'] ?? 0), (string) ($order['id'] ?? ''));
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

        $adminText = trim((string) ($message['text'] ?? ''));
        if ($adminText !== '' && $this->commandName($adminText) === '/videoid') {
            $this->onVideoIdCommand($adminChatId, $message);

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

        if ($adminText === '') {
            $this->send($adminChatId, '❌ أرسل نصاً (License Key) كرد على رسالة «طلب جديد».', null, false);
            return;
        }

        $customerMsg = "🎉 <b>تم تفعيل طلبك</b>\n<code>{$orderId}</code>\n\n"
            ."🔑 <b>مفتاح التفعيل:</b>\n<code>{$adminText}</code>\n\n"
            ."📲 افتح Samurai → Vol↑ → الصق المفتاح → LOGIN";
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
        $data = (string) ($cb['data'] ?? '');
        $message = $cb['message'] ?? null;
        $chatId = is_array($message) ? (int) ($message['chat']['id'] ?? 0) : 0;

        if (str_starts_with($data, 'apk:')) {
            $variantKey = substr($data, 4);
            $this->apiPost('answerCallbackQuery', [
                'callback_query_id' => $id,
                'text' => 'جارٍ إرسال الملف…',
            ]);
            if ($chatId > 0) {
                $this->sendApkVariant($chatId, $variantKey);
            }

            return;
        }

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
        $orderId = $this->createPendingOrder($chatId, $from, $planKey, $plan);
        $this->saveSession($chatId, [
            'state' => 'awaiting_transfer',
            'order_id' => $orderId,
            'plan' => $planKey,
            'plan_label' => $plan['label'],
            'price' => (int) $plan['price'],
        ]);
        $this->send(
            $chatId,
            "✅ <b>{$plan['label']}</b>\n"
            ."💰 المبلغ: <b>{$plan['price']} ريال</b>\n\n"
            .$this->formatBankBlock()."\n\n"
            ."📸 بعد التحويل، <b>أرسل صورة إشعار التحويل</b> هنا.\n"
            .'(أو ❌ إلغاء)'
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
            '🥋 <b>WellzPro</b> · Samurai MiniBot',
            '',
            'اضغط <b>▶️ بدء</b> أو اختر مدة الاشتراك:',
            '📍 📖 📲 — أكواد المناطق + طريقة التشغيل + تحميل Samurai',
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
            default => "📦 {$key} — {$price} ر.س",
        };
    }

    private function persistentKeyboard(): array
    {
        $plans = $this->config['plans'] ?? [];
        $rows = [[['text' => self::BTN_START]]];
        $rows[] = [
            ['text' => $this->planButton('month', (int) ($plans['month']['price'] ?? 25))],
            ['text' => $this->planButton('two_months', (int) ($plans['two_months']['price'] ?? 50))],
            ['text' => $this->planButton('quarter', (int) ($plans['quarter']['price'] ?? 75))],
        ];
        $rows[] = [['text' => $this->areaCodesButton()]];
        $rows[] = [
            ['text' => $this->howToRunButton()],
            ['text' => $this->appDownloadButton()],
        ];
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
            default => "📦 {$key} — {$price} ر.س",
        };
    }

    private function areaCodesButton(): string
    {
        return (string) ($this->config['area_codes_button'] ?? '📍 أكواد المناطق');
    }

    private function sendAllAreaCodes(int $chatId): void
    {
        $areas = $this->config['area_codes'] ?? [];
        if ($areas === []) {
            $this->send($chatId, '❌ لا توجد أكواد مناطق. تواصل مع الإدارة.');

            return;
        }

        $lines = [
            '📍 <b>أكواد المناطق</b>',
            '',
            'الصق الكود في لوحة البوت (<b>Vol↑</b>) ثم <b>SAVE</b>:',
            '',
        ];

        foreach ($areas as $info) {
            if (! is_array($info)) {
                continue;
            }
            $label = trim((string) ($info['label'] ?? ''));
            $code = trim((string) ($info['code'] ?? ''));
            $areaId = (int) ($info['area_id'] ?? 0);
            $shift = trim((string) ($info['api_shift_code'] ?? ''));

            if ($code === '') {
                continue;
            }

            $title = $label !== '' ? $label : $code;
            $idPart = $areaId > 0 ? " · #{$areaId}" : '';
            $shiftPart = $shift !== '' ? " · {$shift}" : '';

            $lines[] = '• <b>'.htmlspecialchars($title, ENT_QUOTES, 'UTF-8').'</b>';
            $lines[] = '<code>'.htmlspecialchars($code, ENT_QUOTES, 'UTF-8')."</code>{$idPart}{$shiftPart}";
            $lines[] = '';
        }

        $lines[] = '📖 للخطوات الكاملة: اضغط <b>طريقة التشغيل</b>.';

        $this->send($chatId, implode("\n", $lines));
    }

    private function howToRunButton(): string
    {
        return (string) ($this->config['how_to_run_button'] ?? '📖 طريقة التشغيل');
    }

    private function appDownloadButton(): string
    {
        return (string) ($this->config['app_download_button'] ?? '📲 تحميل التطبيق');
    }

    private function appDownloadUrl(): string
    {
        return $this->normalizeApkDownloadUrl(trim((string) ($this->config['app_download_url'] ?? '')));
    }

    private function appDownloadFilename(): string
    {
        $name = trim((string) ($this->config['app_download_filename'] ?? ''));

        return $name !== '' ? $name : 'samu.apk';
    }

    /** يحوّل روابط صفحة Release إلى رابط تحميل مباشر إن أمكن. */
    private function normalizeApkDownloadUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        // releases/download/... — جاهز للتحميل المباشر
        if (str_contains($url, '/releases/download/')) {
            return $url;
        }

        // github.com/.../releases/tag/vX.Y.Z → releases/download/vX.Y.Z/filename
        if (preg_match('#github\.com/([^/]+/[^/]+)/releases/tag/([^/?#]+)#i', $url, $m)) {
            $file = $this->appDownloadFilename();

            return 'https://github.com/'.$m[1].'/releases/download/'.$m[2].'/'.$file;
        }

        return $url;
    }

    private function sendAppDownload(int $chatId): void
    {
        if ($this->apkReleaseBaseUrl() === '') {
            $this->send(
                $chatId,
                "❌ رابط التطبيق غير مضبوط بعد.\n\n"
                .'الإدارة: أضف <code>APP_DOWNLOAD_URL</code> في Render Environment '
                ."بصيغة:\n<code>https://github.com/USER/REPO/releases/download/v1.0.0/samu.8.apk</code>"
            );

            return;
        }

        $rows = [];
        foreach ($this->apkVariants() as $variant) {
            $key = (string) ($variant['key'] ?? '');
            $label = (string) ($variant['label'] ?? $key);
            if ($key === '') {
                continue;
            }
            $rows[] = [['text' => $label, 'callback_data' => 'apk:'.$key]];
        }

        if ($rows === []) {
            $this->send($chatId, '❌ لا توجد نسخ APK مضبوطة. تواصل مع الإدارة.');

            return;
        }

        $text = "📲 <b>اختر نسخة التطبيق</b>\n\n"
            ."• <b>النسخة الكاملة</b> — الأفضل لمعظم المستخدمين\n"
            ."• <b>v7a</b> — أجهزة قديمة (32-bit)\n"
            ."• <b>v8a</b> — أجهزة حديثة (64-bit)\n\n"
            .'سيُرسل الملف <b>داخل تيليجرام</b> — لا تفتح GitHub من المتصفح الداخلي.';

        $this->send($chatId, $text, ['inline_keyboard' => $rows]);
    }

    private function apkVariants(): array
    {
        $variants = $this->config['app_apk_variants'] ?? [];
        if (! is_array($variants)) {
            return [];
        }

        $out = [];
        foreach ($variants as $variant) {
            if (! is_array($variant)) {
                continue;
            }
            $filename = trim((string) ($variant['filename'] ?? ''));
            $key = trim((string) ($variant['key'] ?? ''));
            if ($filename === '' || $key === '') {
                continue;
            }
            $out[] = $variant;
        }

        return $out;
    }

    private function apkVariantByKey(string $key): ?array
    {
        foreach ($this->apkVariants() as $variant) {
            if (($variant['key'] ?? '') === $key) {
                return $variant;
            }
        }

        return null;
    }

    private function apkReleaseBaseUrl(): string
    {
        $url = $this->appDownloadUrl();
        if ($url === '') {
            return '';
        }
        $pos = strrpos($url, '/');
        if ($pos === false) {
            return '';
        }

        return substr($url, 0, $pos);
    }

    private function apkUrlForFilename(string $filename): string
    {
        $base = $this->apkReleaseBaseUrl();
        if ($base === '') {
            return '';
        }

        return $base.'/'.ltrim($filename, '/');
    }

    private function sendApkVariant(int $chatId, string $variantKey): void
    {
        $variant = $this->apkVariantByKey($variantKey);
        if ($variant === null) {
            $this->send($chatId, '❌ نسخة غير معروفة. اضغط 📲 تحميل التطبيق واختر من القائمة.');

            return;
        }

        $filename = (string) ($variant['filename'] ?? 'samu.apk');
        $url = $this->apkUrlForFilename($filename);
        if ($url === '') {
            $this->send($chatId, '❌ رابط التحميل غير مضبوط. تواصل مع الإدارة.');

            return;
        }

        $label = (string) ($variant['label'] ?? $filename);
        $hint = trim((string) ($variant['hint'] ?? ''));
        $caption = "📲 <b>WellzPro — {$label}</b>\n";
        if ($hint !== '') {
            $caption .= "{$hint}\n\n";
        }
        $caption .= 'ثبّت APK ثم اتبع <b>📖 طريقة التشغيل</b> للتفعيل.';

        $markup = json_encode($this->persistentKeyboard(), JSON_UNESCAPED_UNICODE);
        if ($this->sendApkDocument($chatId, $url, $filename, $caption, $markup)) {
            return;
        }

        $this->send(
            $chatId,
            "❌ تعذّر إرسال <code>{$filename}</code>.\n\n"
            ."تحقق من وجود الملف في GitHub Releases:\n"
            ."<code>{$url}</code>"
        );
    }

    /** يرسل APK كملف داخل Telegram (بدون فتح GitHub). */
    private function sendApkDocument(int $chatId, string $url, string $filename, string $caption, string $markup): bool
    {
        $local = $this->fetchUrlToTempFile($url);
        if ($local !== null) {
            $payload = [
                'chat_id' => $chatId,
                'document' => new CURLFile($local, 'application/vnd.android.package-archive', $filename),
                'caption' => $caption,
                'parse_mode' => 'HTML',
                'reply_markup' => $markup,
            ];
            $json = $this->apiRequest('sendDocument', $payload, true, 300);
            @unlink($local);
            if (is_array($json)) {
                return true;
            }
        }

        $payload = [
            'chat_id' => $chatId,
            'document' => $url,
            'caption' => $caption,
            'parse_mode' => 'HTML',
            'reply_markup' => $markup,
        ];
        $json = $this->apiRequest('sendDocument', $payload, true, 300);

        return is_array($json);
    }

    private function fetchUrlToTempFile(string $url): ?string
    {
        $tmpBase = tempnam(sys_get_temp_dir(), 'wellz_apk_');
        if ($tmpBase === false) {
            return null;
        }
        $path = $tmpBase.'.apk';
        @unlink($tmpBase);

        $fp = fopen($path, 'w+b');
        if ($fp === false) {
            return null;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 240,
            CURLOPT_CONNECTTIMEOUT => 25,
            CURLOPT_USERAGENT => 'WellzProTelegramBot/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if (! $ok || $code < 200 || $code >= 300) {
            @unlink($path);

            return null;
        }
        if (! is_file($path) || filesize($path) < 1_000_000) {
            @unlink($path);

            return null;
        }

        return $path;
    }

    private function onVideoIdCommand(int $chatId, array $message): void
    {
        $reply = $message['reply_to_message'] ?? null;
        if (is_array($reply) && isset($reply['video']) && is_array($reply['video'])) {
            $this->sendVideoFileIdHelp($chatId, $reply['video']);

            return;
        }

        $this->send(
            $chatId,
            "🎬 أرسل الفيديو أولاً، ثم <b>Reply</b> عليه وأرسل <code>/videoid</code>\n\n"
            .'أو أرفق الفيديو مع تعليق: <code>/videoid</code>',
            null,
            false
        );
    }

    private function sendVideoFileIdHelp(int $chatId, array $video): void
    {
        $fileId = trim((string) ($video['file_id'] ?? ''));
        if ($fileId === '') {
            $this->send($chatId, '❌ لم يُعثر على file_id.', null, false);

            return;
        }

        $this->send(
            $chatId,
            "✅ <b>معرّف الفيديو</b> (لزر طريقة التشغيل):\n\n"
            ."<code>{$fileId}</code>\n\n"
            ."أضف في Render → Environment:\n"
            ."<code>HOW_TO_RUN_VIDEO_FILE_ID={$fileId}</code>\n\n"
            .'أو في <code>telegram-bot/.env</code> ثم أعد تشغيل البوت.',
            null,
            false
        );
    }

    private function howToRunGuideText(): string
    {
        return "📖 <b>طريقة التشغيل — Wellz Pro MiniBot</b>\n\n"
            ."<b>1️⃣ تحميل وتثبيت التطبيق</b>\n"
            ."• اضغط <b>📲 تحميل التطبيق</b> ثم اختر النسخة المناسبة\n"
            ."• <b>النسخة الكاملة</b> للأغلب — أو v7a/v8a حسب جهازك\n"
            ."• ثبّت APK (اسمح بالتثبيت من مصادر غير معروفة إن طُلب)\n"
            ."• احذف أي نسخة قديمة من Samurai قبل التثبيت\n\n"
            ."<b>2️⃣ تسجيل الدخول في Samurai</b>\n"
            ."• افتح تطبيق Samurai وسجّل دخولك ككابتن عادي\n"
            ."• MiniBot يستخدم نفس الجلسة — لا تسجيل منفصل\n\n"
            ."<b>3️⃣ تفعيل خدمة MiniBot (مرة واحدة)</b>\n"
            ."• الإعدادات → إمكانية الوصول (Accessibility)\n"
            ."• فعّل خدمة <b>MiniBot</b>\n"
            ."• ضروري لاختصار <b>Vol↑</b>\n\n"
            ."<b>4️⃣ تفعيل الاشتراك</b>\n"
            ."• اختر الخطة من هذا البوت (▶️ بدء)\n"
            ."• حوّل المبلغ إلى الحساب البنكي الظاهر\n"
            ."• أرسل <b>صورة إشعار التحويل</b> للبوت\n"
            ."• الصق <b>مفتاح التفعيل</b> في شاشة الاشتراك (Vol↑) → <b>تسجيل الدخول - LOGIN</b>\n\n"
            ."<b>5️⃣ لوحة البوت</b>\n"
            ."• بعد التفعيل: <b>Vol↑</b> يفتح لوحة <b>Wellz pro 🇸🇩</b>\n"
            ."• أدخل كود المنطقة (مثل <code>RUH-FLH</code>) → <b>SAVE</b>\n"
            ."• اضبط أوقات الشفت → <b>SAVE</b> لكل صف\n"
            ."• فعّل <b>FAST</b> / <b>DUAL</b> حسب حاجتك\n\n"
            ."<b>6️⃣ تشغيل وإيقاف الحجز</b>\n"
            ."• زر <b>STATE: ON/OFF</b> لتشغيل أو إيقاف البوت\n"
            ."• <b>Vol↓</b> = ضبط سرعة التمرير (اختياري)\n"
            ."• <b>CLOSE</b> = إغلاق اللوحة\n\n"
            ."<b>7️⃣ انتهاء الاشتراك</b>\n"
            ."• عند انتهاء الاشتراك يتوقف البوت تلقائياً\n"
            ."• تظهر شاشة الاشتراك مجدداً للتجديد\n\n"
            ."⚠️ <b>ملاحظات</b>\n"
            ."• يجب أن تكون داخل تطبيق Samurai عند استخدام Vol↑\n"
            ."• تأكد أن <b>showBookShifts</b> مفعّل في حسابك\n\n"
            .'ℹ️ أكواد المناطق: اضغط <b>📍 أكواد المناطق</b> من الأزرار أدناه.';
    }

    private function howToRunVideoCaption(): string
    {
        return "📖 <b>طريقة تشغيل Wellz Pro MiniBot</b>\n\n"
            ."🎬 شاهد الفيديو — الخطوات الكاملة في الرسالة التالية ⬇️";
    }

    private function howToRunVideoFileId(): string
    {
        return trim((string) ($this->config['how_to_run_video_file_id'] ?? ''));
    }

    private function howToRunVideoUrl(): string
    {
        return trim((string) ($this->config['how_to_run_video_url'] ?? ''));
    }

    private function howToRunVideoLocalPath(): string
    {
        $path = __DIR__.'/assets/how-to-run.mp4';

        return is_file($path) ? $path : '';
    }

    private function hasHowToRunVideo(): bool
    {
        return $this->howToRunVideoFileId() !== ''
            || $this->howToRunVideoUrl() !== ''
            || $this->howToRunVideoLocalPath() !== '';
    }

    private function sendHowToRunGuide(int $chatId): void
    {
        $this->send($chatId, $this->howToRunGuideText());
    }

    private function sendHowToRunVideo(int $chatId, string $fullGuideText): bool
    {
        $markup = json_encode($this->persistentKeyboard(), JSON_UNESCAPED_UNICODE);
        // caption قصير دائماً — النص الكامل طويل وقد يفشل sendVideo (حد 1024 حرف)
        $caption = $this->howToRunVideoCaption();

        $payload = [
            'chat_id' => $chatId,
            'caption' => $caption,
            'parse_mode' => 'HTML',
            'reply_markup' => $markup,
            'supports_streaming' => 'true',
        ];

        $fileId = $this->howToRunVideoFileId();
        $videoUrl = $this->howToRunVideoUrl();
        $localPath = $this->howToRunVideoLocalPath();

        if ($fileId !== '') {
            $payload['video'] = $fileId;
        } elseif ($videoUrl !== '') {
            $payload['video'] = $videoUrl;
        } elseif ($localPath !== '') {
            $payload['video'] = new CURLFile($localPath, 'video/mp4', 'how-to-run.mp4');
        } else {
            return false;
        }

        $json = $this->apiRequest('sendVideo', $payload, true, 300);
        if (! is_array($json)) {
            unset($payload['parse_mode']);
            $json = $this->apiRequest('sendVideo', $payload, true, 300);
        }

        if (! is_array($json)) {
            return false;
        }

        $this->send($chatId, $fullGuideText);

        return true;
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
        if (is_array($json)) {
            return (int) ($json['result']['message_id'] ?? 0);
        }

        unset($payload['parse_mode']);
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

    private function apiRequest(string $method, array $params, bool $isPost = false, int $timeoutSec = 0): ?array
    {
        $url = 'https://api.telegram.org/bot'.$this->token.'/'.$method;
        if (! $isPost) {
            $url .= '?'.http_build_query($params);
        }
        $timeout = $timeoutSec > 0 ? $timeoutSec : ($isPost ? 20 : 35);
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $timeout,
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
        if (is_array($json) && ($json['ok'] ?? false)) {
            return $json;
        }
        if (is_array($json) && ! ($json['ok'] ?? true)) {
            echo '['.date('H:i:s')."] Telegram API {$method}: ".($json['description'] ?? 'error')."\n";
        }

        return null;
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
