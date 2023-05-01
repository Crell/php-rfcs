# Usage examples

This section includes a collection of examples the authors feel are representative of how property hooks can and will be used.  It is non-normative, but gives a sense of how hooks can be used to improve a code base.

## Derived properties

This example demonstrates a virtual property that is calculated on the fly off of other values.

```php
class User
{
    public function __construct(private string $first, private string $last) {}

    public string $fullName => $this->first . " " . $this->last;
}
```

## Lazy/cached properties

Sometimes, a derived property may be expensive to compute.  The example above would recompute it every time.  However, the `??=` operator may be used to easily cache the value.

```php
class User
{
    private string $full;

    public function __construct(private string $first, private string $last) {}
    
    public string $fullName => $this->full ??= $this->first . " " . $this->last;
}
```

Note that this approach does raise a question of when to invalidate the cache.  In some cases it will not be necessary at all.  If it is, the ideal solution would be an `afterSet` hook, as described in the RFC "Future Scope" section (especially if CPP were allowed for non-virtual properties).

```php
class User
{
    private string $full;

    private string $first { afterSet => unset($this->full); };
    private string $last { afterSet => unset($this->full); };

    public function __construct(
        string $first,
        string $last,
    ) {
        $this->first = $first;
        $this->last = $last;
    }
    
    public string $fullName => $this->full ??= $this->first . " " . $this->last;
}
```

With just the existing `get`/`set` hooks, the same design can be achieved with a little more work:

```php
class User
{
    private string $full;

    private string $_first;
    private string $_last;

    private string $first {
        get => $this->_first;
        set {
            $this->_first = $value;
            unset($this->full);
        }
    };

    private string $last {
        get => $this->_first;
        set {
            $this->_first = $value;
            unset($this->full);
        }
    };

    public function __construct(
        string $first,
        string $last,
    ) {
        $this->first = $first;
        $this->last = $last;
    }
    
    public string $fullName => $this->full ??= $this->first . " " . $this->last;
}
```

Now, `$fullName` will be cached but the cache reset any time `$first` or `$last` is updated.

If multiple virtual properties are to be cached, they can be collapsed into a single cache array like so:

```php
class User
{
    private array $cache = [];

    // ...

    public string $fullName => $this->cache[__PROPERTY__] ??= $this->first . " " . $this->last;
}
```

All of these options are entirely transparent to the caller, making them straightforward to add after-the-fact.

## Type normalization

As noted in the RFC, the `set` hook may accept a wider set of values than the type of the property.  That allows it to "normalize" the type to a common type for reading, while allowing a broader type for writing.  As shown in the RFC:

```php
use Symfony\Component\String\UnicodeString;

class Person
{
    private UnicodeString $unicodeName;
    public UnicodeString $name {
        get {
            return $this->unicodeName;
        }
        set(string|UnicodeString $value) {
            if (is_string($value)) {
                $value = new UnicodeString($value);
            }
            $this->$unicodeName = $value;
        }
    }
}
```

This example ensures that the `$name` property is always a `UnicodeString` instance, but allows users to write PHP strings to it.  Those will get automatically up-converted to `UnicodeStrings`, which then ensures future code only has one type to have to worry about.

## Definitional interfaces

A common pattern for many PHP libraries is to have an interface that defines methods that return simple strings.  Alternatively, some define magic public properties, which have all the corresponding problems of public properties.

The use of properties on interfaces obviates both issues.

For example, this is a real interface out of a [library maintained by one of the RFC authors](http://github.com/Crell/AttributeUtils|Crell/AttributeUtils), and a typical class that implements it:

```php
interface ParseProperties
{
    public function setProperties(array $properties): void;

    public function includePropertiesByDefault(): bool;

    public function propertyAttribute(): string;
}

#[\Attribute(\Attribute::TARGET_CLASS)]
class ClassWithProperties implements ParseProperties
{
    public array $properties = [];

    public function __construct(
        public readonly int $a = 0,
        public readonly int $b = 0,
    ) {}

    public function setProperties(array $properties): void
    {
        $this->properties = $properties;
    }

    public function includePropertiesByDefault(): bool
    {
        return true;
    }

    public function propertyAttribute(): string
    {
        return BasicProperty::class;
    }
}
```

The `includePropertiesByDefault()` and `propertyAttribute()` methods will, 99% of the time, be static strings.  But making them just a property today would both make them publicly editable and make the other 1% case, where one of the values should be dynamic, impossible or extremely difficult.

This RFC would allow that to be simplified to:

```php
interface ParseProperties
{
    public function setProperties(array $properties): void;

    public bool $includePropertiesByDefault { get; }

    public string $propertyAttribute { get; }
}

#[\Attribute(\Attribute::TARGET_CLASS)]
class ClassWithProperties implements ParseProperties
{
public array $properties = [];

    // This would be technically publicly editable
    // unless asymmetric visibility is added.
    public bool $includePropertiesByDefault = true;
    
    // This simulates public-read-only while still
    // fulfilling the interface.
    public bool $propertyAttribute => BasicProperty::class;

    public function __construct(
        public readonly int $a = 0,
        public readonly int $b = 0,
    ) {}

    public function setProperties(array $properties): void
    {
        $this->properties = $properties;
    }
}
```

And should it become necessary to make either of them dynamic, that can be trivially done without any API change:

```php
#[\Attribute(\Attribute::TARGET_CLASS)]
class ClassWithProperties implements ParseProperties
{
    public array $properties = [];

    public bool $includePropertiesByDefault => $this->include;
    
    public bool $propertyAttribute => $this->propertiesAs;

    public function __construct(
        public readonly int $a = 0,
        public readonly int $b = 0,
        private readonly $include = true;
        private readonly $propertiesAs = BasicProperty::class,
    ) {}

    public function setProperties(array $properties): void
    {
        $this->properties = $properties;
    }
}
```
