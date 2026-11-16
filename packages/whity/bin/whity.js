#!/usr/bin/env node
// The plain `whity` package: the same command as @whity/cli, under the name
// people type (npm install -g whity). All of its code lives in @whity/cli.
import { main } from '@whity/cli/src/cli.js';

process.exitCode = await main(process.argv.slice(2));
