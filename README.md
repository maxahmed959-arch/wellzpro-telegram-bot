# WellzPro Telegram Bot — منصة إدارة اشتراكات

بوت تيليجرام لبيع وتفعيل اشتراكات **Samurai MiniBot** مع كتابة الأكواد مباشرة في **Firebase**.

## الميزات

| للعميل | للأدمن |
|--------|--------|
| خطط اشتراك + تحويل بنكي | لوحة تحكم `/panel` |
| أكواد المناطق + تحميل APK | توليد أكواد في Firebase `/gen` |
| طريقة التشغيل | إحصائيات `/stats` + تقرير CSV `/report` |
| | صلاحيات: سوبر أدمن · مدير · مشرف |
| | تسليم تلقائي (اختياري) + تذكير انتهاء (cron) |

## التثبيت السريع (Render)

1. ارفع المشروع إلى GitHub `wellzpro-telegram-bot`
2. Render → Web Service → ربط المستودع
3. Environment:

| المتغير | مطلوب | الوصف |
|---------|--------|--------|
| `TELEGRAM_BOT_TOKEN` | ✅ | من BotFather |
| `TELEGRAM_ADMIN_IDS` | ✅ | رقم تيليجرام للأدمن |
| `FIREBASE_SERVICE_ACCOUNT` | ✅ | محتوى `service-account.json` كاملاً |
| `TELEGRAM_ADMIN_PIN` | | رمز `/admin` (افتراضي WellzPro2026) |
| `SUPER_ADMIN_IDS` | | أرقام السوبر أدمن |
| `SUPER_ADMIN_PIN` | | رمز إدارة المسؤولين |
| `AUTO_DELIVER_ON_PROOF` | | `true` لتسليم تلقائي بعد الإيصال |
| `RATE_LIMIT_PER_MINUTE` | | حد الطلبات (افتراضي 5) |

4. Manual Deploy

## أوامر الأدمن

```
/panel          لوحة تحكم (أزرار)
/gen            توليد رمز — أزرار المدد
/gen month 3    3 أكواد شهر
/stats          إحصائيات + مخطط نصي
/report         تقرير CSV
/codes          مخزون محلي
/orders         طلبات معلقة
/off CODE       إيقاف رمز (سوبر)
/del CODE       حذف رمز غير مُفعّل (سوبر)
/add_admin PIN user_id admin|moderator
/remove_admin PIN user_id
/roles          قائمة المسؤولين
```

Reply على «طلب جديد» → License Key أو `/sendcode`

## الصلاحيات

| الدور | التوليد | الإحصائيات | التسليم | حذف/إيقاف | إدارة أدمن |
|-------|---------|------------|---------|-----------|------------|
| سوبر أدمن | ✅ | ✅ | ✅ | ✅ | ✅ |
| مدير | ✅ | ✅ | ✅ | ❌ | ❌ |
| مشرف | ❌ | ❌ | ✅ | ❌ | ❌ |

## Cron (تذكير انتهاء الاشتراك)

على Render: **Cron Job** يومي:

```
php scripts/cron-daily.php
```

## هيكل المشروع

```
bot.php              نقطة الدخول + تدفق العميل
config.php           الإعدادات
src/
  FirebaseClient.php اتصال Firebase
  LicenseManager.php توليد/حذف/تعطيل
  RoleManager.php    صلاحيات متعددة
  StatsService.php   إحصائيات وCSV
  AdminHandler.php   أوامر لوحة الأدمن
  AuditLogger.php    سجل تدقيق
  RateLimiter.php    منع الإساءة
  CronService.php    تذكيرات الانتهاء
  KeyboardBuilder.php أزرار Inline
scripts/cron-daily.php
```

## اختبار محلي

```powershell
cd telegram-bot
# ضع FIREBASE_CREDENTIALS أو FIREBASE_SERVICE_ACCOUNT في .env
php bot.php
```

## Build

`2026-07-08-admin-platform`
