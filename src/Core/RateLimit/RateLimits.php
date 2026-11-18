<?php

declare(strict_types=1);

namespace Whity\Core\RateLimit;

/**
 * Every limit a handler counts against a key, in one list (merge map
 * plumbing, WHIT-1158). Each is a keyed {@see RateLimitRule}: its name, how
 * many, and over how long. The handler builds the key (an address, an email,
 * a form) and calls `take`, `hit`, `blocked` or `record`; the answer over the
 * limit is always {@see RateLimited}.
 *
 * The request-wide limits (per address, per workspace, per person, the whole
 * platform) stay on the middleware ({@see RateLimitMiddleware}). Sign-in
 * ({@see \Whity\Auth\LoginThrottleService}), signing
 * ({@see \Whity\Core\Document\Signing\SigningAttemptLimiter}) and MCP keep their
 * own lockout rules on top, as the merge map decided.
 */
final class RateLimits
{
    private const MINUTE = 60;
    private const HOUR = 3600;
    private const DAY = 86400;

    private function __construct()
    {
    }

    /** Checking a document's QR code, per address. */
    public static function documentVerificationPerAddress(): RateLimitRule
    {
        return RateLimitRule::keyed('document_verification.address', 60, self::HOUR);
    }

    /** Redeeming a device activation code, per client address (WHIT-1248). */
    public static function licensingRedeemPerAddress(): RateLimitRule
    {
        return RateLimitRule::keyed('licensing_redeem.address', 30, self::HOUR);
    }

    /** Asking for a verification email, per address asked about. */
    public static function emailVerificationPerEmail(): RateLimitRule
    {
        return RateLimitRule::keyed('email_verification.email', 5, self::HOUR);
    }

    /** Asking for a verification email, per client address. */
    public static function emailVerificationPerAddress(): RateLimitRule
    {
        return RateLimitRule::keyed('email_verification.address', 20, self::HOUR);
    }

    /** Sending one of my own email addresses its verification link again. */
    public static function meEmailsResend(): RateLimitRule
    {
        return RateLimitRule::keyed('me_emails.resend', 5, self::HOUR);
    }

    /** Asking for a password reset link, per email. */
    public static function passwordResetPerEmail(): RateLimitRule
    {
        return RateLimitRule::keyed('password_reset.email', 5, self::HOUR);
    }

    /** Asking for a password reset link, per client address. */
    public static function passwordResetPerAddress(): RateLimitRule
    {
        return RateLimitRule::keyed('password_reset.address', 20, self::HOUR);
    }

    /** Asking to recover two-step sign-in, per email. */
    public static function twoFactorRecoveryPerEmail(): RateLimitRule
    {
        return RateLimitRule::keyed('two_factor_recovery.email', 5, self::HOUR);
    }

    /** Asking to recover two-step sign-in, per client address. */
    public static function twoFactorRecoveryPerAddress(): RateLimitRule
    {
        return RateLimitRule::keyed('two_factor_recovery.address', 20, self::HOUR);
    }

    /** Opening or accepting an invitation link, per address. */
    public static function invitationAcceptPerAddress(): RateLimitRule
    {
        return RateLimitRule::keyed('invitation_accept.address', 30, self::HOUR);
    }

    /** Admin invitations sent from one workspace. */
    public static function invitationsPerWorkspace(): RateLimitRule
    {
        return RateLimitRule::keyed('invitations.workspace', 100, self::HOUR);
    }

    /** Admin invitations sent by one person. */
    public static function invitationsPerPerson(): RateLimitRule
    {
        return RateLimitRule::keyed('invitations.person', 50, self::HOUR);
    }

    /** Team invitations sent by one person in a workspace, per hour. */
    public static function teamInvitationsPerInviterHour(): RateLimitRule
    {
        return RateLimitRule::keyed('team_invitations.inviter_hour', 10, self::HOUR);
    }

    /** Team invitations sent by one person in a workspace, per day. */
    public static function teamInvitationsPerInviterDay(): RateLimitRule
    {
        return RateLimitRule::keyed('team_invitations.inviter_day', 30, self::DAY);
    }

    /** Team invitations sent from one workspace. */
    public static function teamInvitationsPerWorkspace(): RateLimitRule
    {
        return RateLimitRule::keyed('team_invitations.workspace', 100, self::HOUR);
    }

    /** Signed-in form uploads, per person (per workspace for a service principal). */
    public static function formUploadsPerPerson(): RateLimitRule
    {
        return RateLimitRule::keyed('form_uploads.person', 20, self::HOUR);
    }

    /** Provider webhooks delivering inbound messages, per address. */
    public static function inboundMessagesPerAddress(): RateLimitRule
    {
        return RateLimitRule::keyed('inbound_messages.address', 120, self::MINUTE);
    }

    /** Opening a public booking page, per address. */
    public static function publicBookingInfoPerAddress(): RateLimitRule
    {
        return RateLimitRule::keyed('public_booking.info', 120, self::HOUR);
    }

    /** Reading free times on a booking page, per address. */
    public static function publicBookingSlotsPerAddress(): RateLimitRule
    {
        return RateLimitRule::keyed('public_booking.slots', 240, self::HOUR);
    }

    /** Making a public booking, per address. */
    public static function publicBookingBookPerAddress(): RateLimitRule
    {
        return RateLimitRule::keyed('public_booking.book', 10, self::HOUR);
    }

    /** Using a booking's manage link, per address. */
    public static function publicBookingManagePerAddress(): RateLimitRule
    {
        return RateLimitRule::keyed('public_booking.manage', 60, self::HOUR);
    }

    /** Reading a calendar feed, per address. */
    public static function publicBookingFeedPerAddress(): RateLimitRule
    {
        return RateLimitRule::keyed('public_booking.feed', 120, self::HOUR);
    }

    /** Public bookings made in one workspace a day; counted only when a booking is made. */
    public static function publicBookingPerWorkspaceDay(): RateLimitRule
    {
        return RateLimitRule::keyed('public_booking.workspace_day', 200, self::DAY);
    }

    /** Starting a website chat, per address. */
    public static function publicChatStartPerAddress(): RateLimitRule
    {
        return RateLimitRule::keyed('public_chat.start_address', 5, self::HOUR);
    }

    /** Messages sent by one chat visitor. */
    public static function publicChatSendPerVisitor(): RateLimitRule
    {
        return RateLimitRule::keyed('public_chat.send', 30, 10 * self::MINUTE);
    }

    /** Chats started and messages sent in one workspace. */
    public static function publicChatPerWorkspace(): RateLimitRule
    {
        return RateLimitRule::keyed('public_chat.workspace', 600, self::HOUR);
    }

    /** Checks for new messages by one chat visitor. */
    public static function publicChatPollPerVisitor(): RateLimitRule
    {
        return RateLimitRule::keyed('public_chat.poll', 1500, self::HOUR);
    }

    /** Chats started from one address in a workspace in the last day; counted from the chats themselves. */
    public static function publicChatOpenPerAddress(): RateLimitRule
    {
        return RateLimitRule::keyed('public_chat.open_per_address', 3, self::DAY);
    }

    /** The website contact form, per address. */
    public static function publicContactPerAddress(): RateLimitRule
    {
        return RateLimitRule::keyed('public_contact.address', 5, self::HOUR);
    }

    /** The website contact form, shared by everyone with no address. */
    public static function publicContactPerUnknownAddress(): RateLimitRule
    {
        return RateLimitRule::keyed('public_contact.unknown_address', 30, self::HOUR);
    }

    /** The website contact form, everyone together. */
    public static function publicContactPerForm(): RateLimitRule
    {
        return RateLimitRule::keyed('public_contact.form', 200, self::HOUR);
    }

    /** Opening a public form, per address. */
    public static function publicFormRenderPerAddress(): RateLimitRule
    {
        return RateLimitRule::keyed('public_forms.render', 120, self::HOUR);
    }

    /** Submitting a public form, per address. */
    public static function publicFormSubmitPerAddress(): RateLimitRule
    {
        return RateLimitRule::keyed('public_forms.submit', 20, self::HOUR);
    }

    /** Uploading a file to a public form, per address. */
    public static function publicFormUploadPerAddress(): RateLimitRule
    {
        return RateLimitRule::keyed('public_forms.upload', 10, self::HOUR);
    }

    /** Submissions to one public form from every address. */
    public static function publicFormSubmitPerForm(): RateLimitRule
    {
        return RateLimitRule::keyed('public_forms.submit_form', 300, self::HOUR);
    }

    /** Uploads to one public form from every address. */
    public static function publicFormUploadPerForm(): RateLimitRule
    {
        return RateLimitRule::keyed('public_forms.upload_form', 400, self::HOUR);
    }

    /** Opening a workspace's sign-up page, per address. */
    public static function publicSignupInfoPerAddress(): RateLimitRule
    {
        return RateLimitRule::keyed('public_signup.info', 120, self::HOUR);
    }

    /** Asking to sign up, per address. */
    public static function publicSignupRequestPerAddress(): RateLimitRule
    {
        return RateLimitRule::keyed('public_signup.request_address', 10, self::HOUR);
    }

    /** Asking to sign up, per email and workspace; over it the answer is the same 202. */
    public static function publicSignupRequestPerEmail(): RateLimitRule
    {
        return RateLimitRule::keyed('public_signup.request_email', 3, self::HOUR);
    }

    /** Sign-up requests to one workspace. */
    public static function publicSignupRequestPerWorkspace(): RateLimitRule
    {
        return RateLimitRule::keyed('public_signup.request_workspace', 300, self::HOUR);
    }

    /** Opening or finishing a sign-up link, per address. */
    public static function publicSignupConfirmPerAddress(): RateLimitRule
    {
        return RateLimitRule::keyed('public_signup.confirm_address', 30, self::HOUR);
    }

    /** Submitting a plugin's public form, per address and form. */
    public static function pluginFormPerAddress(): RateLimitRule
    {
        return RateLimitRule::keyed('plugin_form.address', 20, self::HOUR);
    }

    /** Submissions to one plugin form in a workspace from every address. */
    public static function pluginFormPerForm(): RateLimitRule
    {
        return RateLimitRule::keyed('plugin_form.form', 300, self::HOUR);
    }

    /** Plugin form preflights and the bot-check config, per address. */
    public static function pluginFormReadPerAddress(): RateLimitRule
    {
        return RateLimitRule::keyed('plugin_form.read_address', 120, self::HOUR);
    }

    /** A plugin's public read, per address and read. */
    public static function pluginReadPerAddress(): RateLimitRule
    {
        return RateLimitRule::keyed('plugin_read.address', 600, self::HOUR);
    }

    /** A plugin's public read in a workspace from every address. */
    public static function pluginReadPerRead(): RateLimitRule
    {
        return RateLimitRule::keyed('plugin_read.read', 6000, self::HOUR);
    }
}
