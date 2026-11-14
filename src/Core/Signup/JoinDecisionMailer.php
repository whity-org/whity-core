<?php

declare(strict_types=1);

namespace Whity\Core\Signup;

use Whity\Core\Mail\BrandedEmailSender;
use Whity\Core\Mail\EmailContent;
use Whity\Core\Mail\EmailOptions;

/**
 * Tells someone who asked to join a workspace what was decided (design 3.4
 * `EmailJoining`, `handoff/emails/join-approved`, `join-declined`). Before
 * this, {@see CustomerSignupService::approve()} and `reject()` only audited,
 * and the person heard nothing.
 *
 * From the workspace, in its brand and language. The decline invites a reply
 * only when the workspace has its own address to receive it (Settings ›
 * Company). Can't be turned off: it answers the person's own request.
 * Best-effort: a failure is logged by the sender and never undoes the decision.
 */
final class JoinDecisionMailer
{
    public function __construct(
        private readonly BrandedEmailSender $sender,
        /** Where the person signs in (the app's sign-in page). */
        private readonly string $signInUrl,
    ) {
    }

    public function approved(int $tenantId, string $email, string $name): bool
    {
        $ar = $this->sender->language($tenantId) === 'ar';
        $workspace = $this->sender->branding($tenantId)->siteName;
        $name = trim($name);

        return $this->sender->send(
            $tenantId,
            $email,
            $ar ? 'تم قبولك: أهلاً بك في ' . $workspace : "You're in: welcome to " . $workspace,
            new EmailContent(
                heading: $ar ? 'أهلاً بك في ' . $workspace : 'Welcome to ' . $workspace,
                paragraphs: [$ar
                    ? ($name !== '' ? 'مرحباً ' . $name . '، ' : '') . 'تمت الموافقة على طلب انضمامك.'
                    : ($name !== '' ? 'Hello ' . $name . ', your' : 'Your') . ' request to join was approved.'],
                ctaLabel: $ar ? 'تسجيل الدخول' : 'Sign in',
                ctaUrl: $this->signInUrl,
                preheader: $ar ? 'تمت الموافقة على طلبك. سجّل الدخول للبدء.' : 'Your request was approved. Sign in to start.',
                notice: $ar
                    ? 'أرسلتها ' . $workspace . ' لأنك طلبت الانضمام باستخدام ' . $email . '.'
                    : 'Sent by ' . $workspace . ' because you asked to join with ' . $email . '.',
                language: $ar ? 'ar' : 'en',
            ),
        );
    }

    public function declined(int $tenantId, string $email, string $name): bool
    {
        $ar = $this->sender->language($tenantId) === 'ar';
        $workspace = $this->sender->branding($tenantId)->siteName;
        $replyTo = $this->sender->workspaceContact($tenantId, $ar ? 'ar' : 'en')['replyTo'];
        $name = trim($name);
        $hello = $ar ? ($name !== '' ? 'مرحباً ' . $name . '، ' : '') : ($name !== '' ? 'Hello ' . $name . ', ' : '');
        $line = $ar
            ? $hello . 'لم تتمكن ' . $workspace . ' من قبول طلب انضمامك.'
            : $hello . $workspace . " couldn't accept your request to join.";
        if ($replyTo !== null) {
            $line .= $ar
                ? ' إن كنت ترى أن ذلك خطأ، ردّ على هذه الرسالة وسيطّلعون عليها.'
                : " If you think this is a mistake, reply to this email and they'll see it.";
        }

        return $this->sender->send(
            $tenantId,
            $email,
            $ar ? 'بشأن طلب انضمامك إلى ' . $workspace : 'About your request to join ' . $workspace,
            new EmailContent(
                heading: $ar ? 'لم يُقبل طلبك' : 'Your request wasn’t accepted',
                paragraphs: [$line],
                preheader: $ar ? 'لم يتمكنوا من قبوله هذه المرة.' : "They couldn't accept it this time.",
                notice: $ar
                    ? 'أرسلتها ' . $workspace . '. لم يُحتفظ إلا باسمك وبريدك.'
                    : 'Sent by ' . $workspace . '. Nothing was kept apart from your name and email.',
                language: $ar ? 'ar' : 'en',
            ),
            new EmailOptions(replyTo: $replyTo),
        );
    }
}
