<div dir="rtl" lang="ar">

# أداة سطر الأوامر في Whity

[English](Whity-CLI.md)

تثبّت الأداة `whity` نسخة Whity مستضافة ذاتيًا على Docker وتحدّثها. وهي تأتي من حزمة npm باسم `@whity/cli`، وتعمل على Windows (PowerShell 5.1 وما بعده) وLinux وmacOS.

تظهر رسائل الأداة بالإنجليزية فقط، لأن وحدة تحكم PowerShell 5.1 تشوّه النص العربي ولا تعرضه من اليمين إلى اليسار. لذلك يأتي هذا الدليل بالعربية، وتبقى أسماء الأوامر والملفات كما هي.

هذه الأداة غير `bin/whity-cli`، فتلك أداة PHP تعمل على الخادم وتتعامل مع قاعدة البيانات مباشرة. أما `whity` فتعمل على جهازك وتستدعي `bin/whity-cli` داخل الحاويات، فتنفَّذ عمليات الترحيل والبيانات الأولية دائمًا بشيفرة الإصدار المثبّت.

## المتطلبات

- Node 20 أو أحدث
- Docker مع Compose v2 (أو Docker Desktop على Windows وmacOS)

<div dir="ltr">

```bash
npm install -g @whity/cli
```

</div>

## التثبيت

<div dir="ltr">

```bash
whity install
```

</div>

يثبّت هذا الأمر أحدث إصدار في المجلد `./whity`. ولتحديد إصدار بعينه، اكتب مثلًا `whity install v0.2.22`. وأقدم إصدار مدعوم هو v0.2.21.

تتحقق الأداة من Docker ومن المنافذ، ثم تكتب الملفات الموضحة أدناه مع أسرار مولّدة، وتنزّل الصور، وتشغّل قاعدة البيانات، وتنفّذ الترحيل والبيانات الأولية، ثم تشغّل كل شيء وتفحصه. وفي النهاية تعرض العنوان والبريد الإلكتروني للمسؤول.

تبقى كلمة المرور في الملف `.env` باسم `INITIAL_SYSTEM_ADMIN_PASSWORD`، حتى لا تظهر في سجل الطرفية. غيّرها بعد تسجيل الدخول.

<div dir="ltr">

| Option | Default |
|---|---|
| `--dir <path>` | `./whity` |
| `--url <url>` | `http://localhost:<web port>` |
| `--web-port <port>` | `3100` |
| `--api-port <port>` | `8100` |
| `--bind <address>` | `127.0.0.1` |
| `--admin-email <email>` | `system@whity.local` |

</div>

إذا فشل التثبيت، فأصلح السبب ثم أعد تشغيل الأمر نفسه. ستكمل الأداة بالأسرار نفسها.

### استخدام عنوان حقيقي

تحمل ملفات تعريف الارتباط الخاصة بالجلسة الخاصية `Secure` خارج بيئة التطوير، ولا تحتفظ بها المتصفحات إلا عبر HTTPS أو على localhost. لذلك يرفض `whity install` أي عنوان `http://` غير localhost، لأن صفحة الدخول كانت ستظهر دون أن يتمكن أحد من البقاء مسجّلًا الدخول.

ضع وكيلًا عكسيًا بشهادة أمام منفذ الويب، وثبّت باستخدام عنوانه:

<div dir="ltr">

```bash
whity install --url https://app.example.com
```

</div>

واضبط أيضًا `TRUSTED_PROXY_HOPS` في `.env` ليرى الخادم عنوان كل عميل الحقيقي ([دليل النشر](DEPLOYMENT_GUIDE.md))، ثم شغّل `docker compose up -d` داخل مجلد التثبيت.

## محتويات مجلد التثبيت

- **`.env`**: الإعدادات والأسرار. أبقِه خاصًا واحتفظ بنسخة احتياطية منه، فالنسخة الاحتياطية لقاعدة البيانات وحدها لا تفك تشفير ما يحميه `ENCRYPTION_KEY`.
- **`docker-compose.yml`**: ملف Compose الخاص بالإصدار المثبّت، ويستبدله `whity update`.
- **`docker-compose.override.yml`**: ملف اختياري لتعديلاتك. يدمجه Compose ولا تمسّه الأداة.
- **`backups/`**: مجلد لكل عملية تحديث.

الملفات المرفوعة في وحدة التخزين `storage`، وقاعدة البيانات في `postgres_data`. **الأمر `docker compose down -v` يحذف الاثنتين.**

## التحديث

<div dir="ltr">

```bash
whity update
```

</div>

ينقلك هذا الأمر إلى أحدث إصدار. ولتحديد إصدار، اكتب مثلًا `whity update v0.2.22`. وأضف `--dir` إذا لم يكن التثبيت في `./whity`.

1. يقرأ الإصدار المثبّت، ويرفض الرجوع إلى إصدار أقدم لأن عمليات الترحيل تسير إلى الأمام فقط.
2. يتحقق من أن إعداداتك تناسب ملف Compose الجديد.
3. ينزّل الصور الثلاث بالإصدار الجديد. يبقى Whity عاملًا خلال ذلك، فلا يسبب التنزيل البطيء توقفًا، ولا يغيّر التنزيل الفاشل شيئًا.
4. يوقف كل شيء ما عدا قاعدة البيانات. يبدأ التوقف من هذه الخطوة.
5. يأخذ نسخة احتياطية من قاعدة البيانات ويثبت صلاحيتها: يستعيدها في قاعدة بيانات مؤقتة ويقارن عدد الصفوف مع القاعدة الحية.
6. ينفّذ عمليات الترحيل بالصورة الجديدة عبر `bin/whity-cli`.
7. يشغّل الإصدار الجديد.
8. يتحقق من أن `/api/health` و`/web-build` وخدمة العرض تُظهر الإصدار الجديد، وأنه لا توجد عمليات ترحيل معلّقة، وأن كل الخدمات تعمل دون إعادة تشغيل متكررة.

إذا فشلت خطوة قبل الترحيل، يعود Whity إلى العمل بالإصدار القديم دون أي تغيير. تشمل النسخة الاحتياطية قاعدة البيانات والإعدادات، ولا تشمل الملفات المرفوعة التي تبقى في وحدة التخزين خلال التحديث.

### إذا فشل التحديث

ابتداءً من خطوة الترحيل، يتوقف التحديث ويعرض الأوامر الدقيقة للعودة، وهي نفسها في PowerShell وbash وzsh. **نفّذها واحدًا تلو الآخر**، لأن `docker compose exec` يقرأ المدخلات وقد يبتلع الأسطر التالية إذا لصقتها كلها معًا.

تكون بهذا الشكل، مع مساراتك وأسمائك:

<div dir="ltr">

```bash
cd "<install folder>"
docker compose down
cp backups/<backup>/.env .env
cp backups/<backup>/docker-compose.yml docker-compose.yml
docker compose up -d --wait postgres
docker compose cp backups/<backup>/whity_core.dump postgres:/tmp/restore.dump
docker compose exec -T postgres dropdb -U whity --force whity_core
docker compose exec -T postgres createdb -U whity whity_core
docker compose exec -T postgres pg_restore -U whity -d whity_core --no-owner --exit-on-error /tmp/restore.dump
docker compose exec -T postgres rm /tmp/restore.dump
docker compose up -d --wait
rm .whity-update-in-progress.json
```

</div>

إعادة تشغيل `whity update` تعرض هذه الأوامر مرة أخرى ولا تغيّر شيئًا. وإذا أصلحت السبب وأردت المتابعة دون استعادة، فأضف `--force`.

## حل المشكلات

- **ظهور `denied` أثناء تنزيل الصور:** الصور عامة، فالسبب عادةً تسجيل دخول قديم إلى `ghcr.io` في Docker. نفّذ `docker logout ghcr.io`.
- **منفذ مستخدم:** اختر غيره باستخدام `--web-port` و`--api-port`.

</div>
