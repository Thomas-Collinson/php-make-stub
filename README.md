# make-stub

Generate PHP extension stub files (`*.stub.php`) from an existing userland PHP library.

When you port a PHP library to a C extension, the stub file is the single source of truth for the extension's API. php-src's `build/gen_stub.php` turns it into the `*_arginfo.h` header your C code includes, and PhpStorm, PHPStan and Psalm read it for completion and type information. Writing stubs by hand for a large library is tedious, and `gen_stub.php` is far stricter than PhpStorm about what it accepts. `make-stub` produces a first draft that already satisfies `gen_stub.php`, and tells you exactly where a human decision is still needed.

## Requirements

You need PHP 8.1 or later and Composer. The only dependency is [nikic/php-parser](https://github.com/nikic/PHP-Parser) 5.x, the same parser `gen_stub.php` itself uses.

Run the script with the PHP version your extension targets. It parses source files with that version's grammar, and it evaluates references to built-in constants such as `JSON_THROW_ON_ERROR` using that version's values.

## Installation

Standalone, from a clone of this repository:

```sh
composer install
php make-stub.php --help
```

As a development dependency of your extension's repository, which puts the script at `vendor/bin/make-stub.php`. This needs the package to be reachable by Composer, for example through a `vcs` or `path` repository, and the package name replaced with your own:

```sh
composer require --dev your-vendor/make-stub
vendor/bin/make-stub.php --help
```

## Usage

```sh
php make-stub.php [--out=PATH] [--split=none|class] [--ext=NAME] [--keep-private] <file-or-dir>...
```

| Option | Meaning |
| --- | --- |
| `--out=PATH` | Where to write. A file for `--split=none` (default: standard output); a directory for `--split=class`. |
| `--split=none` | Write one combined stub. This is the default. |
| `--split=class` | Write `<ext>.stub.php` with all free functions and global constants, plus one stub per class, interface and enum. Requires `--out`. |
| `--ext=NAME` | Extension name, used for the functions stub's filename. Defaults to `myext`. |
| `--keep-private` | Keep private methods, properties and constants. By default they are dropped, except private constructors. |
| `--help` | Print usage. |

Directories are scanned recursively for `*.php` files. Directories named `vendor`, `tests`, `test`, `node_modules` and `.git` are skipped, as are existing `*.stub.php` files.

The generated stubs go to standard output or `--out`. Warnings go to standard error, each prefixed with `file:line` where possible, so you can keep them as a to-do list:

```sh
php make-stub.php --split=class --ext=acme --out=stubs/ ../acme-lib/src 2> todo.txt
```

Then generate the C headers from each stub:

```sh
php /path/to/php-src/build/gen_stub.php stubs/acme.stub.php
```

Extensions built with `phpize` also regenerate a header automatically when its stub changes.

## Organising the stubs

Organise stubs by C source file rather than by PHP class or directory. Each stub becomes one `*_arginfo.h`, and each `.c` file includes one, so the stub layout should mirror how you split the C code.

All free functions belong in the stub included by the `.c` file that defines your `zend_module_entry`, because the module entry points at a single function table. That is why `--split=class` puts functions and global constants together in `<ext>.stub.php`. Classes can each have their own stub and `.c` file. It is usually worth merging a class's small satellites, such as its exception classes or enums, into the same stub.

`--split=class` names each class stub after its fully qualified name, in lower case with backslashes replaced by underscores. For example, `Acme\Http\Client` becomes `acme_http_client.stub.php`. Rename and merge the files to match your C layout.

PhpStorm does not care how stubs are split; it indexes everything.

## What the script changes

The output keeps signatures, docblocks, constants, properties and class shapes, and empties every function body. It then rewrites anything that `gen_stub.php` would reject or that has no equivalent in an internal class.

| Area | What happens |
| --- | --- |
| Missing parameter types | Taken from `@param` when it maps to a native type, otherwise `mixed`. |
| Missing return types | Taken from `@return` when possible. Otherwise the body decides: `\Generator` if it yields, `void` if it never returns a value, `mixed` if it does. |
| Magic methods | Get the signatures the engine enforces, such as `__toString(): string` and `__get(string $name): mixed`. |
| Implicit nullables | `Foo $x = null` becomes `?Foo $x = null`. |
| Traits | Flattened into each class that uses them. The class's own methods take precedence, and abstract trait methods are skipped. |
| Promoted constructor parameters | Turned into explicit property declarations. |
| `self` and `parent` | Replaced with the real class names in types and default values. |
| Docblock class names | Fully qualified, because the source files' `use` statements are not carried over. `@template` names are left alone. |
| Rich docblock types | Types `gen_stub.php` cannot parse move to `@phpstan-param`, `@phpstan-return` and `@phpstan-var`. PhpStorm and static analysers still read them. |
| Constants | Every constant gets a `@var` type. `const A = 1, B = 2;` is split into separate declarations. `define()` calls become `const` declarations. |
| External constant references | References to constants the stubs cannot see, such as `JSON_*`, `Foo::class` and `\Attribute::TARGET_CLASS`, are evaluated to literals. The original expression is kept in the docblock as `Library value: ...`. |
| Property hooks | Dropped, with a warning. |

## Working through the warnings

Each warning marks a decision the script could not make for you.

| Warning | What to do |
| --- | --- |
| `$x has no usable type, using mixed` / `no usable return type, using mixed` | Replace `mixed` with the real type if there is one. |
| `can't infer the constant's type, using @var mixed` | Replace `@var mixed` with the constant's real type. |
| `computed at runtime; emitted as UNKNOWN` / `refers to constants outside the stubs; emitted as UNKNOWN` | Set a built-in `@var` type and add `@cvalue` naming the C macro that holds the value. |
| `can't emit non-empty array values` / `can't emit the non-empty array default` | `gen_stub.php` cannot generate these. Declare the value empty in the stub and fill it in from C. |
| `dropped the default of $x` / `dropped an argument of #[...]` | The value could not be evaluated, often because it is an enum case. Set it from C, or restore it once your stubs declare what it refers to. |
| `trait adaptations (insteadof/as) are not applied` | Check the flattened class for conflicting or renamed methods. |
| `trait ... is not in the input` | Add the trait's source to the input, or declare its members by hand. |
| `dropped property hooks` | Implement the property's behaviour in C, for example through property handlers. |
| `duplicate class/function ..., keeping the first one` | Usually a polyfill declared in several branches. Check that the kept version is the one you want. |
| `parse error, skipped` | The file does not parse with the PHP version running the script. |

After that, run `gen_stub.php` on every stub. Anything it still rejects will be one of the cases above.

## Limitations

Some things are deliberately left for you. Untyped properties stay untyped, because a `@var` on a property is often less precise than it looks. `@property` tags are qualified like other docblock types, but `@method` tags are left exactly as written. Anonymous classes and closures disappear along with the bodies they live in.

Stubs refer to classes and interfaces from outside the input by name. An internal class can only extend or implement classes that already exist when the extension loads, which means core classes or classes from other extensions. Dependencies on userland packages, such as PSR interfaces installed through Composer, need a different approach in the ported extension.

The script has been tested with PHP 8.3, nikic/php-parser 5.7 and `gen_stub.php` from the PHP-8.4 branch of php-src, against Monolog 3.9 and PHP-Parser's own source.

## Using the stubs in PhpStorm

Add the directory containing the stubs under Settings → PHP → Include Path. PhpStorm indexes it without treating it as part of your project's source. Never load a stub at runtime: the extension already declares the same symbols, so PHP would fail with "cannot redeclare" errors.

## License

MIT. See [LICENSE](LICENSE).
