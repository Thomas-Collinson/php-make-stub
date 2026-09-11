#!/usr/bin/env php
<?php

/*
 * (c) 2026 Thomas Collinson
 * All rights reserved
 */

declare(strict_types=1);

/**
 * make-stub.php - generate PHP extension stubs (*.stub.php) from a userland library.
 *
 * The output is a starting point for porting: signatures, docblocks, constants and
 * class shapes are kept; bodies are emptied. It is written to pass php-src's
 * build/gen_stub.php, which is much stricter than PhpStorm:
 *
 *   - missing types are filled from docblocks, inferred (void, \Generator), or set to mixed
 *   - magic methods get their engine-enforced signatures; implicit nullables become ?T
 *   - traits are flattened into the classes that use them; promoted constructor
 *     parameters become explicit properties; private members are dropped
 *   - self/parent become real class names; docblock names are fully qualified
 *   - docblock types gen_stub.php can't parse move to @phpstan-param/-return/-var,
 *     which PhpStorm and static analysers still read
 *   - every constant gets a @var; references to constants outside the stubs
 *     (JSON_*, Foo::class, \Attribute::TARGET_CLASS) are evaluated to literals
 *
 * Anything that needs a human decision is reported on stderr as "file:line ...".
 *
 * Usage:
 *   php make-stub.php [options] <file-or-dir>...
 *
 * Options:
 *   --out=PATH       Output file (default: stdout). With --split=class, a directory.
 *   --split=none     One combined stub (default).
 *   --split=class    <ext>.stub.php for free functions and global constants, plus one
 *                    stub per class/interface/enum.
 *   --ext=NAME       Extension name, used for the functions stub filename (default: myext).
 *   --keep-private   Keep private methods, properties and constants.
 *
 * Directories are scanned recursively for *.php, skipping vendor/, tests/, test/, .git/.
 *
 * Setup: run `composer install` next to this script (see composer.json).
 * Run it with the PHP version your extension targets; it parses with that version's grammar.
 *
 * SPDX-License-Identifier: MIT (see LICENSE)
 */

// Composer's bin proxy sets this when installed as a dependency (vendor/bin/make-stub).
require $GLOBALS['_composer_autoload_path'] ?? __DIR__ . '/vendor/autoload.php';

use PhpParser\BuilderHelpers;
use PhpParser\Comment\Doc;
use PhpParser\ConstExprEvaluationException;
use PhpParser\ConstExprEvaluator;
use PhpParser\Error;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;

function warn(string $msg): void
{
    fwrite(STDERR, "warning: $msg\n");
}

/* ------------------------------------------------------------------------ */
/* Signatures: fill in missing types, strip bodies                          */
/* ------------------------------------------------------------------------ */

/** Finds `return <expr>` and `yield` in a body, ignoring nested closures/classes. */
final class BodyScanner extends NodeVisitorAbstract
{
    public bool $returnsValue = false;
    public bool $yields = false;

    public function enterNode(Node $node)
    {
        if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction
            || $node instanceof Stmt\Function_ || $node instanceof Stmt\ClassLike) {
            return NodeVisitor::DONT_TRAVERSE_CHILDREN;
        }
        if ($node instanceof Stmt\Return_ && $node->expr !== null) {
            $this->returnsValue = true;
        }
        if ($node instanceof Expr\Yield_ || $node instanceof Expr\YieldFrom) {
            $this->yields = true;
        }
        return null;
    }
}

/**
 * Runs right after NameResolver in the same traversal, so native types are already
 * resolved and the name context (use statements) is available for docblock types.
 */
final class SignatureVisitor extends NodeVisitorAbstract
{
    private const SCALARS = [
        'int' => 'int', 'integer' => 'int', 'float' => 'float', 'double' => 'float',
        'string' => 'string', 'bool' => 'bool', 'boolean' => 'bool', 'array' => 'array',
        'callable' => 'callable', 'iterable' => 'iterable', 'object' => 'object',
        'mixed' => 'mixed', 'false' => 'false', 'true' => 'true', 'null' => 'null',
        'list' => 'array', 'void' => 'void', 'never' => 'never', 'noreturn' => 'never', 'static' => 'static',
        '$this' => 'static', 'self' => 'self', 'parent' => 'parent',
    ];
    private const RETURN_ONLY = ['void', 'never', 'static'];

    /** Docblock-only types that look like class names but have no native equivalent. */
    private const PSEUDO_TYPES = ['resource', 'scalar', 'numeric', 'number', 'empty'];

    /** The engine enforces these signatures, so they can't be guessed. null = no return type. */
    private const MAGIC_RETURNS = [
        '__construct' => null, '__destruct' => null, '__clone' => null,
        '__tostring' => 'string', '__sleep' => 'array', '__serialize' => 'array',
        '__unserialize' => 'void', '__wakeup' => 'void', '__isset' => 'bool', '__unset' => 'void',
        '__set' => 'void', '__get' => 'mixed', '__call' => 'mixed', '__callstatic' => 'mixed',
        '__debuginfo' => '?array', '__set_state' => 'object',
    ];
    private const MAGIC_PARAMS = [
        '__get' => ['string'], '__isset' => ['string'], '__unset' => ['string'],
        '__set' => ['string', 'mixed'], '__call' => ['string', 'array'],
        '__callstatic' => ['string', 'array'], '__unserialize' => ['array'], '__set_state' => ['array'],
    ];

    /** Pseudo-types and keywords that must not be treated as class names in docblocks. */
    private const DOC_KEYWORDS = [
        'int', 'integer', 'float', 'double', 'string', 'bool', 'boolean', 'array', 'callable',
        'iterable', 'object', 'mixed', 'false', 'true', 'null', 'void', 'never', 'noreturn',
        'static', 'self', 'parent', 'resource', 'scalar', 'numeric', 'number', 'empty', 'list',
        'max', 'min',
    ];

    /** @var list<list<string>> @template names of enclosing classes */
    private array $classTemplates = [];

    /** @var list<?string> FQN of enclosing classes (null for traits and anonymous classes) */
    private array $classNames = [];

    /** @var list<string> @template names visible in the function being processed */
    private array $templates = [];

    public function __construct(private NameResolver $resolver, private string $file) {}

    public function leaveNode(Node $node)
    {
        if ($node instanceof Stmt\ClassLike) {
            array_pop($this->classTemplates);
            array_pop($this->classNames);
        }
        return null;
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Stmt\ClassLike) {
            $this->classTemplates[] = templatesIn($node->getDocComment()?->getText() ?? '');
            $this->classNames[] = $node instanceof Stmt\Trait_ ? null : $node->namespacedName?->toString();
        }
        $this->qualifyDocComment($node);
        if (!$node instanceof Stmt\Function_ && !$node instanceof Stmt\ClassMethod) {
            return null;
        }
        $where = "{$this->file}:{$node->getStartLine()} {$node->name}()";
        $doc = $node->getDocComment()?->getText() ?? '';
        $this->templates = array_merge(templatesIn($doc), ...$this->classTemplates);

        $magic = $node instanceof Stmt\ClassMethod ? $node->name->toLowerString() : '';
        foreach ($node->params as $i => $param) {
            $var = $param->var->name;
            if ($param->type === null && isset(self::MAGIC_PARAMS[$magic][$i])) {
                $param->type = new Identifier(self::MAGIC_PARAMS[$magic][$i]);
            }
            if ($param->type === null) {
                $docType = docTag($doc, 'param', $var);
                $param->type = $docType !== null ? $this->docType($docType, false) : null;
                if ($param->type === null) {
                    $param->type = new Identifier('mixed');
                    warn("$where: \$$var has no usable type, using mixed");
                }
            }
            if ($param->default instanceof Expr\ConstFetch && $param->default->name->toLowerString() === 'null') {
                $param->type = makeNullable($param->type); // implicit nullable, deprecated in PHP 8.4
            }
            $doc = $this->fillTypelessParamTag($node, $var, $param->type) ?? $doc;
        }

        if ($node->returnType === null) {
            if (!array_key_exists($magic, self::MAGIC_RETURNS)) {
                $node->returnType = $this->inferReturn($node, $doc, $where);
            } elseif (($t = self::MAGIC_RETURNS[$magic]) !== null) {
                $node->returnType = str_starts_with($t, '?')
                    ? new Node\NullableType(new Identifier(substr($t, 1)))
                    : new Identifier($t);
            }
        }

        if ($node->stmts !== null) {
            $node->stmts = [];
        }
        return null;
    }

    /**
     * Rewrites class names in docblock types to fully qualified form, since the stub
     * doesn't carry over the source file's `use` statements.
     */
    private function qualifyDocComment(Node $node): void
    {
        $doc = $node->getDocComment();
        if ($doc === null) {
            return;
        }
        $text = $doc->getText();
        $templates = array_merge(templatesIn($text), ...$this->classTemplates);
        $tags = '@(?:[a-z]+-)?(?:param|return|var|throws|property(?:-read|-write)?|mixin|extends|implements)';

        $self = end($this->classNames) ?: null;

        $new = preg_replace_callback("/($tags\\s+)(\\S.*)$/m", function (array $m) use ($templates, $self) {
            $tag = trim(substr($m[1], 1));
            $len = docTypeLength($m[2]);
            $rest = substr($m[2], $len);
            if ($tag === 'param') {
                $rest = preg_replace('/^(\s*)&\s*/', '$1', $rest); // gen_stub.php can't parse "&$x"
                if ($m[2][0] === '$' || $m[2][0] === '&') {
                    return $m[1] . ltrim($m[2], '&');           // typeless, filled in later
                }
            }
            $type = preg_replace_callback(
                '/(?<![\\w\\\\$\'"-])\\\\?[A-Za-z_]\\w*(?:\\\\[A-Za-z_]\\w*)*(?![\\w\\\\-]|\\s*\\??:(?!:))/',
                function (array $n) use ($templates, $self) {
                    $name = $n[0];
                    if (strtolower($name) === 'self' && $self !== null) {
                        return '\\' . $self;
                    }
                    if ($name[0] === '\\' || in_array(strtolower($name), self::DOC_KEYWORDS, true)
                        || in_array($name, $templates, true)) {
                        return $name;
                    }
                    return '\\' . $this->resolver->getNameContext()->getResolvedClassName(new Name($name));
                },
                substr($m[2], 0, $len),
            );
            // gen_stub.php only understands simple docblock types. Richer ones move to
            // @phpstan-* tags, which PhpStorm and static analysers read and gen_stub ignores.
            if (in_array($tag, ['param', 'return', 'var'], true)) {
                if (str_starts_with($type, '?')) {
                    $type = substr($type, 1) . '|null';
                }
                if (!genStubSafe($type, $tag !== 'param')) {
                    $m[1] = "@phpstan-$tag ";
                }
            }
            return $m[1] . $type . $rest;
        }, $text);

        if ($new !== $text) {
            $node->setDocComment(new Doc($new, $doc->getStartLine(), $doc->getStartFilePos(), $doc->getStartTokenPos()));
        }
    }

    /** gen_stub.php rejects "@param $x" without a type, so write the native type in. */
    private function fillTypelessParamTag(Node $node, string $var, Node $type): ?string
    {
        $doc = $node->getDocComment();
        $pattern = '/(@param\s+)&?\s*((?:\.\.\.)?\$' . preg_quote($var, '/') . '\b)/';
        if ($doc === null || !preg_match($pattern, $doc->getText())) {
            return null;
        }
        $typeString = typeToString($type);
        $tag = genStubSafe($typeString, false) ? '@param ' : '@phpstan-param ';
        $text = preg_replace($pattern, $tag . $typeString . ' $2', $doc->getText(), 1);
        $node->setDocComment(new Doc($text, $doc->getStartLine(), $doc->getStartFilePos(), $doc->getStartTokenPos()));
        return $text;
    }

    private function inferReturn(Node\FunctionLike $fn, string $doc, string $where): Node
    {
        $docType = docTag($doc, 'return');
        if ($docType !== null && ($type = $this->docType($docType, true)) !== null) {
            return $type;
        }
        if ($fn->getStmts() !== null) {
            $scan = new BodyScanner();
            (new NodeTraverser($scan))->traverse($fn->getStmts());
            if ($scan->yields) {
                return new Name\FullyQualified('Generator');
            }
            if (!$scan->returnsValue) {
                return new Identifier('void');
            }
        }
        warn("$where: no usable return type, using mixed");
        return new Identifier('mixed');
    }

    /** Converts a docblock type to a native type, or null if it can't be done faithfully. */
    private function docType(string $doc, bool $isReturn): ?Node
    {
        $nullable = str_starts_with($doc, '?');
        $types = [];
        foreach (splitTopLevel(ltrim($doc, '?'), '|') as $part) {
            $part = trim($part);
            if ($part === '' || str_contains($part, '&') || str_starts_with($part, '(')) {
                return null;
            }
            if (str_ends_with($part, '[]')) {
                $types['array'] = new Identifier('array');
                continue;
            }
            $base = preg_replace('/[<{(].*$/s', '', $part);
            $lower = strtolower($base);
            $native = self::SCALARS[$lower]
                ?? (preg_match('/-(string)$/', $lower) ? 'string' : null)
                ?? (preg_match('/-(int)$/', $lower) ? 'int' : null)
                ?? (preg_match('/-(array|list)$/', $lower) ? 'array' : null);

            if ($native !== null) {
                if (!$isReturn && in_array($native, self::RETURN_ONLY, true)) {
                    return null;
                }
                $types[$native] = in_array($native, ['self', 'parent'], true) ? new Name($native) : new Identifier($native);
            } elseif (in_array($lower, self::PSEUDO_TYPES, true) || in_array($base, $this->templates, true)) {
                return null; // resource, scalar, generic T, ...: no native equivalent
            } elseif (preg_match('/^\\\\?[A-Za-z_]\w*(\\\\[A-Za-z_]\w*)*$/', $base)) {
                $name = $base[0] === '\\'
                    ? new Name\FullyQualified(substr($base, 1))
                    : $this->resolver->getNameContext()->getResolvedClassName(new Name($base));
                $types[strtolower($name->toString())] = $name;
            } else {
                return null; // literals like 'foo' or 0|1
            }
        }
        if ($nullable) {
            $types['null'] = new Identifier('null');
        }

        if (isset($types['mixed'])) {
            return new Identifier('mixed');
        }
        if (count($types) > 1 && (isset($types['void']) || isset($types['never']))) {
            return null;
        }
        if (count($types) === 1) {
            return isset($types['null']) ? null : reset($types);
        }
        if (count($types) === 2 && isset($types['null'])) {
            unset($types['null']);
            $other = reset($types);
            if (!in_array((string) $other, ['false', 'true'], true)) {
                return new Node\NullableType($other);
            }
            $types['null'] = new Identifier('null');
        }
        return $types ? new Node\UnionType(array_values($types)) : null;
    }
}

/** Returns the type of the first @tag (optionally for $var), handling generics with spaces. */
function docTag(string $doc, string $tag, ?string $var = null): ?string
{
    if (!preg_match_all('/@(?:(?:phpstan|psalm)-)?' . $tag . '\s+(.*)$/m', $doc, $m)) {
        return null;
    }
    foreach ($m[1] as $rest) {
        $i = docTypeLength($rest);
        $type = substr($rest, 0, $i);
        $after = ltrim(substr($rest, $i));
        if ($type === '' || ($type[0] === '$' && $type !== '$this')) {
            continue; // "@param $x" with no type
        }
        if ($var === null || preg_match('/^&?\s*(\.\.\.)?\$' . preg_quote($var, '/') . '\b/', $after)) {
            return $type;
        }
    }
    return null;
}

/** Length of the type at the start of a tag's text: up to the first top-level whitespace. */
function docTypeLength(string $s): int
{
    $depth = 0;
    for ($i = 0, $n = strlen($s); $i < $n; $i++) {
        $c = $s[$i];
        if (str_contains('<{(', $c)) {
            $depth++;
        } elseif (str_contains('>})', $c)) {
            $depth--;
        } elseif ($depth === 0 && ctype_space($c)) {
            break;
        }
    }
    return $i;
}

/** @template names declared in a docblock. */
function templatesIn(string $doc): array
{
    preg_match_all('/@(?:[a-z]+-)?template(?:-covariant|-contravariant)?\s+(\w+)/', $doc, $m);
    return $m[1];
}

/** Whether gen_stub.php can parse a docblock type; it understands far less than PhpStorm. */
function genStubSafe(string $type, bool $allowGenerics = true): bool
{
    $builtin = '(?:void|null|false|true|bool|int|float|string|callable|object|resource|mixed|static|never|array)';
    $class = '(?!(?:self|parent|iterable)\b)\\\\?[A-Za-z_]\w*(?:\\\\[A-Za-z_]\w*)*';
    $parts = splitTopLevel($type, '|');
    foreach ($parts as $i => $part) {
        $part = trim($part);
        if (!$allowGenerics && str_ends_with($part, '[]') && $i !== count($parts) - 1) {
            return false; // @param's parser only allows "[]" at the very end
        }
        $simple = preg_match("/^(?:$builtin|$class)(?:\[\])?$/i", $part);
        $generic = $allowGenerics && preg_match("/^array<\s*(?:int|string)(?:\|(?:int|string))?\s*,\s*$builtin(?:\|$builtin)*\s*>$/i", $part);
        if (!$simple && !$generic) {
            return false;
        }
    }
    return true;
}

function makeNullable(Node $type): Node
{
    if ($type instanceof Node\UnionType) {
        foreach ($type->types as $t) {
            if ($t->toLowerString() === 'null') {
                return $type;
            }
        }
        return new Node\UnionType([...$type->types, new Identifier('null')]);
    }
    if ($type instanceof Node\NullableType || $type instanceof Node\IntersectionType
        || in_array($type->toLowerString(), ['mixed', 'null'], true)) {
        return $type;
    }
    return new Node\NullableType($type);
}

function typeToString(Node $type): string
{
    return match (true) {
        $type instanceof Node\NullableType => typeToString($type->type) . '|null',
        $type instanceof Node\UnionType => implode('|', array_map('typeToString', $type->types)),
        $type instanceof Node\IntersectionType => implode('&', array_map('typeToString', $type->types)),
        $type instanceof Name\FullyQualified => '\\' . $type->toString(),
        default => $type->toString(),
    };
}

/** gen_stub.php wants real class names instead of self/parent, in types and in defaults. */
final class SelfResolver extends NodeVisitorAbstract
{
    public function __construct(private string $self, private ?string $parent) {}

    public function leaveNode(Node $node)
    {
        if (!$node instanceof Name || $node instanceof Name\FullyQualified) {
            return null;
        }
        return match ($node->toLowerString()) {
            'self' => new Name\FullyQualified($this->self, $node->getAttributes()),
            'parent' => $this->parent !== null ? new Name\FullyQualified($this->parent, $node->getAttributes()) : null,
            default => null,
        };
    }
}

/** Splits on $sep, ignoring separators nested inside <>, {} or (). */
function splitTopLevel(string $s, string $sep): array
{
    $parts = [];
    $depth = 0;
    $cur = '';
    foreach (str_split($s) as $c) {
        if (str_contains('<{(', $c)) {
            $depth++;
        } elseif (str_contains('>})', $c)) {
            $depth--;
        } elseif ($c === $sep && $depth === 0) {
            $parts[] = $cur;
            $cur = '';
            continue;
        }
        $cur .= $c;
    }
    $parts[] = $cur;
    return $parts;
}

/* ------------------------------------------------------------------------ */
/* Collecting declarations                                                  */
/* ------------------------------------------------------------------------ */

function nsOf(Name $fqn): string
{
    return $fqn->slice(0, -1)?->toString() ?? '';
}

function isConstExpr(Node $n): bool
{
    return match (true) {
        $n instanceof Scalar\InterpolatedString => false,
        $n instanceof Scalar, $n instanceof Expr\ConstFetch, $n instanceof Expr\ClassConstFetch => true,
        $n instanceof Expr\UnaryMinus, $n instanceof Expr\UnaryPlus,
        $n instanceof Expr\BitwiseNot, $n instanceof Expr\BooleanNot => isConstExpr($n->expr),
        $n instanceof Expr\BinaryOp => isConstExpr($n->left) && isConstExpr($n->right),
        $n instanceof Expr\Array_ => array_reduce(
            $n->items,
            fn (bool $ok, ?Node\ArrayItem $i) => $ok && $i !== null && isConstExpr($i->value)
                && ($i->key === null || isConstExpr($i->key)),
            true,
        ),
        default => false,
    };
}

/** Native type of a literal constant value, or null if it isn't obvious. */
function constType(Expr $v): ?string
{
    return match (true) {
        $v instanceof Scalar\String_, $v instanceof Expr\BinaryOp\Concat => 'string',
        $v instanceof Expr\ClassConstFetch && $v->name instanceof Identifier
            && $v->name->toLowerString() === 'class' => 'string',
        $v instanceof Expr\BinaryOp\BitwiseOr, $v instanceof Expr\BinaryOp\BitwiseAnd,
        $v instanceof Expr\BinaryOp\BitwiseXor, $v instanceof Expr\BinaryOp\ShiftLeft,
        $v instanceof Expr\BinaryOp\ShiftRight, $v instanceof Expr\BinaryOp\Mod,
        $v instanceof Expr\BitwiseNot => 'int',
        $v instanceof Scalar\Int_ => 'int',
        $v instanceof Scalar\Float_ => 'float',
        $v instanceof Expr\Array_ => 'array',
        $v instanceof Expr\UnaryMinus, $v instanceof Expr\UnaryPlus => constType($v->expr),
        $v instanceof Expr\BinaryOp\Plus, $v instanceof Expr\BinaryOp\Minus,
        $v instanceof Expr\BinaryOp\Mul, $v instanceof Expr\BinaryOp\Pow
            => match ([constType($v->left), constType($v->right)]) {
                ['int', 'int'] => 'int',
                ['int', 'float'], ['float', 'int'], ['float', 'float'] => 'float',
                default => null,
            },
        $v instanceof Expr\ConstFetch => match ($v->name->toLowerString()) {
            'true', 'false' => 'bool',
            'null' => 'null',
            default => null,
        },
        default => null,
    };
}

/** gen_stub.php requires every constant to have a native type or a @var tag. */
function ensureConstType(Stmt $stmt, Expr $value, string $where): void
{
    if ($stmt instanceof Stmt\ClassConst && $stmt->type !== null) {
        return;
    }
    $doc = $stmt->getDocComment()?->getText();
    if ($doc !== null && preg_match('/@var\s/', $doc)) {
        return; // a plain @var; @phpstan-var doesn't count, gen_stub.php ignores it
    }
    if ($value instanceof Expr\Array_ && $value->items) {
        warn("$where: gen_stub.php can't emit non-empty array values; declare it empty and fill it in C");
    }
    $type = constType($value);
    $isUnknown = $value instanceof Expr\ConstFetch && $value->name->toString() === 'UNKNOWN';
    if ($type === null && !$isUnknown) {
        warn("$where: can't infer the constant's type, using @var mixed");
    }
    $type ??= 'mixed';
    addDocTag($stmt, "@var $type");
}

/**
 * gen_stub.php evaluates constant values, property defaults and attribute arguments
 * itself, and can only follow references to constants declared in the same stubs.
 * References to anything else are evaluated here when this PHP knows them (JSON_*,
 * \Attribute::TARGET_CLASS); the rest become UNKNOWN / get dropped, with a warning.
 */
function finishEvaluatedValues(array $decls): void
{
    $classes = [];
    $enumCases = [];
    foreach ($decls as $d) {
        if ($d['kind'] === 'class') {
            $classes[strtolower($d['fqn'])] = true;
            foreach ($d['node']->stmts as $s) {
                if ($s instanceof Stmt\EnumCase) {
                    $enumCases[strtolower($d['fqn']) . '::' . $s->name] = true;
                }
            }
        }
    }
    $isExternal = fn (Node $n) => ($n instanceof Expr\ConstFetch
            && !in_array($n->name->toLowerString(), ['true', 'false', 'null', 'unknown'], true))
        || ($n instanceof Expr\ClassConstFetch && (!$n->class instanceof Name
            || !isset($classes[strtolower($n->class->toString())])
            || isset($enumCases[strtolower($n->class->toString()) . '::' . $n->name])
            || ($n->name instanceof Identifier && $n->name->toLowerString() === 'class')))
        || $n instanceof Scalar\MagicConst;
    $finder = new NodeFinder();
    $needsWork = fn (?Node $e) => $e !== null && $finder->findFirst($e, $isExternal) !== null;

    foreach ($decls as $d) {
        $node = $d['node'];
        $label = "{$d['file']}:{$node->getStartLine()} {$d['fqn']}";

        // Constants: evaluate, or fall back to UNKNOWN + @cvalue.
        $consts = match ($d['kind']) {
            'const' => [$node],
            'class' => array_filter($node->stmts, fn (Stmt $s) => $s instanceof Stmt\ClassConst),
            default => [],
        };
        foreach ($consts as $stmt) {
            $c = $stmt->consts[0];
            $where = "{$d['file']}:{$stmt->getStartLine()} {$d['fqn']}" . ($d['kind'] === 'class' ? "::{$c->name}" : '');
            if (!$needsWork($c->value)) {
                ensureConstType($stmt, $c->value, $where);
                continue;
            }
            $literal = evaluateToLiteral($c->value);
            ensureConstType($stmt, $literal ?? $c->value, $where);
            addDocTag($stmt, 'Library value: ' . (new Printer())->prettyPrintExpr($c->value));
            $c->value = $literal ?? new Expr\ConstFetch(new Name('UNKNOWN'));
            if ($literal === null) {
                warn("$where: value refers to constants outside the stubs; emitted as UNKNOWN, "
                    . "add @cvalue with the C macro that holds it");
            }
        }

        // Attribute arguments and property defaults: evaluate, or drop.
        foreach ($finder->findInstanceOf([$node], Node\AttributeGroup::class) as $group) {
            foreach ($group->attrs as $attr) {
                foreach ($attr->args as $i => $arg) {
                    if ($needsWork($arg->value)) {
                        $literal = evaluateToLiteral($arg->value);
                        if ($literal === null) {
                            warn("$label: dropped an argument of #[{$attr->name}] that gen_stub.php can't evaluate");
                            unset($attr->args[$i]);
                        } else {
                            $arg->value = $literal;
                        }
                    }
                }
                $attr->args = array_values($attr->args);
            }
        }
        foreach ($finder->findInstanceOf([$node], Node\PropertyItem::class) as $prop) {
            if ($needsWork($prop->default)) {
                $prop->default = evaluateToLiteral($prop->default);
                if ($prop->default === null) {
                    warn("$label: dropped the default of \${$prop->name}, gen_stub.php can't evaluate it");
                }
            }
        }
    }
}

/** Evaluates a constant expression using the constants this PHP process knows about. */
function evaluateToLiteral(Expr $expr): ?Expr
{
    $evaluator = new ConstExprEvaluator(function (Expr $e) {
        if ($e instanceof Expr\ClassConstFetch && $e->class instanceof Name\FullyQualified
            && $e->name instanceof Identifier && $e->name->toLowerString() === 'class') {
            return $e->class->toString();
        }
        $name = match (true) {
            $e instanceof Expr\ConstFetch => $e->name->toString(),
            $e instanceof Expr\ClassConstFetch && $e->class instanceof Name && $e->name instanceof Identifier
                => $e->class->toString() . '::' . $e->name->toString(),
            default => null,
        };
        if ($name === null || !defined($name) || is_object($value = constant($name))) {
            throw new ConstExprEvaluationException();
        }
        return $value;
    });
    try {
        return BuilderHelpers::normalizeValue($evaluator->evaluateDirectly($expr));
    } catch (ConstExprEvaluationException | LogicException) {
        return null;
    }
}

function addDocTag(Node $node, string $tag): void
{
    $doc = $node->getDocComment();
    if ($doc === null) {
        $node->setDocComment(new Doc("/** $tag */"));
        return;
    }
    $lines = preg_split('/\R/', trim(preg_replace('#^/\*\*|\*/$#', '', $doc->getText())));
    $lines = array_map(fn (string $l) => ' * ' . ltrim(preg_replace('/^\s*\*/', '', $l)), $lines);
    $lines[] = " * $tag";
    $node->setDocComment(new Doc("/**\n" . implode("\n", array_map('rtrim', $lines)) . "\n */"));
}

/**
 * Walks top-level statements (including namespace blocks and `if (!function_exists())`
 * wrappers) and records declarations. Traits go into $traits instead of $decls.
 */
function collect(array $stmts, string $file, array &$decls, array &$traits): void
{
    $add = function (string $kind, string $fqn, string $ns, Stmt $node) use (&$decls, $file) {
        $key = $kind . ':' . ($kind === 'const' ? $fqn : strtolower($fqn));
        if (isset($decls[$key])) {
            warn("$file:{$node->getStartLine()} duplicate $kind $fqn, keeping the first one");
            return;
        }
        $decls[$key] = ['kind' => $kind, 'fqn' => $fqn, 'ns' => $ns, 'node' => $node, 'file' => $file];
    };

    foreach ($stmts as $s) {
        if ($s instanceof Stmt\Namespace_ || $s instanceof Stmt\Block
            || ($s instanceof Stmt\Declare_ && $s->stmts !== null)) {
            collect($s->stmts, $file, $decls, $traits);
        } elseif ($s instanceof Stmt\If_) {
            collect($s->stmts, $file, $decls, $traits);
            foreach ($s->elseifs as $elseif) {
                collect($elseif->stmts, $file, $decls, $traits);
            }
            if ($s->else) {
                collect($s->else->stmts, $file, $decls, $traits);
            }
        } elseif ($s instanceof Stmt\Trait_) {
            $traits[strtolower($s->namespacedName->toString())] = $s;
        } elseif ($s instanceof Stmt\ClassLike && $s->name !== null) {
            $add('class', $s->namespacedName->toString(), nsOf($s->namespacedName), $s);
        } elseif ($s instanceof Stmt\Function_) {
            $add('function', $s->namespacedName->toString(), nsOf($s->namespacedName), $s);
        } elseif ($s instanceof Stmt\Const_) {
            foreach ($s->consts as $c) {
                $single = new Stmt\Const_([$c], $s->getAttributes());
                $add('const', $c->namespacedName->toString(), nsOf($c->namespacedName), $single);
            }
        } elseif ($s instanceof Stmt\Expression && ($const = fromDefine($s, $file)) !== null) {
            $add('const', ...$const);
        }
    }
}

/** Turns define('NAME', value) into [fqn, ns, Stmt\Const_], or null if it isn't one. */
function fromDefine(Stmt\Expression $s, string $file): ?array
{
    $call = $s->expr;
    if (!$call instanceof Expr\FuncCall || !$call->name instanceof Name
        || strtolower($call->name->getLast()) !== 'define'
        || !isset($call->args[0], $call->args[1])
        || !$call->args[0] instanceof Node\Arg || !$call->args[0]->value instanceof Scalar\String_) {
        return null;
    }
    $fqn = ltrim($call->args[0]->value->value, '\\');
    $pos = strrpos($fqn, '\\');
    $ns = $pos === false ? '' : substr($fqn, 0, $pos);
    $short = $pos === false ? $fqn : substr($fqn, $pos + 1);

    $value = $call->args[1]->value;
    if (!isConstExpr($value)) {
        warn("$file:{$s->getStartLine()} $fqn is computed at runtime; emitted as UNKNOWN, "
            . "set its @var type and add @cvalue with the C macro that holds the value");
        $value = new Expr\ConstFetch(new Name('UNKNOWN'));
    }
    $const = new Stmt\Const_([new Node\Const_($short, $value)], $s->getAttributes());
    return [$fqn, $ns, $const];
}

/* ------------------------------------------------------------------------ */
/* Class post-processing: traits, promoted properties, private members      */
/* ------------------------------------------------------------------------ */

function deepClone(Node $node): Node
{
    return (new NodeTraverser(new CloningVisitor()))->traverse([$node])[0];
}

/** Copies a trait's members into the using class; the class's own methods win. */
function traitMembers(Stmt\TraitUse $use, array $traits, array $own, string $where, array $seen = []): array
{
    if ($use->adaptations) {
        warn("$where: trait adaptations (insteadof/as) are not applied, check for conflicts");
    }
    $out = [];
    foreach ($use->traits as $name) {
        $key = strtolower($name->toString());
        if (isset($seen[$key])) {
            continue;
        }
        if (!isset($traits[$key])) {
            warn("$where: trait {$name->toString()} is not in the input, its members are missing");
            continue;
        }
        foreach ($traits[$key]->stmts as $s) {
            if ($s instanceof Stmt\TraitUse) {
                array_push($out, ...traitMembers($s, $traits, $own, $where, $seen + [$key => true]));
            } elseif ($s instanceof Stmt\ClassMethod
                && ($s->isAbstract() || isset($own[$s->name->toLowerString()]))) {
                continue;
            } else {
                $out[] = deepClone($s);
            }
        }
    }
    return $out;
}

/** Moves promoted constructor parameters into explicit property declarations. */
function promotedProperties(Stmt\ClassMethod $ctor, string $where): array
{
    $props = [];
    foreach ($ctor->params as $p) {
        if ($p->flags === 0) {
            continue;
        }
        $flags = $p->flags;
        if (!($flags & Modifiers::VISIBILITY_MASK)) {
            $flags |= Modifiers::PUBLIC;
        }
        if ($p->hooks) {
            warn("$where: dropped hooks on promoted property \${$p->var->name}");
        }
        $props[] = new Stmt\Property($flags, [new Node\PropertyItem($p->var->name)], [], $p->type, $p->attrGroups);
        $p->flags = 0;
        $p->attrGroups = [];
        $p->hooks = [];
    }
    return $props;
}

function finishClass(Stmt\ClassLike $class, array $traits, bool $keepPrivate): void
{
    $where = "class {$class->namespacedName}";
    $own = [];
    foreach ($class->getMethods() as $m) {
        $own[$m->name->toLowerString()] = true;
    }

    $members = [];
    foreach ($class->stmts as $s) {
        if ($s instanceof Stmt\TraitUse) {
            array_push($members, ...traitMembers($s, $traits, $own, $where));
        } else {
            $members[] = $s;
        }
    }

    $out = [];
    foreach ($members as $m) {
        $isCtor = $m instanceof Stmt\ClassMethod && $m->name->toLowerString() === '__construct';
        $candidates = $isCtor ? [...promotedProperties($m, $where), $m] : [$m];
        foreach ($candidates as $c) {
            // A private constructor is meaningful (not instantiable), so it is always kept.
            $private = ($c instanceof Stmt\ClassMethod || $c instanceof Stmt\Property
                || $c instanceof Stmt\ClassConst) && $c->isPrivate();
            if ($private && !$keepPrivate && !($c === $m && $isCtor)) {
                continue;
            }
            if ($c instanceof Stmt\Property && $c->props[0]->default instanceof Expr\Array_
                && $c->props[0]->default->items) {
                warn("$where: gen_stub.php can't emit the non-empty array default of \${$c->props[0]->name}; "
                    . "initialize it in C instead");
            }
            if ($c instanceof Stmt\Property && $c->hooks) {
                warn("$where: dropped property hooks on \${$c->props[0]->name}");
                $c->hooks = [];
            }
            $out[] = $c;
        }
    }
    // One constant per declaration, so each can get its own @var.
    $out = array_merge(...array_map(fn (Stmt $s) => !$s instanceof Stmt\ClassConst ? [$s] : array_map(
        fn (Node\Const_ $c) => new Stmt\ClassConst([$c], $s->flags, $s->getAttributes(), $s->attrGroups, $s->type),
        $s->consts,
    ), $out));

    // Trait members and promoted properties were spliced in mid-class; regroup them.
    $rank = fn (Stmt $s) => match (true) {
        $s instanceof Stmt\EnumCase => 0,
        $s instanceof Stmt\ClassConst => 1,
        $s instanceof Stmt\Property => 2,
        $s instanceof Stmt\ClassMethod && $s->name->toLowerString() === '__construct' => 3,
        default => 4,
    };
    usort($out, fn (Stmt $a, Stmt $b) => $rank($a) <=> $rank($b));

    $parent = $class instanceof Stmt\Class_ ? $class->extends?->toString() : null;
    $resolver = new SelfResolver($class->namespacedName->toString(), $parent);
    $class->stmts = (new NodeTraverser($resolver))->traverse($out);
}

/* ------------------------------------------------------------------------ */
/* Output                                                                   */
/* ------------------------------------------------------------------------ */

function render(array $decls): string
{
    $byNs = ['' => []];
    foreach ($decls as $d) {
        $byNs[$d['ns']][] = $d['node'];
    }
    $byNs = array_filter($byNs);

    if (array_keys($byNs) === ['']) {
        $nodes = $byNs[''];
    } else {
        $nodes = [];
        foreach ($byNs as $ns => $stmts) {
            $nodes[] = new Stmt\Namespace_($ns === '' ? null : new Name($ns), $stmts);
        }
    }
    $code = tidy((new Printer())->prettyPrintFile($nodes));
    return preg_replace('/^<\?php\s*/', "<?php\n\n/** @generate-class-entries */\n\n", $code, 1) . "\n";
}

/** php-src stub style: `{}` bodies and blank lines between declarations. */
function tidy(string $code): string
{
    $code = preg_replace('/\n[ \t]*\{\n[ \t]*\}/', ' {}', $code);
    $out = [];
    $prevKind = null;
    foreach (explode("\n", $code) as $line) {
        $t = trim($line);
        $kind = match (true) {
            str_starts_with($t, '/**') => 'doc',
            (bool) preg_match('/^(namespace|((final|abstract|readonly)\s+)*(class|interface|enum|trait))\b/', $t) => 'type',
            (bool) preg_match('/^((public|protected|private|static|final|abstract)\s+)*function\b/', $t) => 'function',
            (bool) preg_match('/^((public|protected|private|final)\s+)*const\b/', $t) => 'const',
            str_starts_with($t, 'case ') => 'case',
            (bool) preg_match('/^(public|protected|private|static|readonly|var)\b.*\$/', $t) => 'property',
            default => null,
        };
        $prev = $out ? trim(end($out)) : '';
        if ($kind !== null && $prev !== '' && !str_ends_with($prev, '{') && !str_ends_with($prev, '*/')
            && (in_array($kind, ['doc', 'type', 'function'], true) || $kind !== $prevKind)) {
            $out[] = '';
        }
        if ($kind !== null && $kind !== 'doc') {
            $prevKind = $kind;
        }
        $out[] = $line;
    }
    return implode("\n", $out);
}

function findPhpFiles(array $paths): array
{
    $skip = ['vendor', 'tests', 'test', 'node_modules', '.git'];
    $files = [];
    foreach ($paths as $p) {
        if (is_file($p)) {
            $files[] = $p;
            continue;
        }
        if (!is_dir($p)) {
            warn("no such file or directory: $p");
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($p, FilesystemIterator::SKIP_DOTS),
            fn (SplFileInfo $f) => $f->isDir()
                ? !in_array($f->getFilename(), $skip, true)
                : $f->getExtension() === 'php' && !str_ends_with($f->getFilename(), '.stub.php'),
        ));
        foreach ($it as $f) {
            $files[] = $f->getPathname();
        }
    }
    sort($files);
    return $files;
}

function writeFile(?string $path, string $contents): void
{
    if ($path === null) {
        echo $contents;
        return;
    }
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, $contents);
    fwrite(STDERR, "wrote $path\n");
}

/* ------------------------------------------------------------------------ */
/* Main                                                                     */
/* ------------------------------------------------------------------------ */

$opts = getopt('', ['out:', 'split:', 'ext:', 'keep-private', 'help'], $rest);
$paths = array_slice($argv, $rest);
$split = $opts['split'] ?? 'none';
$out = $opts['out'] ?? null;
$ext = $opts['ext'] ?? 'myext';

if (isset($opts['help']) || !$paths || !in_array($split, ['none', 'class'], true)
    || ($split === 'class' && $out === null)) {
    fwrite(STDERR, "usage: php make-stub.php [--out=PATH] [--split=none|class] [--ext=NAME] "
        . "[--keep-private] <file-or-dir>...\n       --split=class requires --out=DIR\n");
    exit(isset($opts['help']) ? 0 : 1);
}

$parser = (new ParserFactory())->createForHostVersion();
$decls = [];
$traits = [];

foreach (findPhpFiles($paths) as $file) {
    try {
        $ast = $parser->parse(file_get_contents($file)) ?? [];
    } catch (Error $e) {
        warn("$file: parse error, skipped: {$e->getMessage()}");
        continue;
    }
    $resolver = new NameResolver(null, ['replaceNodes' => true]);
    $ast = (new NodeTraverser($resolver, new SignatureVisitor($resolver, $file)))->traverse($ast);
    collect($ast, $file, $decls, $traits);
}

foreach ($decls as $d) {
    if ($d['kind'] === 'class') {
        finishClass($d['node'], $traits, isset($opts['keep-private']));
    }
}

finishEvaluatedValues($decls);

if (!$decls) {
    warn('nothing to write');
    exit(1);
}

if ($split === 'none') {
    writeFile($out, render($decls));
    exit(0);
}

$dir = rtrim($out, '/');
$globals = array_filter($decls, fn (array $d) => $d['kind'] !== 'class');
if ($globals) {
    writeFile("$dir/$ext.stub.php", render($globals));
}
foreach ($decls as $d) {
    if ($d['kind'] === 'class') {
        $name = strtolower(str_replace('\\', '_', $d['fqn']));
        writeFile("$dir/$name.stub.php", render([$d]));
    }
}
