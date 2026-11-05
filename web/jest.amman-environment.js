/* eslint-disable @typescript-eslint/no-require-imports -- Jest loads this as CommonJS, outside the transform. */
/**
 * jsdom, running in Amman time (UTC+3).
 *
 * A date picker that goes through UTC shows no bug at UTC, which is where CI
 * runs. Our people are not there. Setting TZ inside a test does nothing, because
 * Jest gives each test a copy of process.env, so it is set here, in the real one.
 *
 * Use with: @jest-environment ./jest.amman-environment.js
 */
const { TestEnvironment } = require('jest-environment-jsdom');

class AmmanEnvironment extends TestEnvironment {
  async setup() {
    this.previousTz = process.env.TZ;
    process.env.TZ = 'Asia/Amman';
    await super.setup();
  }

  async teardown() {
    if (this.previousTz === undefined) {
      delete process.env.TZ;
    } else {
      process.env.TZ = this.previousTz;
    }
    await super.teardown();
  }
}

module.exports = AmmanEnvironment;
