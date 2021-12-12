# php-pkg-template

[![License](https://img.shields.io/github/license/phppkg/taskpm.svg?style=flat-square)](LICENSE)
[![Php Version](https://img.shields.io/packagist/php-v/phppkg/taskpm?maxAge=2592000)](https://packagist.org/packages/phppkg/taskpm)
[![GitHub tag (latest SemVer)](https://img.shields.io/github/tag/phppkg/taskpm)](https://github.com/phppkg/taskpm)
[![Actions Status](https://github.com/phppkg/taskpm/workflows/Unit-Tests/badge.svg)](https://github.com/phppkg/taskpm/actions)

Run multi tasks by PHP multi process

## Install

**composer**

```bash
composer require phppkg/taskpm
```

## Usage

- github: `use the template` for quick create project
- clone this repository to local
- search `package_description` and replace your package description
- search all `phppkg/taskpm` and replace to your package name.
  - contains all words like `InhereLab\DemoPkg`
- update `composer.json` contents, field: name, description, require, autoload

## Refers

- https://github.com/upfor/forkman
  - https://github.com/SegmentFault/SimpleFork
- https://github.com/huyanping/simple-fork-php
- https://github.com/Arara/Process
- https://github.com/bouiboui/spawn
- https://github.com/symplely/processor
- https://github.com/symplely/coroutine

## License

[MIT](LICENSE)
