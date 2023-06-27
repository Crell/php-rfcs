## Background

Many langauges feature some form of property accessor syntax.  There are a many variants, as well as many use cases served by such features.  Broadly speaking, implementations fall into two categories:

1. Promoted methods.  In this model, an otherwise normal method gets some kind of marker to indicate that it should be triggered behind-the-scenes when a pseudo-property is accessed.  There is no intrinsic storage value associated with these methods.  If one is desired, the developer must implement one themselves.  Examples here include Python and Javascript.

2. Expanded properties.  In this model, an otherwise normal property has explicit "hooks" associated with it where additional logic may be included.  These hooks are tightly coupled to the property, and there is may or may not be an automatic backing storage value.  Examples here include C# and Swift.


Regardless of the mechanism, there are a number of "hook" types that could be implemented, although not all languages implement all of them.

1. `get` - Provides custom logic for retrieving a property or pseudo-property.
2. `set` - Provides custom logic for setting a property or pseudo-property.
3. `cached`/`lazy` - A special case of `get` in which the result of the custom logic is cached automatically.
4. `guard` - A special case of `set` in which the the only additional logic is validation beyond the capability of the type system.  In this case, the custom logic would not actually set a storage value, just validate that the value to be set is acceptable.  (It could return false, throw a custom exception, etc. depending on the design.)
5. `delete`/`unset` - Explicitly set a property or pseudo-property to an undefined state.  This may or may not be desireable to support depending on the circumstances.
6. `once` - A special case of `set` that may only be called once, and is an error thereafter.
7. `before` - Custom logic that is called before a `set` operation, but does not actually do the set itself.  Useful for validation.
8. `after` - Custom logic that is called after a `set` operation, but does not actually do the set itself.  Useful for updating derived values.

## Use cases

There are a number of use cases that such functionality addresses, either directly or indirectly.

1. Following the Uniform Access Principle (https://en.wikipedia.org/wiki/Uniform_access_principle) in order to cleanly transition a property to a method or back again without breaking BC.  Generally this is allow a property to be defined "bare," with the option to add method-like functionality later (eg, for validation).

2. Derived properties.  These properties do not exist on their own as a data value, but can conceptually be thought of as one that is derived from other properties.  The canonical example here is `fullName`, which is fully derived from `$this->firstName` and `$this->lastName` (assuming Western European naming conventions).  These properties could be implemented as a method, but at times it is convenient to treat them as properties, particularly if migrating from one API design to another.  (See point 1.)

3. Automatic caching of derived properties.  Sometimes, computing a derived property may be relatively expensive.  In those caes, having some sort of automatic caching of the resulting property may be convenient.  Again, this is possible today using just-methods, and the patterns are well known, but they are so common that there is benefit to automating them.

4. Asymmetric visibility.  Although not strictly a part of property access logic, asymmetric visibility is often included in the same syntax.  The custom logic for `get` and `set` hooks may, like a method, be restricted to `public`/`protected`/`private` access.  That allows for trivial impementations to be written either manually or auto-implied by the compiler that produce the net effect of a property that is readable in a wider scope than it is writeable.  (Technically the inverse is also possible, but generally useless.)


## References

As usual, references cause problems.  Specifically, if a `get` hook override returns a value by reference, it may expose an underlying storage value to modifications that bypass the `set` hook.  That violates encapsulation, and thus could lead to inconsistent data and unpredictable results.  For that reason, by-ref retuns from `get` or by-ref assignment on `set` cannot be allowed.

A side effect of that restriction is that arrays cannot be modified in place on properties.  That is, assuming the `$bar` property is an array that leverages the extra hooks above (by whatever syntax), the following is not possible:

```php
$f = new Foo();

$f->bar[] = 'beep';
```

That would require returning `$bar` by reference so that the `[]` operation could apply to it directly.  However, that then bypasses any restrictions imposed by a `set` hook (such as the values had to be integers).  The following, however, would be legal:

```php
$f = new Foo();

$b = $f->bar;

$b[] = 'beep';

$f->bar = $b;
```

This snippet would require no references, and if a `set` hook is defined for `$bar` then it could validate the array on the last line.

Because objects pass by-handle (that is, a handle to the object is returned by value), that concern does not exist there.  It only applies to arrays (and to any potentially future array-like structures, such as a list or set if those are ever implemented).  For all other types, references must still be disallowed but it would not otherwise restrict typical usage patterns.

This limitation imposes an extra caveat on the Uniform Access Principle consideration, specifically, that switching a property from a "raw" property to an accessor property will inherently involve an API break of disabling references.  Therefore, some simple way to disable references on "raw" properties is also necessary in order to prepare a property for future accessor conversion.

## Elements

The constituent parts of an accessor implementation for PHP, then, include:

* Marking a property as no-ref, for forward compatibility.
* Asymmetric visibility, either on its own or as part of custom hook implementations.
* Support for defining an arbitrary number of "hooks," possibly mutually-exclusive, in the future.
* Support for defining arbitrary logic within a hook.

## Comparisons

For comparison, here's how several other languages handle accessors.

### Javascript

Javascript, oddly, supports both promoted methods and expanded properties, although the promoted methods approach is far more common:

```js
let user = {
  name: "John",
  surname: "Smith",

  get fullName() {
    return `${this.name} ${this.surname}`;
  },

  set fullName(value) {
    [this.name, this.surname] = value.split(" ");
  }
};

// or

let user = {
  name: "John",
  surname: "Smith"
};

Object.defineProperty(user, 'fullName', {
  get() {
    return `${this.name} ${this.surname}`;
  },

  set(value) {
    [this.name, this.surname] = value.split(" ");
  }
});

// Both allow:

user.fullName = "Larry Garfield";
console.log(user.fullName); // prints "Larry Garfield"
```

In either case, there is no automatic backing value.  If one is desired, the convention (not enforced by the language) is to use a dynamic property with a `_` prefix and the same name as the method.  Note that in Javascript, all properties are dynamic and there is no property-level visibility control.

Implementing only one of `get`/`set` is fully supported.

See https://javascript.info/property-accessors for more information.

### Python

Python supports only the promoted methods approach.  It supports three hooks: `get`, `set`, and `delete`, and it is possible to implement any combination of them.  The specific syntax uses Python decorators, which are roughly equivalent to PHP Attributes, although the syntax is not symmetric.

```python
class User:
    def __init__(self, first, last):
        self.first = first
        self.last = last
        
    @property
    def full_name(self):
        return self.first + " " + self.last
    
    @full_name.setter
    def full_name(self, new_name):
        [self.first, self.last] = new_name.split(' ')

    @full_name.deleter
    def full_name(self):
        del self.first
        del self.last

# Allows

u = new User("Ilija", "Tovilo")
u.full_name = "Larry Garfield"
print(u.full_name) # prints "Larry Garfield"
del u.full_name # Unsets both first and last properties
```

The method names must match the pseudo-property being defined.  The `get` hook is always identified with `@property` while the `set` and `delete` hooks include the property name and then the hook.

As with Javascript, a storage variable is not provided automatically, although the convention is to use a `_`-prefixed property.  There is no class-level visibility control.

### Swift

Swift has a few different mechanisms relating to property behavior.  It differentiates them into "stored properties" and "computed properties."  Stored properties are largely like properties in any other language, but with one caveat: There is an optional `lazy` keyword that will delay initialization of a property until it is first accessed.

```swift
class Address {
  var number = 123
  var street = "Main St"
  var city: "Somewhere"
  var state = "California"
  var zip = "90036"
}

class User {
  lazy var address = Address()
  var first: String
  var last: String
}

u = User(first: "Ilija", last: "Tovilo")
// u.address doesn't exist yet.

print(u.address.city) // u.address gets created here.
```

Computed properties are defined as properties, but also include additional `get`/`set` hooks that are invoked when appropriate.

```swift
class User {
  var address = Address()
  var first: String
  var last: String
  
  var fullName: String {
    get {
        return first + " " + last
    }
    set(newName) {
        let ar = newName.components(separatedBy: " ")
        first = ar[0]
        last = ar[1]
    }
  }
}

// Allows

u = User(first: "Ilija", last: "Tovilo")
u.fullName = "Larry Garfield"
print(u.fullName) // prints "Larry Garfield"
```

Either the `get` or `set` operation may be defined independently.  They support arbitrary method bodies.  There are three shorthand variants on the syntax.

One, the argument to the `set` method body may be omitted in which case it defaults to `newValue`:

```swift
class User {
  var address = Address()
  var first: String
  var last: String
  
  var fullName: String {
    get {
        return first + " " + last
    }
    set {
        // newValue is magically named.
        let ar = newValue.components(separatedBy: " ")
        first = ar[0]
        last = ar[1]
    }
  }
}
```

Second, if only the `get` hook is implemented, then it may be used as the body of the property directly:

```swift
class User {
  var address = Address()
  var first: String
  var last: String
  
  var fullName: String {
    return first + " " + last
  }
}
```

Third, if the body of `get` is a single expression it may be inlined without `return`.

```swift
class User {
  var address = Address()
  var first: String
  var last: String
  
  var fullName: String {
    first + " " + last // Note no "return" keyword, like short lambdas in PHP.
  }
}
```

As a practical matter, there is no difference between a non-accessor property and a property with trivial get/set behavior.  Swift auto-generates the trivial implementations if not specified, so the effective behavior is the same.

Swift also supports specifying properties on protocols (what PHP would call interfaces).  The protocol may specify that a property must support reading, writing, or both, but whether an implementation uses a stored property or a computed property is up to the implementation.

```swift
protocol Person {
    var first: String { get set }
    var last: String { get set }
  
    var fullName: String { get }
}
```

The above protocol requires that `first` and `last` be read/write, but `fullName` only needs to be readable.  (Also making it writeable is allowed, but not required.)

Swift additionally has two more hooks that run before/after a set operation, independently of whether or not the property has a `set` implementation.  These hooks do not preclude an auto-generated storage value or automatic `get`/`set` behavior.

```swift
class User {
  var address = Address()
  var nameChanged: Bool
  
  var fullName: String {
    // Ensure that the name has two parts in it.
    willSet {
        assert(newValue.contains(" "))
    }
    // Record some side-band data.
    didSet {
        nameChanged = true
    }
  }
}
```

Additionally, Swift supports "property wrappers," which I won't get into here in detail.  They are effectively a way to "package up" a more involved property definition and reuse it on different properties on multiple objects.

Access control in Swift is independent of the `get`/`set` hooks.  It is always specified before the property itself, whether or not there are any hooks defined.  Read access is specified with a `public`, `private`, etc. keyword (Swift has more levels than PHP does).  Write access is the same as read, *unless* specified with a separate `protected(set)`, `private(set)`, etc. indicator.  If specified, write access must be more restrictive than read access.  If read access is not specified, it defaults to `internal` (limited to the package).


For more information, see https://docs.swift.org/swift-book/LanguageGuide/Properties.html


### C#

C# is firmly in the expanded properties camp, and has a large number of features baked into its property support.  It supports several hooks, including `get`, `set`, and `init` (a write-once operation only callable in the constructor, mutually exclusive with `set`).

```csharp
public class User
{
    public string first;
    public string last;
    
    public string fullName {
        get { return first + " " + last; }
        set { 
            string[] names = value.split(' ');
            first = names[0];
            last = names[1];
        }
    }
}

// Allows

u = new User("Ilija", "Tovilo");
u.fullName = "Larry Garfield";
System.Console.WriteLine(u.fullName);
```

Note that the `set` hook always has a magic variable named `value` that represents the value being assigned.  It cannot be overridden.

Either the `get` or `set`/`init` hooks may have a custom visibility provided it is narrower than the property's visibility, but not both.

```csharp
public class User
{
    public string first;
    public string last;
    
    public string fullName {
        get { return first + " " + last; }
        protected set { 
            string[] names = value.split(' ');
            first = names[0];
            last = names[1];
        }
    }
}
```

As a shorthand, if any hook body is a single expression (including an assignment for `set`/`init`), it may be abbreviated as a single arrow-like expression:

```csharp
public class User
{
    public string first;
    public string last;
    
    // Force this property always be a lowercase string.
    private string _username;
    public string username {
        get => _username;
        set => _username = value.toLower();
    }
    
    public string fullName {
        get => first + " " + last;
        protected set { 
            string[] names = value.split(' ');
            first = names[0];
            last = names[1];
        }
    }
}
```

A non-accessor property is subtly different to an empty-accessor property.

```csharp
class Person
{
    public string name { get; set; }
    public int age;
}
```

Both `name` and `age` in this case will support read and write without any additional behavior.  However, `name` will not support references while `age` will.  Additionally, if a child class extends `Person` it may not override `name` with a non-accessor property nor `age` with an accessor property.  That is invariant.

Interfaces may also declare properties, and specify that the property must have a `get` and/or `set` hook with public visibility.  An auto-generated implementation counts, as does just defining a normal public property.

```csharp
interface NamedThing
{
    // Must have a readable name property.
    // How or if it is writeable is undefined.
    public string name { get; }
    
    // Must have both read/write defined, however you prefer.
    public string alias { get; set; }
}
```

PHP 8.1's `readonly` directive is approximately equivalent to `public int foo { get; private init; }`, although that would be slightly more restrictive as `readonly` doesn't limit the single write to just the constructor.  (Some static analysis tools incorrectly do so, unfortunately.)

For more details, see: https://docs.microsoft.com/en-us/dotnet/csharp/programming-guide/classes-and-structs/properties

### PHP

PHP has property overloading... sort of.  It is implemented through four magic methods that operate on any missing or undefined property: `__get`, `__set`, `__isset`, and `__unset`.  Any or all may be implemented, and will be passed the name of the property being read, written, checked for set-ness, and unset, respectively, along with the value to write for `__set`.

While effective, it suffers from three key problems:

1. It is mutually exclusive with defining a property explicitly in most ways.
2. It is entirely opaque, so static analysis tools are unable to determine what pseudo-properties may exist without additional manual documentation.
3. For implementation reasons, it's 3x slower than a method call.


## Usage examples

The following is a non-exhaustive list of examples of common code in PHP 8.1 that could benefit from some variety of improved property accessor.  The intent is to ensure that they are covered, either by the initial implementation or by natural extensions that could be implemented later.  (Viz, the syntax chosen would extend to them gracefully.)

### Derived properties

```php
class User
{
    private string $first;
    private string $last;
    
    public function fullName(): string
    {
        return $this->first . " " . $this->last;
    }
}
```

### Caching

```php
class User
{
    private string $first;
    private string $last;
    private string $fullName;
    
    public function fullName(): string
    {
        return $this->fullName ??= $this->first . " " . $this->last;
    }
    
    public function setFirst($first): string
    {
        $this->first = $first;
        $this->fullName = null;
    }

    public function setLast($last): string
    {
        $this->last = $last;
        $this->fullName = null;
    }
}
```

### Asymmetric visibility

```php
class Person
{
    priviate string $id;
    
    public function getId(): string
    {
        return $this->id;
    }
}
```

### Validation

```php
class Person
{
    // US 10 digit number
    private string $phone;
    
    public function getPhone(): string
    {
        return $this->phone;
    }
    
    public function setPhone(string $new): void
    {
        if (!preg_match('/\d\d\d\-\d\d\d\-\d\d\d\d/', $new)) {
            throw new \InvalidArgumentException();
        } 
        $this->phone = $new;
    }
}
```

### Type condensing

```php
class Person
{
    private array $degrees = [];
    
    public function getDegrees(): array
    {
        return $this->degrees;
    }
    
    public function setDegrees(string|array $degrees): void
    {
        $this->degrees = is_string($degrees) ? [$degrees] : $degrees;
    }
}
```

### Set-once

```php
class Person
{
    public function __construct(
        public readonly string $name,
    ) {}
}
```

### Evolvable value object

This one would depend solely on asymmetric visibility to allow public read/private write, so that only the `with*()` methods are able to clone-and-update.

```php
class Person
{
    public function __construct(
        public readonly string $name,
        public readonly int $age,
    ) {}
    
    public function withName(string $new): static
    {
        return new static($new, $this->age);
    }

    public function withAge(int $new): static
    {
        return new static($this->name, $new);
    }
}
```

### Logging/change tracking

```php
class Person
{
    private array $isModified = [];

    public function __construct(
        public string $name,
        public int $age,
    ) {}
    
    public function setName(string $new): void
    {
        $this->isModified['name'] = true;
        $this->name = $new;
    }

    public function setAge(int $new): void
    {
        $this->isModified['age'] = true;
        $this->age = $new;
    }
    
    public function save(): void
    {
        foreach (array_keys($this->isModified) as $prop) {
            // ...
        }
    }
}
```

## Constraints

The goal is to make the above examples simpler/easier to read and write, while respecting the unique challenges of PHP.

The most notable is the aforementioned issue regarding references.  A property that has any additional enhanced behavior cannot be allowed to return by reference, but current properties are referenceable by default.  That means there must be some mechanism to prepare a property for future enhancement by disabling references but not otherwise impacting behavior.  That could be via a no-op version of whatever syntax is used for accessors or some other marker.

Additionally, there is the `readonly` directive.  `readonly` does not quite correspond to any of the accessor designs above.  As noted, it is most close to C#'s `public int foo { get; private init; }`, but not quite the same.  Ideally, `readonly` should be recast as a shorthand for a particular accessor directive to avoid complex interactions between `readonly` and accessors.  Otherwise, we have to consider what happens when using `readonly` and accessors at the same time, or just outlaw it.

A third consideration is Constructor Property Promotion, which allows properties to be inlined in the constructor of a class to avoid code duplication.  Depending on the syntax chosen, that could result in highly convoluted nested code that would be difficult to maintain.  For that reason, Nikita's previous draft of accessors forbid combining CPP with accessors if the `get` and `set` hook have implementations.  That is sensible in many cases, but does make cases such as providing additional validation for an otherwise promotable property more clumsy.

## Syntax possibilities

In practice, the behavior of both the promoted methods and enhanced properties is effectively the same.  Both approaches support all the same capabilities, and at runtime would operate effectively the same.  The main difference is in the subtle edge cases, as well as subjective developer convenience factors.

This section should be understood as "Larry talking to himself in code samples."

### Promoted methods (keyword)

This uses an extra keyword for methods, a la Javascript.  Using attributes would be, I think, almost the same in most ways that matter.

```php
class User
{
    private string $first;
    private string $last;
    
    // Could be with or without the function keyword?
    public get function fullName(): string
    {
        return $this->first . " " . $this->last;
    }
}

print $user->fullName;
```

Caching a property whose dependent values could change sounds super complex, as we'd need to also track when those change to clear the cache.  That's more complexity than we can justify.  So caching is only sensible on readonly properties.

```php
class User
{
    // Use => for "Evaluates to".  Only legal on readonly properties.
    // Swift-esque.
    // If you need multiple statements, call $this->makeFirstName() or similar.
    private readonly string $fullName => $this->first . " " . $this->last;

    public function __construct(
        public readonly string $first,
        public readonly string $last,
    ) {}
}
```

Asymmetric visibility via methods is... just gross, as it forces you to implement a storage value manually.

```php
class Person
{
    priviate string $id;
    
    public get function id(): string
    {
        return $this-id;
    }
    
    private set function id(): string
    {
        return $this->id;
    }
}
```

This also runs into the interesting question of if the parser/engine can effectively mangle that, with two identically named methods.

Taking a Swift-style approach could be much better:

```php
class Person
{
    public private(set) string $id;
}
```

Open question: Does that mean in the future we could have `public private(init) string $id` as a synonym for `readonly`?  Does that also mean `public public(init)` for a write-once-from-anywhere property? Huh.

For validation, a `set` hook could do it, but I do really like a Swift-style "before" hook.

```php
class Person
{
    // US 10 digit number
    public string $phone;
    
    public string $username;
    
    public set function username(string $new): void
    {
        $this->username = strtolower($new);
    }
    
    public function set phone(string $new): void
    {
        if (!preg_match('/\d\d\d\-\d\d\d\-\d\d\d\d/', $new)) {
            throw new \InvalidArgumentException();
        } 
        $this->phone = $new;
    }
}

// Or

class Person
{
    
    public function __construct(
        // US 10 digit number
        public string $phone,
        public string $username;
    ) {}
    
    public before function username(string $new): void
    {
        return strtolower($new);
    }
    
    public before function phone(string $new): void
    {
        if (!preg_match('/\d\d\d\-\d\d\d\-\d\d\d\d/', $new)) {
            throw new \InvalidArgumentException();
        }
        return $new;
    }
}
```

This does create interesting interactions between the visibility of the property and the visibility of the method.  What happens if they're out of sync?

This approach would handle CPP without issue, it seems, since it desugars to a private set, the hook methods operate exactly as they would if there were no CPP.

Another interesting factor: PHP uses separate keyspaces for properties and methods.  So... what is the potential for confusion there?  It *seems* like it "just kinda works" in the example above, but it feels like there are many pitfalls waiting.  For instance, what happens here?

```php
class Person
{
    public string $username;
    
    public function username(): string
    {
        print "In Method\n";
        return $this->username;
    }
    
    public get function username(): string
    {
        print "In Getter\n";
        return $this->username;
    }
    
    print "In Setter\n";
    public set function username(string $new): void
    {
        $this->username = strtolower($new);
    }
}
```

Even if we can figure out what the code logically would do, it still seems highly confusing for the user.  In some ways, the fact that properties and methods can have the same name makes this problem worse than it would be in other languages that force you to, at minimum, use `$_username` or something dumb like that.

Condensing types is interesting:

```php
class Person
{
    public array $degrees = [];

    public set function degrees(string|array $degrees): void
    {
        $this->degrees = is_string($degrees) ? [$degrees] : $degrees;
    }
}

// Or

class Person
{
    public array $degrees = [];

    public before function degrees(string|array $degrees): string
    {
        return is_string($degrees) ? [$degrees] : $degrees;
    }
}
```

Neither Python nor Javascript have meaningful explicit types, so this sort of logic is both reasonable and irrelevant at the same time. :-)  I don't believe it's supported in Swift or C#, but I can very much see the use for it in PHP.

One interesting observation here: technically, *any* `set` hook on an array property would allow overriding assignment to mean concatenation.  That would be unavoidable.  That could get weird, but again, I don't see a way to prevent that.

For set-once, let's imagine that `readonly` doesn't exist to see what it would equate to.

```php
class Person
{
    public string $name;
    
    public function __construct(string $name)
    {
        $this->name = $name;
    }
    
    public set function name(string $new): void
    {
        static $nameSet = false;
        if ($nameSet) {
            throw new \LogicException();
        }
        $nameSet = true;
        $this->name = $new;
    }
}
```

That's a bit gross.  It wouldn't really work without an "init" or "once" hook, or something like that.  (Whether not those are synonymous depends on if you define "init" to be limited to certain scopes, like the constructor.)

```php
class Person
{
    public string $name;
    
    public function __construct(string $name)
    {
        $this->name = $name;
    }
    
    public once function name(string $new): void
    {
        $this->name = $new;
    }
}
```

Which is still not great.  IMO, this is better resolved with just access control indicators.


Evolvable objects:

```php
class Person
{
    public function __construct(
        public private(set) string $name,
        public private(set) int $age,
    ) {}
    
    public function withName(string $new): static
    {
        $that = clone($this);
        $that->name = $new;
        retrn $that;
    }

    public function withAge(int $new): static
    {
        $that = clone($this);
        $that->age = $new;
        retrn $that;
    }
}
```

This looks longer, but only because there's only 2 properties.  On a larger object, this would be a considerable improvement over needing to go through the constructor again every time, which only works if you have an all-promoted constructor.  This would be far more flexible, and would allow for both `private` and `protected` as appropriate.

A `clone with` syntax would still be better, however.

What's unclear is how any of these would enable "disable by-ref but don't do anything else," which is a required feature for PHP.

Ironically, the separate keyspaces for methods and properties helps in the case of logging or other interception:

```php
class Person
{
    private array $isModified = [];

    public function __construct(
        public string $name,
        public int $age,
    ) {}
    
    public set function name(string $new): void
    {
        $this->isModified['name'] = true;
        $this->name = $new;
    }

    public set function age(int $new): void
    {
        $this->isModified['age'] = true;
        $this->age = $new;
    }
    
    public function save(): void
    {
        foreach (array_keys($this->isModified) as $prop) {
            // ...
        }
    }
}
```

Though ideally it would be nice for there to be some way to cover multiple properties at once.  I think that's where Swift's property wrappers would come in.  Doing the above with `before` or `after` would be largely similar.

For kicks, let's imagine an object that does everything it can:

```php
class Person
{
    public protected(set) int $readCount = 0;
    public protected(set) int $writeCount = 0;
    private bool $degreesModified = false;

    public array $degrees = [];

    public function __construct(
        public string $first,
        public string $last,
        public string $phone,
    ) {}
    
    public get function fullName(): string
    {
        $this->readCount++;
        return $this->first . ' ' . $this->last;
    }
    
    public get function first(): string
    {
        $this->readCount++;
        return $this->first;
    }
    
    public before function phone(string $new): string
    {
        if (!preg_match('/\d\d\d\-\d\d\d\-\d\d\d\d/', $new)) {
            throw new \InvalidArgumentException();
        }
        return $new;
    }
    
    public before function degrees(string|array $degrees): array
    {
        return is_string($degrees) ? [$degrees] : $degrees;
    }
    
    public after function degrees(array $degrees): void
    {
        $this->degreesModified = true;
    }
}
```

#### Pros

* Simple enough to read, most of the time.
* No deep nesting.
* Interaction with inheritance and traits is self-evidence, as there is no automatic relationship between a property and a method/hook.  The hooks are just methods and act accordingly.
* Moving asymmetric visibility out of the methods to Swift-style prefixes helps keep the concepts separate, because, really, they are.
* Swift-esque lazy properties are very appealing, with the caveat that people have to remember that they cannot be reset.
* CPP is trivially solved.

#### Cons

* The confusion between method names and property names could get weird.
* Yet another keyword on methods to make them do magic things.
* Multiple methods/hooks with the same name could get confusing, either for the engine or the user or both.
* Are hook methods directly invokable somehow?  Is that good or bad?
* Having the visibility markers on both the method and the property is ripe for confusion, as we'd have to figure out what happens when they're out of sync, and it's one more thing for developers to think about.
* Not obvious how to disable by-ref behavior without adding other behavior.

### Expanded properties

This is the Swift/C# model.  They're very similar, syntactically.  The main meaningful difference is that Swift keeps access control separate from the hook implementations while C# puts the access control on the hook itself.  Swift also has more/different hooks.

I'm going to try both visibility positions and see what feels good.

```php
class User
{
    private string $first;
    private string $last;
    
    public string $fullName {
        get { 
            return $this->first . " " . $this->last;
        }
    }
}
```

That's roughly the same as Swift and C#, and about the same typing as the method-based version.  However, both Swift and C# have a shorthand, which in PHP would look like this:

```php
class User
{
    private string $first;
    private string $last;
    
    public string $fullName {
        get => $this->first . " " . $this->last;
    }
}

// Or even

class User
{
    private string $first;
    private string $last;
    
    public string $fullName {
        $this->first . " " . $this->last;
    }
}
```

I much prefer this abbreviated syntax, and it's consistent with `=>` elsewhere.  The extra short one is a bit unusual for PHP, as PHP does not have "expression blocks" the way Swift, C#, or Rust do.  IMO, it either `return` or `=>` is necessary to indicate that a value comes back, because otherwise it's inconsistent with the rest of PHP.

Which does suggest that it could shorten to:

```php
class User
{
    private string $first;
    private string $last;
    
    public string $fullName {
        get => $this->first . " " . $this->last;
    }
}

// Or even

class User
{
    private string $first;
    private string $last;
    
    public string $fullName => $this->first . " " . $this->last;
}
```

Which is actually kinda nice, and looks an awful lot like the lazy-cached option above in the promoted methods section.

Which would, in this model, look like this:

```php
```php
class User
{
    private string $first;
    private string $last;
    
    // No way to reset it, so must be readonly.
    public readonly string $fullName {
        lazy {
            $this->fullName ??= $this->first . " " . $this->last;
        }
    }
}

// Or

class User
{
    private string $first;
    private string $last;
    
    public readonly string $fullName => $this->first . " " . $this->last;
}
```

Asymmetric visibility is where Swift and C# differ.  If that's all you're doing, it would look like this:

```php
// Swift style
class Person
{
    public private(set) string $id;
}

// C# style
class Person
{
    public string $id { get; private set; }
}
```

I aesthetically prefer the former, as it keeps all the visibility information in one place.  However, it does raise an interesting question about what to do with other hooks besides `set`.  Does visibility on `init` or `lazy` or `before` or ... make sense?  Or can we guarantee everything is a subset of get or set, and thus should just inherit that?

If that is expanded such that any action can be in parens, then assuming a `foo` operation, the syntax would naturally extend to:

```php
class Person
{
    public private(set) protected(foo) string $id;
}
```

Whether that even makes sense to ever do I don't know, but in concept the syntax could scale to it.

Validation depends on what hooks are implemented.

```php
class Person
{
    private string $_phone;

    // US 10 digit number
    public string $phone {
        get => $this->_phone;
        set {
            if (!preg_match('/\d\d\d\-\d\d\d\-\d\d\d\d/', $value)) {
                throw new \InvalidArgumentException();
            } 
            $this->_phone = $new;
        }
    }
}

// Or, if we expand to a beforeSet hook:

class Person
{
    // US 10 digit number
    public string $phone {
        beforeSet {
            if (!preg_match('/\d\d\d\-\d\d\d\-\d\d\d\d/', $value)) {
                throw new \InvalidArgumentException();
            }
            return $value;
        }
    }
}
```

In these examples, I'm using the C#-style magic variable name.  I do not like it.  At all.  It's learnable, sure, but entirely unintuitive.  I'd much rather spend the extra characters and have people specify a variable name.  That's especially relevant when, for instance, working with compound types:

```php
class Person
{
    public array $degrees = [] {
        set(string|array $new) {
            $this->degrees = is_string($degrees) ? [$degrees] : $degrees;
        }
    }
}
```

Set-once, as noted above, really should not be solved with callback hooks.  That's an ugly way of doing it.  They should be solved with the access control system.  Depending if that's Swift style or C# style:

```php
// Swift
class Person
{
    public private(init) string $name;
    
    public function __construct(string $name)
    {
        $this->name = $name;
    }
}

// C#
class Person
{
    public string $name {
        get;
        private set;
        // or possibly
        private once;
    }
    
    public function __construct(string $name)
    {
        $this->name = $name;
    }
}
```

Both of which seem like they'd still be CPP-compatible:

```php
// Swift
class Person
{
   public function __construct(public private(init) string $name) {}
}

// C#
class Person
{
    public function __construct(public string $name { get; private once; }) {}
}
```

Evolvable objects, as with the promoted method style, are more the domain of access control rather than accessors.  For completeness:

```php
// Swift
class Person
{
    public function __construct(
        public private(set) string $name,
        public private(set) int $age,
    ) {}
    
    public function withName(string $new): static
    {
        $that = clone($this);
        $that->name = $new;
        retrn $that;
    }

    public function withAge(int $new): static
    {
        $that = clone($this);
        $that->age = $new;
        retrn $that;
    }
}

// C#
class Person
{
    public function __construct(
        public string $name { get; private set; },
        public int $age { get; private set; },
    ) {}
    
    public function withName(string $new): static
    {
        $that = clone($this);
        $that->name = $new;
        retrn $that;
    }

    public function withAge(int $new): static
    {
        $that = clone($this);
        $that->age = $new;
        retrn $that;
    }
}
```

Not too different.

Change tracking gets problematic with expanded properties, because there's no auto-backing value.  With `set` only, it honestly kinda sucks:

```php
class Person
{
    private array $isModified = [];

    private string $_name;
    private int $_age;

    public string $name {
        get => $this->$_name;
        set($new) {
            $this->_name = $new;
            $this->isModified['name'] = true;
        }
    }

    public int $age {
        get => $this->$_age;
        set($new) {
            $this->_age = $new;
            $this->isModified['age'] = true;
        }
    }

    public function __construct(
        string $name,
        int $age,
    ) {
        $this->name = $name;
        $this->age = $age;
    }

    public function save(): void
    {
        foreach (array_keys($this->isModified) as $prop) {
            // ...
        }
    }
}
```

With a `beforeSet` hook, though, it's way nicer, although it does cause issues with CPP.

```php
class Person
{
    private array $isModified = [];

    public string $name {
        beforeSet($new) => $this->isModified['name'] = true;
    }

    public int $age {
        beforeSet($new) => $this->isModified['age'] = true;
    }

    public function __construct(
        string $name,
        int $age,
    ) {
        $this->name = $name;
        $this->age = $age;
    }

    public function save(): void
    {
        foreach (array_keys($this->isModified) as $prop) {
            // ...
        }
    }
}
```

Which leads to the biggest drawback of the expanded properties approach, its practical incompatibility with CPP.  For a great many use cases, that doesn't matter.  The one place it does matter would be for an otherwise normal property that wants extra validation.  Some languages (e.g., Midori) have a whole separate feature for that, or some form of design-by-contract, or dependent types, or, whatever.  So certainly there are other ways to solve that use case, but it's such a common one in practice that if we're adding accessors anyway then it should be seriously considered as a primary use case.  Additionally, those are mostly based on function/argument parameters, not on properties.  Those are not the same thing, even if they often overlap.

The only loophole I could possibly see would be enable both a `beforeSet` hook *and* to allow a hook to still be CPP inlined if it has a single hook, and that hook uses the expression-form.  That would allow for:

```php
class User
{
    public function __construct(
        public private(set) $username { beforeSet($username) => strtolower($username) },
        public private(set) $phone { beforeSet($phone) => $this->validPhone($phone) },
    ) {}
    
    private function validPhone(string $new): string
    {
        if (!preg_match('/\d\d\d\-\d\d\d\-\d\d\d\d/', $new)) {
            throw new \InvalidArgumentException();
        }
        return $new;
    }
}
```

I'm not sure if I like that.  I wouldn't worry about it too much if I didn't see this as one of the three largest use cases (the other two being dynamic properties and asymmetric visibility).

Or, perhaps, allowing properties to be double-defined iff they use accessors?

```php
class User
{
    public private(set) $username { 
        beforeSet($username) => strtolower($username);
    }

    public private(set) $phone { 
        beforeSet($phone) => $this->validPhone($phone);
    }

    public function __construct(
        public private(set) $username,
        public private(set) $phone,
    ) {}
    
    private function validPhone(string $new): string
    {
        if (!preg_match('/\d\d\d\-\d\d\d\-\d\d\d\d/', $new)) {
            throw new \InvalidArgumentException();
        }
        return $new;
    }
}
```

That would only work if the visibility is identical in both cases, which... is probably fine.

For completeness, here's the previous "everying" object but done in this style.  I've assumed the short-hands from C#/Swift, because I feel they really do improve the DX considerably.

```php
class Person
{
    public protected(set) int $readCount = 0;
    public protected(set) int $writeCount = 0;
    private bool $degreesModified = false;

    private string $_first;

    public string $first {
        get {
            $this->readCount++;
            return $this->_first;
        }
        set($first) => $this->_first = $first;
    }

    public string $fullName {
        $this->readCount++;
        return $this->first . ' ' . $this->last;
    }

    public array $degrees = [] {
       before(string|array $degrees) => is_string($degrees) ? [$degrees] : $degrees;
       after(array $degrees) => $this->degreesModified = true;
    },

    public function __construct(
        string $first,
        public string $last,
        public string $phone { before($new) 
            => preg_match('/\d\d\d\-\d\d\d\-\d\d\d\d/', $new) ? $new : throw new \InvalidArgumentException() },
    ) {
        $this->first = $first;
    }
}
```

The existence of `$_first` is a red flag.  There are use cases where you want to not change the storage, just intercept it.  (Logging in this case.)  The `before`/`after` hooks help with that some, but those don't exist on `get` in any language I know of.  But tracking "has something been modified" is a valid use case, especially for objects that may get stored, or clearing a cache when something is modified.  Arguably, though, interstitial behavior on `get` is a lot less useful than on `set`, so maybe just a before/after on `set` is sufficient.  On `get`, the more interesting behavior is dynamic properties, with or without caching.

#### Pros

* No confusion on visibility between methods and properties.
* The short-hand syntax is frankly really nice.
* Collects all behavior for a given property into one place, rather than scattering it all over the class.
* It's self-evident that the hooks are not directly invokable.  (Though if they call a method themselves, that method is invokable like any other method.)
* Like in C#, using empty `get`/`set` blocks is a natural way to flag the property as no-ref, as it pushes it over into accessor territory.

#### Cons

* CPP compatibility issues.  This is the big one.
* Code is more deeply nested.
* We have to bikeshed about whether a custom variable for `*set` is optional, required, or forbidden.
* I definitely favor the Swift-style separate visibility markers.
* Interaction with inheritance becomes more complex to consider.

## Disabling references

Because of the reference issues, there needs to be some way to switch a property from non-accessor behavior to accessor behavior (what C# calls "fields" and "properties", respectively) without actually implementing a hook.  There's a couple of ways that could be done:

* A `noref` modifier on the property.
* An `accessor` modifiier on the property.
* In the expanded property approach, requiring `{ get; set;}` to indicate "and use the default get/set logic."  (This is what C# does.)
* In the expanded property approach, requiring `{}` to indicate "this is an enhanced property, even though I do nothing with that."

One tricky bit is that *most* hooks are mutually-independent, *except* for `get` and `set`.  Implementing either one disables the automatic storage variable, which makes the default version of the other nonsensical.  However, depending on the details having auto-generated versions of those hooks exist "only sometimes" could be confusing.

Analyzing the potential implicit implementation behavior, however:

| Input                       | Inferred                    | Notes                                   |
|-----------------------------|-----------------------------|-----------------------------------------|
| { }                         | { get; set; }               | Use default get and set                 |
| { get {} }                  | { get {} }                  | An empty get, no set                    |
| { set {} }                  | { set {} }                  | An empty set, no get                    |
| { get {} set {} }           | { get {} set {} }           | Explicitly empty set and get            |
| { didSet {} }               | { get; set; didSet {} }     | Explicit after, but default get/set     |
| { get {} didSet {} }        | Disallowed                  | Empty get, no set, but after; illogical |
| { set {} didSet {} }        | { set {} didSet {} }        | Write-only property with after-hook.    |
| { get {} set {} didSet {} } | { get {} set {} didSet {} } | Explicitly empty everything. Unusable.  |

Which leads to a reasonably memorable rule: "If neither `get` nor `set` are specified, both get automatic default implementations.  In all other situations, nothing is provided by default and you must implement it yourself."

That's a simple-enough rule for developers to learn, and logically self-evident once thought about, so is probably the best we can do.

To that end, `{}` after a property is probably the best option for indicating "switch to fancy mode."

## Overall thoughts

At the moment, I am leaning in favor of Swift as the best model.  In particular, it allows splitting asymmetric visibility off from accessor hooks, which I believe is the correct move.  They really are separate things.

Ideally, we could redefine `readonly` as an alias of `public private(once)`, or similar, to avoid weirdly inconsistent behavior.  That `once` is an oddball thing to add, but it would also allow for readonly properties that are settable from a child class, which is currently broken with `readonly`.

The big downside of the accessor style is CPP compatibility.  In particular, change tracking and extended validation are really obvious use cases where you'd still want to use a same-named storage value, so CPP has a clear benefit, but combining the two results in weirdness.  My best solution there at present is to allow double-definition of a property (on its own and in CPP) iff it's defining at least one hook and the visibility definition matches exactly.  That's not ideal, but probably the best we can do.

The examples above have also convinced me that even if `get`/`set` are the only hooks included in the initial patch, `beforeSet` and `afterSet` need to be added very soon after.  `lazy` or whatever would also be useful, but is not on the same level.  Honestly, I think the before/after set cases (for validation, normalization, and change tracking) and asymmetric visibility will cover the 90% case, and if we have those, defining `set` yourself will actually be rather rare.

Regardless of the mechanism, interface support for accessors will be necessary.  No question.

