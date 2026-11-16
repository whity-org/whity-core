/**
 * The install's words in Arabic (SU.7: Arabic when the system language is
 * Arabic). Used only where the console shows Unicode; see messages.js. Commands,
 * addresses, versions and file names stay in Latin. Every key here must exist in
 * the English catalogue (test/messages.test.js); a key missing here stays English.
 *
 * @param {{ok: string, no: string, warn: string, doing: string, dot: string}} MARK
 */
export function ar(MARK) {
  return {
    error: (message) => `${MARK.no} ${message}`,

    header: () => `مُثبِّت Whity Core  ${MARK.dot}  مجاني ومفتوح المصدر (AGPL-3.0)`,
    done: (label, detail) => `${MARK.ok} ${label}${detail ? `   ${detail}` : ''}`,
    doing: (label) => `${MARK.doing} ${label}`,
    warning: (label, todo, command) => `${MARK.warn} ${label}\n  ${todo}${command ? `\n  ${command}` : ''}`,
    checkedServer: () => 'فُحص هذا الخادم',
    serverFacts: ({ os, cpus, memGb, diskGb }) => `${os} ${MARK.dot} ${cpus} CPUs ${MARK.dot} ${memGb} GB`
      + (diskGb === null ? '' : ` ${MARK.dot} ${diskGb} GB متاحة`),
    dockerFound: () => 'وُجد Docker',
    askDomain: () => 'عنوان الويب لـ Whity',
    askDomainExample: () => '(مثل office.example.jo؛ اتركه فارغاً لتجربته على هذا الحاسوب)',
    askEmail: () => 'بريدك (المالك الأول)',
    downloading: (version) => `جارٍ تنزيل Whity Core ${version.replace(/^v/, '')}`,
    downloaded: (version) => `تم تنزيل Whity Core ${version.replace(/^v/, '')}`,
    starting: () => 'جارٍ تشغيل قاعدة البيانات والتطبيق',
    started: () => 'بدأت قاعدة البيانات والتطبيق',
    gettingCertificate: (domain) => `جارٍ الحصول على شهادة لـ ${domain}`,
    gotCertificate: (domain) => `حصلنا على شهادة لـ ${domain}`,
    noCertificate: (domain) => `لا توجد شهادة لـ ${domain} بعد`,
    noCertificateTodo: (domain, port) => `يعمل Whity، لكن Let's Encrypt لم يصل إلى هذا الخادم عبر ${domain}. `
      + `تأكد أن سجل A يشير إلى هنا وأن المنفذين 80 و${port} مفتوحان للإنترنت، ثم:`,
    makingOwner: () => 'جارٍ إنشاء حساب المالك',
    madeOwner: () => 'أُنشئ حساب المالك',
    lowMemory: () => 'أقل من 2 غيغابايت من الذاكرة',
    lowMemoryTodo: () => 'سيعمل Whity لكن ببطء. 4 غيغابايت أفضل.',
    ready: () => 'Whity جاهز.',
    readyNoCertificate: () => 'Whity يعمل؛ ويُفتح عند وصول الشهادة.',
    openAt: (url) => `  افتح   ${url}`,
    signInAs: (email, envPath) => `  سجّل الدخول باسم ${email} بكلمة المرور INITIAL_SYSTEM_ADMIN_PASSWORD في ${envPath}، وغيّرها بعد الدخول.`,
    keepEnv: () => '  احتفظ بنسخة من ملف .env: فيه المفاتيح التي تُشفَّر بها بياناتك.',
    updateLater: () => '  للتحديث لاحقاً: whity update',

    badDomain: (domain) => `«${domain}» ليس عنوان ويب\n  اكتب اسم نطاق مثل office.example.jo بلا http:// وبلا مسار.\n  لم يتغيّر شيء.`,
    badEmail: (email) => `«${email}» ليس بريداً إلكترونياً\n  اكتب العنوان الذي يسجّل به المالك الدخول.\n  لم يتغيّر شيء.`,
    domainUnresolved: (domain) => `${domain} لا يشير إلى أي مكان بعد\n  أضف سجل A للنطاق ${domain} بعنوان هذا الخادم لدى مزوّد النطاق، `
      + 'انتظر بضع دقائق ثم شغّل هذا مرة أخرى.\n  لم يتغيّر شيء.',
    alreadyInstalled: (dir) => `Whity مُثبَّت مسبقاً في ${dir}\n  حدّثه بدلاً من ذلك:\n  whity update\n  لم يتغيّر شيء.`,
    portInUse: (port) => `المنفذ ${port} مستخدم\n  أوقف البرنامج الذي يستخدمه، أو اختر غيره بـ --web-port أو --api-port.\n  لم يتغيّر شيء.`,
    serverPortInUse: (port) => (port === 80
      ? 'المنفذ 80 مستخدم\n  يحتاجه Let\'s Encrypt لإصدار الشهادة. أوقف البرنامج الذي يستخدمه ثم شغّل هذا مرة أخرى.\n  لم يتغيّر شيء.'
      : `المنفذ ${port} مستخدم\n  أوقف البرنامج الذي يستخدمه، أو شغّل المُثبِّت بمنفذ آخر، مثلاً:\n  --port 8443\n  لم يتغيّر شيء.`),
    dockerMissing: () => 'Docker غير مُثبَّت\n  ثبّته بالسكربت الرسمي ثم شغّل هذا مرة أخرى:\n  curl -fsSL https://get.docker.com | sh\n  لم يتغيّر شيء.',
    dockerStopped: (detail) => `Docker مُثبَّت لكنه لا يعمل. شغّله ثم شغّل هذا مرة أخرى.\n${detail}`,
  };
}
