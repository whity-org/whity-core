/**
 * The shortest password a person may choose, the same as the server's
 * `PasswordPolicy::MIN_LENGTH` (READINESS CE-5, ASVS 2.1.1). The server
 * decides; this only lets a form say so before sending.
 */
export const PASSWORD_MIN_LENGTH = 12;
