# @whity/cli

The `whity` command installs and updates a self-hosted [Whity](https://github.com/whity-org/whity-core)
on Docker. It works on Windows (PowerShell 5.1 and newer), Linux and macOS.

You need Node 20 or newer, and Docker with Compose v2.

```bash
npm install -g @whity/cli
whity install
```

That installs the latest release into `./whity`, starts it and prints how to sign in.

```bash
whity update
```

That backs up the database and proves the backup restores, moves all three images to the latest
release, runs migrations, restarts and checks the result. If a step fails, it stops and prints the
exact commands to go back.

Run `whity --help` for the options. The full guide is in
[docs/wiki/Whity-CLI.md](https://github.com/whity-org/whity-core/blob/main/docs/wiki/Whity-CLI.md).
