# Possible design for PHP modules system

We're using the term "module" instead of "package" primarily because "package" means "Composer package."  There is no inherent reason why all of a Composer package should be the same module.  A package is a unit of distribution.  A module is a unit of scoping.

## Goals

1. Give the optimizer a larger "set" of files to operate on at once.  This has, we believe, many possibilities for improved performance as the optimizer can be smarter.  In the extreme case, it has similar options to an AOT (Ahead-Of-Time) compiled language.
2. Define a limited scope for module-based visibility and similar functionality.
3. Make it easier to support non-PSR-4-style file organization.  Aka, allow loading many symbols from one file.  This is especially helpful if those files contain non-autoloadable symbols (functions, constants, etc.)

## Non-Goals

1. Allowing more than one version of a symbol to be loaded at once in different scopes.  This is just way too messy and hard for the engine to figure out.
2. Replace namespaces.  Like them or not, namespaces are a key part of PHP today, and it's impossible to "replace" them within our lifetimes.
3. Radically shift the language itself.  Eg, forcing strict-types, removing `<?php`, and so on are not in scope.

## Constraints

1. Gradual opt-in.  It should be possible for an application to use some modularized and some non-modularized packages together with ease.
2. Upgrading an application from using v2 of a package that is non-modular to using v3 of a package that is modular should be simple.  Maybe not trivial, but at least simple.
3. It has to play nice with Composer, without making Composer a required part of using PHP.
4. While this may render autoloading less used, it should not render autoloading obsolete.  It does not *replace* autoloading.  It supplements it.

## Definitions

* Package - A unit of distribution.  99.9% of the time a Composer package, but technically would also include a Wordpress module.  In practice, will be equivalent to a directory path.
* Module - A unit of scope.  One or more files that are, or may be, parsed and loaded at once.  A module defines a scope in which additional functionality (eg, visibility) may be implemented.  A module must be the same as or a subset of a Package.
* X - A unit of compilation.  These files are loaded by the engine all at once and compiled/optimized.  It's equivalent to concatenating all the files together first, then loading them. (I don't have a name here yet. Suggestions welcome.)
* Namespace - A prefix on symbol names used for disambiguation.  At compile time, these are optimized away into just really-long symbol names.

## Syntax

All modules correspond to a particular namespace fragment.  Like namespaces, they may be defined as a block or as a file-level declaration.

There is an implicit module named `\` that applies to all code not otherwise placed into a module.

```php
# foo-partial.php
module Foo\Bar {
    // Anything here is part of module Foo\Bar
}

// Anything here is implicitly in the `\` module.
```

```php
# foo-complete.php
module Foo\Bar;

// Anything in this file is part of module Foo\Bar.
```

A namespace declaration inside a module is always relative to the current module.

```php
module Foo\Bar {
  namespace Baz {
      // This class's FQDN is \Foo\Bar\Baz\Beep
      class Beep{};
  }
}
```

```php
module Foo\Bar;
namespace Baz;

// This class's FQDN is \Foo\Bar\Baz\Beep
class Beep{};
```

If no namespace is specified, the namespace is equal to the module.

```php
module Foo\Bar;

// FQDN is \Foo\Bar\Beep.
class Beep;
```

In general, that means converting a non-module file to a module file requires simply replacing `namespace` with `module`.  If the file's contents should be in a sub-namespace but not a sub-module, it would involve splitting one `namespace` line into two lines, one `module` and one `namespace`.

`use` references to a given symbol are unchanged from today.  They still reference a symbol by its full name.

### Possible extension.

Do we allow sub-modules to have technical implications?  I'm not sure.  For now they do not.  (This only really applies to module visibility, not loading.)

## Loading

We define a new type of file.  It could either be an `ini`-file, or a PHP file that returns an array, or some new PHP structure.  (Or TOML, or whatever.  To bikeshed later.)  For now, we'll use an `ini` file.

This file is always named `module.ini` (TBD: Change the name?).  It must be unique in a directory.  It defines a module name and a list of files, via glob definitions.  For example:

```ini
module = Foo\Bar
files=**/*.php, *.ext
exclude=examples/*
```

This definition would declare a module unit of all files in the current directory named `.ext`, any sibling or descendant files named `*.php`, but excluding anything found in the `examples` directory.

Other declarations could conceivably be defined in the future.

A new core function is added, `require_modules(array $modules)`.  It takes an array of file names of `module.ini` files.  When called, it will load all the referenced `ini` files, and then load all PHP files referenced by those `ini` files.  These files will all be loaded at once, and passed to the optimizer at once, and will form a single opcache entry.

When loading files in this way, the following rules are enforced:

* If a file is not a sibling or descendant of the `module.ini` file, it is a fatal error.
* If a file referenced by `module.ini` does not declare the same module as `module.ini`, it is a fatal error.
* If a file referenced by `module.ini` is already loaded by some other module, it is a fatal error.
* All symbols referenced in the loaded files must exist, or must be declared by one of the loaded files, or must be autoloadable (the autoloader may be called if necessary). This excludes symbols referenced in strings, in `use` statements, and in `::class` constants. Symbols referenced in conditional declarations are not required to exist of the declaration is not made. 
* Modules must not have cyclic dependencies (if a module A depends on module B, then module B can not directly or indirectly depend on module A)

In the following snippet, classes B, C, and D must exist, but class E is not required to exist:

```php
module Foo\Bar;

use Foo\{B,C};
use Qux\{D,E};

class A extends B {
    function f(): C {
        return new D;
    }
}

$a = E::class;
```

In the following snippet, the classes `CurlHandler`, `HandlerStack`, and `Client` must exist, but only if the class `GuzzleAdapter` is declared. The `class_exists()` call may trigger autoloading.

```php
module Foo\Bar;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\CurlHandler;

if (class_exists(GuzzleHttp\Client::class)) {
    class GuzzleAdapter {
        function __construct() {
            $handler = new CurlHandler();
            $stack = HandlerStack::create($handler);
            $this->client = new Client(['handler' => $stack]);
            // ...
        }
    }
}
```

Rationale for rule 4 "all symbols must exist":
 * The compiler's view of the code base does not depend on loading order
 * Non-qualified function calls can be resolved at compile time

Additionally, when loading a file with `require()` and similar:

* If the file declares a module that is already loaded AND is it not a child or sibling of the `module.ini` file that was used to load it, it is a fatal error.  This allows the package to have module files that are not bulk-loaded (because they are rarely used), but prevents other packages from "sneaking" into a foreign module.
* If a file uses a namespace that is equivalent to an already-loaded module, it is a fatal error.  Eg, a file with `namespace Foo\Bar`, if loaded after `module Foo\Bar`, would be an error.  However, a file with `module Foo\Bar` may still be loaded after the equivalent `module.ini` file, subject to the previous restriction.

The reason for allowing multiple modules is so that a package-developer-defined unit of compilation is always loaded together, BUT a site admin may further optimize their application by combining multiple compilation units together into one.  In the extreme case, an application could conceivably front-load almost its entire code base at the module level, creating a single opcache entry of the entire application for the optimizer to work on.  (Whether that is wise will depend on the application, just like preloading.)

There is no automatic loading of modules.  However, `require_modules()` may be invoked from within an autoload callback.  That means, if desired, the first use of any symbol out of a given module would trigger loading the entire module (or a large portion thereof), but if the module is never used, it would not get loaded.

## Composer integration

Composer is concerned primarily with two things:

1. Distribution of code.
2. Loading of code.

The distribution is irrelevant to this discussion.

For loading, Composer would make the following additions:

1. A `composer.json` file may declare the existence of one or more `module.ini` files.  Composer would keep a record of these files, just like it does for class prefixes, indexed by the module they declare.  This does mean Composer would need to parse each file when building the autoloader, but ini is cheap to parse.  (Optionally, it could auto-detect one in the package root.)
2. When the class autoloader is triggered, the namespace of the symbol to load will be compared against the index of module namespace prefixes.  If it is found, that `module.ini` file will be loaded.  If the symbol still does not exist, it will be autoloaded the same as today.
3. The project-root `composer.json` file MAY declare a list of module names (eg, `Foo\Bar`) that will get bulk-loaded when the autoloader is initialized.

## Non-Composer applications

Critically, nothing in this approach mandates the use of Composer.  It simply provides a single new API function that an application may use however it wishes.  Triggering it from an autoloader is one way, but not the only, and just as applications are free to write their own autoloader (whether it follows PSR-4 or not), they are free to write their own logic around `require_modules()`.

## Modular visibility

A new visibility scope is added, `local`.  A constant, function, class, trait, class constant, method, or property may have a visibility modifier specified of `local`, the same as any other visibility.  A `local` visibility makes the symbol accessible from code in the same module only.

If no visibility is specified, it is `public`, meaning available to any module scope to use.  For example:

```php
module Foo\Bar;

// These two are equivalent, and make the class available anywhere.
class Foo {}
public class Foo {}

// This class may only be referenced by code in the same module.
local class Foo {}

// Available in all modules.
function doStuff() {}

// May be called only from code in module Foo\Bar.
local function doSecretStuff() {}
```

A method or property may have a visibility no wider than its class/interface/trait.  If no visibility is specified, then its visibility is equal to its enclosing class/interface/trait.  (`local` is between `public` and `protected` in the visibility hierarchy.)

```php
module Foo\Bar;

// May be implemented by a class in any module.
interface Inty {}

// May only be implemented by a class in the Foo\Bar module.
local interface Secret {}

local class Baz implements Inty, Secret {
    
    // Only callable from the Foo\Bar module.
    string $name;

    // Only callable from this class or its children.
    protected int $age;

    // Only callable from the Foo\Bar module.
    function __construct() {}
    
    // This is a compile error.
    public function invalid() {}
}

// This class may be referenced by any module.
// The Secret interface does not preclude that.
// However, code not in Foo\Bar will not be able to
// instanceof against Secret, only Inty.
public class Pub implements Inty, Secret {}
```

If asymmetric visibility is adopted, `local` scope would slot into it naturally.  Eg, `public local(set) string $name`.

### Local-only inheritance

A class or interface may also declare its visibility in two parts, similar to property asymmetric visibility.

```php
module Foo\Bar;

// Any code may type check against or instanceof
// against this interface, but only classes in
// the Foo\Bar module may implement it.
public local(implement) interface Useful {}

// Any code may instantiate this class, but only
// code in Foo\Bar may extend it. Effectively,
// this class is "final" outside of its module
// but non-final within it.
public local(implement) class Guarded {}
```

This effectively gives us sealed classes/interfaces for free.

```php
module Foo\Bar;

local(implement) interface Result {}

public local(implement) class OK implements Result {}
public local(implement) class Err implements Result {}
```

Any module may reference `Result`, `OK`, or `Err`, and instantiate the latter two.  However, only code within `Foo\Bar` may implement or extend them, effectively creating a sealed class.

## Using modularized code

Crucially, from a purely loading perspective, whether a symbol used by a file is in a module is irrelevant.  It is still referenced by a FQCN, and that FQCN can be shortened via a `use` statement at the top of the file.  Nothing changes on the consumer end, unless one of those symbols uses module visibility modifiers, in which case the only impact is that certain symbols become unavailable.

That means if, for example, `Crell\Serde` declared itself to be a module, no code that uses `Crell\Serde` needs to change (other than upgrading to an appropriate PHP version to avoid syntax errors, but that's true of any feature).

## Usage/upgrading examples

### Package == module

In the degenerate case (but likely most common case) where a package consists of a single module, the required steps to upgrade would be as follows:

1. Change `namespace My\Package` to `module My\Package` in all top-level files (in `/src`).
2. If necessary, change `namespace My\Package\Sub` in any sub-directory to `module My\Package; namespace Sub;`.
3. Add a `module.ini` file in the source root like so:

```ini
module=My\Package
files=**/*.php
```

4. Declare the module in `composer.json`.

The above steps are straightforward, and should be easily scriptable by tools like Rector.

5. OPTIONAL: At this point, any autoloadable symbol will trigger loading the entire package.  That means autoloadable symbols may be moved to any file within the package that the author wishes, and it will not break loading, nor will it constitute an API break.  This is entirely at the discretion of the author.

### Package == Module, but only some of it is important

Suppose we have a package with a common core of behavior, plus assorted other code that is only rarely used so forcing it to always load may not be appropriate.  One way of converting it to modules would be:

1. Change `namespace My\Package` to `module My\Package` in all top-level files (in `/src`).
2. If necessary, change `namespace My\Package\Sub` in any sub-directory to `module My\Package; namespace Sub;`.
3. Add a `module.ini` file in the source root like so:

```ini
module=My\Package
files=Main.php, Support.php, OtherSupport.php, Enums.php
```

4. Declare the module in `composer.json`.

Now, trying to load the class `My\Package\Main` will trigger an autoloader, which will call `require_modules()`, which will bulk-load all four files specified.  Trying to load the class `My\Package\Rare` will trigger `require_modules()` and bulk-load those files, and then load `My/Package/Rare.php` as it normally would.

### Function/constant autoloading

An interesting side effect of this approach is that loading a module will load any files specified, regardless of the symbols they use.  That provides a way to autoload functions and constants implicitly when their module is loaded.  In a minimal case:

1. Declare a module in all relevant files.
2. Define a `module.ini` file like so:

```ini
module=My\Package
files=functions.php, constants.php, util/array.php
```

Now, while functions declared in `functions.php` cannot be directly autoloaded themselves, the use of any autoloadable symbol (class, etc.) in `My\Package` will cause that file to be loaded as well.  That allows all files to be lazily loaded individually, while still depending on non-autoloadable utilities in the same package.

### Multi-module packages, siblings

A package may declare multiple modules if it wishes, in separate directories.  This creates no complications.  For example:

```text
/src
  /mod1
    module.ini
    SomeCode.php
  /mod2
    module.ini
    SomeCode.php
```

In this case, `composer.json` would be configured with two module references.  Whether those modules are in similar namespaces to each other is irrelevant.  From a loading perspective, they are unrelated.

### Multi-module packages, children

In some cases, it may be logical for a package to ship multiple modules but not in sibling directories.  For example, a DBAL may want to offer each of the bundled drivers it provides in a separate namespace, but not have to fully reorganize their code to do so.  The package could be structured like this:

```text
/src
  module.ini
  Driver.php
  /Parser
    ParserStuff.php
  /MySQL
    module.ini
    MySQL.php
  /Postgres
    module.ini
    Postgres.php
```

```ini
# /src/module.ini
module=My\DBAL
files=**.php
exclude=MySQL/*, Postgres/*
```

```ini
# /src/MySQL/module.ini
module=My\DBAL\MySQL
files=**.php
```

```ini
# /src/Postgres/module.ini
module=My\DBAL\Postgres
files=**.php
```

Importantly, `src/MySQL/MySQL.php` is owned by the module `My\DBAL\MySQL`, *not* by `My\DBAL`.  It is in that namespace, specifies that module, and is referenced by that `module.ini`.  If the `My\DBAL` module tries to reference that file in its `module.ini`, it will be an error.

These additional modules are not sub-modules in the sense that they have a relationship with the parent module.  However, they are in the sense that their namespace is an extension of the parent module.  Technically they are a "sub-module" in the same way that a namespace is a "sub-namespace" today.

### Local constructors

A potentially powerful pattern with module visibility would be a class that may be used anywhere, but only constructed within its own module.  That can be achieved by setting the constructor of a public class to `local`.

```php
module MyFramework\Routing;

class RoutingResult
{
    local function __construct(string $a, string $b) {}

    public function whatever() {}
}
```

This class may be used, referenced, typed against, and passed around in any module.  But it may only be constructed within the `MyFramework\Routing` module.
