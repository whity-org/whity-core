# whity

Install Whity on your own server, with Docker:

```bash
npm install -g whity
whity install
```

It asks for the web address (a domain pointing at this server) and your email, then downloads the
latest release, starts it, gets a certificate from Let's Encrypt and makes your owner account.
Later, `whity update` backs up and moves to the newest release.

This package is the `whity` command from [@whity/cli](https://www.npmjs.com/package/@whity/cli)
under a shorter name; install one or the other, not both. The guide is in
[English](https://github.com/whity-org/whity-core/blob/main/docs/wiki/Whity-CLI.md) and
[العربية](https://github.com/whity-org/whity-core/blob/main/docs/wiki/Whity-CLI-ar.md).
