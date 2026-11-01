/** A failure the person running the command can act on. Printed without a stack. */
export class CliError extends Error {
  constructor(message, exitCode = 1) {
    super(message);
    this.exitCode = exitCode;
  }
}
